<?php
require_once __DIR__ . '/../includes/auth.php';

$verified = verifyEmailToken(trim((string) ($_GET['token'] ?? '')));
$pageTitle = 'Verificação de e-mail';
$metaDescription = 'Confirmação do endereço de e-mail.';
$bodyClass = 'auth-page';
require __DIR__ . '/../includes/header.php';
?>
<main class="auth-frame"><div class="container"><div class="auth-card">
    <h1><?= $verified ? 'E-mail verificado' : 'Link inválido' ?></h1>
    <p><?= $verified ? 'Sua conta está pronta. Você já pode entrar.' : 'Este link é inválido ou expirou.' ?></p>
    <a class="button button-primary" href="<?= getBasePath() ?>pages/login.php">Ir para o login</a>
</div></div></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
