<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/api/_api.php';
        // Loading the API installs its HTTP exception handler; keep PHPUnit's handler.
        restore_exception_handler();
    }

    public function testPublicUserRemovesSensitiveFieldsAndPreservesPublicData(): void
    {
        $public = ['id' => 7, 'nome' => 'Maria', 'status' => 'ativo'];
        $user = $public + [
            'senha' => 'password-hash',
            'two_factor_code_hash' => 'code-hash',
            'two_factor_expires_at' => '2026-01-01 12:00:00',
            'documento_certificado' => '/private/document.pdf',
            'cpf' => '12345678900',
        ];

        self::assertSame($public, apiPublicUser($user));
        self::assertArrayHasKey('senha', $user);
    }

    public function testPublicUserAcceptsMissingSensitiveFields(): void
    {
        self::assertSame([], apiPublicUser([]));
        self::assertSame(['id' => 7], apiPublicUser(['id' => 7]));
    }

    #[DataProvider('invalidTokens')]
    public function testInvalidTokensAreRejectedBeforeDatabaseAccess(?string $token): void
    {
        self::assertNull(apiTokenUser($token));
    }

    public static function invalidTokens(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'whitespace' => [" \t\n"],
            'too short' => [str_repeat('a', 63)],
            'too long' => [str_repeat('a', 65)],
            'not hexadecimal' => [str_repeat('g', 64)],
            'uppercase' => [str_repeat('A', 64)],
            'leading space' => [' ' . str_repeat('a', 64)],
        ];
    }
}
