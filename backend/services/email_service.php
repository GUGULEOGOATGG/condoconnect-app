<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/http_client.php';
require_once __DIR__ . '/smtp_client.php';

function deliverBrevoMail(string $to, string $subject, string $body, string $fromName, ?HttpTransport $httpTransport = null, ?SmtpConnector $smtpConnector = null): bool
{
    if ($fromName==='CondoConnect') $fromName=getenv('BREVO_FROM_NAME') ?: $fromName;
    $from = getenv('BREVO_FROM_EMAIL') ?: '';
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)
        || preg_match('/[\r\n\x00]/', $to . $from . $subject . $fromName)) {
        error_log('BREVO_CONFIGURATION_ERROR: remetente, destinatário ou cabeçalho inválido.');
        return false;
    }
    $apiKey = getenv('BREVO_API_KEY') ?: '';
    try {
        if ($apiKey !== '') {
            if (preg_match('/[\r\n\x00]/', $apiKey)) throw new InvalidArgumentException('Chave Brevo inválida.');
            $payload = json_encode([
                'sender' => ['name' => $fromName, 'email' => $from], 'to' => [['email' => $to]],
                'subject' => $subject, 'htmlContent' => $body,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $response = ($httpTransport ?? new CurlHttpTransport())->request('POST', 'https://api.brevo.com/v3/smtp/email',
                ['accept: application/json', 'api-key: ' . $apiKey, 'content-type: application/json'], $payload, 20);
            if ($response->status < 200 || $response->status >= 300) throw new RuntimeException('Brevo respondeu HTTP ' . $response->status . '.');
            $result = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($result) || !isset($result['messageId']) || !is_string($result['messageId']) || $result['messageId'] === '') {
                throw new RuntimeException('Resposta Brevo sem identificador da mensagem.');
            }
            return true;
        }
        $username = getenv('BREVO_SMTP_USER') ?: '';
        $password = getenv('BREVO_SMTP_PASSWORD') ?: '';
        if ($username === '' || $password === '') throw new RuntimeException('Configure BREVO_SMTP_USER e BREVO_SMTP_PASSWORD.');
        $host = getenv('BREVO_SMTP_HOST') ?: 'smtp-relay.brevo.com';
        $portValue = getenv('BREVO_SMTP_PORT') ?: '465';
        if (!ctype_digit($portValue)) throw new InvalidArgumentException('Porta SMTP inválida.');
        $port = (int)$portValue;
        TlsSmtpConnector::validateEndpoint($host, $port);
        return (new SmtpClient($smtpConnector ?? new TlsSmtpConnector()))->send(
            compact('host', 'port', 'username', 'password', 'from'), $to, $subject, $body, $fromName);
    } catch (RuntimeException|InvalidArgumentException|JsonException $exception) {
        error_log('BREVO_DELIVERY_FAILED: ' . $exception->getMessage());
        return false;
    }
}
