<?php
require_once __DIR__ . '/../includes/auth.php';
requireVerifiedEmail();
$user = currentUser();
$pageTitle = 'Meus chamados'; $bodyClass = 'dashboard-page';
$statusFilter = trim((string)($_GET['status'] ?? ''));
$allowedStatuses = ['aberto','andamento','concluido','cancelado'];
$tickets = getVisibleChamadosForUser((int)$user['id']);
if (in_array($statusFilter, $allowedStatuses, true)) $tickets = array_values(array_filter($tickets, static fn($row) => $row['status'] === $statusFilter));
require __DIR__ . '/../includes/header.php';
?>
<main class="section section-single"><div class="container">
    <div class="page-title-row"><div class="section-header page-heading"><span>Atendimentos</span><h1>Meus chamados</h1><p>Acompanhe os chamados que você pode acessar.</p></div><?php if (($user['tipo_usuario'] ?? '') === 'morador'): ?><a class="button button-primary" href="<?= getBasePath() ?>pages/novo-chamado.php">＋ Novo chamado</a><?php endif; ?></div>
    <nav class="filter-tabs" aria-label="Filtrar chamados"><?php foreach ([''=>'Todos','aberto'=>'Aguardando','andamento'=>'Em andamento','concluido'=>'Concluídos','cancelado'=>'Cancelados'] as $value=>$label): ?><a class="<?= $statusFilter === $value ? 'active' : '' ?>" href="<?= getBasePath() ?>pages/meus-chamados.php<?= $value !== '' ? '?status=' . rawurlencode($value) : '' ?>"><?= sanitize($label) ?></a><?php endforeach; ?></nav>
    <?php if ($tickets === []): ?><div class="empty-state"><h3>Nenhum chamado nesta lista</h3><p>Novas solicitações e atualizações autorizadas aparecerão aqui.</p><?php if (($user['tipo_usuario'] ?? '') === 'morador'): ?><a class="button button-primary" href="<?= getBasePath() ?>pages/novo-chamado.php">Abrir chamado</a><?php endif; ?></div>
    <?php else: ?><div class="panel-card"><div class="table-wrapper"><table><thead><tr><th>Protocolo</th><th>Categoria</th><th>Descrição</th><th>Status</th><th>Data</th><th></th></tr></thead><tbody><?php foreach ($tickets as $ticket): ?><tr><td data-label="Protocolo"><?= sanitize($ticket['protocolo']) ?></td><td data-label="Categoria"><?= sanitize($ticket['categoria']) ?></td><td data-label="Descrição"><?= sanitize($ticket['titulo']) ?></td><td data-label="Status"><span class="badge badge-<?= sanitize($ticket['status']) ?>"><?= sanitize(ucfirst($ticket['status'])) ?></span></td><td data-label="Data"><?= sanitize(date('d/m/Y', strtotime((string)$ticket['criado_em']))) ?></td><td data-label="Ação"><a class="button button-secondary" href="<?= getBasePath() ?>pages/detalhes-chamado.php?id=<?= (int)$ticket['id'] ?>">Ver detalhes</a></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
</div></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
