<?php
require_once __DIR__ . '/auth.php';
$basePath = getBasePath();
$user = currentUser();
$loggedIn = isLoggedIn();
$isAdmin = $loggedIn && isAdminUser();
$isVerified = $loggedIn && (int) ($user['email_verificado'] ?? 1) === 1;
$showSidebar = $loggedIn && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['verificar-email.php'], true);
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
function condoIcon(string $name): string {
    $shapes = [
        'home'=>'<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-6v-7h-4v7H4a1 1 0 0 1-1-1z"/>',
        'ticket'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M8 9h8M8 13h5"/>',
        'notice'=>'<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/><path d="M8 7h8M8 11h8"/>',
        'bill'=>'<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'search'=>'<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'building'=>'<path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"/><path d="M8 9v.01M8 12v.01M8 15v.01M8 18v.01M16 14v.01M16 17v.01"/>',
        'calendar'=>'<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'user'=>'<circle cx="12" cy="8" r="4"/><path d="M5 21a7 7 0 0 1 14 0"/>',
        'chat'=>'<path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8z"/>',
        'assistant'=>'<path d="m12 3 1.9 5.8L20 11l-6.1 2.2L12 19l-1.9-5.8L4 11l6.1-2.2L12 3Z"/><path d="m19 14 1.2 2.8L23 18l-2.8 1.2L19 22l-1.2-2.8L15 18l2.8-1.2L19 14Z"/>',
        'admin'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'check'=>'<path d="m5 12 4 4L19 6"/>',
        'arrow'=>'<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
        'close'=>'<path d="m6 6 12 12M6 18 18 6"/>',
        'bell'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'activity'=>'<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'shield'=>'<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6z"/><path d="m8 12 3 3 5-6"/>',
        'bolt'=>'<path d="m13 2-9 12h7l-1 8 10-12h-7z"/>',
        'drop'=>'<path d="M12 3s-7 8-7 12a7 7 0 0 0 14 0c0-4-7-12-7-12Z"/><path d="M8 15a4 4 0 0 0 4 4"/>',
        'paint'=>'<rect x="3" y="3" width="14" height="6" rx="2"/><path d="M17 6h3v7h-9v3"/><rect x="9" y="16" width="4" height="6" rx="1"/>',
        'clean'=>'<path d="m15 3-5 11M8 12l7 3-3 7-9-4zM6 17l6 3m5-10 1-3 3-1-3-1-1-3-1 3-3 1 3 1z"/>',
        'lift'=>'<rect x="4" y="3" width="16" height="18" rx="2"/><path d="m7 10 2-3 2 3M9 7v10m4-3 2 3 2-3M15 7v10"/>',
        'leaf'=>'<path d="M20 3C9 2 3 7 4 14c1 7 9 8 13 3 3-4 3-9 3-14ZM4 21 15 10"/>',
        'tool'=>'<path d="m14 6 4-4a6 6 0 0 1-7 8L4 17a2 2 0 1 0 3 3l7-7a6 6 0 0 0 8-7l-4 4z"/>',
        'exit'=>'<path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/>'
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ($shapes[$name] ?? $shapes['ticket']) . '</svg>';
}
$navItems = [
    ['dashboard.php', 'Início', 'home'],
    ['meus-chamados.php', 'Meus chamados', 'ticket'],
    ['comunicados.php', 'Comunicados', 'notice'],
    ['pagamentos.php', 'Boletos', 'bill'],
    ['pesquisa.php', 'Prestadores', 'search'],
    ['condominio.php', 'Condomínio', 'building'],
    ['reservas.php', 'Reservas', 'calendar'],
    ['perfil.php', 'Perfil', 'user'],
];
?>
<?php if ($loggedIn && $showSidebar): ?>
<aside id="app-sidebar" class="app-header" aria-label="Navegação principal">
    <button class="sidebar-close" type="button" aria-label="Fechar menu"><?= condoIcon('close') ?></button>
    <a class="brand" href="<?= $basePath ?>pages/dashboard.php" aria-label="CondoConnect início">
        <span class="brand-mark" aria-hidden="true"><?= condoIcon('home') ?></span>
        <span class="brand-copy"><strong>CondoConnect<span class="brand-dot">.</span></strong><small>Viver bem começa aqui</small></span>
    </a>
    <nav>
        <span class="nav-section-title">SEU ESPAÇO</span>
        <?php foreach ($navItems as [$file, $label, $icon]): ?>
            <a class="<?= $currentPage === $file ? 'active' : '' ?>" <?= $currentPage === $file ? 'aria-current="page"' : '' ?> href="<?= $basePath ?>pages/<?= $file ?>" title="<?= sanitize($label) ?>"><span class="nav-icon"><?= condoIcon($icon) ?></span><span class="nav-label"><?= sanitize($label) ?></span></a>
        <?php endforeach; ?>
        <a class="<?= $currentPage === 'chat.php' ? 'active' : '' ?>" href="<?= $basePath ?>pages/chat.php"><span class="nav-icon"><?= condoIcon('chat') ?></span><span class="nav-label">Conversas</span><span class="chat-unread-count" hidden></span></a>
        <?php if ($isVerified): ?><a class="<?= $currentPage === 'assistente.php' ? 'active' : '' ?>" href="<?= $basePath ?>pages/assistente.php"><span class="nav-icon"><?= condoIcon('assistant') ?></span><span class="nav-label">Assistente</span></a><?php endif; ?>
        <?php if ($isAdmin): ?>
            <span class="nav-section-title">ADMINISTRAÇÃO</span>
            <a class="<?= $currentPage === 'admin-dashboard.php' ? 'active' : '' ?>" href="<?= $basePath ?>pages/admin-dashboard.php"><span class="nav-icon"><?= condoIcon('admin') ?></span><span class="nav-label">Painel</span></a>
            <a class="<?= $currentPage === 'admin-usuarios.php' ? 'active' : '' ?>" href="<?= $basePath ?>pages/admin-usuarios.php"><span class="nav-icon"><?= condoIcon('user') ?></span><span class="nav-label">Usuários</span></a>
            <a class="<?= $currentPage === 'admin-verificacoes.php' ? 'active' : '' ?>" href="<?= $basePath ?>pages/admin-verificacoes.php"><span class="nav-icon"><?= condoIcon('check') ?></span><span class="nav-label">Verificações</span></a>
        <?php endif; ?>
        <form method="post" action="<?= $basePath ?>logout.php"><?= csrfField() ?><button type="submit" class="logout-link" aria-label="Sair da conta"><span class="nav-icon"><?= condoIcon('exit') ?></span><span class="nav-label">Sair</span></button></form>
    </nav>
    <div class="sidebar-note"><?= condoIcon('building') ?><p>Mais conexão.<br><strong>Mais qualidade de vida.</strong></p></div>
</aside>
<button class="sidebar-backdrop" type="button" aria-label="Fechar menu" hidden></button>
<?php elseif (!$loggedIn): ?>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="<?= $basePath ?>index.php"><span class="brand-mark"><?= condoIcon('building') ?></span><span><strong>CondoConnect<span class="brand-dot">.</span></strong></span></a>
        <button class="menu-toggle" type="button" aria-controls="public-navigation" aria-expanded="false" aria-label="Abrir navegação"><?= condoIcon('menu') ?></button>
        <nav id="public-navigation" class="main-nav" aria-label="Navegação principal">
            <?php foreach ([['index.php','Início'], ['pages/sobre.php','Sobre'], ['pages/servicos.php','Serviços'], ['pages/faq.php','Dúvidas'], ['pages/contato.php','Contato']] as [$href, $label]): ?>
            <a href="<?= $basePath . $href ?>" <?= $currentPage === basename($href) ? 'aria-current="page"' : '' ?>><?= $label ?></a>
            <?php endforeach; ?>
            <a class="nav-login" href="<?= $basePath ?>pages/login.php">Entrar</a><a href="<?= $basePath ?>pages/cadastro.php" class="button button-primary">Começar agora <?= condoIcon('arrow') ?></a>
        </nav>
    </div>
</header>
<?php endif; ?>
