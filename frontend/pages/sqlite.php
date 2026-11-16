<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Banco de dados';
require __DIR__ . '/../includes/header.php';
?>
<main class="container"><h1>Banco MySQL</h1><p>Esta versão usa MySQL. A consulta administrativa das tabelas deve ser feita pelo phpMyAdmin ou cliente MySQL.</p></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
