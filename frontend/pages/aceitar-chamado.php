<?php
require_once __DIR__ . '/../includes/auth.php';
requireVerifiedEmail();
$user = currentUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($user['tipo_usuario'] ?? '') !== 'prestador') {
    http_response_code(403); exit('Acesso não permitido.');
}
$ticketId = filter_input(INPUT_POST, 'chamado_id', FILTER_VALIDATE_INT) ?: 0;
try {
    $chatId = acceptChamado($ticketId, (int)$user['id']);
    redirect(getBasePath() . 'pages/chat.php?chat=' . $chatId);
} catch (Throwable $e) {
    $_SESSION['chat_error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível aceitar o chamado.';
    redirect(getBasePath() . 'pages/dashboard.php');
}
