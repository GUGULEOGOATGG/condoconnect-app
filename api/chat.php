<?php
require_once __DIR__ . '/../backend/includes/auth.php';
require_once __DIR__ . '/_api.php';
header('Content-Type: application/json; charset=utf-8');
$user = currentUser();
if (!$user || (int)($user['email_verificado'] ?? 1) !== 1) {
    http_response_code(401); echo json_encode(['error' => 'Autenticação necessária.']); exit;
}
$userId = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    if ($method === 'GET') {
        $chatId = filter_input(INPUT_GET, 'chat_id', FILTER_VALIDATE_INT) ?: 0;
        if (!userCanAccessChat($chatId, $userId)) { http_response_code(404); echo json_encode(['error' => 'Conversa não encontrada.']); exit; }
        $messages = listChatMessages($chatId, $userId);
        echo json_encode(['messages' => array_map(static fn($m) => [
            'mensagem' => (string)$m['mensagem'], 'mine' => (int)$m['remetente_id'] === $userId,
            'hora' => date('d/m H:i', strtotime((string)$m['created_at'])),
            'remetente_nome'=>(string)$m['remetente_nome'], 'lida'=>(bool)$m['lida_por_todos'],
        ], $messages)], JSON_UNESCAPED_UNICODE); exit;
    }
    if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Método não permitido.']); exit; }
    $action = (string)($_POST['action'] ?? 'send');
    if ($action === 'accept_ticket') {
        if (($user['tipo_usuario'] ?? '') !== 'prestador') { http_response_code(403); echo json_encode(['error' => 'Ação permitida somente a prestadores.']); exit; }
        $ticketId = filter_input(INPUT_POST, 'chamado_id', FILTER_VALIDATE_INT) ?: 0;
        $chatId = acceptChamado($ticketId, $userId);
        echo json_encode(['ok' => true, 'chat_id' => $chatId]); exit;
    }
    if ($action !== 'send') { apiJson(400, ['error' => 'Ação inválida.']); }
    $chatId = filter_input(INPUT_POST, 'chat_id', FILTER_VALIDATE_INT) ?: 0;
    $message = (string)($_POST['mensagem'] ?? '');
    if (!sendChatMessage($chatId, $userId, $message)) { http_response_code(422); echo json_encode(['error' => 'Mensagem inválida ou conversa indisponível.']); exit; }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    [$status, $payload] = apiFailureResponse($e);
    apiJson($status, $payload);
}
