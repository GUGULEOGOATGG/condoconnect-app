<?php
require_once __DIR__ . '/_api.php';
require_once __DIR__ . '/../backend/includes/auth.php';
require_once __DIR__ . '/../backend/services/openai_service.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    apiOptions();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiJson(405, ['error' => 'Método não permitido.']);
}

$user = currentUser();
if (!$user || !(int)($user['email_verificado'] ?? 0)) apiJson(401, ['error' => 'Autenticação necessária.']);
$data = apiReadJson();
$question = trim((string) ($data['question'] ?? ''));
if ($question === '') {
    apiJson(400, ['error' => 'Informe a pergunta ou descrição do problema.']);
}

$result = openAiAssistantReply($question, ['user_role' => ($_SESSION['user_role'] ?? 'morador')]);
apiJson(200, $result);
