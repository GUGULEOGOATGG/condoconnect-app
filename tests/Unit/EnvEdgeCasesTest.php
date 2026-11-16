<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnvEdgeCasesTest extends TestCase
{
    public function testProjectEnvUsesLegacyFileOnlyWhenDotEnvIsAbsent(): void
    {
        $this->clearKeys(['SISC_ENV_SELECTION']);
        $root = sys_get_temp_dir() . '/sisc-env-' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            file_put_contents($root . '/vai.env', "SISC_ENV_SELECTION=legacy\n");
            loadProjectEnv($root);
            self::assertSame('legacy', getenv('SISC_ENV_SELECTION'));

            $this->clearKeys(['SISC_ENV_SELECTION']);
            file_put_contents($root . '/.env', "SISC_ENV_SELECTION=primary\n");
            loadProjectEnv($root);
            self::assertSame('primary', getenv('SISC_ENV_SELECTION'));

            putenv('SISC_ENV_SELECTION=server');
            loadProjectEnv($root);
            self::assertSame('server', getenv('SISC_ENV_SELECTION'));

            $this->clearKeys(['SISC_ENV_SELECTION']);
            file_put_contents($root . '/.env', '');
            loadProjectEnv($root);
            self::assertFalse(getenv('SISC_ENV_SELECTION'));
        } finally {
            foreach (['.env', 'vai.env'] as $file) {
                if (is_file($root . '/' . $file)) unlink($root . '/' . $file);
            }
            rmdir($root);
        }
    }

    private array $saved = [];
    private array $files = [];

    private function clearKeys(array $keys): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $this->saved)) $this->saved[$key] = [getenv($key), array_key_exists($key, $_ENV), $_ENV[$key] ?? null, array_key_exists($key, $_SERVER), $_SERVER[$key] ?? null];
            putenv($key); unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    private function environmentFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'sisc-env-unit-');
        file_put_contents($file, $contents);
        $this->files[] = $file;
        return $file;
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => [$env, $hadEnv, $envValue, $hadServer, $serverValue]) {
            putenv($env === false ? $key : $key . '=' . $env);
            if ($hadEnv) $_ENV[$key] = $envValue; else unset($_ENV[$key]);
            if ($hadServer) $_SERVER[$key] = $serverValue; else unset($_SERVER[$key]);
        }
        foreach ($this->files as $file) unlink($file);
    }

    public function testQuotedSecretsAndSpecialCharactersAreLiteralAndNotExecuted(): void
    {
        $this->clearKeys(['SISC_ENV_QUOTES', 'SISC_ENV_SINGLE', 'SISC_ENV_LITERAL', 'SISC_ENV_ZERO', 'SISC_ENV_COMMAND']);
        $file = $this->environmentFile(<<<'ENV'
SISC_ENV_QUOTES="quote\" slash\\ newline\n return\r tab\t" # comment
SISC_ENV_SINGLE='literal\n # text'
SISC_ENV_LITERAL=https://example.test/#fragment
SISC_ENV_ZERO=0
export SISC_ENV_COMMAND=$(echo never_execute) ${HOME}
ENV);
        loadEnvFile($file);
        self::assertSame("quote\" slash\\ newline\n return\r tab\t", getenv('SISC_ENV_QUOTES'));
        self::assertSame('literal\n # text', getenv('SISC_ENV_SINGLE'));
        self::assertSame('https://example.test/#fragment', getenv('SISC_ENV_LITERAL'));
        self::assertSame('0', getenv('SISC_ENV_ZERO'));
        self::assertSame('$(echo never_execute) ${HOME}', getenv('SISC_ENV_COMMAND'));
    }

    #[DataProvider('aliasGroups')]
    public function testFileAliasIsAvailableUnderBothNamesAndServerAliasWins(string $canonical, string $alias): void
    {
        $this->clearKeys([$canonical, $alias]);
        $file = $this->environmentFile($alias . "=file-secret\n");
        loadEnvFile($file);
        self::assertSame('file-secret', getenv($canonical));
        self::assertSame('file-secret', $_ENV[$alias]);
        $this->clearKeys([$canonical, $alias]);
        putenv($alias . '=server-secret');
        loadEnvFile($file);
        self::assertSame('server-secret', getenv($canonical));
        self::assertSame('server-secret', $_SERVER[$alias]);
    }

    public static function aliasGroups(): array
    {
        return [['DB_NAME', 'DB_DATABASE'], ['DB_USER', 'DB_USERNAME'], ['DB_PASS', 'DB_PASSWORD'], ['BREVO_FROM_EMAIL', 'BREVO_SENDER_EMAIL'], ['BREVO_FROM_NAME', 'BREVO_SENDER_NAME']];
    }

    public function testEmptyServerSecretIsPreservedAndInvalidArrayValueDoesNotCauseWarnings(): void
    {
        $this->clearKeys(['SISC_ENV_EMPTY', 'SISC_ENV_ARRAY', 'SISC_ENV_SERVER']);
        putenv('SISC_ENV_EMPTY=');
        $_ENV['SISC_ENV_ARRAY'] = ['invalid'];
        $_SERVER['SISC_ENV_SERVER'] = 'server-value';
        $file = $this->environmentFile("SISC_ENV_EMPTY=file-value\nSISC_ENV_ARRAY=file-value\nSISC_ENV_SERVER=file-value\n");
        loadEnvFile($file);
        self::assertSame('', getenv('SISC_ENV_EMPTY'));
        self::assertSame('file-value', getenv('SISC_ENV_ARRAY'));
        self::assertSame('server-value', getenv('SISC_ENV_SERVER'));
    }

    public function testEnvironmentValueHasPriorityOverServerArray(): void
    {
        $this->clearKeys(['SISC_ENV_PRIORITY']);
        $_ENV['SISC_ENV_PRIORITY'] = 'environment-value';
        $_SERVER['SISC_ENV_PRIORITY'] = 'server-value';
        loadEnvFile($this->environmentFile("SISC_ENV_PRIORITY=file-value\n"));
        self::assertSame('environment-value', getenv('SISC_ENV_PRIORITY'));
    }

    public function testMalformedFileDoesNotPartiallyReplaceConfiguration(): void
    {
        $this->clearKeys(['SISC_ENV_PARTIAL']);
        $file = $this->environmentFile("SISC_ENV_PARTIAL=new-value\nINVALID LINE\n");
        try {
            loadEnvFile($file);
            self::fail('Configuração inválida aceita.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('2', $error->getMessage());
            self::assertFalse(getenv('SISC_ENV_PARTIAL'));
            self::assertArrayNotHasKey('SISC_ENV_PARTIAL', $_ENV);
        }
    }
}
