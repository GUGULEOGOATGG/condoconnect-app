<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/backend/services/http_client.php';

final class RecordingCurlExecutorForTest implements CurlExecutor
{
    public array $calls = [];

    public function __construct(private readonly CurlExecutionResult $result) {}

    public function execute(string $url, array $options): CurlExecutionResult
    {
        $this->calls[] = ['url' => $url, 'options' => $options];
        return $this->result;
    }
}

final class HttpClientTest extends TestCase
{
    public function testTransportVerifiesTlsAndRestrictsProtocolsWithoutFollowingRedirects(): void
    {
        $executor = new RecordingCurlExecutorForTest(new CurlExecutionResult(201, '{"id":1}'));
        $response = (new CurlHttpTransport($executor))->request('POST', 'https://service.example.test/resource', ['Authorization: Bearer fake', 'Content-Type: application/json'], '{"name":"Teste"}', 30);

        self::assertSame(201, $response->status);
        self::assertSame('{"id":1}', $response->body);
        self::assertCount(1, $executor->calls);
        self::assertSame('https://service.example.test/resource', $executor->calls[0]['url']);
        $options = $executor->calls[0]['options'];
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(CURL_SSLVERSION_TLSv1_2, $options[CURLOPT_SSLVERSION]);
        self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame('POST', $options[CURLOPT_CUSTOMREQUEST]);
        self::assertSame(30, $options[CURLOPT_TIMEOUT]);
        self::assertSame(10, $options[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame('{"name":"Teste"}', $options[CURLOPT_POSTFIELDS]);
        self::assertSame(['Authorization: Bearer fake', 'Content-Type: application/json'], $options[CURLOPT_HTTPHEADER]);
    }

    public function testHeadHasNoBodyAndRespectsShortTimeout(): void
    {
        $executor = new RecordingCurlExecutorForTest(new CurlExecutionResult(204, ''));
        $response = (new CurlHttpTransport($executor))->request('HEAD', 'https://service.example.test', [], null, 1);
        self::assertSame('', $response->body);
        self::assertTrue($executor->calls[0]['options'][CURLOPT_NOBODY]);
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $executor->calls[0]['options']);
        self::assertSame(1, $executor->calls[0]['options'][CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(1, $executor->calls[0]['options'][CURLOPT_TIMEOUT]);
    }

    public function testHttpFailureStatusRemainsAvailableToService(): void
    {
        $executor = new RecordingCurlExecutorForTest(new CurlExecutionResult(503, '{"error":"unavailable"}'));
        $response = (new CurlHttpTransport($executor))->request('GET', 'https://service.example.test');
        self::assertSame(503, $response->status);
        self::assertSame('{"error":"unavailable"}', $response->body);
    }

    public function testMissingCurlExtensionProducesConfigurationErrorWithoutPhpFatal(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sisc-no-curl-');
        $servicePath = var_export(dirname(__DIR__, 2) . '/backend/services/openai_service.php', true);
        file_put_contents($file, '<?php require_once ' . $servicePath . ';' . <<<'PHP'
$result = [];
foreach (['transport' => new CurlHttpTransport(), 'executor' => new NativeCurlExecutor()] as $name => $client) {
    try {
        if ($client instanceof HttpTransport) $client->request('GET', 'https://service.example.test');
        else $client->execute('https://service.example.test', []);
        $result[$name] = 'unexpected-success';
    } catch (HttpTransportException $error) {
        $result[$name] = $error->reason;
    }
}
putenv('OPENAI_API_KEY=unit-test-key');
$result['openai'] = openAiAssistantReply('Vazamento')['mode'];
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP);
        try {
            $process = proc_open([PHP_BINARY, '-n', $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors . $output);
            self::assertSame('', $errors);
            self::assertSame(['transport' => 'configuration', 'executor' => 'configuration', 'openai' => 'error'], json_decode($output, true, 512, JSON_THROW_ON_ERROR));
        } finally {
            unlink($file);
        }
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidRequestsNeverReachExecutor(string $method, string $url, array $headers, int $timeout): void
    {
        $executor = new RecordingCurlExecutorForTest(new CurlExecutionResult(200, '{}'));
        try {
            (new CurlHttpTransport($executor))->request($method, $url, $headers, null, $timeout);
            self::fail('Requisição insegura aceita.');
        } catch (InvalidArgumentException $error) {
            self::assertNotSame('', $error->getMessage());
            self::assertSame([], $executor->calls);
        }
    }

    public static function invalidRequests(): array
    {
        return [
            'plain HTTP' => ['GET', 'http://service.example.test', [], 30],
            'FTP' => ['GET', 'ftp://service.example.test', [], 30],
            'malformed URL' => ['GET', 'https:///invalid', [], 30],
            'relative URL' => ['GET', '/relative', [], 30],
            'credentials in URL' => ['GET', 'https://user:password@service.example.test', [], 30],
            'fragment' => ['GET', 'https://service.example.test/#fragment', [], 30],
            'control characters' => ['GET', "https://service.example.test/\r\n", [], 30],
            'unknown method' => ['INVALID', 'https://service.example.test', [], 30],
            'header injection' => ['GET', 'https://service.example.test', ["Authorization: Bearer value\r\nInjected: yes"], 30],
            'invalid header name' => ['GET', 'https://service.example.test', ['Invalid Name: value'], 30],
            'non-string header' => ['GET', 'https://service.example.test', [[]], 30],
            'missing header separator' => ['GET', 'https://service.example.test', ['Invalid'], 30],
            'zero timeout' => ['GET', 'https://service.example.test', [], 0],
            'negative timeout' => ['GET', 'https://service.example.test', [], -1],
            'unbounded timeout' => ['GET', 'https://service.example.test', [], 121],
        ];
    }

    #[DataProvider('transportFailures')]
    public function testTransportFailureIsDistinguishedFromHttpFailure(CurlExecutionResult $result, string $reason): void
    {
        $executor = new RecordingCurlExecutorForTest($result);
        try {
            (new CurlHttpTransport($executor))->request('GET', 'https://service.example.test');
            self::fail('Falha de transporte aceita.');
        } catch (HttpTransportException $error) {
            self::assertSame($reason, $error->reason);
            self::assertCount(1, $executor->calls);
        }
    }

    public static function transportFailures(): array
    {
        return [
            'timeout' => [new CurlExecutionResult(0, false, CURLE_OPERATION_TIMEDOUT), 'timeout'],
            'TLS error' => [new CurlExecutionResult(0, false, CURLE_SSL_CONNECT_ERROR), 'network'],
            'empty failed transfer' => [new CurlExecutionResult(200, false), 'network'],
            'missing HTTP status' => [new CurlExecutionResult(0, '{}'), 'network'],
            'invalid HTTP status' => [new CurlExecutionResult(600, '{}'), 'network'],
            'error with partial body' => [new CurlExecutionResult(200, 'partial', CURLE_RECV_ERROR), 'network'],
        ];
    }
}
