<?php
require_once __DIR__ . '/env.php';
// Edite aqui OU defina MERCADOPAGO_CLIENT_ID, MERCADOPAGO_CLIENT_SECRET,
// MERCADOPAGO_ACCESS_TOKEN, MERCADOPAGO_PUBLIC_KEY e MERCADOPAGO_REDIRECT_URI
// no ambiente/servidor antes de ativar o checkout.
return [
    'client_id' => getenv('MERCADOPAGO_CLIENT_ID') ?: '',
    'client_secret' => getenv('MERCADOPAGO_CLIENT_SECRET') ?: '',
    'access_token' => getenv('MERCADOPAGO_ACCESS_TOKEN') ?: '',
    'public_key' => getenv('MERCADOPAGO_PUBLIC_KEY') ?: '',
    'redirect_uri' => getenv('MERCADOPAGO_REDIRECT_URI') ?: 'https://seu-dominio.com/mercadopago/callback',
    'sandbox' => filter_var(getenv('MERCADOPAGO_SANDBOX') !== false ? getenv('MERCADOPAGO_SANDBOX') : 'true', FILTER_VALIDATE_BOOLEAN),
];
