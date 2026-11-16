<?php
require_once __DIR__ . '/../includes/auth.php';
requireVerifiedEmail();
$user = currentUser();
$pdo = getPDO();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    $id = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT) ?: 0;
    markNotificationRead((int)$user['id'],$id);
    redirect(getBasePath() . 'pages/notificacoes.php');
}
$pageTitle = 'Notificações';
$metaDescription = 'Notificações da sua conta CondoConnect.';
$bodyClass = 'dashboard-page';
$notifications = listNotifications((int)$user['id']);
require __DIR__ . '/../includes/header.php';
?>
<main class="section section-single"><div class="container">
    <div class="section-header page-heading"><span>Atividade</span><h1>Notificações</h1><p>Acompanhe atualizações dos seus chamados e conversas.</p></div>
    <?php if ($notifications === []): ?>
        <div class="empty-state"><div class="icon">♧</div><h3>Você está em dia</h3><p>Quando houver uma atualização para sua conta, ela aparecerá aqui.</p></div>
    <?php else: ?>
        <section class="notice-list">
        <?php foreach ($notifications as $notice): ?>
            <article class="notice-item <?= (int)$notice['lida'] === 0 ? 'notice-unread' : '' ?>"><div class="icon">♧</div><div class="notice-copy"><h3><a href="<?= sanitize(notificationUrl($notice)) ?>"><?= sanitize($notice['titulo']) ?></a></h3><p><?= nl2br(sanitize($notice['mensagem'])) ?></p><time><?= sanitize(date('d/m/Y · H:i', strtotime((string)$notice['criado_em']))) ?></time></div>
                <?php if ((int)$notice['lida'] === 0): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="mark_read"><input type="hidden" name="notification_id" value="<?= (int)$notice['id'] ?>"><button class="button button-secondary" type="submit">Marcar como lida</button></form><?php endif; ?>
            </article>
        <?php endforeach; ?>
        </section>
        <form method="post" class="mark-all-form"><?= csrfField() ?><input type="hidden" name="action" value="mark_read"><button class="button button-secondary" type="submit">Marcar todas como lidas</button></form>
    <?php endif; ?>
</div></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
