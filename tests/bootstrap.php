<?php
declare(strict_types=1);
ob_start();
// Integration tests only use explicit TEST_DB_* settings and a reserved database.
foreach (['HOST' => '127.0.0.1', 'PORT' => '33079', 'NAME' => 'sisc_test', 'USER' => 'root', 'PASS' => ''] as $key => $default) {
    $value = getenv('TEST_DB_' . $key);
    if ($value === false) $value = $default;
    if ($key === 'NAME' && !preg_match('/^sisc_test(?:_[a-z0-9]+)?$/D', $value)) throw new RuntimeException('Use um banco exclusivo sisc_test.');
    putenv('DB_' . $key . '=' . $value);
    $_ENV['DB_' . $key] = $_SERVER['DB_' . $key] = $value;
}
putenv('APP_AUTO_MIGRATE=0');
putenv('APP_DEPLOY_UPGRADE=0');
putenv('APP_DEMO_LOGIN=0');
putenv('OPENAI_API_KEY=');
putenv('BREVO_API_KEY=');
putenv('BREVO_SMTP_PASSWORD=');
putenv('APP_BASE_PATH=/');
putenv('APP_HTTPS=0');
putenv('APP_URL=http://localhost');
foreach (['CLIENT_ID','CLIENT_SECRET','ACCESS_TOKEN','PUBLIC_KEY'] as $key) putenv('MERCADOPAGO_' . $key . '=');
// Start before PHPUnit writes to stdout; auth.php uses the same configuration.
require_once dirname(__DIR__) . '/backend/includes/functions.php';
require_once dirname(__DIR__) . '/backend/includes/security.php';
startAppSession();
