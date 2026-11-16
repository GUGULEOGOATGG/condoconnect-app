<?php
require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Redefinir senha';
$metaDescription = 'Crie uma nova senha para sua conta.';
$bodyClass = 'auth-page';
$message = '';

try {
    $token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
    if (!empty($token)) {
        $reset = validatePasswordResetToken($token);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = trim((string) ($_POST['token'] ?? ''));
        $novaSenha = (string) ($_POST['nova_senha'] ?? '');
        $confirmar = (string) ($_POST['confirmar_senha'] ?? '');

        if ($token === '') {
            $message = 'Token ausente ou inválido.';
        } elseif ($novaSenha === '' || $confirmar === '') {
            $message = 'Informe a nova senha e confirme.';
        } elseif (strlen($novaSenha) < 8 || strlen($novaSenha) > 255) {
            $message = 'A senha deve ter entre 8 e 255 caracteres.';
        } elseif ($novaSenha !== $confirmar) {
            $message = 'As senhas não conferem.';
        } else {
            $reset = validatePasswordResetToken($token);
            if ($reset === null) {
                $message = 'Link expirado ou inválido.';
            } else {
                if (!resetUserPassword($token, $novaSenha)) {
                    $message = 'Link expirado ou inválido.';
                } else {
                    $message = 'Senha redefinida com sucesso. Você já pode fazer login.';
                }
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
            <p class="eyebrow">Nova senha</p>
            <h1>Redefinir senha</h1>
            <p>Crie uma senha nova para recuperar o acesso à sua conta.</p>

            <?php if ($message !== ''): ?>
                <div class="alert alert-info"><?= sanitize($message) ?></div>
            <?php endif; ?>

            <?php if (empty($token) || (isset($reset) && $reset === null)): ?>
                <p>O link de redefinição está inválido ou expirou.</p>
            <?php else: ?>
                <form action="<?= getBasePath() ?>pages/redefinir-senha.php" method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="token" value="<?= sanitize($token) ?>">
                    <label>
                        <span>Nova senha</span>
                        <input type="password" name="nova_senha" placeholder="Mínimo 6 caracteres" required>
                    </label>
                    <label>
                        <span>Confirmar nova senha</span>
                        <input type="password" name="confirmar_senha" placeholder="Repita a nova senha" required>
                    </label>
                    <button type="submit" class="button button-primary auth-submit">Salvar nova senha</button>
                </form>
            <?php endif; ?>

            <div class="auth-footer">
                <a href="<?= getBasePath() ?>pages/login.php">Voltar para o login</a>
            </div>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
