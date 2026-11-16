<?php
declare(strict_types=1);
require_once __DIR__ . '/DatabaseTestCase.php';

final class MigrationTest extends DatabaseTestCase
{
    public function testLegacyChatPairIndexCanBeMigratedWithoutDroppingParticipantForeignKeys(): void
    {
        require_once dirname(__DIR__).'/backend/database/schema_upgrade.php';
        $pdo=getPDO();
        if (!hasIndex($pdo,'chats','uk_chat_pair')) $pdo->exec('ALTER TABLE chats ADD UNIQUE KEY uk_chat_pair (user_a_id,user_b_id)');
        foreach ($pdo->query('SHOW INDEX FROM chats')->fetchAll() as $index) {
            if ((int)$index['Seq_in_index']===1 && $index['Column_name']==='user_a_id' && $index['Key_name']!=='uk_chat_pair') {
                $pdo->exec('ALTER TABLE chats DROP INDEX `'.str_replace('`','``',$index['Key_name']).'`');
            }
        }
        ob_start();
        try { upgradeCommunitySchema($pdo); } finally { ob_end_clean(); }
        self::assertTrue(hasIndex($pdo,'chats','idx_chats_user_a'));
        self::assertFalse(hasIndex($pdo,'chats','uk_chat_pair'));
        self::assertGreaterThan(0,(int)dbRow("SELECT COUNT(*) AS n FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chats' AND COLUMN_NAME='user_a_id' AND REFERENCED_TABLE_NAME='users'")['n']);
    }
    protected function setUp(): void {}
    protected function tearDown(): void
    {
        $pdo = getPDO(); $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try { foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '``', $table) . '`'); }
        finally { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); }
    }
    public function testMigrationAndDemoSeedAreRepeatableAndPreserveRecords(): void
    {
        ob_start(); require dirname(__DIR__) . '/backend/database/upgrade_community.php'; $output = ob_get_clean();
        self::assertStringContainsString('COMMUNITY_MIGRATION_OK', $output);
        self::assertTrue(hasColumn(getPDO(), 'users', 'email_verificado')); self::assertTrue(hasIndex(getPDO(), 'chats', 'uk_chat_contexto')); self::assertTrue(hasConstraint(getPDO(), 'chats', 'fk_chat_chamado'));
        ensureUserProfileColumns(); ensurePasswordResetTable();
        ob_start(); require dirname(__DIR__) . '/backend/database/seed_demo.php'; $output = ob_get_clean(); self::assertStringContainsString('DEMO_SEED_OK', $output);
        $counts = getAdminDashboardStats(); self::assertGreaterThan(0, (int)$counts['total_usuarios']); self::assertGreaterThan(0, (int)$counts['total_chamados']);
        ob_start(); require dirname(__DIR__) . '/backend/database/upgrade_community.php'; require dirname(__DIR__) . '/backend/database/seed_demo.php'; ob_end_clean();
        self::assertSame($counts['total_usuarios'], getAdminDashboardStats()['total_usuarios']); self::assertSame($counts['total_chamados'], getAdminDashboardStats()['total_chamados']);
        self::assertSame([], getPDO()->query("SHOW INDEX FROM reservas WHERE Key_name='uk_reserva_usuario_horario'")->fetchAll());
    }

    public function testDeploymentUpgradeSeedsAndMarksTheReleaseOnce(): void
    {
        require_once dirname(__DIR__) . '/backend/database/deployment_upgrade.php';
        dbExecute("INSERT INTO condominios (nome,endereco,cidade,estado,latitude,longitude) VALUES ('Comunidade existente','Rua existente','São Paulo','SP',0,0)");
        $existing=(int)getPDO()->lastInsertId();
        dbExecute("INSERT INTO users (nome,cpf,email,telefone,senha,tipo_usuario,condominio,unidade) VALUES ('Administrador existente','00000000000','admin-existing@example.test','11900000000',?,'admin','Comunidade existente','Administração')",[hashPassword('SenhaDeTeste123!')]);
        putenv('APP_DEPLOY_UPGRADE=1');
        ob_start();
        try {
            upgradeDeploymentDatabase();
            $before = getAdminDashboardStats();
            self::assertGreaterThan(0, $before['reservas']);
            self::assertGreaterThan(0, $before['comunicados']);
            self::assertGreaterThan(0, dbRow('SELECT COUNT(*) AS n FROM chat_mensagens')['n']);
            self::assertSame('Rua existente',dbRow('SELECT endereco FROM condominios WHERE id=?',[$existing])['endereco']);
            self::assertNotEmpty(dbRow('SELECT descricao,regras,contato FROM condominios WHERE id=?',[$existing])['contato']);
            self::assertNotNull(dbRow('SELECT id FROM comunicados WHERE condominio_id=?',[$existing]));
            self::assertNotNull(dbRow("SELECT id FROM reservas WHERE condominio_id=? AND status='cancelada'",[$existing]));
            self::assertNotNull(dbRow('SELECT version FROM app_migrations WHERE version=?', ['20261002_chat_admin_demo_v2']));
            upgradeDeploymentDatabase();
            self::assertSame($before, getAdminDashboardStats());
        } finally { ob_end_clean(); putenv('APP_DEPLOY_UPGRADE=0'); }
    }
}
