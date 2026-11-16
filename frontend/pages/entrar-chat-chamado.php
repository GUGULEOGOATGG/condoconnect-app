<?php
require_once __DIR__ . '/../includes/auth.php';
requireVerifiedEmail();
$user = currentUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($user['tipo_usuario'] ?? '') !== 'sindico') { http_response_code(403); exit('Acesso não permitido.'); }
$ticketId = filter_input(INPUT_POST, 'chamado_id', FILTER_VALIDATE_INT) ?: 0;
$q = getPDO()->prepare("SELECT id FROM chats WHERE chamado_id = :ticket AND status = 'ativo' LIMIT 1");
$q->execute([':ticket' => $ticketId]); $chatId = (int)$q->fetchColumn();
if ($chatId && joinTicketChatAsSyndic($chatId, (int)$user['id'])) redirect(getBasePath() . 'pages/chat.php?chat=' . $chatId);
$_SESSION['ticket_notice'] = 'Não foi possível entrar nesta conversa. Verifique o condomínio e o chamado.';
redirect(getBasePath() . 'pages/dashboard.php');
