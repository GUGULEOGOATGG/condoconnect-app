<?php
require_once __DIR__ . '/../includes/auth.php';
$user = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['user_id']) && !empty($_POST['status'])) {
    $userId = (int) $_POST['user_id'];
    $status = trim((string) $_POST['status']);
    try {
        $_SESSION['syndic_notice'] = approveSyndicVerification($userId, (int) $user['id'], $status)
            ? 'Verificação atualizada.' : 'A verificação não foi permitida.';
    } catch (Throwable $e) { $_SESSION['syndic_notice'] = friendlyError($e); }
    redirect(getBasePath() . 'pages/admin-verificacoes.php');
}

$pageTitle = 'Verificações';
$metaDescription = 'Aprove ou recuse verificações de síndicos.';
$bodyClass = 'dashboard-page';
require __DIR__ . '/../includes/header.php';

$pendentes = listPendingSyndicVerifications();
$notice = (string)($_SESSION['syndic_notice'] ?? ''); unset($_SESSION['syndic_notice']);
?>
<main class="section section-single">
    <div class="container">
        <div class="section-header page-heading">
            <span>Administração</span>
            <h2>Verificação de síndicos</h2>
            <p>Aprove ou rejeite os certificados enviados pelos síndicos para ativação da conta.</p>
        </div>
        <?php if ($notice !== ''): ?><div class="alert alert-info"><?= sanitize($notice) ?></div><?php endif; ?>

        <?php if ($pendentes === []): ?>
            <div class="empty-state"><p>Nenhuma verificação pendente.</p></div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Email</th>
                            <th>Condomínio</th>
                            <th>Unidade</th>
                            <th>Certificado</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendentes as $item): ?>
                            <tr>
                                <td data-label="Nome"><?= sanitize($item['nome']) ?></td>
                                <td data-label="E-mail"><?= sanitize($item['email']) ?></td>
                                <td data-label="Condomínio"><?= sanitize($item['condominio']) ?></td>
                                <td data-label="Unidade"><?= sanitize($item['unidade']) ?></td>
                                <td data-label="Certificado">
                                    <?php if (!empty($item['documento_certificado'])): ?>
                                        <a href="<?= getBasePath() ?>backend/storage/certificados/<?= sanitize($item['documento_certificado']) ?>" target="_blank" rel="noopener">Visualizar</a>
                                    <?php else: ?>
                                        <span>Não enviado</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Ação">
                                    <form method="post" class="inline-actions">
                                        <input type="hidden" name="user_id" value="<?= (int) $item['id'] ?>">
                                        <button type="submit" name="status" value="aprovado" class="button button-primary">Aprovar</button>
                                        <button type="submit" name="status" value="rejeitado" class="button button-secondary">Rejeitar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
