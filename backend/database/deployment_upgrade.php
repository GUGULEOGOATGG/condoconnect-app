<?php
declare(strict_types=1);
require_once __DIR__ . '/schema_upgrade.php';
require_once __DIR__ . '/community_examples.php';
require_once __DIR__ . '/schema_status.php';

/** Apply this release once, serialized across concurrent PHP requests. */
function upgradeDeploymentDatabase(): void
{
    static $done = false;
    if ($done || getenv('APP_DEPLOY_UPGRADE') === '0') return;
    $pdo = getPDO();
    $version = APP_SCHEMA_VERSION;
    $exists = dbRow("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='app_migrations'");
    if ($exists && dbRow('SELECT version FROM app_migrations WHERE version=?', [$version])) { $done = true; return; }
    $lock = 'sisc_upgrade_' . substr(hash('sha256', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
    $query = $pdo->prepare('SELECT GET_LOCK(?,30)');
    $query->execute([$lock]);
    if ((int)$query->fetchColumn() !== 1) throw new RuntimeException('Atualização do banco em andamento. Tente novamente em alguns instantes.');
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS app_migrations (version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if (!dbRow('SELECT version FROM app_migrations WHERE version=?', [$version])) {
            // DDL commits implicitly in MySQL; seed data and its marker are atomic.
            upgradeCommunitySchema($pdo);
            atomic(function () use ($pdo, $version): void {
                foreach (explode(';', file_get_contents(__DIR__ . '/demo_data.sql')) as $sql) {
                    if (trim($sql) !== '') $pdo->exec($sql);
                }
                seedCommunityExamples();
                dbExecute('INSERT INTO app_migrations (version) VALUES (?)', [$version]);
            });
        }
        $done = true;
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lock]);
    }
}
