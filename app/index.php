<?php
declare(strict_types=1);

// CondoConnect no celular: aplicativo web que usa a mesma API do app Android (api/mobile.php).
// Esta página não lê banco nem sessão; ela só entrega a interface com cabeçalhos de proteção.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
    . "connect-src 'self'; manifest-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
$version = '2.1.0';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CondoConnect</title>
<meta name="description" content="Seu condomínio na palma da mão: chamados, conversas, reservas e comunicados.">
<meta name="theme-color" content="#0F2A47">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="CondoConnect">
<link rel="manifest" href="manifest.json">
<link rel="icon" type="image/png" href="icon-192.png">
<link rel="apple-touch-icon" href="icon-192.png">
<link rel="stylesheet" href="app.css?v=<?= $version ?>">
</head>
<body>
<div id="app" aria-live="polite"><div class="splash"><div class="splash-mark">CondoConnect<span>.</span></div><div class="spinner" role="status" aria-label="Carregando"></div></div></div>
<noscript><p class="noscript">Ative o JavaScript do navegador para usar o CondoConnect.</p></noscript>
<script src="app.js?v=<?= $version ?>" defer></script>
</body>
</html>
