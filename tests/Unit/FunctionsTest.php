<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FunctionsTest extends TestCase
{
    public function testRegistrationNormalizationPreservesPasswordAndNormalizesContactData(): void
    {
        $password = '  Senha segura!  ';
        $normalized = normalizeRegistrationData(['nome' => '  João Silva  ', 'email' => ' JOAO@EXAMPLE.TEST ', 'senha' => $password,
            'telefone' => '(11) 98765-4321', 'cpf' => '123.456.789-00', 'condominio' => ' Alpha ', 'unidade' => ' 101 ']);
        self::assertSame('João Silva', $normalized['nome']);
        self::assertSame('joao@example.test', $normalized['email']);
        self::assertSame('11987654321', $normalized['telefone']);
        self::assertSame('12345678900', $normalized['cpf']);
        self::assertSame('Alpha', $normalized['condominio']);
        self::assertSame('101', $normalized['unidade']);
        self::assertSame('morador', $normalized['tipo_usuario']);
        self::assertSame($password, $normalized['senha']);
    }

    #[DataProvider('invalidRegistrationInputs')]
    public function testRegistrationRejectsMalformedAndOutOfRangeFields(array $changes, string $expectedMessage): void
    {
        $data = $changes + ['nome' => 'Morador', 'email' => 'morador@example.test', 'senha' => 'Seguro123!', 'telefone' => '11987654321', 'condominio' => 'Alpha'];
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($expectedMessage);
        normalizeRegistrationData($data);
    }

    public static function invalidRegistrationInputs(): array
    {
        return [
            'empty name' => [['nome' => ' '], 'obrigatórios'], 'null email' => [['email' => null], 'obrigatórios'],
            'empty password' => [['senha' => ''], 'obrigatórios'], 'empty condominium' => [['condominio' => ''], 'obrigatórios'],
            'missing phone' => [['telefone' => ''], 'obrigatórios'], 'array field' => [['nome' => []], 'Campo inválido'],
            'object field' => [['email' => new stdClass()], 'Campo inválido'], 'bool field' => [['telefone' => true], 'Campo inválido'],
            'password containing NUL' => [['senha' => "Seguro123!\0"], 'Caracteres inválidos'],
            'malformed utf8 name' => [['nome' => "Nome\xB1"], 'Caracteres inválidos'],
            'too long name' => [['nome' => str_repeat('x', 191)], 'limite'], 'too long condominium' => [['condominio' => str_repeat('x', 191)], 'limite'],
            'too long unit' => [['unidade' => str_repeat('x', 191)], 'limite'], 'too long certificate' => [['documento_certificado' => str_repeat('x', 256)], 'limite'],
            'malformed email' => [['email' => 'invalid'], 'e-mail válido'], 'password below minimum' => [['senha' => '1234567'], '8 e 255'],
            'password above maximum' => [['senha' => str_repeat('x', 256)], '8 e 255'], 'short phone' => [['telefone' => '119876543'], 'telefone válido'],
            'long phone' => [['telefone' => '119876543210'], 'telefone válido'], 'invalid cpf' => [['cpf' => '123'], 'CPF'],
            'public administrator' => [['tipo_usuario' => 'admin'], 'Perfil'], 'unknown role' => [['tipo_usuario' => 'unknown'], 'Perfil'],
            'syndic without certificate' => [['tipo_usuario' => 'sindico'], 'certificado'],
        ];
    }

    public function testRegistrationAllowsDocumentedBoundariesAndOptionalFields(): void
    {
        $data = ['nome' => str_repeat('á', 190), 'email' => 'resident@example.test', 'senha' => '12345678', 'telefone' => '1123456789', 'condominio' => 'Alpha'];
        $normalized = normalizeRegistrationData($data);
        self::assertSame('', $normalized['cpf']);
        self::assertSame('', $normalized['unidade']);
        self::assertSame(190, mb_strlen($normalized['nome'], 'UTF-8'));
        self::assertSame('12345678', $normalized['senha']);
        $normalized = normalizeRegistrationData(['senha' => str_repeat('x', 255), 'tipo_usuario' => 'sindico', 'documento_certificado' => 'certificate.pdf'] + $data);
        self::assertSame(255, strlen($normalized['senha']));
        self::assertSame('sindico', $normalized['tipo_usuario']);
    }

    public function testPortfolioEntriesAreNormalizedWithoutConvertingNestedObjects(): void
    {
        self::assertSame(['photo.png', 'other.jpg'], normalizePortfolioEntries(['photo.png', '', 'other.jpg']));
        self::assertSame([], normalizePortfolioEntries([]));
        $this->expectException(DomainException::class);
        normalizePortfolioEntries([['nested' => 'photo.png']]);
    }

    #[DataProvider('unsupportedValues')]
    public function testSanitizationRejectsValuesThatWouldRaisePhpConversionWarnings(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        sanitize($value);
    }

    public static function unsupportedValues(): array
    {
        return ['array' => [[]], 'nested array' => [['nested' => ['x']]], 'object without string conversion' => [new stdClass()]];
    }

    public function testSanitizationPreservesValidTextAroundMalformedUtf8(): void
    {
        self::assertSame('Olá � &lt;script&gt;', sanitize("Olá \xB1 <script>"));
        self::assertSame('Nome &amp; valor', sanitize(new class implements Stringable {
            public function __toString(): string { return '  Nome & valor  '; }
        }));
        self::assertSame('', sanitize(false));
        self::assertSame('1', sanitize(true));
    }

    public function testBasePathDoesNotTrustUnrelatedOrMismatchedScriptLocations(): void
    {
        $basePath = getenv('APP_BASE_PATH');
        $server = $_SERVER;
        try {
            putenv('APP_BASE_PATH');
            $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__, 2) . '/frontend/pages/login.php';
            $_SERVER['SCRIPT_NAME'] = '/other-app/login.php';
            self::assertSame('/', getBasePath());
            $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__, 2) . '-other/frontend/pages/login.php';
            $_SERVER['SCRIPT_NAME'] = '/other/frontend/pages/login.php';
            self::assertSame('/', getBasePath());
            $_SERVER['SCRIPT_FILENAME'] = str_replace('/', '\\', dirname(__DIR__, 2) . '/pages/login.php');
            $_SERVER['SCRIPT_NAME'] = '/sisc-test/pages/login.php';
            self::assertSame('/sisc-test/', getBasePath());
        } finally {
            putenv($basePath === false ? 'APP_BASE_PATH' : 'APP_BASE_PATH=' . $basePath);
            $_SERVER = $server;
        }
    }

    public function testPasswordHashesUseIndependentSalts(): void
    {
        $password = 'Uma-senha-forte-123';
        $first = hashPassword($password);
        $second = hashPassword($password);
        self::assertNotSame($first, $second);
        self::assertTrue(passwordMatches($password, $first));
        self::assertTrue(passwordMatches($password, $second));
        self::assertFalse(passwordMatches('', $first));
    }

    public function testNewHashesDoNotTruncateLongPasswordSuffixes(): void
    {
        $prefix = str_repeat('á', 36);
        $password = $prefix . 'Correto!';
        if (!defined('PASSWORD_ARGON2ID')) {
            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Argon2id');
            hashPassword($password);
            return;
        }
        $hash = hashPassword($password);
        self::assertSame('argon2id', password_get_info($hash)['algoName']);
        self::assertTrue(passwordMatches($password, $hash));
        self::assertFalse(passwordMatches($prefix . 'Incorreto!', $hash));
        self::assertFalse(passwordMatches($prefix, $hash));
    }

    public function testExistingBcryptHashesRemainValidWithoutAutomaticRehash(): void
    {
        $password = 'Senha-legada-segura!';
        $legacyHash = password_hash($password, PASSWORD_BCRYPT);
        self::assertTrue(passwordMatches($password, $legacyHash));
        self::assertFalse(passwordMatches('Senha-incorreta!', $legacyHash));
        self::assertSame('bcrypt', password_get_info($legacyHash)['algoName']);
    }

    #[DataProvider('malformedPasswords')]
    public function testMalformedPasswordIsRejectedByHashingAndVerification(string $password): void
    {
        self::assertFalse(passwordMatches($password, '$2y$10$invalid'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('caracteres inválidos');
        hashPassword($password);
    }

    public static function malformedPasswords(): array
    {
        return ['NUL' => ["Seguro123!\0extra"], 'malformed UTF8' => ["Seguro123!\xB1"]];
    }
}
