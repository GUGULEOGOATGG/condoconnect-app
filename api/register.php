<?php
require_once __DIR__ . '/_api.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    apiOptions();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiJson(405, ['error' => 'Método não permitido.']);
}

$data = apiReadJson();
$nome = trim((string) ($data['nome'] ?? ''));
$email = strtolower(trim((string) ($data['email'] ?? '')));
$senha = (string) ($data['senha'] ?? '');
$confirmarSenha = (string) ($data['confirmarSenha'] ?? '');
$telefone = trim((string) ($data['telefone'] ?? ''));
$cpf = trim((string) ($data['cpf'] ?? ''));
$tipoUsuario = trim((string) ($data['tipoUsuario'] ?? $data['tipo_usuario'] ?? 'morador'));
$condominio = trim((string) ($data['condominio'] ?? ''));

if ($nome === '' || $email === '' || $senha === '' || $telefone === '' || $condominio === '') {
    apiJson(400, ['error' => 'Preencha todos os campos obrigatórios.']);
}

foreach (['nome' => [$nome, 190], 'email' => [$email, 190], 'telefone' => [$telefone, 30], 'cpf' => [$cpf, 20], 'condominio' => [$condominio, 190], 'unidade' => [(string) ($data['unidade'] ?? ''), 190]] as $field => [$value, $limit]) {
    if (mb_strlen($value, 'UTF-8') > $limit) apiJson(400, ['error' => 'O campo ' . $field . ' excede o tamanho permitido.']);
}

if ($senha !== $confirmarSenha && $confirmarSenha !== '') {
    apiJson(400, ['error' => 'As senhas não conferem.']);
}

if (strlen($senha) < 8) {
    apiJson(400, ['error' => 'A senha deve ter pelo menos 8 caracteres.']);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    apiJson(400, ['error' => 'Informe um e-mail válido.']);
}

if (!in_array($tipoUsuario, ['morador', 'prestador'], true)) {
    apiJson(400, ['error' => 'Perfil inválido.']);
}

try {
    $registrationData = normalizeRegistrationData([
        'nome' => $nome,
        'cpf' => $cpf,
        'email' => $email,
        'telefone' => $telefone,
        'senha' => $senha,
        'tipo_usuario' => $tipoUsuario,
        'condominio' => $condominio,
        'unidade' => trim((string) ($data['unidade'] ?? '')),
    ]);
} catch (DomainException $error) {
    apiJson(400, ['error' => $error->getMessage()]);
}

if (findUserByEmail($email) !== null) {
    apiJson(409, ['error' => 'Este e-mail já está cadastrado.']);
}
if (dbRow('SELECT id FROM users WHERE telefone=? LIMIT 1', [$registrationData['telefone']])) {
    apiJson(409, ['error' => 'Este telefone já está cadastrado.']);
}

$userId = createUser($registrationData);

$user = findUserById($userId);
if ($user === null) {
    apiJson(500, ['error' => 'Não foi possível criar a conta.']);
}

apiJson(201, ['user' => apiPublicUser($user), 'token' => apiIssueToken((int)$user['id'])]);
