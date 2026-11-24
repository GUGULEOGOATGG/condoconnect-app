<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/backend/database/schema_status.php';

/**
 * Instalação nova, como no README: banco vazio, db_sisc.sql importado (duas vezes, para provar que
 * repetir não quebra) e backend/database/update_application.php. Usa um banco reservado só para isto.
 */
final class FreshInstallTest extends TestCase
{
    private const DATABASE = 'sisc_test_fresh';
    private PDO $server;

    protected function setUp(): void
    {
        $this->server = new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';charset=utf8mb4', getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->server->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        $this->server->exec('CREATE DATABASE `' . self::DATABASE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->server->exec('USE `' . self::DATABASE . '`');
    }

    protected function tearDown(): void
    {
        $this->server->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
    }

    private function import(): void
    {
        foreach (splitSqlStatements(file_get_contents(dirname(__DIR__, 2) . '/backend/database/db_sisc.sql')) as $statement) $this->server->exec($statement);
    }

    public function testSqlFileImportsIntoAnyDatabaseNameAndCanBeImportedAgain(): void
    {
        $this->import();
        $first = databaseSchemaStatus($this->server);
        self::assertSame([], $first['missing_tables']);
        self::assertFalse($first['migrated']);
        $users = (int)$this->server->query('SELECT COUNT(*) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $users);
        self::assertSame(self::DATABASE, $this->server->query('SELECT DATABASE()')->fetchColumn(), 'O arquivo não pode trocar de banco com USE.');

        $this->import();
        self::assertSame($users, (int)$this->server->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'Importar de novo não duplica registros.');
        self::assertSame(4, (int)$this->server->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('fk_users_condominio','fk_users_unidade','fk_notice_ticket','fk_notice_chat')")->fetchColumn());
    }

    public function testUpdateApplicationCompletesAFreshInstallation(): void
    {
        $this->import();
        $environment = getenv();
        $environment['DB_NAME'] = self::DATABASE; $environment['APP_DEPLOY_UPGRADE'] = '1';
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/backend/database/update_application.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $environment);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output . $errors);
        self::assertStringContainsString('APPLICATION_UPDATE_OK', $output);
        $status = databaseSchemaStatus($this->server);
        self::assertSame([], $status['missing_tables']);
        self::assertTrue($status['migrated']);
    }

    public function testSplitterKeepsSemicolonsInsideTextAndSkipsComments(): void
    {
        self::assertSame(["INSERT INTO t VALUES ('a;b', 'it''s', \"x;y\")", 'SELECT 2'],
            splitSqlStatements("-- comentário; com ponto e vírgula\nINSERT INTO t VALUES ('a;b', 'it''s', \"x;y\");\n\nSELECT 2;\n"));
        self::assertSame(["SELECT 'barra \\'; ainda texto'"], splitSqlStatements("SELECT 'barra \\'; ainda texto';"));
    }

}
