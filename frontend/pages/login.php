<?php
require_once __DIR__ . '/../includes/auth.php';
if (currentUser() !== null) redirect(getBasePath() . 'pages/dashboard.php');

$pageTitle = 'Login';
$metaDescription = 'Acesse sua conta do CondoConnect.';
$bodyClass = 'auth-page auth-login';
$errorMessage = '';
$registrationMessage = (string) ($_SESSION['registration_message'] ?? '');
unset($_SESSION['registration_message']);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!empty($_SESSION['pending_2fa_user_id'])) {
            $code = trim((string) ($_POST['codigo_2fa'] ?? ''));
            if (preg_match('/^\d{6}$/', $code) && verifyTwoFactorCode((int) $_SESSION['pending_2fa_user_id'], $code)) {
                $user = findUserById((int) $_SESSION['pending_2fa_user_id']);
                establishUserSession($user);
                auditAction((int)$user['id'], 'login', 'usuario', (int)$user['id']);
                redirect(getBasePath() . 'pages/dashboard.php');
            }
            $errorMessage = 'Código de verificação inválido ou expirado.';
        } else {
            $email = trim((string) ($_POST['email'] ?? ''));
            $senha = (string) ($_POST['senha'] ?? '');

            if ($email === '' || $senha === '') {
                $errorMessage = 'Informe seu e-mail e senha para entrar.';
            } else {
                $user = authenticateUser($email, $senha);
                if ($user !== null) {
                    if ((int) ($user['two_factor_enabled'] ?? 0) === 1) {
                        $code = (string) random_int(100000, 999999);
                        setTwoFactorCode((int) $user['id'], $code);
                        $_SESSION['pending_2fa_user_id'] = (int) $user['id'];
                        if (!sendBrevoSmtpMail($user['email'], 'Código de acesso - CondoConnect', '<p>Seu código de acesso é <strong>' . $code . '</strong>. Ele expira em 10 minutos.</p>')) {
                            unset($_SESSION['pending_2fa_user_id']);
                            $errorMessage = 'Não foi possível enviar o código de acesso. Tente novamente mais tarde.';
                        }
                    } else {
                        establishUserSession($user);
                        auditAction((int)$user['id'], 'login', 'usuario', (int)$user['id']);
                        if ((int) ($user['email_verificado'] ?? 1) !== 1) {
                            $_SESSION['auth_notice'] = 'Sua conta está ativa, mas confirme seu e-mail para liberar as funcionalidades.';
                        }
                        redirect(getBasePath() . 'pages/dashboard.php');
                    }
                } else {
                    $errorMessage = 'E-mail, senha ou verificação de e-mail inválidos.';
                }
            }
        }
    }
} catch (Throwable $error) {
    $errorMessage = friendlyError($error);
    http_response_code(503);
}

require __DIR__ . '/../includes/header.php';
?>

<main class="auth-frame">
    <div class="container">
        <?php require __DIR__ . '/../includes/auth-welcome.php'; ?>
        <div class="auth-card">
            <a class="auth-back" href="<?= $basePath ?>index.php">← Voltar ao início</a>
            <span class="auth-symbol"><?= condoIcon('home') ?></span>
            <p class="eyebrow">SEU ESPAÇO ESTÁ AQUI</p>
            <h1>Que bom ter você de volta.</h1>
            <p>Entre na sua conta e sinta-se em casa.</p>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-error"><?= sanitize($errorMessage) ?></div>
            <?php endif; ?>
            <?php if ($registrationMessage !== ''): ?>
                <div class="alert alert-info"><?= sanitize($registrationMessage) ?></div>
            <?php endif; ?>

            <form action="<?= $basePath ?>pages/login.php" method="post">
                <?= csrfField() ?>
                <?php if (!empty($_SESSION['pending_2fa_user_id'])): ?>
                <label>
                    <span>Código de 6 dígitos</span>
                    <input type="text" name="codigo_2fa" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required>
                </label>
                <?php else: ?>
                <label>
                    <span>E-mail</span>
                    <input type="email" name="email" autocomplete="username" placeholder="seu@email.com" required>
                </label>
                <?php endif; ?>
                <?php if (empty($_SESSION['pending_2fa_user_id'])): ?>
                <label>
                    <span>Senha</span>
                    <div class="password-input">
                        <input type="password" name="senha" autocomplete="current-password" data-password placeholder="Sua senha" required>
                        <button type="button" data-toggle-password>Mostrar</button>
                    </div>
                </label>
                <?php endif; ?>
                <button type="submit" class="button button-primary auth-submit">Entrar na minha conta <?= condoIcon('arrow') ?></button>
            </form>

            <div class="auth-footer">
                <a href="<?= getBasePath() ?>pages/recuperar-senha.php">Esqueci minha senha</a>
                <a href="<?= getBasePath() ?>pages/cadastro.php">Criar conta</a>
            </div>
            <p class="auth-security"><?= condoIcon('shield') ?> Seu condomínio começa com uma conexão segura.</p>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
