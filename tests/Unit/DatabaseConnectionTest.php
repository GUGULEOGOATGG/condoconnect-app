<?php
declare(strict_types=1);
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__, 2) . '/backend/services/database_connection.php';

final class DatabaseConnectionTest extends TestCase
{
    private static function settings(): array { return ['host' => '127.0.0.1', 'port' => '3306', 'name' => 'sisc_test', 'user' => 'tester', 'password' => '0']; }

    public function testConnectionUsesUtf8NativeStatementsAndPreservesPasswordZero(): void
    {
        $pdo = $this->createMock(PDO::class);
        $called = false;
        $result = createDatabaseConnection(self::settings(), static function (string $dsn, string $user, string $password, array $options) use ($pdo, &$called): PDO {
            $called = true;
            self::assertSame('mysql:host=127.0.0.1;port=3306;dbname=sisc_test;charset=utf8mb4', $dsn);
            self::assertSame('tester', $user); self::assertSame('0', $password);
            self::assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
            self::assertSame(PDO::FETCH_ASSOC, $options[PDO::ATTR_DEFAULT_FETCH_MODE]);
            self::assertFalse($options[PDO::ATTR_EMULATE_PREPARES]);
            return $pdo;
        });
        self::assertTrue($called); self::assertSame($pdo, $result);
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreRejectedBeforeConnection(array $changes): void
    {
        $this->expectException(DatabaseConfigurationException::class);
        createDatabaseConnection($changes + self::settings(), static function (): PDO { self::fail('Configuração inválida não pode abrir conexão.'); });
    }

    public static function invalidSettings(): array
    {
        return array_map(static fn(array $changes): array => [$changes], [['host' => ''], ['host' => 'localhost;dbname=other'], ['name' => ''], ['name' => 'db;host=other'], ['name' => str_repeat('a', 65)], ['user' => ' '], ['password' => []], ['host' => null], ['port' => '0'], ['port' => 65536], ['port' => 'abc'], ['port' => []]]);
    }

    public function testConnectionFailureDoesNotLeakCredentialsAndKeepsCause(): void
    {
        $cause = new PDOException('secret details tester/password');
        try {
            createDatabaseConnection(self::settings(), static function () use ($cause): PDO { throw $cause; });
            self::fail('A falha de conexão deve ser informada.');
        } catch (RuntimeException $error) {
            self::assertSame($cause, $error->getPrevious());
            self::assertStringContainsString('DB_PORT', $error->getMessage());
            self::assertStringNotContainsString('secret details', $error->getMessage());
        }
    }

    public function testPortBoundariesAndDefaultPassword(): void
    {
        foreach ([1, 65535] as $port) {
            $settings = self::settings(); $settings['port'] = $port; unset($settings['password']);
            self::assertSame($port, databaseConnectionSettings($settings)['port']);
            self::assertSame('', databaseConnectionSettings($settings)['password']);
        }
    }
}
