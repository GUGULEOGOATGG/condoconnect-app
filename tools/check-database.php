<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/includes/functions.php';
try {
    $pdo=getPDO();
    echo "DATABASE_CONNECTED\n";
    foreach (['users','chats','chat_participantes','chat_mensagens','condominios','areas_comuns','reservas','comunicados','chamado_historico'] as $table) {
        try { echo $table.': '.$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn().PHP_EOL; }
        catch (PDOException $error) { echo $table.': SQLSTATE '.($error->errorInfo[0]??$error->getCode()).PHP_EOL; }
    }
    try { getAdminDashboardStats(); echo "ADMIN_DASHBOARD_OK\n"; }
    catch (PDOException $error) { echo 'ADMIN_DASHBOARD_ERROR: SQLSTATE '.($error->errorInfo[0]??$error->getCode()).PHP_EOL; exit(1); }
} catch (Throwable $error) {
    echo "DATABASE_UNAVAILABLE\n";
    $previous=$error->getPrevious();
    if ($previous instanceof PDOException) echo 'SQLSTATE: '.($previous->errorInfo[0]??$previous->getCode()).' DRIVER_CODE: '.($previous->errorInfo[1]??'').PHP_EOL;
    exit(1);
}
