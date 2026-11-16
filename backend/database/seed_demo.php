<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/functions.php';
$pdo=getPDO();
// The generated seed uses semicolons only as statement separators.
atomic(function() use($pdo) {
    foreach(explode(';',file_get_contents(__DIR__.'/demo_data.sql')) as $sql) if(trim($sql)!=='') $pdo->exec($sql);
});
echo "DEMO_SEED_OK\n";
