<?php
require_once __DIR__ . '/../includes/auth.php';
requireVerifiedEmail();
$user = currentUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método não permitido.'); }
$ticketId = filter_input(INPUT_POST, 'chamado_id', FILTER_VALIDATE_INT) ?: 0;
$status = trim((string)($_POST['status'] ?? ''));
$note = trim((string)($_POST['observacao'] ?? ''));
try {
    if (!updateChamadoStatus($ticketId, (int)$user['id'], $status, $note)) $_SESSION['ticket_notice'] = 'A alteração não foi permitida para este chamado.';
    else $_SESSION['ticket_notice'] = 'Status atualizado e registrado no histórico.';
} catch (Throwable $e) { $_SESSION['ticket_notice'] = friendlyError($e); }
redirect(getBasePath() . 'pages/dashboard.php');
