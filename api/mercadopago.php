<?php
require_once __DIR__ . '/_api.php';
require_once __DIR__ . '/../backend/services/mercadopago_service.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') apiOptions();
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) apiJson(405, ['error' => 'Método não permitido.']);

$config = mpConfig();
$data = $_SERVER['REQUEST_METHOD'] === 'POST' ? apiReadJson() : [];
$action = strtolower(trim((string)($data['action'] ?? 'status')));
if ($action === 'status') {
    $status = [
        'configured' => !empty($config['access_token']) || (!empty($config['client_id']) && !empty($config['client_secret'])),
        'sandbox' => (bool)($config['sandbox'] ?? true),
        'public_key_set' => !empty($config['public_key']), 'access_token_set' => !empty($config['access_token']),
        'client_id_set' => !empty($config['client_id']), 'client_secret_set' => !empty($config['client_secret']),
        'redirect_uri' => $config['redirect_uri'] ?? '',
    ];
    if ($_SERVER['REQUEST_METHOD'] === 'GET') $status['message'] = 'Configure MERCADOPAGO_ACCESS_TOKEN e MERCADOPAGO_PUBLIC_KEY para ativar o checkout.';
    apiJson(200, $status);
}

if (in_array($action, ['oauth_token', 'create_payment'], true)) {
    require_once __DIR__ . '/../backend/includes/auth.php';
    $actor = currentUser();
    if ($action === 'oauth_token') {
        if (!$actor || $actor['tipo_usuario'] !== 'admin') apiJson(403, ['error' => 'Acesso restrito aos administradores.']);
        $response = mpOAuthRequest($data, $config);
    } else {
        if (!$actor || !(int)$actor['email_verificado'] || !in_array($actor['tipo_usuario'], ['admin', 'sindico'], true)) apiJson(403, ['error' => 'Acesso restrito à administração.']);
        $response = mpPaymentRequest($data, $config);
    }
    if ($response['status'] >= 400) apiJson((int)$response['status'], ['error' => $response['body']['error'] ?? 'Não foi possível concluir a operação no Mercado Pago.']);
    apiJson($action === 'oauth_token' ? 200 : 201, [$action === 'oauth_token' ? 'token' : 'payment' => $response['body']]);
}

apiJson(400, ['error' => 'Ação inválida. Use status, oauth_token ou create_payment.']);
