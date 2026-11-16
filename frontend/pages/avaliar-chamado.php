<?php
require_once __DIR__ . '/../includes/auth.php';
requireVerifiedEmail();
$user = currentUser(); $ticketId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: (filter_input(INPUT_POST, 'chamado_id', FILTER_VALIDATE_INT) ?: 0);
$ticket = getChamadoById($ticketId); $feedback = '';
if (($user['tipo_usuario'] ?? '') !== 'morador' || !$ticket || (int)$ticket['usuario_id'] !== (int)$user['id'] || $ticket['status'] !== 'concluido' || empty($ticket['prestador_id'])) {
    http_response_code(403); exit('Somente o morador pode avaliar o prestador de um chamado concluído.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        createReview($ticketId, (int)$user['id'], (int)$ticket['prestador_id'], (int)($_POST['nota'] ?? 0), trim((string)($_POST['comentario'] ?? '')));
        $feedback = 'Avaliação registrada. Obrigado pelo retorno.';
    } catch (Throwable $e) { $feedback = $e instanceof PDOException ? 'Este chamado já possui uma avaliação ou não pôde ser avaliado.' : $e->getMessage(); }
}
$pageTitle = 'Avaliar atendimento'; $bodyClass = 'dashboard-page'; require __DIR__ . '/../includes/header.php';
?>
<main class="auth-frame"><div class="container"><div class="auth-card">
    <p class="eyebrow">Chamado #<?= (int)$ticketId ?></p><h1>Avaliar atendimento</h1>
    <?php if ($feedback !== ''): ?><div class="alert alert-info"><?= sanitize($feedback) ?></div><?php endif; ?>
    <?php if ($feedback === ''): ?><form method="post" action="<?= getBasePath() ?>pages/avaliar-chamado.php?id=<?= (int)$ticketId ?>">
        <?= csrfField() ?><input type="hidden" name="chamado_id" value="<?= (int)$ticketId ?>">
        <label><span>Nota (1 a 5)</span><select name="nota" required><option value="">Selecione</option><?php for ($i=1; $i<=5; $i++): ?><option value="<?= $i ?>"><?= $i ?></option><?php endfor; ?></select></label>
        <label><span>Comentário</span><textarea name="comentario" maxlength="2000" rows="4"></textarea></label>
        <button class="button button-primary" type="submit">Enviar avaliação</button>
    </form><?php endif; ?>
</div></div></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
