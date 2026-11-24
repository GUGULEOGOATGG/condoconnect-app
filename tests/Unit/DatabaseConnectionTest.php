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
            // Sem tempo limite, um MySQL inalcançável segura a requisição além dos 20 s do app.
            self::assertSame(DATABASE_CONNECT_TIMEOUT_DEFAULT, $options[PDO::ATTR_TIMEOUT]);
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
        return array_map(static fn(array $changes): array => [$changes], [['host' => ''], ['host' => 'localhost;dbname=other'], ['name' => ''], ['name' => 'db;host=other'], ['name' => str_repeat('a', 65)], ['user' => ' '], ['password' => []], ['host' => null], ['port' => '0'], ['port' => 65536], ['port' => 'abc'], ['port' => []], ['timeout' => '0'], ['timeout' => '16'], ['timeout' => 'abc'], ['timeout' => []]]);
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

    public function testConnectTimeoutIsConfigurableWithinTheAppDeadline(): void
    {
        foreach (['1' => 1, '15' => 15, '' => DATABASE_CONNECT_TIMEOUT_DEFAULT, 7 => 7] as $value => $expected) {
            self::assertSame($expected, databaseConnectionSettings(['timeout' => $value] + self::settings())['timeout']);
        }
        self::assertSame(DATABASE_CONNECT_TIMEOUT_DEFAULT, databaseConnectionSettings(self::settings())['timeout']);
        self::assertLessThan(20, DATABASE_CONNECT_TIMEOUT_MAX, 'O PHP precisa desistir antes do app (20 s).');
        $options = [];
        createDatabaseConnection(['timeout' => '3'] + self::settings(), function (string $dsn, string $user, string $password, array $given) use (&$options): PDO {
            $options = $given; return $this->createMock(PDO::class);
        });
        self::assertSame(3, $options[PDO::ATTR_TIMEOUT]);
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
