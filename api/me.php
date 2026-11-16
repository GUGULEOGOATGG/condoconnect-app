<?php
require_once __DIR__ . '/_api.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    apiOptions();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiJson(405, ['error' => 'Método não permitido.']);
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
    apiJson(401, ['error' => 'Sessão inválida. Entre novamente.']);
}

$user = apiTokenUser($matches[1]);
if ($user === null) {
    apiJson(401, ['error' => 'Sessão inválida. Entre novamente.']);
}

apiJson(200, ['user' => apiPublicUser($user)]);
