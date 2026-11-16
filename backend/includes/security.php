<?php
declare(strict_types=1);

function requestIsHttps(): bool
{
    // A proxy must set HTTPS at the web server, or APP_HTTPS=1 in the environment.
    // Do not trust forwarded headers supplied by arbitrary clients.
    return getenv('APP_HTTPS') === '1'
        || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function startAppSession(): void
{
    // PHPSESSID is shared by unrelated PHP applications on the same host.
    // Never let an auto-started or legacy session replace this application's cookie.
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() === 'SISCSESSID') return;
        session_write_close();
        $_SESSION = [];
        session_id('');
    }
    session_name('SISCSESSID');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    // Keep forms usable for two hours of inactivity, including anonymous forms.
    ini_set('session.gc_maxlifetime', '7200');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => getBasePath(), 'secure' => requestIsHttps(),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    if (!session_start()) throw new RuntimeException('Não foi possível iniciar a sessão.');
    header('Cache-Control: no-store, private');
    expireIdleSession(time());
}

function expireIdleSession(int $now): void
{
    if (isset($_SESSION['_last_activity']) && $now - (int)$_SESSION['_last_activity'] > 7200) {
        $_SESSION = [];
        if (!session_regenerate_id(true)) throw new RuntimeException('Não foi possível renovar a sessão.');
    }
    $_SESSION['_last_activity'] = $now;
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || !preg_match('/^[a-f0-9]{64}$/D', $_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . csrfToken() . '">';
}

function csrfIsValid(mixed $token): bool
{
    $stored = $_SESSION['csrf'] ?? null;
    return is_string($token) && is_string($stored) && $stored !== '' && hash_equals($stored, $token);
}

function validateCsrfRequest(): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return;
    // Canonical form field: csrf. JSON clients send X-CSRF-Token.
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? null;
    if (csrfIsValid($token)) {
        foreach ($_POST as $value) {
            if (!is_scalar($value)) {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                exit(json_encode(['error' => 'Campos de formulário inválidos.'], JSON_UNESCAPED_UNICODE));
            }
        }
        return;
    }
    http_response_code(403);
    $reason = !isset($_COOKIE[session_name()]) ? 'session_cookie_missing'
        : (!isset($_SESSION['csrf']) ? 'session_token_missing'
            : (!is_string($token) || $token === '' ? 'request_token_missing' : 'token_mismatch'));
    error_log('SISC CSRF rejected: ' . $reason);
    $message = 'Formulário expirado. Volte à página, atualize e tente novamente.';
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
        || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message, 'code' => 'csrf_invalid'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Keep the rejection, but provide a GET that obtains a fresh form without
    // replaying credentials or any other submitted data.
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
        $path = getBasePath() . 'pages/login.php';
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Formulário expirado</title><body><p>'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p><a href="'
        . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '">Atualizar formulário e tentar novamente</a></p></body></html>';
    exit;
}

function establishUserSession(array $user): void
{
    if (($user['status'] ?? '') !== 'ativo') throw new DomainException('Conta sem acesso.');
    if (!session_regenerate_id(true)) throw new RuntimeException('Não foi possível renovar a sessão.');
    unset($_SESSION['pending_2fa_user_id']);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_role'] = (string)$user['tipo_usuario'];
    $_SESSION['_password_version'] = hash('sha256', (string)($user['senha'] ?? ''));
    $_SESSION['_last_activity'] = time();
    // Preserve the CSRF token: open tabs still belong to this browser session.
}

function applicationOrigin(): string
{
    $origin = getenv('APP_URL');
    if ($origin !== false && $origin !== '') {
        if (!filter_var($origin, FILTER_VALIDATE_URL) || !in_array(parse_url($origin, PHP_URL_SCHEME), ['http', 'https'], true)) throw new RuntimeException('APP_URL inválida.');
        return rtrim($origin, '/');
    }
    $host = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
    if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $host)) throw new RuntimeException('Configure APP_URL.');
    $secure = requestIsHttps(); $port = (int)($_SERVER['SERVER_PORT'] ?? ($secure ? 443 : 80));
    return ($secure ? 'https://' : 'http://') . $host . (in_array($port, [80, 443], true) ? '' : ':' . $port);
}
