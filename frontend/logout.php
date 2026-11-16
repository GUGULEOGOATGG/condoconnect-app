<?php
require_once __DIR__ . '/includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405); header('Allow: POST'); exit('M?todo n?o permitido.');
}
$loggedOutUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
if ($loggedOutUserId) auditAction($loggedOutUserId, 'logout', 'usuario', $loggedOutUserId);

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'], 'domain' => $params['domain'], 'secure' => $params['secure'], 'httponly' => $params['httponly'], 'samesite' => $params['samesite']]);
}

session_destroy();
redirect(getBasePath() . 'index.php');
