<?php
declare(strict_types=1);
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/backend/services/mercadopago_service.php';

final class PaymentFakeTransport implements HttpTransport
{
    public array $calls = [];
    public function __construct(private HttpResponse|Throwable $response) {}
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 30): HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body', 'timeoutSeconds');
        if ($this->response instanceof Throwable) throw $this->response;
        return $this->response;
    }
}

final class MercadoPagoServiceTest extends TestCase
{
    public function testPaymentPayloadUsesConfiguredCredentialsAndIdempotency(): void
    {
        $fake = new PaymentFakeTransport(new HttpResponse(201, '{"id":42,"status":"pending"}'));
        $data = ['amount' => '15.50', 'description' => ' Reserva ', 'idempotency_key' => 'reservation-42', 'payer_email' => 'payer@example.test', 'payer_name' => ' Ana ', 'notification_url' => 'https://condo.example.test/callback', 'external_reference' => 'reserva42', 'metadata' => ['reserva_id' => 42]];
        $response = mpPaymentRequest($data, ['access_token' => 'fake-secret'], $fake);
        self::assertSame(201, $response['status']); self::assertSame(42, $response['body']['id']); self::assertCount(1, $fake->calls);
        $call = $fake->calls[0]; self::assertSame('https://api.mercadopago.com/v1/payments', $call['url']);
        self::assertContains('Authorization: Bearer fake-secret', $call['headers']); self::assertContains('X-Idempotency-Key: reservation-42', $call['headers']);
        $payload = json_decode($call['body'], true);
        self::assertSame(15.5, $payload['transaction_amount']); self::assertSame('Reserva', $payload['description']);
        self::assertSame(['email' => 'payer@example.test', 'first_name' => 'Ana'], $payload['payer']);
        self::assertSame(['reserva_id' => 42], $payload['metadata']); self::assertSame(30, $call['timeoutSeconds']);
        self::assertArrayNotHasKey('access_token', $payload);
    }

    public function testOptionalPaymentFieldsAreOmittedAndKeyIsGenerated(): void
    {
        $fake = new PaymentFakeTransport(new HttpResponse(201, '{"id":1}'));
        self::assertSame(201, mpPaymentRequest(['amount' => 1], ['access_token' => 'fake'], $fake)['status']);
        $payload = json_decode($fake->calls[0]['body'], true);
        foreach (['payer', 'notification_url', 'external_reference'] as $key) self::assertArrayNotHasKey($key, $payload);
        self::assertSame('pix', $payload['payment_method_id']); self::assertSame('Pagamento via Mercado Pago', $payload['description']);
        self::assertMatchesRegularExpression('/X-Idempotency-Key: [a-f0-9]{32}/', implode("\n", $fake->calls[0]['headers']));
    }

    #[DataProvider('invalidPayments')]
    public function testInvalidPaymentsAreRejectedWithoutCallingProvider(array $data): void
    {
        $fake = new PaymentFakeTransport(new HttpResponse(201, '{}'));
        $response = mpPaymentRequest($data, ['access_token' => 'fake'], $fake);
        self::assertSame(400, $response['status']); self::assertNotSame('', $response['body']['error']); self::assertSame([], $fake->calls);
    }

    public static function invalidPayments(): array
    {
        return array_map(static fn(array $data): array => [$data], [[], ['amount' => 0], ['amount' => -1], ['amount' => '10abc'], ['amount' => true], ['amount' => []], ['amount' => INF], ['amount' => NAN], ['amount' => 10, 'idempotency_key' => "bad\r\n"], ['amount' => 10, 'payer_email' => 'bad'], ['amount' => 10, 'notification_url' => 'http://example.test'], ['amount' => 10, 'notification_url' => 'invalid'], ['amount' => 10, 'payer_name' => []], ['amount' => 10, 'access_token' => []]]);
    }

    public function testMissingConfigurationNeverCallsProvider(): void
    {
        $fake = new PaymentFakeTransport(new HttpResponse(200, '{}'));
        self::assertSame(400, mpPaymentRequest(['amount' => 10], [], $fake)['status']);
        self::assertSame(400, mpOAuthRequest([], [], $fake)['status']); self::assertSame([], $fake->calls);
    }

    public function testOAuthIncludesOnlyProvidedOptionalFields(): void
    {
        $fake = new PaymentFakeTransport(new HttpResponse(200, '{"access_token":"new-fake-token"}'));
        $response = mpOAuthRequest(['grant_type' => 'authorization_code', 'code' => 'code', 'code_verifier' => 'verifier', 'redirect_uri' => 'https://example.test/callback', 'refresh_token' => '', 'test_token' => true], ['client_id' => 'id', 'client_secret' => 'secret'], $fake);
        self::assertSame('new-fake-token', $response['body']['access_token']);
        $payload = json_decode($fake->calls[0]['body'], true);
        self::assertSame('authorization_code', $payload['grant_type']); self::assertSame('secret', $payload['client_secret']);
        self::assertSame('code', $payload['code']); self::assertSame('verifier', $payload['code_verifier']); self::assertTrue($payload['test_token']); self::assertArrayNotHasKey('refresh_token', $payload);
        self::assertSame('https://api.mercadopago.com/oauth/token', $fake->calls[0]['url']);
    }

    #[DataProvider('providerFailures')]
    public function testFailuresNeverBecomeSuccessfulPayments(HttpResponse|Throwable $provider, int $expected): void
    {
        $fake = new PaymentFakeTransport($provider);
        $result = mpPaymentRequest(['amount' => 10], ['access_token' => 'fake'], $fake);
        self::assertSame($expected, $result['status']); self::assertArrayHasKey('error', $result['body']);
    }

    public static function providerFailures(): array
    {
        return [[new HttpResponse(401, '{"error":"unauthorized"}'), 401], [new HttpResponse(503, '{"error":"unavailable"}'), 503],
            [new HttpResponse(201, 'invalid'), 502], [new HttpResponse(201, 'null'), 502], [new HttpResponse(201, '[]'), 502],
            [new HttpResponse(302, '{"id":1}'), 502], [new HttpTransportException('timeout', 'timeout'), 504],
            [new HttpTransportException('TLS failed', 'network'), 502], [new InvalidArgumentException('bad credentials header'), 502]];
    }

    public function testInvalidUtf8PayloadFailsBeforeNetwork(): void
    {
        $fake = new PaymentFakeTransport(new HttpResponse(201, '{}'));
        self::assertSame(400, mpPaymentRequest(['amount' => 1, 'description' => "\xB1"], ['access_token' => 'fake'], $fake)['status']);
        self::assertSame([], $fake->calls);
    }

    public function testInvalidProviderSchemaDoesNotIssueTokensOrAcceptPayments(): void
    {
        foreach (['{}', '{"access_token":[]}', '{"id":0}', '{"id":"bad"}'] as $json) {
            self::assertSame(502, mpPaymentRequest(['amount' => 1], ['access_token' => 'fake'], new PaymentFakeTransport(new HttpResponse(201, $json)))['status']);
            self::assertSame(502, mpOAuthRequest([], ['client_id' => 'id', 'client_secret' => 'fake'], new PaymentFakeTransport(new HttpResponse(200, $json)))['status']);
        }
        $fake = new PaymentFakeTransport(new HttpResponse(200, '{"access_token":"fake"}'));
        self::assertSame(400, mpOAuthRequest(['client_secret' => []], [], $fake)['status']); self::assertSame([], $fake->calls);
    }
}
