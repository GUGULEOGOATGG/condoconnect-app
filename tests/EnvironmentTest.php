<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testLiteralEnvironmentParsingAliasesAndServerPriority(): void
    {
        $keys = ['SISC_LITERAL','SISC_QUOTED','SISC_EXPORT','SISC_SINGLE','SISC_EMPTY','DB_NAME','DB_DATABASE'];
        $saved = []; foreach ($keys as $key) { $saved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null]; putenv($key); unset($_ENV[$key], $_SERVER[$key]); }
        $file = tempnam(sys_get_temp_dir(), 'sisc-env-');
        try {
            file_put_contents($file, "\xEF\xBB\xBF# comentário\n\nSISC_LITERAL=value # comentário\nSISC_QUOTED=\"line\\nnext\"\nexport SISC_EXPORT=ok\nSISC_SINGLE='literal\\ntext'\nSISC_EMPTY=\nDB_DATABASE=file_db\n");
            putenv('DB_NAME=server_db'); loadEnvFile($file);
            self::assertSame('value', getenv('SISC_LITERAL')); self::assertSame("line\nnext", getenv('SISC_QUOTED')); self::assertSame('ok', getenv('SISC_EXPORT')); self::assertSame('literal\ntext', getenv('SISC_SINGLE')); self::assertSame('', getenv('SISC_EMPTY')); self::assertSame('server_db', getenv('DB_DATABASE'));
            putenv('SISC_LITERAL'); unset($_ENV['SISC_LITERAL']); $_SERVER['SISC_LITERAL'] = 'server'; loadEnvFile($file); self::assertSame('server', getenv('SISC_LITERAL'));
            foreach (["BAD LINE\n", "SISC_LITERAL=\"unclosed\n"] as $invalid) {
                file_put_contents($file, $invalid);
                try { loadEnvFile($file); self::fail('Arquivo inválido aceito'); } catch (RuntimeException $e) { self::assertStringContainsString('inválid', $e->getMessage()); }
            }
            loadEnvFile($file . '.missing');
        } finally {
            unlink($file);
            foreach ($saved as $key => [$env, $value, $server]) { putenv($env === false ? $key : $key . '=' . $env); if ($value === null) unset($_ENV[$key]); else $_ENV[$key] = $value; if ($server === null) unset($_SERVER[$key]); else $_SERVER[$key] = $server; }
        }
    }

    public function testAssistantValidationAndLocalSuggestion(): void
    {
        require_once dirname(__DIR__) . '/backend/services/openai_service.php';
        self::assertFalse(openAiEnabled()); self::assertSame('validation', openAiAssistantReply(' ')['mode']);
        $reply = openAiAssistantReply(' Vazamento na torneira '); self::assertTrue($reply['success']); self::assertSame('Vazamento na torneira', $reply['suggestion']['description']);
        self::assertSame('mock', $reply['mode']);
    }
}
