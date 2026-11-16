<?php
require_once __DIR__ . '/../includes/auth.php';
requireVerifiedEmail();

$user = currentUser();
$profileId = isset($_GET['id']) ? (int) $_GET['id'] : (int) $user['id'];
$profileUser = findUserById($profileId);
if (!$profileUser || !canViewProfile($user,$profileUser)) { http_response_code(404); exit('Perfil não encontrado.'); }
$canEdit = ((int) $profileUser['id']) === (int) $user['id'];
$reviewCount = 0;
if (($profileUser['tipo_usuario'] ?? '') === 'prestador') {
    $reviewQuery = getPDO()->prepare('SELECT COUNT(*) FROM avaliacoes WHERE prestador_id = :id');
    $reviewQuery->execute([':id' => (int)$profileUser['id']]);
    $reviewCount = (int)$reviewQuery->fetchColumn();
}
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $data = [
        'nome' => trim((string) ($_POST['nome'] ?? $profileUser['nome'] ?? '')),
        'bio' => trim((string) ($_POST['bio'] ?? $profileUser['bio'] ?? '')),
        'especialidades' => trim((string) ($_POST['especialidades'] ?? $profileUser['especialidades'] ?? '')),
        'servicos_prestados' => trim((string) ($_POST['servicos_prestados'] ?? $profileUser['servicos_prestados'] ?? '')),
    ];

    try {
    $avatarFile = $_FILES['avatar'] ?? null;
    $portfolioFiles = $_FILES['portfolio'] ?? [];
    if (($profileUser['tipo_usuario'] ?? '') === 'sindico' && isset($_FILES['documento_certificado'])) {
        $name = storeUpload($_FILES['documento_certificado'], dirname(__DIR__, 2) . '/backend/storage/certificados', true);
        if ($name !== null) {
            $data['documento_certificado'] = $name;
            $data['certificado_status'] = 'pendente';
            $data['verificado'] = 0;
        }
    }
    $ok = saveUserProfileData((int) $profileUser['id'], $data, $avatarFile, $portfolioFiles);
    if (isset($_POST['two_factor_enabled'])) {
        setTwoFactorEnabled((int) $profileUser['id'], $_POST['two_factor_enabled'] === '1');
    }
    if ($ok) {
        $profileUser = findUserById((int) $profileUser['id']) ?: $profileUser;
        $message = 'Perfil atualizado com sucesso.';
    } else {
        $message = 'Não foi possível atualizar seu perfil agora.';
    }
    } catch (Throwable $e) { $message = friendlyError($e); }
}

$pageTitle = 'Perfil';
$metaDescription = 'Perfil do usuário e suas especialidades no CondoConnect.';
$bodyClass = 'dashboard-page';
require __DIR__ . '/../includes/header.php';

$roleLabel = getUserRoleLabel((string) ($profileUser['tipo_usuario'] ?? 'morador'));
$avatar = profileImageUrl($profileUser);
$portfolio = json_decode((string) ($profileUser['portfolio_imagens'] ?? '[]'), true);
if (!is_array($portfolio)) {
    $portfolio = [];
}
$especialidades = array_filter(array_map('trim', preg_split('/[,;\n]+/', (string) ($profileUser['especialidades'] ?? ''))));
$servicos = array_filter(array_map('trim', preg_split('/[,;\n]+/', (string) ($profileUser['servicos_prestados'] ?? ''))));
?>

<main class="section section-single">
    <div class="container">
        <div class="section-header page-heading">
            <span>Perfil</span>
            <h2><?= sanitize($profileUser['nome']) ?></h2>
            <p><?= sanitize($roleLabel) ?> · <?= sanitize($profileUser['condominio'] ?? '') ?> · <?= $canEdit || $user['tipo_usuario']==='admin' ? sanitize($profileUser['unidade'] ?? '') : '' ?></p>
        </div>

        <?php if (!$canEdit && canUsersChat((int)$user['id'], (int)$profileUser['id'])): ?>
            <form method="post" action="<?= getBasePath() ?>pages/chat.php" class="profile-contact-form">
                <?= csrfField() ?><input type="hidden" name="action" value="open_chat"><input type="hidden" name="usuario_id" value="<?= (int)$profileUser['id'] ?>">
                <button class="button button-primary" type="submit">Iniciar conversa</button>
            </form>
        <?php endif; ?>

        <?php if ($message !== ''): ?>
            <div class="alert alert-info"><?= sanitize($message) ?></div>
        <?php endif; ?>

        <div class="dashboard-grid profile-stats">
            <article class="stats-card">
                <h3>Foto</h3>
                <div class="profile-avatar-wrap">
                    <?php if ($avatar !== ''): ?>
                        <img class="profile-avatar" src="<?= $avatar ?>" alt="Avatar de <?= sanitize($profileUser['nome']) ?>">
                    <?php else: ?>
                        <div class="profile-avatar profile-avatar-fallback">
                            <?= sanitize(mb_strtoupper(mb_substr((string)($profileUser['nome']??'U'),0,1))) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
            <article class="stats-card">
                <h3>Resumo</h3>
                <p><strong>E-mail:</strong> <?= sanitize($profileUser['email'] ?? '') ?></p>
                <p><strong>Telefone:</strong> <?= sanitize($profileUser['telefone'] ?? '') ?></p>
                <p><strong>Avaliação:</strong> <?php if ((float)($profileUser['avaliacao_media'] ?? 0) > 0): ?><?= number_format((float) ($profileUser['avaliacao_media'] ?? 0), 1, ',', '.') ?> / 5<?php else: ?>Sem avaliações<?php endif; ?></p>
                <?php if (($profileUser['tipo_usuario'] ?? '') === 'prestador'): ?><p><strong>Avaliações:</strong> <?= $reviewCount ?></p><?php endif; ?>
            </article>
            <article class="stats-card">
                <h3>Tipo</h3>
                <p><?= sanitize($roleLabel) ?></p>
                <p><strong>Status:</strong> <?= sanitize($profileUser['status'] ?? 'ativo') ?></p>
                <p><strong>Certificado:</strong> <?= sanitize($profileUser['certificado_status'] ?? 'aprovado') ?></p>
            </article>
        </div>

        <div class="panel-grid">
            <section class="panel-card">
                <h3>Bio e especialidades</h3>
                <p><?= !empty($profileUser['bio']) ? nl2br(sanitize($profileUser['bio'])) : 'Nenhuma bio cadastrada ainda.' ?></p>

                <?php if ($especialidades !== []): ?>
                    <div class="profile-tags">
                        <?php foreach ($especialidades as $especialidade): ?>
                            <span class="badge badge-aberto"><?= sanitize($especialidade) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="panel-card">
                <h3>Serviços prestados</h3>
                <?php if ($servicos !== []): ?>
                    <ul>
                        <?php foreach ($servicos as $servico): ?>
                            <li><?= sanitize($servico) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>Nenhum serviço cadastrado.</p>
                <?php endif; ?>
            </section>
        </div>

        <?php if ($portfolio !== []): ?>
            <section class="panel-card profile-section">
                <h3>Portfólio</h3>
                <div class="profile-portfolio">
                    <?php foreach ($portfolio as $item): ?>
                        <?php $path = getBasePath() . 'backend/storage/perfil/' . sanitize((string) $item); ?>
                        <img src="<?= $path ?>" alt="Portfolio">
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($profileUser['tipo_usuario']==='prestador'): ?>
        <section class="panel-card profile-section"><h2>Avaliações de atendimentos</h2>
        <?php $reviews=dbRows('SELECT a.nota,a.comentario,a.criado_em,u.nome FROM avaliacoes a JOIN users u ON u.id=a.morador_id WHERE a.prestador_id=? ORDER BY a.id DESC LIMIT 30',[$profileId]); ?>
        <?php if (!$reviews): ?><p>Este profissional ainda não recebeu avaliações.</p><?php endif; ?>
        <?php foreach($reviews as $review): ?><article class="notice-item"><div><strong><?= (int)$review['nota'] ?> / 5 · <?= sanitize(explode(' ',$review['nome'])[0]) ?></strong><p><?= nl2br(sanitize($review['comentario'])) ?></p><small><?= sanitize(date('d/m/Y',strtotime($review['criado_em']))) ?></small></div></article><?php endforeach; ?>
        </section><?php endif; ?>
        <?php if ($canEdit): ?>
            <section class="panel-card profile-section">
                <h3>Editar perfil</h3>
                <form action="<?= getBasePath() ?>pages/perfil.php" method="post" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <div class="profile-form-grid">
                        <label>
                            <span>Nome</span>
                            <input type="text" name="nome" value="<?= sanitize($profileUser['nome'] ?? '') ?>" required>
                        </label>
                        <label>
                            <span>Verificação em duas etapas</span>
                            <select name="two_factor_enabled">
                                <option value="0" <?= (int) ($profileUser['two_factor_enabled'] ?? 0) === 0 ? 'selected' : '' ?>>Desativada</option>
                                <option value="1" <?= (int) ($profileUser['two_factor_enabled'] ?? 0) === 1 ? 'selected' : '' ?>>Ativada (código por e-mail)</option>
                            </select>
                        </label>
                    </div>

                    <label>
                        <span>Bio</span>
                        <textarea name="bio" rows="4" placeholder="Conte um pouco sobre você..."><?= sanitize((string) ($profileUser['bio'] ?? '')) ?></textarea>
                    </label>

                    <div class="profile-form-grid">
                        <label>
                            <span>Especialidades</span>
                            <input type="text" name="especialidades" value="<?= sanitize((string) ($profileUser['especialidades'] ?? '')) ?>" placeholder="Elétrica, Hidráulica, Segurança">
                        </label>
                        <label>
                            <span>Serviços prestados</span>
                            <input type="text" name="servicos_prestados" value="<?= sanitize((string) ($profileUser['servicos_prestados'] ?? '')) ?>" placeholder="Instalações, manutenção, reformas">
                        </label>
                    </div>

                    <div class="profile-form-grid">
                        <label>
                            <span>Avatar</span>
                            <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp">
                        </label>
                        <label>
                            <span>Portfólio (imagens)</span>
                            <input type="file" name="portfolio[]" accept="image/png,image/jpeg,image/webp" multiple>
                        </label>
                    </div>

                    <?php if (($profileUser['tipo_usuario'] ?? '') === 'sindico'): ?>
                        <label class="profile-certificate-field">
                            <span>Atualizar comprovante / certificado</span>
                            <input type="file" name="documento_certificado" accept=".pdf,.png,.jpg,.jpeg">
                        </label>
                    <?php endif; ?>

                    <button type="submit" class="button button-primary">Salvar perfil</button>
                </form>
            </section>
        <?php endif; ?>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
