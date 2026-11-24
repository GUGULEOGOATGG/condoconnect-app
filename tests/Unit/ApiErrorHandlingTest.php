<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiErrorHandlingTest extends TestCase
{
    public function testMissingConfigurationAndConnectionFailuresReturnServiceUnavailable(): void
    {
        require_once dirname(__DIR__, 2) . '/backend/services/database_connection.php';
        foreach ([new DatabaseConfigurationException('PRIVATE configuration'), new DatabaseConnectionException('PRIVATE connection')] as $error) {
            [$status, $payload] = apiFailureResponse($error);
            self::assertSame(503, $status);
            self::assertSame('database_unavailable', $payload['code']);
            self::assertStringNotContainsString('PRIVATE', $payload['error']);
        }
    }

    public function testMissingTableOrColumnAsksForTheMigration(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'sisc-api-error-');
        $savedLog = ini_get('error_log');
        ini_set('error_log', $log);
        try {
            foreach (['42S02', '42S22'] as $state) {
                $error = new PDOException('SQLSTATE[' . $state . ']: PRIVATE table name');
                $error->errorInfo = [$state, 1146, 'PRIVATE'];
                (new ReflectionProperty(Exception::class, 'code'))->setValue($error, $state);
                [$status, $payload] = apiFailureResponse($error);
                self::assertSame(503, $status);
                self::assertSame('database_outdated', $payload['code']);
                self::assertStringContainsString('migração', $payload['error']);
                self::assertStringNotContainsString('PRIVATE', $payload['error']);
            }
        } finally {
            ini_set('error_log', $savedLog);
            unlink($log);
        }
    }

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('apiFailureResponse')) {
            require_once dirname(__DIR__, 2) . '/api/_api.php';
            restore_exception_handler();
        }
    }

    #[DataProvider('constraintErrors')]
    public function testExpectedDatabaseConstraintHasSafeClientResponse(int $driverCode, int $expectedStatus): void
    {
        $error = new PDOException('SQLSTATE[23000]: PRIVATE table and credentials');
        $error->errorInfo = ['23000', $driverCode, 'PRIVATE SQL details'];
        [$status, $payload] = apiFailureResponse($error);
        self::assertSame($expectedStatus, $status);
        self::assertArrayHasKey('error', $payload);
        self::assertNotSame('', $payload['error']);
        self::assertStringNotContainsString('PRIVATE', $payload['error']);
        self::assertStringNotContainsString('SQLSTATE', $payload['error']);
    }

    public static function constraintErrors(): array
    {
        return ['duplicate' => [1062, 409], 'referenced delete' => [1451, 409], 'null required value' => [1048, 422], 'oversized data' => [1406, 422], 'missing relation' => [1452, 422], 'MySQL check' => [3819, 422], 'MariaDB check' => [4025, 422]];
    }

    public function testBusinessAndValidationErrorsPreserveUsefulPublicMessage(): void
    {
        self::assertSame([422, ['error' => 'Condomínio inválido.']], apiFailureResponse(new DomainException('Condomínio inválido.')));
        self::assertSame([400, ['error' => 'Campo inválido.']], apiFailureResponse(new InvalidArgumentException('Campo inválido.')));
    }

    public function testUnexpectedDatabaseFailureIsLoggedAndNeverExposedToClient(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'sisc-api-error-');
        $savedLog = ini_get('error_log');
        ini_set('error_log', $log);
        try {
            $error = new PDOException('SQLSTATE[HY000]: PRIVATE connection detail');
            $error->errorInfo = ['HY000', 2006, 'PRIVATE detail'];
            [$status, $payload] = apiFailureResponse($error);
            self::assertSame(500, $status);
            self::assertSame('Não foi possível concluir a operação. Tente novamente.', $payload['error']);
            self::assertStringContainsString('PRIVATE connection detail', file_get_contents($log));
        } finally {
            ini_set('error_log', $savedLog);
            unlink($log);
        }
    }
}
