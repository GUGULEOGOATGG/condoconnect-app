<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/backend/services/openai_service.php';

final class RecordingOpenAiTransport implements HttpTransport
{
    public array $requests = [];

    public function __construct(private readonly HttpResponse|Throwable $result) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 30): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'timeoutSeconds');
        if ($this->result instanceof Throwable) throw $this->result;
        return $this->result;
    }
}

final class OpenAiServiceTest extends TestCase
{
    private array $savedEnvironment;

    protected function setUp(): void
    {
        $this->savedEnvironment = ['OPENAI_API_KEY' => getenv('OPENAI_API_KEY'), 'OPENAI_MODEL' => getenv('OPENAI_MODEL')];
        putenv('OPENAI_API_KEY=unit-test-key');
        putenv('OPENAI_MODEL=unit-test-model');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
    }

    public function testSuccessfulReplyUsesExpectedPayloadAndTrimsQuestionAndAnswer(): void
    {
        $raw = ['choices' => [['message' => ['content' => '  Consulte o síndico.  ']]]];
        $transport = new RecordingOpenAiTransport(new HttpResponse(200, json_encode($raw, JSON_THROW_ON_ERROR)));
        $reply = openAiAssistantReply('  Minha torneira está vazando  ', ['user_role' => 'morador'], $transport);

        self::assertTrue($reply['success']);
        self::assertSame('openai', $reply['mode']);
        self::assertSame('Consulte o síndico.', $reply['message']);
        self::assertSame($raw, $reply['raw']);
        self::assertCount(1, $transport->requests);
        $request = $transport->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.openai.com/v1/chat/completions', $request['url']);
        self::assertSame(['Authorization: Bearer unit-test-key', 'Content-Type: application/json'], $request['headers']);
        self::assertSame(30, $request['timeoutSeconds']);
        $payload = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('unit-test-model', $payload['model']);
        self::assertSame(0.2, $payload['temperature']);
        self::assertSame('system', $payload['messages'][0]['role']);
        self::assertStringContainsString('Nunca invente regras', $payload['messages'][0]['content']);
        self::assertSame(['role' => 'user', 'content' => 'Minha torneira está vazando'], $payload['messages'][1]);
        self::assertCount(2, $payload['messages']);
    }

    public function testBlankQuestionIsRejectedBeforeAnyExternalCall(): void
    {
        $transport = new RecordingOpenAiTransport(new HttpResponse(200, '{}'));
        $reply = openAiAssistantReply(" \n\t", [], $transport);
        self::assertFalse($reply['success']);
        self::assertSame('validation', $reply['mode']);
        self::assertSame([], $transport->requests);
    }

    #[DataProvider('disabledKeys')]
    public function testMissingOrPlaceholderConfigurationKeepsLocalAssistantWithoutNetwork(string|false $key): void
    {
        putenv($key === false ? 'OPENAI_API_KEY' : 'OPENAI_API_KEY=' . $key);
        $transport = new RecordingOpenAiTransport(new HttpResponse(200, '{}'));
        self::assertFalse(openAiEnabled());
        $reply = openAiAssistantReply(' Vazamento no banheiro ', [], $transport);
        self::assertTrue($reply['success']);
        self::assertSame('mock', $reply['mode']);
        self::assertSame('Vazamento no banheiro', $reply['suggestion']['description']);
        self::assertNotSame('', $reply['suggestion']['note']);
        self::assertSame([], $transport->requests);
    }

    public static function disabledKeys(): array
    {
        return [[''], ['  '], ['replace_with_openai_key'], [false]];
    }

    public function testBlankModelFallsBackToDefault(): void
    {
        putenv('OPENAI_MODEL=   ');
        self::assertSame('gpt-4o-mini', openAiSettings()['model']);
        self::assertTrue(openAiEnabled());
    }

    public function testInvalidUtf8QuestionCannotProduceMalformedRequest(): void
    {
        $transport = new RecordingOpenAiTransport(new HttpResponse(200, '{}'));
        $reply = openAiAssistantReply("Vazamento \xB1", [], $transport);
        self::assertFalse($reply['success']);
        self::assertSame('validation', $reply['mode']);
        self::assertSame([], $transport->requests);
    }

    #[DataProvider('upstreamFailures')]
    public function testUpstreamFailuresAreReportedWithoutLeakingSecrets(HttpResponse|Throwable $result, bool $timeout): void
    {
        $transport = new RecordingOpenAiTransport($result);
        $reply = openAiAssistantReply('Vazamento', [], $transport);
        self::assertFalse($reply['success']);
        self::assertSame('error', $reply['mode']);
        self::assertArrayNotHasKey('raw', $reply);
        self::assertStringNotContainsString('unit-test-key', $reply['message']);
        self::assertStringNotContainsString('PRIVATE', $reply['message']);
        if ($timeout) self::assertStringContainsString('tempo limite', $reply['message']);
        self::assertCount(1, $transport->requests);
    }

    public static function upstreamFailures(): array
    {
        return [
            'timeout' => [new HttpTransportException('PRIVATE timeout unit-test-key', 'timeout'), true],
            'network failure' => [new HttpTransportException('PRIVATE DNS failure', 'network'), false],
            'extension missing' => [new HttpTransportException('PRIVATE extension missing', 'configuration'), false],
            'invalid configuration' => [new InvalidArgumentException('PRIVATE malformed header'), false],
            'authentication failure' => [new HttpResponse(401, '{"error":"PRIVATE key rejected"}'), false],
            'rate limit' => [new HttpResponse(429, '{"error":"PRIVATE limit"}'), false],
            'upstream unavailable' => [new HttpResponse(503, 'PRIVATE unavailable'), false],
            'redirect rejected' => [new HttpResponse(302, 'PRIVATE redirect'), false],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function testInvalidApiResponseCannotBeReportedAsSuccess(string $body): void
    {
        $transport = new RecordingOpenAiTransport(new HttpResponse(200, $body));
        $reply = openAiAssistantReply('Vazamento', [], $transport);
        self::assertFalse($reply['success']);
        self::assertSame('error', $reply['mode']);
        self::assertStringContainsString('resposta inválida', $reply['message']);
        self::assertArrayNotHasKey('raw', $reply);
    }

    public static function invalidResponses(): array
    {
        return [
            'malformed JSON' => ['{bad'],
            'empty body' => [''],
            'null' => ['null'],
            'scalar' => ['123'],
            'boolean' => ['true'],
            'missing choices' => ['{}'],
            'empty choices' => ['{"choices":[]}'],
            'malformed choices' => ['{"choices":"invalid"}'],
            'missing message' => ['{"choices":[{}]}'],
            'missing content' => ['{"choices":[{"message":{}}]}'],
            'array content' => ['{"choices":[{"message":{"content":[]}}]}'],
            'numeric content' => ['{"choices":[{"message":{"content":123}}]}'],
            'empty content' => ['{"choices":[{"message":{"content":""}}]}'],
            'blank content' => ['{"choices":[{"message":{"content":"   "}}]}'],
        ];
    }
}
