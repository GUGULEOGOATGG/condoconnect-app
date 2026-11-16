<?php
require_once __DIR__ . '/../includes/auth.php';
$user = requireAdmin();
$filter = trim((string) ($_GET['filtro'] ?? ''));
$users = listUsersForAdmin($filter);
$pageTitle = 'Usuários';
$metaDescription = 'Consulta administrativa de usuários.';
$bodyClass = 'dashboard-page';
require __DIR__ . '/../includes/header.php';
?>
<main class="section section-single">
    <div class="container">
        <div class="section-header page-heading">
            <span>Administração</span>
            <h2>Usuários cadastrados</h2>
            <p>Consulte contas, perfis, status e situação de verificação sem alterar a identidade de nenhuma conta.</p>
        </div>
        <div class="panel-card search-panel">
            <form method="get" action="<?= getBasePath() ?>pages/admin-usuarios.php" class="search-form">
                <label>
                    <span>Filtrar por nome, e-mail, tipo ou status</span>
                    <input type="search" name="filtro" value="<?= sanitize($filter) ?>" placeholder="Ex.: admin, síndico, ativo">
                </label>
                <button class="button button-primary" type="submit">Filtrar</button>
            </form>
        </div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>ID</th><th>Nome</th><th>E-mail</th><th>Tipo</th><th>Status</th><th>E-mail</th><th>Síndico</th></tr></thead>
                <tbody>
                <?php foreach ($users as $item): ?>
                    <tr>
                        <td data-label="ID"><?= (int) $item['id'] ?></td>
                        <td data-label="Nome"><?= sanitize($item['nome']) ?></td>
                        <td data-label="E-mail"><?= sanitize($item['email']) ?></td>
                        <td data-label="Tipo"><?= sanitize(getUserRoleLabel((string) $item['tipo_usuario'])) ?></td>
                        <td data-label="Status"><?= sanitize($item['status']) ?></td>
                        <td data-label="Verificação de e-mail"><?= (int) $item['email_verificado'] === 1 ? 'Verificado' : 'Pendente' ?></td>
                        <td data-label="Certificado do síndico"><?= $item['tipo_usuario'] === 'sindico' ? sanitize($item['certificado_status']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($users === []): ?><div class="empty-state"><p>Nenhum usuário encontrado.</p></div><?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
