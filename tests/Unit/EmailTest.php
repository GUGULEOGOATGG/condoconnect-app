<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/backend/services/email_service.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailFakeHttpTransport implements HttpTransport
{
    public array $requests = [];
    public function __construct(public HttpResponse|Throwable $response) {}
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 30): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'timeoutSeconds');
        if ($this->response instanceof Throwable) throw $this->response;
        return $this->response;
    }
}

final class EmailFakeSmtpConnection implements SmtpConnection
{
    public array $events = [];
    public bool $closed = false;
    public bool $failTls = false;
    public function __construct(public array $responses) {}
    public function readLine(): string|false
    {
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) throw $response;
        return $response ?? false;
    }
    public function write(string $data): void { $this->events[] = $data; }
    public function upgradeTls(): void
    {
        $this->events[] = 'TLS';
        if ($this->failTls) throw new RuntimeException('Certificado inválido.');
    }
    public function close(): void { $this->closed = true; }
}

final class EmailFakeSmtpConnector implements SmtpConnector
{
    public array $connections = [];
    public function __construct(public EmailFakeSmtpConnection|Throwable $connection) {}
    public function connect(string $host, int $port, int $timeoutSeconds): SmtpConnection
    {
        $this->connections[] = compact('host', 'port', 'timeoutSeconds');
        if ($this->connection instanceof Throwable) throw $this->connection;
        return $this->connection;
    }
}

final class EmailTest extends TestCase
{
    private array $environment = [];
    private const ENVIRONMENT = ['BREVO_FROM_NAME', 'BREVO_FROM_EMAIL', 'BREVO_API_KEY', 'BREVO_SMTP_HOST', 'BREVO_SMTP_PORT', 'BREVO_SMTP_USER', 'BREVO_SMTP_PASSWORD'];

    protected function setUp(): void
    {
        foreach (self::ENVIRONMENT as $key) {
            $this->environment[$key] = getenv($key);
            putenv($key . '=');
        }
        putenv('BREVO_FROM_EMAIL=sender@example.test');
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
    }

    public function testApiDeliversStructuredUtf8MessageUsingHttpsAndConfiguredName(): void
    {
        putenv('BREVO_API_KEY=test-key');
        putenv('BREVO_FROM_NAME=Condomínio Alpha');
        $http = new EmailFakeHttpTransport(new HttpResponse(201, '{"messageId":"test-message-1"}'));
        self::assertTrue(sendBrevoSmtpMail('resident@example.test', 'Código de acesso', '<p>Olá, João</p>', 'CondoConnect', $http));
        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.brevo.com/v3/smtp/email', $request['url']);
        self::assertSame(20, $request['timeoutSeconds']);
        self::assertContains('api-key: test-key', $request['headers']);
        self::assertSame([
            'sender' => ['name' => 'Condomínio Alpha', 'email' => 'sender@example.test'],
            'to' => [['email' => 'resident@example.test']], 'subject' => 'Código de acesso', 'htmlContent' => '<p>Olá, João</p>',
        ], json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('apiFailures')]
    public function testApiErrorsNeverReportDeliverySuccess(HttpResponse|Throwable $response): void
    {
        putenv('BREVO_API_KEY=test-key');
        $http = new EmailFakeHttpTransport($response);
        self::assertFalse(sendBrevoSmtpMail('resident@example.test', 'Assunto', 'Mensagem', 'CondoConnect', $http));
        self::assertCount(1, $http->requests);
    }

    public static function apiFailures(): array
    {
        return [
            'api rejected request' => [new HttpResponse(401, '{"message":"invalid api key"}')],
            'server unavailable' => [new HttpResponse(503, '')],
            'redirect rejected' => [new HttpResponse(302, '')],
            'invalid json' => [new HttpResponse(201, 'not-json')],
            'invalid result' => [new HttpResponse(201, '{"messageId":null}')],
            'missing id' => [new HttpResponse(200, '{}')],
            'timeout' => [new RuntimeException('Tempo limite.')],
            'network error' => [new RuntimeException('Conexão indisponível.')],
        ];
    }

    #[DataProvider('malformedMessages')]
    public function testInvalidMessageIsRejectedBeforeAnyExternalRequest(string $to, string $subject, string $name, string $from): void
    {
        putenv('BREVO_API_KEY=test-key');
        putenv('BREVO_FROM_EMAIL=' . $from);
        $http = new EmailFakeHttpTransport(new HttpResponse(201, '{"messageId":"must-not-send"}'));
        self::assertFalse(sendBrevoSmtpMail($to, $subject, 'Mensagem', $name, $http));
        self::assertSame([], $http->requests);
    }

    public static function malformedMessages(): array
    {
        return [
            'empty recipient' => ['', 'Assunto', 'SISC', 'sender@example.test'],
            'invalid recipient' => ['invalid', 'Assunto', 'SISC', 'sender@example.test'],
            'recipient injection' => ["resident@example.test\r\nRCPT TO:<other@example.test>", 'Assunto', 'SISC', 'sender@example.test'],
            'subject injection' => ['resident@example.test', "Assunto\r\nBcc: other@example.test", 'SISC', 'sender@example.test'],
            'name injection' => ['resident@example.test', 'Assunto', "SISC\nBcc: other@example.test", 'sender@example.test'],
            'missing sender configuration' => ['resident@example.test', 'Assunto', 'SISC', ''],
        ];
    }

    public function testInvalidApiKeyAndUtf8ReturnFailureWithoutSending(): void
    {
        $http = new EmailFakeHttpTransport(new HttpResponse(201, '{"messageId":"must-not-send"}'));
        putenv("BREVO_API_KEY=test\nkey");
        self::assertFalse(sendBrevoSmtpMail('resident@example.test', 'Assunto', 'Mensagem', 'SISC', $http));
        putenv('BREVO_API_KEY=test-key');
        self::assertFalse(sendBrevoSmtpMail('resident@example.test', 'Assunto', "\xB1", 'SISC', $http));
        self::assertSame([], $http->requests);
    }

    public function testMissingSmtpCredentialsDoesNotConnect(): void
    {
        $connector = new EmailFakeSmtpConnector(new RuntimeException('Must not connect.'));
        self::assertFalse(sendBrevoSmtpMail('resident@example.test', 'Assunto', 'Mensagem', 'SISC', null, $connector));
        self::assertSame([], $connector->connections);
    }

    public function testSmtpsSendsEncodedHeadersAndEscapesBodyTerminator(): void
    {
        $this->smtpConfiguration();
        $connection = new EmailFakeSmtpConnection(self::successfulResponses());
        $connector = new EmailFakeSmtpConnector($connection);
        self::assertTrue(sendBrevoSmtpMail('resident@example.test', 'Código de acesso', "Primeira\n.\n..continua", 'Condomínio', null, $connector));
        self::assertSame([['host' => 'smtp-relay.brevo.com', 'port' => 465, 'timeoutSeconds' => 20]], $connector->connections);
        self::assertSame("AUTH LOGIN\r\n", $connection->events[1]);
        self::assertContains(base64_encode('smtp-password') . "\r\n", $connection->events);
        $message = $connection->events[7];
        self::assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Código de acesso') . '?=', $message);
        self::assertStringContainsString("Primeira\r\n..\r\n...continua\r\n.\r\n", $message);
        self::assertSame("QUIT\r\n", $connection->events[8]);
        self::assertTrue($connection->closed);
    }

    public function testStarttlsAuthenticatesOnlyAfterVerifiedUpgrade(): void
    {
        $this->smtpConfiguration();
        putenv('BREVO_SMTP_PORT=587');
        $connection = new EmailFakeSmtpConnection(array_merge(["220 Ready\r\n", "250-SIZE 1000\r\n", "250 STARTTLS\r\n", "220 Start TLS\r\n", "250 Ready\r\n"], array_slice(self::successfulResponses(), 2)));
        self::assertTrue(sendBrevoSmtpMail('resident@example.test', 'Assunto', 'Mensagem', 'SISC', null, new EmailFakeSmtpConnector($connection)));
        self::assertSame(["EHLO localhost\r\n", "STARTTLS\r\n", 'TLS', "EHLO localhost\r\n", "AUTH LOGIN\r\n"], array_slice($connection->events, 0, 5));
        self::assertTrue($connection->closed);
    }

    #[DataProvider('tlsFailures')]
    public function testFailedTlsNeverSendsCredentials(bool $serverRefuses): void
    {
        $this->smtpConfiguration();
        putenv('BREVO_SMTP_PORT=587');
        $connection = new EmailFakeSmtpConnection(["220 Ready\r\n", "250 Ready\r\n", $serverRefuses ? "454 TLS unavailable\r\n" : "220 Start TLS\r\n"]);
        $connection->failTls = !$serverRefuses;
        self::assertFalse(sendBrevoSmtpMail('resident@example.test', 'Assunto', 'Mensagem', 'SISC', null, new EmailFakeSmtpConnector($connection)));
        self::assertNotContains("AUTH LOGIN\r\n", $connection->events);
        self::assertNotContains(base64_encode('smtp-password') . "\r\n", $connection->events);
        self::assertTrue($connection->closed);
    }

    public static function tlsFailures(): array { return ['refused STARTTLS' => [true], 'bad certificate' => [false]]; }

    #[DataProvider('smtpFailures')]
    public function testSmtpRejectionClosesConnectionAndDoesNotReportSuccess(int $responseIndex): void
    {
        $this->smtpConfiguration();
        $responses = self::successfulResponses();
        $responses[$responseIndex] = "550 Rejected\r\n";
        $connection = new EmailFakeSmtpConnection($responses);
        self::assertFalse(sendBrevoSmtpMail('resident@example.test', 'Assunto', 'Mensagem', 'SISC', null, new EmailFakeSmtpConnector($connection)));
        self::assertTrue($connection->closed);
        self::assertNotContains("QUIT\r\n", $connection->events);
    }

    public static function smtpFailures(): array
    {
        return ['greeting' => [0], 'EHLO' => [1], 'AUTH' => [2], 'username' => [3], 'password' => [4], 'sender' => [5], 'recipient' => [6], 'DATA' => [7], 'delivery' => [8]];
    }

    public function testConnectionFailureAndReadTimeoutAreReported(): void
    {
        $this->smtpConfiguration();
        self::assertFalse(sendBrevoSmtpMail('resident@example.test', 'Assunto', 'Mensagem', 'SISC', null, new EmailFakeSmtpConnector(new RuntimeException('Timeout conectando.'))));
        $connection = new EmailFakeSmtpConnection([new RuntimeException('Timeout lendo.')]);
        self::assertFalse(sendBrevoSmtpMail('resident@example.test', 'Assunto', 'Mensagem', 'SISC', null, new EmailFakeSmtpConnector($connection)));
        self::assertTrue($connection->closed);
    }

    public function testMalformedAndUnboundedSmtpRepliesAreRejected(): void
    {
        $this->smtpConfiguration();
        foreach ([[], ['not-smtp'], array_fill(0, 100, "220-Continuation\r\n")] as $responses) {
            $connection = new EmailFakeSmtpConnection($responses);
            self::assertFalse(sendBrevoSmtpMail('resident@example.test', 'Assunto', 'Mensagem', 'SISC', null, new EmailFakeSmtpConnector($connection)));
            self::assertTrue($connection->closed);
        }
    }

    #[DataProvider('invalidEndpoints')]
    public function testMalformedSmtpConfigurationDoesNotConnect(string $host, string $port): void
    {
        $this->smtpConfiguration();
        putenv('BREVO_SMTP_HOST=' . $host);
        putenv('BREVO_SMTP_PORT=' . $port);
        $connector = new EmailFakeSmtpConnector(new RuntimeException('Must not connect.'));
        self::assertFalse(sendBrevoSmtpMail('resident@example.test', 'Assunto', 'Mensagem', 'SISC', null, $connector));
        self::assertSame([], $connector->connections);
    }

    public static function invalidEndpoints(): array
    {
        return [['smtp.example.test', '25'], ['smtp.example.test', 'abc'], ['smtp.example.test', '65536'], ['tcp://smtp.example.test', '465'], ["smtp.example.test\r\ncommand", '465']];
    }

    public function testStreamAdapterReadsWritesAndClosesOwnedResource(): void
    {
        $resource = fopen('php://temp', 'w+');
        $connection = new StreamSmtpConnection($resource);
        $connection->write("250 Ready\r\n");
        rewind($resource);
        self::assertSame("250 Ready\r\n", $connection->readLine());
        self::assertFalse($connection->readLine());
        $connection->close();
        self::assertFalse(is_resource($resource));
        $connection->close();
    }

    public function testStreamAdapterCannotWriteAfterConnectionClosed(): void
    {
        $connection = new StreamSmtpConnection(fopen('php://temp', 'w+'));
        $connection->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('encerrada');
        $connection->write("AUTH LOGIN\r\n");
    }

    public function testStreamAdapterRejectsTlsOnAnUnencryptedStreamAndRestoresHandler(): void
    {
        $connection = new StreamSmtpConnection(fopen('php://temp', 'w+'));
        $handler = static fn() => false;
        set_error_handler($handler);
        try {
            try { $connection->upgradeTls(); self::fail('TLS deve falhar em stream de memória.'); }
            catch (RuntimeException $error) { self::assertNotSame('', $error->getMessage()); }
            $restored = set_error_handler(static fn() => false);
            self::assertSame($handler, $restored);
            restore_error_handler();
        } finally {
            restore_error_handler();
            $connection->close();
        }
    }

    public function testSmtpClientRejectsHeaderInjectionBeforeOpeningConnection(): void
    {
        $connector = new EmailFakeSmtpConnector(new RuntimeException('Must not connect.'));
        try {
            (new SmtpClient($connector))->send(['from' => 'sender@example.test'], 'resident@example.test', "Assunto\r\nBcc: intruder@example.test", 'Mensagem', 'SISC');
            self::fail('Cabeçalho malformado não deve ser enviado.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('Cabeçalho', $error->getMessage());
            self::assertSame([], $connector->connections);
        }
    }

    private function smtpConfiguration(): void
    {
        putenv('BREVO_SMTP_USER=smtp-user');
        putenv('BREVO_SMTP_PASSWORD=smtp-password');
    }

    private static function successfulResponses(): array
    {
        return ["220 Ready\r\n", "250 Ready\r\n", "334 Username\r\n", "334 Password\r\n", "235 Authenticated\r\n", "250 Sender\r\n", "250 Recipient\r\n", "354 Message\r\n", "250 Queued\r\n"];
    }
}
