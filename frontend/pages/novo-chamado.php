<?php
require_once __DIR__ . '/../includes/auth.php';
requireVerifiedEmail();
if (currentUser()['tipo_usuario']!=='morador') { http_response_code(403); exit('Somente moradores podem abrir chamados.'); }

$pageTitle = 'Novo chamado';
$metaDescription = 'Abra um novo chamado de manutenção no CondoConnect.';
$bodyClass = 'auth-page';
$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values = [];
    foreach (['categoria','titulo','descricao','local_problema','prioridade','data_servico'] as $key) {
        $values[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $date = DateTime::createFromFormat('!Y-m-d', $values['data_servico']);
    $target = null;
    try {
        if (in_array('', $values, true)) throw new RuntimeException('Preencha todos os campos.');
        if (!in_array($values['categoria'], ['Elétrica','Hidráulica','Pintura','Limpeza','Elevadores','Jardinagem','Segurança','Reparos gerais'], true)) throw new RuntimeException('Categoria inválida.');
        if (!in_array($values['prioridade'], ['Baixa','Média','Alta','Urgente'], true)) throw new RuntimeException('Prioridade inválida.');
        if (!$date || $date->format('Y-m-d') !== $values['data_servico']) throw new RuntimeException('Data inválida.');
        if (strlen($values['titulo']) > 30 || strlen($values['local_problema']) > 100 || strlen($values['descricao']) > 300) throw new RuntimeException('Reduza o tamanho dos textos informados.');
        $filename = null;
        $file = $_FILES['anexo'] ?? null;
        if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2097152) throw new RuntimeException('Envie uma imagem de até 2 MB.');
            $types = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (!isset($types[$mime])) throw new RuntimeException('Formato de imagem inválido.');
            $dir = dirname(__DIR__, 2) . '/backend/storage/uploads';
            if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new RuntimeException('Não foi possível preparar a pasta de anexos.');
            $filename = bin2hex(random_bytes(16)) . '.' . $types[$mime];
            $target = $dir . '/' . $filename;
            if (!move_uploaded_file($file['tmp_name'], $target)) throw new RuntimeException('Não foi possível salvar o anexo. Confira as permissões.');
        }
        $pdo = getPDO();
        $pdo->beginTransaction();
        $values['usuario_id'] = (int) $_SESSION['user_id'];
        $values['protocolo'] = 'CP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6)));
        $values['status'] = 'aberto';
        $id = createChamado($values);
        if ($filename !== null) addChamadoAttachment($id, $filename);
        $pdo->commit();
        flashMessage('Chamado criado com sucesso.');
        redirect(getBasePath() . 'pages/detalhes-chamado.php?id='.$id);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        if ($target && is_file($target)) unlink($target);
        error_log((string) $e);
        if ($e instanceof PDOException && in_array($e->getCode(), ['42S02', '42S22'], true)) {
            $errorMessage = 'A estrutura do banco precisa ser atualizada. Execute a migration de regras de negócio e tente novamente.';
        } else {
            $errorMessage = $e instanceof PDOException ? 'Não foi possível salvar o chamado agora. Tente novamente em instantes.' : $e->getMessage();
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<main class="auth-frame">
    <div class="container">
        <div class="auth-card">
            <p class="eyebrow">Solicitação</p>
            <h1>Nova solicitação de manutenção</h1>
            <p>Descreva o problema para que o condomínio possa organizar o atendimento.</p>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-error"><?= sanitize($errorMessage) ?></div>
            <?php endif; ?>
            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success"><?= sanitize($successMessage) ?></div>
            <?php endif; ?>

            <form action="<?= $basePath ?>pages/novo-chamado.php" method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
                <label>
                    <span>Categoria</span>
                    <select name="categoria" required>
                        <option value="Elétrica">Elétrica</option>
                        <option value="Hidráulica">Hidráulica</option>
                        <option value="Pintura">Pintura</option>
                        <option value="Limpeza">Limpeza</option>
                        <option value="Elevadores">Elevadores</option>
                        <option value="Jardinagem">Jardinagem</option>
                        <option value="Segurança">Segurança</option>
                        <option value="Reparos gerais">Reparos gerais</option>
                    </select>
                </label>
                <label>
                    <span>Título do problema</span>
                    <input type="text" name="titulo" placeholder="Ex.: Queda de energia no 12º andar" required>
                </label>
                <label>
                    <span>Descrição</span>
                    <textarea name="descricao" rows="5" placeholder="Descreva detalhadamente o problema" required></textarea>
                </label>
                <label>
                    <span>Local do problema</span>
                    <input type="text" name="local_problema" placeholder="Bloco A, garagem, apartamento 1202" required>
                </label>
                <label>
                    <span>Prioridade</span>
                    <select name="prioridade" required>
                        <option value="Baixa">Baixa</option>
                        <option value="Média">Média</option>
                        <option value="Alta">Alta</option>
                        <option value="Urgente">Urgente</option>
                    </select>
                </label>
                <label>
                    <span>Data desejada</span>
                    <input type="date" name="data_servico" required>
                </label>
                <label>
                    <span>Anexo (imagem)</span>
                    <input type="file" name="anexo" accept="image/*">
                </label>
                <button type="submit" class="button button-primary auth-submit">Salvar chamado</button>
            </form>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
