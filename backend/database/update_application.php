<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/deployment_upgrade.php';
try {
    upgradeDeploymentDatabase();
    $stats = getAdminDashboardStats();
    echo json_encode(array_intersect_key($stats, array_flip(['total_usuarios','total_chamados','condominios','reservas','comunicados'])), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    echo "APPLICATION_UPDATE_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR, friendlyError($error) . PHP_EOL);
    exit(1);
}
