<?php
require_once __DIR__ . '/../includes/auth.php';
requireVerifiedEmail();

$user = currentUser();
$uid = (int) $user['id'];
$cond = communityCondominioId($uid, isset($_GET['condominio_id']) ? (int) $_GET['condominio_id'] : null);
$building = userCondominio($uid, $cond);
$error = '';
$manager = $building && canManageCommunity($uid, (int) $building['id']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        saveCondominio($uid, (int) ($building['id'] ?? 0), $_POST);
        flashMessage('Informações do condomínio atualizadas.');
        redirect(getBasePath() . 'pages/condominio.php?condominio_id=' . $cond);
    } catch (Throwable $e) {
        $error = friendlyError($e);
    }
}
$pageTitle = 'Condomínio';
$bodyClass = 'dashboard-page community-page';
require __DIR__ . '/../includes/header.php';
?>
<main class="section section-single">
    <div class="container">
        <div class="section-header page-heading">
            <span>Meu espaço</span>
            <h1><?= sanitize($building['nome'] ?? 'Condomínio') ?></h1>
            <p>Informações, espaços e serviços da sua comunidade em um só lugar.</p>
        </div>
        <?php require __DIR__ . '/../includes/community-selector.php'; ?>
        <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= sanitize($error) ?></div><?php endif; ?>
        <?php if (!$building): ?>
            <div class="empty-state">
                <div class="icon"><?= condoIcon('building') ?></div>
                <h2>Conta sem condomínio vinculado</h2>
                <p>Solicite à administração a atualização do seu vínculo.</p>
            </div>
        <?php else: ?>
            <?php
            $syndic = dbRow('SELECT u.nome,u.id FROM condominio_sindicos s JOIN users u ON u.id=s.usuario_id WHERE s.condominio_id=?', [$building['id']]);
            $areas = listAreas($uid, $cond);
            $communityQuery = '?condominio_id=' . (int) $building['id'];
            $description = trim((string) ($building['descricao'] ?? ''));
            $rules = trim((string) ($building['regras'] ?? ''));
            ?>
            <div class="community-overview">
                <section class="panel-card community-details">
                    <div class="community-panel-title">
                        <div class="icon"><?= condoIcon('building') ?></div>
                        <div><h2>Nosso condomínio</h2><p>Conheça a sua comunidade</p></div>
                    </div>
                    <div class="community-address">
                        <strong><?= sanitize($building['endereco']) ?></strong>
                        <span><?= sanitize(implode(' · ', array_filter([$building['cidade'], $building['estado']]))) ?></span>
                    </div>
                    <?php if ($description !== ''): ?><div class="rich-copy"><?= nl2br(sanitize($description)) ?></div><?php endif; ?>
                    <dl class="community-facts">
                        <div><dt>Contato</dt><dd><?= sanitize(trim((string) ($building['contato'] ?? '')) ?: 'Não informado') ?></dd></div>
                        <div><dt>Sua unidade</dt><dd><?= sanitize((int) ($user['condominio_id'] ?? 0) === (int) $building['id'] ? (trim((string) ($user['unidade'] ?? '')) ?: 'Não informada') : 'Sem unidade neste condomínio') ?></dd></div>
                        <div><dt>Síndico</dt><dd><?= sanitize($syndic['nome'] ?? 'Não designado') ?></dd></div>
                    </dl>
                </section>
                <section class="panel-card community-rules">
                    <div class="community-panel-title">
                        <div class="icon"><?= condoIcon('notice') ?></div>
                        <div><h2>Regras de convivência</h2><p>Para uma rotina melhor para todos</p></div>
                    </div>
                    <div class="rich-copy"><?= nl2br(sanitize($rules !== '' ? $rules : 'Consulte a administração para conhecer as regras do condomínio.')) ?></div>
                    <div class="quick-links">
                        <a href="<?= getBasePath() ?>pages/reservas.php<?= $communityQuery ?>"><span>Reservar áreas comuns</span><?= condoIcon('arrow') ?></a>
                        <a href="<?= getBasePath() ?>pages/comunicados.php<?= $communityQuery ?>"><span>Comunicados e avisos</span><?= condoIcon('arrow') ?></a>
                    </div>
                </section>
            </div>
            <section class="panel-card community-section">
                <div class="panel-title-row">
                    <div><h2>Áreas comuns</h2><p>Espaços para aproveitar o seu condomínio.</p></div>
                    <?php if ($areas): ?><a class="button button-secondary" href="<?= getBasePath() ?>pages/reservas.php<?= $communityQuery ?>"><?= condoIcon('calendar') ?> Ver reservas</a><?php endif; ?>
                </div>
                <?php if ($areas): ?>
                    <div class="community-areas">
                        <?php foreach ($areas as $area): ?>
                            <article class="community-area">
                                <span class="icon"><?= condoIcon('building') ?></span>
                                <h3><?= sanitize($area['nome']) ?></h3>
                                <p><?= sanitize(trim((string) ($area['descricao'] ?? '')) ?: 'Consulte a disponibilidade e as orientações antes de reservar.') ?></p>
                                <span class="community-area__capacity">Capacidade: <?= (int) $area['capacidade'] ?> <?= (int) $area['capacidade'] === 1 ? 'pessoa' : 'pessoas' ?></span>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state compact"><p>Nenhuma área comum cadastrada neste condomínio.</p></div>
                <?php endif; ?>
            </section>
            <?php if ($manager): ?>
                <section class="panel-card community-section">
                    <div class="panel-title-row"><div><h2>Administrar informações</h2><p>Mantenha os moradores informados sobre o condomínio.</p></div></div>
                    <form method="post" class="community-edit-form">
                        <?= csrfField() ?>
                        <label>Descrição<textarea name="descricao" rows="4" maxlength="15000"><?= sanitize($error !== '' ? ($_POST['descricao'] ?? '') : ($building['descricao'] ?? '')) ?></textarea></label>
                        <label>Regras de convivência<textarea name="regras" rows="4" maxlength="15000"><?= sanitize($error !== '' ? ($_POST['regras'] ?? '') : ($building['regras'] ?? '')) ?></textarea></label>
                        <label class="community-edit-form__contact">Contato<input name="contato" maxlength="190" value="<?= sanitize($error !== '' ? ($_POST['contato'] ?? '') : ($building['contato'] ?? '')) ?>" placeholder="Telefone ou e-mail da administração"></label>
                        <button class="button button-primary" type="submit">Salvar alterações</button>
                    </form>
                </section>
                <section class="panel-card community-section">
                    <div class="panel-title-row"><div><h2>Moradores do condomínio</h2><p>Unidades e perfis dos moradores ativos.</p></div></div>
                    <?php $residents = dbRows("SELECT id,nome,unidade FROM users WHERE condominio_id=? AND tipo_usuario='morador' AND status='ativo' ORDER BY nome", [$building['id']]); ?>
                    <?php if ($residents): ?>
                        <div class="table-wrapper"><table>
                            <thead><tr><th scope="col">Nome</th><th scope="col">Unidade</th><th scope="col">Perfil</th></tr></thead>
                            <tbody><?php foreach ($residents as $resident): ?>
                                <tr><td data-label="Nome"><?= sanitize($resident['nome']) ?></td><td data-label="Unidade"><?= sanitize($resident['unidade']) ?></td><td data-label="Perfil"><a class="button button-secondary" href="<?= getBasePath() ?>pages/perfil.php?id=<?= (int) $resident['id'] ?>">Ver perfil</a></td></tr>
                            <?php endforeach; ?></tbody>
                        </table></div>
                    <?php else: ?>
                        <div class="empty-state compact"><p>Nenhum morador ativo vinculado a este condomínio.</p></div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
