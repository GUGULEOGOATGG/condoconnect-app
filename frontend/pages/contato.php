<?php
require_once __DIR__ . '/../includes/auth.php';
$feedback = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $data = [];
    foreach (['nome','email','assunto','mensagem'] as $key) $data[$key] = trim((string) ($_POST[$key] ?? ''));
    if (in_array('', $data, true) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $feedback = 'Preencha os campos e informe um e-mail válido.';
    } elseif (strlen($data['nome']) > 30 || strlen($data['email']) > 30 || strlen($data['assunto']) > 30 || strlen($data['mensagem']) > 300) {
        $feedback = 'Reduza o tamanho dos campos informados.';
    } else {
        getPDO()->prepare('INSERT INTO contatos (nome,email,assunto,mensagem) VALUES (:nome,:email,:assunto,:mensagem)')->execute($data);
        $_SESSION['contato_salvo'] = true;
        redirect(getBasePath() . 'pages/contato.php');
    }
}
if (!empty($_SESSION['contato_salvo'])) { $feedback = 'Mensagem registrada com sucesso.'; unset($_SESSION['contato_salvo']); }

$pageTitle = 'Contato';
$metaDescription = 'Entre em contato com o CondoConnect.';
$bodyClass = 'public-page';
require __DIR__ . '/../includes/header.php';
?>

<main class="section section-single">
    <div class="container">
        <div class="section-header">
            <span>Contato</span>
            <h2>Fale conosco</h2>
            <p>Envie uma mensagem e vamos responder com a maior brevidade possível.</p>
        </div>
        <div class="form-card">
            <p role="status"><?= sanitize($feedback) ?></p>
            <form action="#" method="post">
                <?= csrfField() ?>
                <label>
                    <span>Nome</span>
                    <input type="text" name="nome" placeholder="Seu nome completo" required>
                </label>
                <label>
                    <span>E-mail</span>
                    <input type="email" name="email" placeholder="nome@exemplo.com" required>
                </label>
                <label>
                    <span>Assunto</span>
                    <input type="text" name="assunto" placeholder="Motivo do contato" required>
                </label>
                <label>
                    <span>Mensagem</span>
                    <textarea name="mensagem" rows="6" placeholder="Escreva sua mensagem..." required></textarea>
                </label>
                <button type="submit" class="button button-primary">Enviar mensagem</button>
            </form>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
