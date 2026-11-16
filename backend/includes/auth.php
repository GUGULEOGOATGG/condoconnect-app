<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';

// Keep a failed render from appending an error document to a partial dashboard.
$appResponseBuffer = null;
if (PHP_SAPI !== 'cli') { ob_start(); $appResponseBuffer = ob_get_level(); }

// Cover database failures in every web page, including session lookups in headers.
set_exception_handler(static function (Throwable $error) use ($appResponseBuffer): void {
    if ($appResponseBuffer !== null) {
        while (ob_get_level() >= $appResponseBuffer) ob_end_clean();
    }
    http_response_code($error instanceof DatabaseConfigurationException || $error instanceof DatabaseConnectionException || $error instanceof PDOException ? 503 : 500);
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    $message = friendlyError($error);
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Serviço indisponível</title><body><h1>Não foi possível concluir a operação</h1><p>' . sanitize($message) . '</p><p><a href="' . sanitize(getBasePath() . 'pages/login.php') . '">Voltar para o login</a></p></body></html>';
});

startAppSession();
validateCsrfRequest();

function currentUser(): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    $id = $_SESSION['user_id'];
    if ((!is_int($id) && (!is_string($id) || !ctype_digit($id))) || (int)$id < 1) {
        unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['_password_version']);
        return null;
    }
    $user = findUserById((int) $_SESSION['user_id']);
    $version = $_SESSION['_password_version'] ?? null;
    if (!$user || $user['status'] !== 'ativo' || !demoLoginAllowed((string)$user['email']) || (isset($_SESSION['_password_version']) && (!is_string($version) || !hash_equals($version, hash('sha256', (string)$user['senha']))))) {
        unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['_password_version']);
        return null;
    }
    return $user;
}

function isLoggedIn(): bool
{
    return currentUser() !== null;
}

function isAdminUser(): bool
{
    $user = currentUser();
    return $user !== null && (string) ($user['tipo_usuario'] ?? '') === 'admin';
}

function requireLogin(): void {
    if (currentUser() === null) redirect(getBasePath() . 'pages/login.php');
    require_once __DIR__ . '/../database/deployment_upgrade.php';
    // Updates delivered by GitHub are applied on the first authenticated visit.
    ob_start();
    try { upgradeDeploymentDatabase(); }
    finally { ob_end_clean(); }
}

function requireVerifiedEmail(): void
{
    requireLogin();
    $user = currentUser();
    if ($user !== null && (int) ($user['email_verificado'] ?? 1) !== 1) {
        $_SESSION['auth_notice'] = 'Confirme seu e-mail para acessar esta funcionalidade.';
        redirect(getBasePath() . 'pages/dashboard.php');
    }
}

function requireAdmin(): array
{
    requireLogin();
    $user = currentUser();
    if ($user === null || (string) ($user['tipo_usuario'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Acesso restrito aos administradores.');
    }
    return $user;
}

if (getenv('APP_AUTO_MIGRATE') === '1') {
    ensureUserProfileColumns();
    ensurePasswordResetTable();

}
