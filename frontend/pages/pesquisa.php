<?php
require_once __DIR__ . '/../includes/auth.php';
requireVerifiedEmail();

$pageTitle = 'Prestadores';
$metaDescription = 'Busque por profissionais ou serviços por categoria específica.';
$bodyClass = 'dashboard-page providers-page';
$user = currentUser();
$category = trim((string) ($_GET['categoria'] ?? ''));
$results = searchProfilesByCategory($category);
require __DIR__ . '/../includes/header.php';
?>

<main class="section section-single">
    <div class="container">
        <div class="section-header page-heading">
            <span>Profissionais</span>
            <h1>Prestadores</h1>
            <p>Encontre o profissional certo para cuidar do seu condomínio.</p>
        </div>

        <div class="panel-card search-panel">
            <form method="get" action="<?= getBasePath() ?>pages/pesquisa.php" class="search-form">
                <label>
                    <span>Busque por nome, serviço ou especialidade</span>
                    <input type="search" name="categoria" value="<?= sanitize($category) ?>" placeholder="Ex.: elétrica, hidráulica ou nome do profissional">
                </label>
                <button type="submit" class="button button-primary">Pesquisar</button>
            </form>
        </div>
        <nav class="filter-tabs provider-filters" aria-label="Filtrar por especialidade"><a href="<?= getBasePath() ?>pages/pesquisa.php" class="<?= $category === '' ? 'active' : '' ?>">Todos</a>
            <?php foreach (['Elétrica','Hidráulica','Pintura','Limpeza','Elevadores','Jardinagem','Segurança','Reparos gerais'] as $specialty): ?>
                <a class="<?= mb_strtolower($category, 'UTF-8') === mb_strtolower($specialty, 'UTF-8') ? 'active' : '' ?>" href="<?= getBasePath() ?>pages/pesquisa.php?categoria=<?= rawurlencode($specialty) ?>"><?= sanitize($specialty) ?></a>
            <?php endforeach; ?>
        </nav>

        <p class="results-summary"><?= count($results) ?> <?= count($results) === 1 ? 'profissional encontrado' : 'profissionais encontrados' ?><?= $category !== '' ? ' para “' . sanitize($category) . '”' : '' ?></p>
        <?php if ($results === []): ?>
            <div class="empty-state">
                <div class="icon"><?= condoIcon('search') ?></div>
                <h2>Nenhum profissional encontrado</h2>
                <p><?= $category !== '' ? 'Tente outro nome ou especialidade para ampliar a busca.' : 'Os profissionais disponíveis aparecerão aqui.' ?></p>
                <?php if ($category !== ''): ?><a class="button button-secondary" href="<?= getBasePath() ?>pages/pesquisa.php">Ver todos os prestadores</a><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="provider-grid">
                <?php foreach ($results as $result): ?>
                    <?php
                    $providerAvatar = trim((string) ($result['avatar'] ?? ''));
                    $specialties = trim((string) ($result['especialidades'] ?? ''));
                    $bio = trim((string) ($result['bio'] ?? ''));
                    $reviewCount = (int) ($result['total_avaliacoes'] ?? 0);
                    ?>
                    <article class="provider-card" aria-labelledby="provider-<?= (int) $result['id'] ?>">
                        <div class="provider-card__head">
                            <?php if ($providerAvatar !== ''): ?>
                                <img class="provider-avatar" src="<?= sanitize(profileImageUrl($result)) ?>" alt="" width="64" height="64" loading="lazy">
                            <?php else: ?>
                                <span class="provider-avatar" aria-hidden="true"><?= sanitize(mb_strtoupper(mb_substr((string) $result['nome'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
                            <?php endif; ?>
                            <div class="provider-card__identity">
                                <span class="provider-card__role"><?= sanitize(getUserRoleLabel((string) $result['tipo_usuario'])) ?></span>
                                <h2 id="provider-<?= (int) $result['id'] ?>"><?= sanitize($result['nome']) ?></h2>
                            </div>
                        </div>
                        <div class="provider-card__rating">
                            <?php if ($reviewCount > 0): ?>
                                <span class="provider-rating"><span aria-hidden="true">★</span> <?= number_format((float) $result['avaliacao_media'], 1, ',', '.') ?><span class="visually-hidden"> de 5</span></span>
                                <span><?= $reviewCount ?> <?= $reviewCount === 1 ? 'avaliação' : 'avaliações' ?></span>
                            <?php else: ?>
                                <span>Ainda sem avaliações</span>
                            <?php endif; ?>
                        </div>
                        <div class="provider-card__specialties">
                            <h3>Especialidades</h3>
                            <p><?= sanitize($specialties !== '' ? $specialties : 'Consulte os serviços no perfil.') ?></p>
                        </div>
                        <p class="provider-card__bio"><?= sanitize($bio !== '' ? $bio : 'Conheça os serviços e a experiência deste profissional no perfil.') ?></p>
                        <div class="provider-card__actions">
                            <a href="<?= getBasePath() ?>pages/perfil.php?id=<?= (int) $result['id'] ?>" class="button button-secondary">Ver perfil</a>
                            <?php if (canUsersChat((int) $user['id'], (int) $result['id'])): ?>
                                <form method="post" action="<?= getBasePath() ?>pages/chat.php">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="open_chat">
                                    <input type="hidden" name="usuario_id" value="<?= (int) $result['id'] ?>">
                                    <button class="button button-primary" type="submit"><?= condoIcon('chat') ?> Conversar</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
