<?php
$basePath = getBasePath();
?>
<footer class="site-footer">
    <div class="container footer-inner">
        <div>
            <div class="footer-brand">
                <span class="brand-mark"><?= condoIcon('building') ?></span>
                <div>
                    <strong>CondoConnect</strong>
                    <p>Mais conexão. Uma vida em condomínio melhor.</p>
                </div>
            </div>
            <p class="footer-copy">2026 © CondoConnect. Projeto acadêmico de TCC.</p>
        </div>
        <nav class="footer-nav" aria-label="Rodapé">
            <a href="<?= $basePath ?>index.php">Início</a>
            <a href="<?= $basePath ?>pages/sobre.php">Sobre</a>
            <a href="<?= $basePath ?>pages/servicos.php">Serviços</a>
            <a href="<?= $basePath ?>pages/contato.php">Contato</a>
            <a href="<?= $basePath ?>pages/faq.php">FAQ</a>
        </nav>
    </div>
</footer>
<script src="<?= $basePath ?>frontend/assets/js/main.js?v=20261108" defer></script>
</body>
</html>
