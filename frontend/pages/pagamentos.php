<?php
require_once __DIR__ . '/../includes/auth.php';
requireVerifiedEmail();
$pageTitle = 'Boletos'; $bodyClass = 'dashboard-page';
require __DIR__ . '/../includes/header.php';
?>
<main class="section section-single"><div class="container">
    <div class="section-header page-heading"><span>Financeiro</span><h1>Meus boletos</h1><p>Consulte cobranças vinculadas à sua unidade.</p></div>
    <div class="empty-state"><div class="icon icon-orange">▧</div><h2>Nenhum boleto disponível</h2><p>O sistema atual ainda não possui registros de boletos associados às unidades. Quando houver uma integração financeira ativa e cobranças cadastradas, elas serão exibidas aqui.</p></div>
</div></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
