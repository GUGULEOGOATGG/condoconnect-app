<?php
require_once __DIR__ . '/env.php';
// env.php carrega o .env da raiz e normaliza os aliases DB_DATABASE,
// DB_USERNAME e DB_PASSWORD antes de consultar as variáveis.
return [
    'host' => getenv('DB_HOST') === false ? '' : getenv('DB_HOST'),
    'port' => getenv('DB_PORT') ?: '3306',
    'name' => getenv('DB_NAME') === false ? '' : getenv('DB_NAME'),
    'user' => getenv('DB_USER') === false ? '' : getenv('DB_USER'),
    'password' => getenv('DB_PASS') === false ? '' : getenv('DB_PASS'),
    // Segundos de espera pela conexão com o MySQL (1 a 15; padrão 5).
    'timeout' => getenv('DB_TIMEOUT') ?: '5',
];
