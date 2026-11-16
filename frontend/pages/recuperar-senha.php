<?php
require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Recuperar senha';
$metaDescription = 'Recupere o acesso à sua conta do CondoConnect.';
$bodyClass = 'auth-page';
$message = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Informe um e-mail válido.';
        } else {
            $user = findUserByEmail($email);
            if ($user !== null) {
                $token = createPasswordResetToken((int) $user['id']);
                $base = applicationOrigin();
                $resetUrl = $base . getBasePath() . 'pages/redefinir-senha.php?token=' . urlencode($token);
                $sent = sendBrevoSmtpMail(
                    $email,
                    'Redefinição de senha - CondoConnect',
                    '<p>Olá, ' . sanitize($user['nome']) . '.</p><p>Recebemos sua solicitação para redefinir sua senha.</p><p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">Clique aqui para redefinir sua senha</a></p><p>Se você não solicitou, ignore este e-mail.</p>'
                );
                if (!$sent) {
                    $message = 'Não foi possível enviar o e-mail no momento. Tente novamente mais tarde.';
                } else {
                    $message = 'Se o e-mail estiver cadastrado, um link de recuperação foi enviado para sua caixa de entrada.';
                }
            } else {
                $message = 'Se o e-mail estiver cadastrado, um link de recuperação foi enviado para sua caixa de entrada.';
            }
        }
    }
} catch (Throwable $error) {
    $message = friendlyError($error);
    http_response_code(503);
}

require __DIR__ . '/../includes/header.php';
?>

<main class="auth-frame">
    <div class="container">
        <div class="auth-card">
            <p class="eyebrow">Recuperação</p>
            <h1>Recuperar senha</h1>
            <p>Informe seu e-mail para receber um link de redefinição enviado pela API Brevo.</p>

            <?php if ($message !== ''): ?>
                <div class="alert alert-info"><?= sanitize($message) ?></div>
            <?php endif; ?>

            <form action="<?= $basePath ?>pages/recuperar-senha.php" method="post">
                <?= csrfField() ?>
                <label>
                    <span>E-mail de cadastro</span>
                    <input type="email" name="email" placeholder="seu@email.com" required>
                </label>
                <button type="submit" class="button button-primary auth-submit">Enviar link</button>
            </form>

            <div class="auth-footer">
                <a href="<?= getBasePath() ?>pages/login.php">Voltar para o login</a>
                <a href="<?= getBasePath() ?>pages/cadastro.php">Criar conta</a>
            </div>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
