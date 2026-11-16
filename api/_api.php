<?php
require_once __DIR__ . '/../backend/includes/functions.php';
set_exception_handler(static function(Throwable $e): void {
    [$status, $payload] = apiFailureResponse($e);
    apiJson($status, $payload);
});

/** Expected input conflicts remain client errors; unexpected failures are logged. */
function apiFailureResponse(Throwable $error): array
{
    if ($error instanceof DatabaseConfigurationException || $error instanceof DatabaseConnectionException) {
        return [503, ['error' => friendlyError($error)]];
    }
    if ($error instanceof PDOException) {
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        if ($driverCode === 1062) return [409, ['error' => 'Este e-mail ou telefone já está cadastrado.']];
        if ($driverCode === 1451) return [409, ['error' => 'Este registro ainda está associado a outros dados.']];
        if (in_array($driverCode, [1048, 1406, 1452, 3819, 4025], true)) {
            return [422, ['error' => 'Os dados informados são inválidos ou fazem referência a um registro inexistente.']];
        }
    }
    if ($error instanceof DomainException) return [422, ['error' => $error->getMessage()]];
    if ($error instanceof InvalidArgumentException) return [400, ['error' => $error->getMessage()]];
    return [500, ['error' => friendlyError($error)]];
}

function apiOptions(): void
{
    http_response_code(204);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
    exit;
}

function apiJson(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiReadJson(): array
{
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || trim($rawBody) === '') {
        return [];
    }

    try {
        $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        apiJson(400, ['error' => 'JSON inválido.']);
    }
    if (!is_array($decoded) || !str_starts_with(ltrim($rawBody), '{')) {
        apiJson(400, ['error' => 'Envie um objeto JSON.']);
    }
    foreach ($decoded as $key => $value) {
        if (!is_scalar($value) && $value !== null && !($key === 'metadata' && is_array($value))) {
            apiJson(400, ['error' => 'Campos JSON inválidos.']);
        }
    }
    return $decoded;
}

function apiPublicUser(array $user): array
{
    unset($user['senha'],$user['two_factor_code_hash'],$user['two_factor_expires_at'],$user['documento_certificado'],$user['cpf']);
    return $user;
}

function apiTokenUser(?string $token): ?array
{
    if (!is_string($token) || trim($token) === '') {
        return null;
    }

    if (!preg_match('/^[a-f0-9]{64}$/',$token)) return null;
    $row=dbRow('SELECT user_id FROM sessions WHERE token=? AND created_at>DATE_SUB(NOW(),INTERVAL 7 DAY)',[hash('sha256',$token)]);
    $user = $row ? findUserById((int)$row['user_id']) : null;
    if ($user === null || ($user['status'] ?? 'ativo') !== 'ativo' || !demoLoginAllowed((string)$user['email'])) {
        return null;
    }

    return $user;
}

function apiIssueToken(int $userId): string {
    $token=bin2hex(random_bytes(32));
    dbExecute('INSERT INTO sessions (user_id,token) VALUES (?,?)',[$userId,hash('sha256',$token)]);
    return $token;
}
