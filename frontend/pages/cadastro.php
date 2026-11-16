<?php
require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Cadastro';
$metaDescription = 'Crie sua conta no CondoConnect.';
$bodyClass = 'auth-page auth-register';
$errorMessage = '';
$nome = '';
$cpf = '';
$email = '';
$telefone = '';
$senha = '';
$confirmarSenha = '';
$tipoUsuario = 'morador';
$condominio = '';
$unidade = '';
$clearFields = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim((string) ($_POST['nome'] ?? ''));
    $cpf = trim((string) ($_POST['cpf'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $telefone = trim((string) ($_POST['telefone'] ?? ''));
    $senha = (string) ($_POST['senha'] ?? '');
    $confirmarSenha = (string) ($_POST['confirmar_senha'] ?? '');
    $tipoUsuario = trim((string) ($_POST['tipo_usuario'] ?? 'morador'));
    $condominio = trim((string) ($_POST['condominio'] ?? ''));
    $unidade = trim((string) ($_POST['unidade'] ?? ''));
    $certificadoFile = $_FILES['documento_certificado'] ?? null;

    $cpfDigits = preg_replace('/\D+/', '', $cpf);
    $phoneDigits = preg_replace('/\D+/', '', $telefone);

    if ($nome === '' || $cpf === '' || $email === '' || $telefone === '' || $senha === '' || $confirmarSenha === '' || $condominio === '' || $unidade === '') {
        $errorMessage = 'Preencha todos os campos obrigatórios.';
        foreach ([
            'nome' => $nome,
            'cpf' => $cpf,
            'email' => $email,
            'telefone' => $telefone,
            'senha' => $senha,
            'confirmar_senha' => $confirmarSenha,
            'condominio' => $condominio,
            'unidade' => $unidade,
        ] as $field => $value) {
            if ($value === '') {
                $clearFields[] = $field;
            }
        }
    } elseif (strlen($nome) > 190 || strlen($cpfDigits) !== 11 || strlen($condominio) > 190 || strlen($unidade) > 190 || strlen($senha) > 255) {
        $errorMessage = 'Algum campo excedeu o limite permitido.';
        if (strlen($nome) > 190) $clearFields[] = 'nome';
        if (strlen($cpfDigits) !== 11) $clearFields[] = 'cpf';
        if (strlen($condominio) > 190) $clearFields[] = 'condominio';
        if (strlen($unidade) > 190) $clearFields[] = 'unidade';
        if (strlen($senha) > 255) {
            $clearFields[] = 'senha';
            $clearFields[] = 'confirmar_senha';
        }
    } elseif (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 11) {
        $errorMessage = 'Informe um telefone válido com DDD e número.';
        $clearFields[] = 'telefone';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $errorMessage = 'Informe um e-mail válido.';
        $clearFields[] = 'email';
    } elseif (!in_array($tipoUsuario, ['morador', 'prestador', 'sindico'], true)) {
        $errorMessage = 'Perfil inválido.';
        $clearFields[] = 'tipo_usuario';
    } elseif ($tipoUsuario === 'sindico' && (!is_array($certificadoFile) || $certificadoFile['error'] !== UPLOAD_ERR_OK || $certificadoFile['size'] <= 0)) {
        $errorMessage = 'Síndicos devem anexar o certificado para aprovação.';
        $clearFields[] = 'documento_certificado';
    } elseif (strlen($senha) < 8) {
        $errorMessage = 'Use uma senha com pelo menos 8 caracteres.';
        $clearFields[] = 'senha';
        $clearFields[] = 'confirmar_senha';
    } elseif ($senha !== $confirmarSenha) {
        $errorMessage = 'As senhas não conferem.';
        $clearFields[] = 'senha';
        $clearFields[] = 'confirmar_senha';
    } elseif (findUserByEmail($email) !== null) {
        $errorMessage = 'Este e-mail já está cadastrado.';
        $clearFields[] = 'email';
    } else {
        $certificadoNome = '';
        if ($tipoUsuario === 'sindico' && is_array($certificadoFile)) {
            try {
                $certificadoNome = storeUpload($certificadoFile, dirname(__DIR__, 2) . '/backend/storage/certificados', true) ?? '';
            } catch (Throwable $e) { $errorMessage = friendlyError($e); }
        }

        if ($errorMessage === '') {
            try {
            $userId = createUser([
                'nome' => $nome,
                'cpf' => $cpfDigits,
                'email' => $email,
                'telefone' => $phoneDigits,
                'senha' => $senha,
                'tipo_usuario' => $tipoUsuario,
                'condominio' => $condominio,
                'unidade' => $unidade,
                'documento_certificado' => $certificadoNome,
            ]);
            $token = createEmailVerificationToken($userId);
            $base = applicationOrigin();
            $verifyUrl = $base . getBasePath() . 'pages/verificar-email.php?token=' . urlencode($token);
            if (!sendBrevoSmtpMail($email, 'Confirme seu e-mail - CondoConnect', '<p>Olá, ' . sanitize($nome) . '.</p><p>Confirme seu cadastro:</p><p><a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '">Verificar e-mail</a></p>')) {
                $errorMessage = 'Conta criada, mas não foi possível enviar o e-mail de confirmação. Solicite um novo envio.';
            } else {
                $_SESSION['registration_message'] = 'Cadastro realizado. Verifique seu e-mail antes de entrar.';
                redirect(getBasePath() . 'pages/login.php');
            }
            } catch(Throwable $e) { $errorMessage=friendlyError($e); }
        }
    }
}

foreach (array_unique($clearFields) as $field) {
    if ($field === 'nome') $nome = '';
    if ($field === 'cpf') $cpf = '';
    if ($field === 'email') $email = '';
    if ($field === 'telefone') $telefone = '';
    if ($field === 'senha') $senha = '';
    if ($field === 'confirmar_senha') $confirmarSenha = '';
    if ($field === 'tipo_usuario') $tipoUsuario = 'morador';
    if ($field === 'condominio') $condominio = '';
    if ($field === 'unidade') $unidade = '';
}

require __DIR__ . '/../includes/header.php';
?>

<main class="auth-frame">
    <div class="container">
        <?php require __DIR__ . '/../includes/auth-welcome.php'; ?>
        <div class="auth-card">
            <a class="auth-back" href="<?= $basePath ?>index.php">← Voltar ao início</a>
            <p class="eyebrow">VAMOS NOS CONECTAR</p>
            <h1>Seu novo começo é aqui.</h1>
            <p>Crie sua conta e aproxime-se do seu condomínio.</p>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-error"><?= sanitize($errorMessage) ?></div>
            <?php endif; ?>

            <form action="<?= $basePath ?>pages/cadastro.php" method="post" enctype="multipart/form-data">
                <?= csrfField() ?>

                <label>
                    <span>Nome completo</span>
                    <input type="text" name="nome" value="<?= sanitize($nome) ?>" placeholder="Seu nome" required>
                </label>
                <label>
                    <span>CPF</span>
                    <input type="text" name="cpf" value="<?= sanitize($cpf) ?>" placeholder="000.000.000-00" required>
                </label>
                <label>
                    <span>E-mail</span>
                    <input type="email" name="email" value="<?= sanitize($email) ?>" placeholder="seu@email.com" required>
                </label>
                <label>
                    <span>Telefone</span>
                    <input type="tel" name="telefone" value="<?= sanitize($telefone) ?>" placeholder="(11) 99999-9999" required>
                </label>
                <label>
                    <span>Senha</span>
                    <input type="password" name="senha" value="" placeholder="Crie uma senha" required>
                </label>
                <label>
                    <span>Confirme a senha</span>
                    <input type="password" name="confirmar_senha" value="" placeholder="Repita a senha" required>
                </label>
                <label>
                    <span>Tipo de usuário</span>
                    <select name="tipo_usuario" required>
                        <option value="morador" <?= $tipoUsuario === 'morador' ? 'selected' : '' ?>>Morador</option>
                        <option value="prestador" <?= $tipoUsuario === 'prestador' ? 'selected' : '' ?>>Prestador</option>
                        <option value="sindico" <?= $tipoUsuario === 'sindico' ? 'selected' : '' ?>>Síndico</option>
                    </select>
                </label>
                <label>
                    <span>Condomínio</span>
                    <input type="text" name="condominio" value="<?= sanitize($condominio) ?>" placeholder="Nome do condomínio" required>
                </label>
                <label>
                    <span>Unidade / Bloco</span>
                    <input type="text" name="unidade" value="<?= sanitize($unidade) ?>" placeholder="Ex.: Torre A / Unidade 1202" required>
                </label>
                <label id="certificado-field">
                    <span>Certificado / comprovação</span>
                    <input type="file" name="documento_certificado" accept=".pdf,.png,.jpg,.jpeg">
                </label>
                <button type="submit" class="button button-primary auth-submit">Criar conta</button>
            </form>

            <div class="auth-footer">
                <span>Já possui conta?</span>
                <a href="<?= getBasePath() ?>pages/login.php">Entrar</a>
            </div>
        </div>
    </div>
</main>

<script>
    const tipoUsuarioSelect = document.querySelector('select[name="tipo_usuario"]');
    const certificadoField = document.getElementById('certificado-field');
    const toggleCertificado = () => {
        const visible = tipoUsuarioSelect && tipoUsuarioSelect.value === 'sindico';
        if (certificadoField) {
            certificadoField.style.display = visible ? 'block' : 'none';
            const input = certificadoField.querySelector('input');
            if (input) input.required = visible;
        }
    };
    tipoUsuarioSelect?.addEventListener('change', toggleCertificado);
    toggleCertificado();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
