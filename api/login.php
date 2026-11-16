<?php
require_once __DIR__ . '/_api.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    apiOptions();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiJson(405, ['error' => 'Método não permitido.']);
}

$data = apiReadJson();
$email = strtolower(trim((string) ($data['email'] ?? '')));
$senha = (string) ($data['senha'] ?? '');

if ($email === '' || $senha === '') {
    apiJson(400, ['error' => 'Informe e-mail e senha.']);
}

$user = authenticateUser($email, $senha);
if ($user === null) {
    apiJson(401, ['error' => 'E-mail ou senha inválidos.']);
}

if ((int)$user['two_factor_enabled']===1) apiJson(403,['error'=>'Use o login web para concluir a verificação em duas etapas.']);
apiJson(200, ['user' => apiPublicUser($user), 'token' => apiIssueToken((int)$user['id'])]);
