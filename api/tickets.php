<?php
require_once __DIR__ . '/_api.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    apiOptions();
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
    apiJson(401, ['error' => 'Sessão inválida. Entre novamente.']);
}

$user = apiTokenUser($matches[1]);
if ($user === null) {
    apiJson(401, ['error' => 'Sessão inválida. Entre novamente.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tickets = getChamadosForUser((int) $user['id']);
    apiJson(200, ['tickets' => $tickets]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiJson(405, ['error' => 'Método não permitido.']);
}

$data = apiReadJson();
$payload = [
    'usuario_id' => (int) $user['id'],
    'categoria' => trim((string) ($data['categoria'] ?? '')),
    'titulo' => trim((string) ($data['titulo'] ?? '')),
    'descricao' => trim((string) ($data['descricao'] ?? '')),
    'local_problema' => trim((string) ($data['localProblema'] ?? $data['local_problema'] ?? '')),
    'prioridade' => trim((string) ($data['prioridade'] ?? '')),
    'data_servico' => trim((string) ($data['dataServico'] ?? $data['data_servico'] ?? '')),
    'protocolo' => 'CP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6))),
    'status' => 'aberto',
];

foreach (['categoria', 'titulo', 'descricao', 'local_problema', 'prioridade', 'data_servico'] as $requiredField) {
    if (!isset($payload[$requiredField]) || trim((string) $payload[$requiredField]) === '') {
        apiJson(400, ['error' => 'Preencha todos os campos.']);
    }
}

if (!in_array($payload['categoria'], ['Elétrica','Hidráulica','Pintura','Limpeza','Elevadores','Jardinagem','Segurança','Reparos gerais'], true)) {
    apiJson(400, ['error' => 'Categoria inválida.']);
}

if (!in_array($payload['prioridade'], ['Baixa','Média','Alta','Urgente'], true)) {
    apiJson(400, ['error' => 'Prioridade inválida.']);
}

$date = DateTime::createFromFormat('!Y-m-d', $payload['data_servico']);
if (!$date || $date->format('Y-m-d') !== $payload['data_servico']) {
    apiJson(400, ['error' => 'Data inválida.']);
}

$ticketId = createChamado($payload);
$ticket = getChamadoById($ticketId);
if ($ticket === null) {
    apiJson(500, ['error' => 'Não foi possível registrar o chamado.']);
}

apiJson(201, ['ticket' => $ticket]);
