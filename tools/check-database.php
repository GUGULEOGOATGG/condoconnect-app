<?php
declare(strict_types=1);
// Diagnóstico rápido com a configuração do .env: php tools/check-database.php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/includes/functions.php';
require_once __DIR__.'/../backend/database/schema_status.php';
try {
    $pdo=getPDO();
    echo "DATABASE_CONNECTED\n";
    $schema=databaseSchemaStatus($pdo);
    echo $schema['missing_tables'] ? 'MISSING_TABLES: '.implode(',', $schema['missing_tables']).PHP_EOL : "TABLES_OK\n";
    echo $schema['migrated'] ? "MIGRATION_OK\n" : "MIGRATION_PENDING: php backend/database/update_application.php\n";
    foreach (['users','chats','chat_participantes','chat_mensagens','condominios','areas_comuns','reservas','comunicados','chamado_historico'] as $table) {
        try { echo $table.': '.$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn().PHP_EOL; }
        catch (PDOException $error) { echo $table.': SQLSTATE '.($error->errorInfo[0]??$error->getCode()).PHP_EOL; }
    }
    try { getAdminDashboardStats(); echo "ADMIN_DASHBOARD_OK\n"; }
    catch (PDOException $error) { echo 'ADMIN_DASHBOARD_ERROR: SQLSTATE '.($error->errorInfo[0]??$error->getCode()).PHP_EOL; exit(1); }
    if ($schema['missing_tables']) exit(1);
} catch (Throwable $error) {
    echo "DATABASE_UNAVAILABLE\n";
    $previous=$error->getPrevious();
    if ($previous instanceof PDOException) echo 'SQLSTATE: '.($previous->errorInfo[0]??$previous->getCode()).' DRIVER_CODE: '.($previous->errorInfo[1]??'').PHP_EOL;
    echo "Detalhes e correção: php tools/configurar-banco.php --testar\n";
    exit(1);
}
