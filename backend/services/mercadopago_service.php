<?php
declare(strict_types=1);
require_once __DIR__ . '/http_client.php';

function mpConfig(): array
{
    return require __DIR__ . '/../config/mercadopago.php';
}

function mpHttpRequest(string $method, string $url, array $payload = [], array $headers = [], ?HttpTransport $transport = null): array
{
    try {
        $body = in_array($method, ['POST', 'PUT', 'PATCH'], true)
            ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null;
        if ($body !== null && !in_array('Content-Type: application/json', $headers, true)) $headers[] = 'Content-Type: application/json';
        $response = ($transport ?? new CurlHttpTransport())->request($method, $url, $headers, $body, 30);
    } catch (JsonException $error) {
        return ['status' => 400, 'body' => ['error' => 'Dados do pagamento inválidos.']];
    } catch (HttpTransportException | InvalidArgumentException $error) {
        return ['status' => $error instanceof HttpTransportException && $error->reason === 'timeout' ? 504 : 502,
            'body' => ['error' => 'Não foi possível conectar ao Mercado Pago.']];
    }
    try {
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        return ['status' => 502, 'body' => ['error' => 'Resposta inválida do Mercado Pago.']];
    }
    if (!is_array($decoded) || !str_starts_with(ltrim($response->body), '{') || $response->status < 200 || ($response->status >= 300 && $response->status < 400)) {
        return ['status' => 502, 'body' => ['error' => 'Resposta inválida do Mercado Pago.']];
    }
    return ['status' => $response->status, 'body' => $decoded];
}

function mpOAuthRequest(array $data, array $config, ?HttpTransport $transport = null): array
{
    foreach (['client_id', 'client_secret', 'grant_type', 'code', 'code_verifier', 'redirect_uri', 'refresh_token', 'test_token'] as $field) {
        if (isset($data[$field]) && !is_scalar($data[$field])) return ['status' => 400, 'body' => ['error' => 'Dados de autenticação inválidos.']];
    }
    $clientId = trim((string)($data['client_id'] ?? $config['client_id'] ?? ''));
    $secret = trim((string)($data['client_secret'] ?? $config['client_secret'] ?? ''));
    if ($clientId === '' || $secret === '') return ['status' => 400, 'body' => ['error' => 'Informe client_id e client_secret do Mercado Pago.']];
    $payload = ['client_secret' => $secret, 'client_id' => $clientId, 'grant_type' => trim((string)($data['grant_type'] ?? 'client_credentials'))];
    foreach (['code', 'code_verifier', 'redirect_uri', 'refresh_token', 'test_token'] as $field) {
        if (array_key_exists($field, $data) && $data[$field] !== '') $payload[$field] = $data[$field];
    }
    $response = mpHttpRequest('POST', 'https://api.mercadopago.com/oauth/token', $payload, ['Content-Type: application/json'], $transport);
    if ($response['status'] < 300 && (!is_string($response['body']['access_token'] ?? null) || trim($response['body']['access_token']) === '')) {
        return ['status' => 502, 'body' => ['error' => 'Resposta inválida do Mercado Pago.']];
    }
    return $response;
}

function mpPaymentRequest(array $data, array $config, ?HttpTransport $transport = null): array
{
    foreach (['access_token', 'idempotency_key', 'description', 'title', 'payment_method_id', 'notification_url', 'external_reference', 'payer_email', 'payer_name', 'payer_last_name'] as $field) {
        if (isset($data[$field]) && !is_scalar($data[$field])) return ['status' => 400, 'body' => ['error' => 'Dados do pagamento inválidos.']];
    }
    $accessToken = trim((string)($data['access_token'] ?? $config['access_token'] ?? ''));
    if ($accessToken === '') return ['status' => 400, 'body' => ['error' => 'Configure MERCADOPAGO_ACCESS_TOKEN antes de criar pagamentos.']];
    $amount = $data['amount'] ?? 0;
    if ((!is_int($amount) && !is_float($amount) && !is_string($amount)) || !is_numeric($amount) || !is_finite((float)$amount) || (float)$amount <= 0) {
        return ['status' => 400, 'body' => ['error' => 'Informe o valor do pagamento.']];
    }
    $key = (string)($data['idempotency_key'] ?? bin2hex(random_bytes(16)));
    if (!preg_match('/^[a-zA-Z0-9._-]{8,64}$/D', $key)) return ['status' => 400, 'body' => ['error' => 'Chave de idempotência inválida.']];
    $payload = [
        'transaction_amount' => (float)$amount,
        'description' => trim((string)($data['description'] ?? $data['title'] ?? 'Pagamento via Mercado Pago')),
        'payment_method_id' => trim((string)($data['payment_method_id'] ?? 'pix')),
        'notification_url' => trim((string)($data['notification_url'] ?? '')),
        'external_reference' => trim((string)($data['external_reference'] ?? '')),
        'metadata' => is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        'payer' => ['email' => trim((string)($data['payer_email'] ?? '')), 'first_name' => trim((string)($data['payer_name'] ?? '')), 'last_name' => trim((string)($data['payer_last_name'] ?? ''))],
    ];
    if ($payload['payer']['email'] !== '' && !filter_var($payload['payer']['email'], FILTER_VALIDATE_EMAIL)) return ['status' => 400, 'body' => ['error' => 'Informe um e-mail válido para o pagador.']];
    if ($payload['notification_url'] !== '' && (!filter_var($payload['notification_url'], FILTER_VALIDATE_URL) || parse_url($payload['notification_url'], PHP_URL_SCHEME) !== 'https')) {
        return ['status' => 400, 'body' => ['error' => 'A URL de notificação deve usar HTTPS.']];
    }
    foreach (['email', 'first_name', 'last_name'] as $field) if ($payload['payer'][$field] === '') unset($payload['payer'][$field]);
    if ($payload['payer'] === []) unset($payload['payer']);
    foreach (['notification_url', 'external_reference'] as $field) if ($payload[$field] === '') unset($payload[$field]);
    $response = mpHttpRequest('POST', 'https://api.mercadopago.com/v1/payments', $payload,
        ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json', 'X-Idempotency-Key: ' . $key], $transport);
    $id = $response['body']['id'] ?? null;
    if ($response['status'] < 300 && ((!is_int($id) && (!is_string($id) || !ctype_digit($id))) || (int)$id < 1)) {
        return ['status' => 502, 'body' => ['error' => 'Resposta inválida do Mercado Pago.']];
    }
    return $response;
}
