<?php
require_once __DIR__ . '/auth.php';
$pageTitle = $pageTitle ?? 'CondoConnect';
$metaDescription = $metaDescription ?? 'Plataforma para solicitação e acompanhamento de serviços em condomínios.';
$bodyClass = $bodyClass ?? '';
$basePath = getBasePath();
$apiBase = rtrim($basePath, '/') . '/api';
$loggedIn = isLoggedIn();
$user = $loggedIn ? currentUser() : null;
$showSidebar = $loggedIn && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['verificar-email.php'], true);
$unreadNotifications = 0;
if ($showSidebar && $user) {
    $countStmt = getPDO()->prepare('SELECT COUNT(*) FROM notificacoes WHERE usuario_id = :usuario AND lida = 0'); $countStmt->execute([':usuario' => (int)$user['id']]); $unreadNotifications = (int)$countStmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?> | CondoConnect</title><meta name="description" content="<?= sanitize($metaDescription) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <meta name="theme-color" content="#0F2A47">
    <link rel="icon" type="image/svg+xml" href="<?= sanitize($basePath . 'frontend/assets/images/favicon.svg?v=20261108-blue') ?>">
    <link rel="stylesheet" href="<?= sanitize($basePath . 'frontend/assets/css/condoconnect.css?v=20261108-images') ?>">
    <link rel="stylesheet" href="<?= sanitize($basePath . 'frontend/assets/css/design.css?v=20261108-panels') ?>">
    <meta name="csrf-token" content="<?= sanitize(csrfToken()) ?>">
</head>
<body class="<?= sanitize($bodyClass) ?> <?= $loggedIn ? 'is-logged-in' : 'is-guest' ?> <?= $showSidebar ? 'has-sidebar' : 'no-sidebar' ?>" data-api-base="<?= sanitize($apiBase) ?>">
<?php include __DIR__ . '/navbar.php'; ?>
<?php if ($showSidebar): ?>
<?php $notificationState=dbRow('SELECT COALESCE(MAX(id),0) AS ultimo FROM notificacoes WHERE usuario_id=?',[(int)$user['id']]); ?>
<div id="toast-region" class="toast-region" aria-live="polite" data-user-id="<?= (int)$user['id'] ?>" data-notification-cursor="<?= (int)$notificationState['ultimo'] ?>"></div>
<?php if (!empty($_SESSION['flash_message'])): ?><div class="flash-banner" role="status"><?= sanitize($_SESSION['flash_message']) ?></div><?php unset($_SESSION['flash_message']); endif; ?>
<div class="workspace-topbar" aria-label="Barra superior do sistema"><div class="workspace-topbar__inner">
    <button type="button" class="sidebar-toggle" aria-controls="app-sidebar" aria-expanded="true" aria-label="Recolher menu" title="Expandir ou recolher menu"><?= condoIcon('menu') ?></button>
    <a class="topbar-home" href="<?= $basePath ?>pages/dashboard.php" aria-label="Início"><?= condoIcon('home') ?></a>
    <form class="topbar-search" action="<?= $basePath ?>pages/pesquisa.php" method="get"><label class="visually-hidden" for="global-provider-search">Buscar prestadores</label><input id="global-provider-search" name="categoria" type="search" placeholder="Encontre um prestador..."><button type="submit" aria-label="Pesquisar"><?= condoIcon('search') ?></button></form>
    <div class="workspace-topbar__actions">
    <a class="notification-link" href="<?= $basePath ?>pages/notificacoes.php" aria-label="Notificações"><?= condoIcon('bell') ?><?php if ($unreadNotifications > 0): ?><span class="notification-count"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span><?php else: ?><span class="notification-count" hidden>0</span><?php endif; ?></a>
        <?php $topAvatar = trim((string) ($user['avatar'] ?? '')); ?>
        <a class="user-chip" href="<?= $basePath ?>pages/perfil.php">
            <?php if ($topAvatar !== ''): ?><img class="user-chip__avatar" src="<?= sanitize(profileImageUrl($user)) ?>" alt="">
            <?php else: ?><span class="user-chip__avatar"><?= sanitize(mb_strtoupper(mb_substr((string)($user['nome'] ?? 'C'), 0, 1, 'UTF-8'), 'UTF-8')) ?></span><?php endif; ?>
            <span><strong><?= sanitize($user['nome'] ?? 'CondoConnect') ?></strong><small><?= sanitize(getUserRoleLabel((string) ($user['tipo_usuario'] ?? 'morador'))) ?><?= !empty($user['unidade']) ? ' · ' . sanitize($user['unidade']) : '' ?></small></span><span aria-hidden="true">⌄</span>
        </a>
    </div>
</div></div>
<nav class="mobile-bottom-nav" aria-label="Navegação móvel"><a href="<?= $basePath ?>pages/dashboard.php"><?= condoIcon('home') ?><span>Início</span></a><a href="<?= $basePath ?>pages/meus-chamados.php"><?= condoIcon('ticket') ?><span>Chamados</span></a><a href="<?= $basePath ?>pages/chat.php"><?= condoIcon('chat') ?><span>Conversas</span></a><a href="<?= $basePath ?>pages/perfil.php"><?= condoIcon('user') ?><span>Perfil</span></a></nav>
<?php endif; ?>
