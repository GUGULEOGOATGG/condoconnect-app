<?php
require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Serviços';
$metaDescription = 'Veja as categorias de serviços disponíveis no CondoConnect.';
$bodyClass = 'public-page';
$selectedCategory = trim((string) ($_GET['categoria'] ?? ''));
$categories = ['Elétrica','Hidráulica','Pintura','Limpeza','Elevadores','Jardinagem','Segurança','Reparos gerais'];
require __DIR__ . '/../includes/header.php';
?>

<main class="section section-single">
    <div class="container">
        <div class="section-header">
            <span>Serviços</span>
            <h2>Categorias disponíveis para o condomínio</h2>
            <p>Use a busca para encontrar profissionais ou serviços por categoria específica.</p>
        </div>

        <div class="panel-card search-panel">
            <form method="get" action="<?= getBasePath() ?>pages/pesquisa.php" class="search-form">
                <label>
                    <span>Buscar por categoria</span>
                    <select name="categoria" required>
                        <option value="">Selecione uma categoria</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= sanitize($category) ?>" <?= $selectedCategory === $category ? 'selected' : '' ?>><?= sanitize($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="button button-primary">Buscar</button>
            </form>
        </div>

        <div class="service-list">
            <?php foreach ($categories as $category): ?>
                <article class="service-card">
                    <div class="icon"><?= condoIcon(['bolt','drop','paint','clean','lift','leaf','shield','tool'][array_search($category, $categories, true)] ?? 'tool') ?></div>
                    <h3><?= sanitize($category) ?></h3>
                    <p>Atendimento para <?= strtolower(sanitize($category)) ?> com profissionais qualificados.</p>
                    <a href="<?= getBasePath() ?>pages/pesquisa.php?categoria=<?= urlencode($category) ?>" class="button button-secondary">Ver profissionais</a>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
