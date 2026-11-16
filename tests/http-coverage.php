<?php
// Used only by the loopback PHP server launched by HttpTestCase.
declare(strict_types=1);
$coverageTarget = getenv('SISC_HTTP_COVERAGE');
if ($coverageTarget) {
    // Windows child processes can omit empty environment entries. Preserve explicit
    // empty test settings so dotenv cannot restore production passwords/API keys.
    foreach (['DB_PASS','DB_PASSWORD','BREVO_API_KEY','BREVO_SMTP_PASSWORD','OPENAI_API_KEY','MERCADOPAGO_CLIENT_ID','MERCADOPAGO_CLIENT_SECRET','MERCADOPAGO_ACCESS_TOKEN','MERCADOPAGO_PUBLIC_KEY'] as $key) {
        if (getenv($key)===false) putenv($key.'=');
    }
}
if ($coverageTarget && function_exists('xdebug_start_code_coverage')) {
    xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
    register_shutdown_function(static function () use ($coverageTarget): void {
        file_put_contents($coverageTarget, serialize(xdebug_get_code_coverage()));
    });
}
