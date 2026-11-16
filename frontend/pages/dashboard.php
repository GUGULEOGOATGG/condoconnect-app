<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$pageTitle = 'Meu espaço'; $metaDescription = 'Painel principal CondoConnect.'; $bodyClass = 'dashboard-page';
$user = currentUser();
if (($user['tipo_usuario'] ?? '') === 'admin') redirect(getBasePath() . 'pages/admin-dashboard.php');
$authNotice = (string)($_SESSION['auth_notice'] ?? ''); unset($_SESSION['auth_notice']);
$ticketNotice = (string)($_SESSION['ticket_notice'] ?? ''); unset($_SESSION['ticket_notice']);
$chatNotice = (string)($_SESSION['chat_error'] ?? ''); unset($_SESSION['chat_error']);
$stats = getUserRoleStats((int)$user['id']);
$chamados = getVisibleChamadosForUser((int)$user['id']);
$roleLabel = getUserRoleLabel((string)($user['tipo_usuario'] ?? 'morador'));
$role = (string)($user['tipo_usuario'] ?? 'morador');
require __DIR__ . '/../includes/header.php';
?>
<main class="section section-single"><div class="container">
    <div class="page-title-row"><div class="section-header page-heading"><span>MEU ESPAÇO · <?= sanitize($roleLabel) ?></span><h1>Olá, <?= sanitize($user['nome']) ?>!</h1><p>Que bom ter você por aqui. Vamos cuidar do que importa?</p></div><?php if ($role === 'morador'): ?><a class="button button-primary" href="<?= getBasePath() ?>pages/novo-chamado.php"><?= condoIcon('ticket') ?> Novo chamado</a><?php endif; ?></div>
    <?php foreach ([$authNotice,$ticketNotice,$chatNotice] as $notice): if ($notice !== ''): ?><div class="alert alert-info"><?= sanitize($notice) ?></div><?php endif; endforeach; ?>
    <?php if ((int)($user['email_verificado'] ?? 1) !== 1): ?><div class="empty-state"><h3>Confirme seu e-mail</h3><p>Confirme seu endereço de e-mail para liberar os recursos da conta.</p></div>
    <?php elseif ($role === 'sindico' && (int)($user['verificado'] ?? 0) !== 1): ?><div class="empty-state"><h3>Verificação pendente</h3><p>Chamados e recursos administrativos serão liberados após a verificação do certificado.</p></div><?php endif; ?>
    <?php if ((int)($user['email_verificado'] ?? 1) === 1): ?>
    <section class="welcome-banner"><div><span class="eyebrow">CADA CONEXÃO FAZ A DIFERENÇA</span><h2><?= $role === 'prestador' ? 'Seu trabalho transforma o dia de alguém.' : 'Uma rotina mais leve começa aqui.' ?></h2><p><?= $role === 'prestador' ? 'Encontre oportunidades, acompanhe seus atendimentos e esteja perto de quem precisa de você.' : 'Acompanhe os chamados, converse com a comunidade e mantenha tudo em dia, em um só lugar.' ?></p></div><span class="welcome-banner__icon" aria-hidden="true"><?= condoIcon('building') ?></span></section>
    <div class="dashboard-grid">
        <article class="stats-card"><span class="stat-icon"><?= condoIcon('ticket') ?></span><h3><?= $role === 'prestador' ? 'Disponíveis' : 'Total de chamados' ?></h3><p><?= $role === 'morador' ? 'Vinculados à sua conta' : 'Que você pode acessar' ?></p><strong><?= (int)$stats['total'] ?></strong></article>
        <article class="stats-card"><span class="stat-icon stat-warning"><?= condoIcon('clock') ?></span><h3>Aguardando</h3><p>Chamados abertos</p><strong><?= (int)$stats['abertos'] ?></strong></article>
        <article class="stats-card"><span class="stat-icon stat-blue"><?= condoIcon('activity') ?></span><h3>Em andamento</h3><p>Atendimentos ativos</p><strong><?= (int)$stats['andamento'] ?></strong></article>
        <article class="stats-card"><span class="stat-icon stat-green"><?= condoIcon('check') ?></span><h3>Concluídos</h3><p>Serviços finalizados</p><strong><?= (int)$stats['concluidos'] ?></strong></article>
    </div>
    <div class="panel-grid">
        <section class="panel-card"><div class="panel-title-row"><div><h2><?= $role === 'prestador' ? 'Chamados disponíveis' : ($role === 'sindico' ? 'Chamados do seu condomínio' : 'Meus chamados recentes') ?></h2><p>Acompanhe atualizações e acesse os detalhes.</p></div><a class="button button-secondary" href="<?= getBasePath() ?>pages/meus-chamados.php">Ver todos</a></div>
            <?php if ($chamados === []): ?><div class="empty-state compact"><p><?= $role === 'prestador' ? 'Nenhum chamado disponível para atendimento agora.' : ($role === 'sindico' ? 'Não há chamados visíveis para este condomínio.' : 'Você ainda não abriu chamados.') ?></p><?php if ($role === 'morador'): ?><a class="button button-primary" href="<?= getBasePath() ?>pages/novo-chamado.php">Criar chamado</a><?php endif; ?></div>
            <?php else: ?><div class="table-wrapper"><table><thead><tr><th>Protocolo</th><th>Categoria</th><th>Status</th><th>Prioridade</th><th>Detalhes</th><?php if ($role === 'prestador'): ?><th>Ação</th><?php endif; ?><?php if ($role === 'morador'): ?><th>Avaliação</th><?php endif; ?><?php if ($role === 'sindico'): ?><th>Conversa</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($chamados as $chamado): $status = (string)($chamado['status'] ?? 'aberto'); ?>
                <tr><td data-label="Protocolo"><?= sanitize($chamado['protocolo']) ?></td><td data-label="Categoria"><?= sanitize($chamado['categoria']) ?></td><td data-label="Status"><span class="badge badge-<?= sanitize($status) ?>"><?= sanitize(ucfirst($status)) ?></span></td><td data-label="Prioridade"><?= sanitize($chamado['prioridade']) ?></td><td data-label="Detalhes"><a class="table-link" href="<?= getBasePath() ?>pages/detalhes-chamado.php?id=<?= (int)$chamado['id'] ?>">Abrir</a></td>
                <?php if ($role === 'prestador'): ?><td data-label="Ação"><?php if ($status === 'aberto' && empty($chamado['prestador_id'])): ?><form method="post" action="<?= getBasePath() ?>pages/aceitar-chamado.php"><?= csrfField() ?><input type="hidden" name="chamado_id" value="<?= (int)$chamado['id'] ?>"><button class="button button-primary" type="submit">Aceitar</button></form><?php elseif ((int)($chamado['prestador_id'] ?? 0) === (int)$user['id'] && $status === 'andamento'): ?><form method="post" action="<?= getBasePath() ?>pages/atualizar-chamado.php"><?= csrfField() ?><input type="hidden" name="chamado_id" value="<?= (int)$chamado['id'] ?>"><input type="hidden" name="status" value="concluido"><button class="button button-secondary" type="submit">Concluir</button></form><?php else: ?>—<?php endif; ?></td><?php endif; ?>
                <?php if ($role === 'morador'): ?><td data-label="Avaliação"><?php if ($status === 'concluido' && !empty($chamado['prestador_id'])): ?><a class="button button-secondary" href="<?= getBasePath() ?>pages/avaliar-chamado.php?id=<?= (int)$chamado['id'] ?>">Avaliar</a><?php else: ?>—<?php endif; ?></td><?php endif; ?>
                <?php if ($role === 'sindico'): ?><td data-label="Conversa"><?php if (!empty($chamado['prestador_id']) && in_array($status,['andamento','concluido'],true)): ?><form method="post" action="<?= getBasePath() ?>pages/entrar-chat-chamado.php"><?= csrfField() ?><input type="hidden" name="chamado_id" value="<?= (int)$chamado['id'] ?>"><button class="button button-secondary" type="submit">Acompanhar</button></form><?php else: ?>—<?php endif; ?></td><?php endif; ?></tr>
            <?php endforeach; ?></tbody></table></div><?php endif; ?>
        </section>
        <aside class="dashboard-side"><section class="panel-card assistant-widget"><div class="icon">✳</div><h2>Assistente do condomínio</h2><p>Tem alguma dúvida? Peça ajuda sobre chamados e uso da plataforma.</p><form id="dashboard-assistant-form"><label class="visually-hidden" for="dashboard-assistant-question">Sua pergunta</label><input id="dashboard-assistant-question" type="text" maxlength="2000" placeholder="Digite sua mensagem..." required><button class="button button-primary" type="submit">Enviar</button></form><div id="dashboard-assistant-response" class="assistant-inline-response" aria-live="polite"></div><div class="assistant-prompts"><button type="button" data-assistant-prompt="Como abrir um chamado?">Como abrir um chamado?</button><button type="button" data-assistant-prompt="Como encontro um prestador?">Como encontrar prestadores?</button></div><a class="assistant-more" href="<?= getBasePath() ?>pages/assistente.php">Abrir assistente completo →</a></section>
        <section class="panel-card"><h2>Acesso rápido</h2><div class="quick-links"><a href="<?= getBasePath() ?>pages/chat.php">Conversas <span>→</span></a><a href="<?= getBasePath() ?>pages/notificacoes.php">Notificações <span>→</span></a><a href="<?= getBasePath() ?>pages/pesquisa.php">Buscar prestadores <span>→</span></a></div></section></aside>
    </div><?php endif; ?>
</div></main>
<script>
(() => {
    const form = document.getElementById('dashboard-assistant-form');
    const input = document.getElementById('dashboard-assistant-question');
    const output = document.getElementById('dashboard-assistant-response');
    if (!form || !input || !output) return;
    document.querySelectorAll('[data-assistant-prompt]').forEach(button => button.addEventListener('click', () => { input.value = button.dataset.assistantPrompt || ''; input.focus(); }));
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const question = input.value.trim();
        if (!question) return;
        output.textContent = 'Consultando o assistente...';
        try {
            const response = await fetch('<?= getBasePath() ?>api/openai.php', {method:'POST',headers:{'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({question})});
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Não foi possível consultar o assistente.');
            output.textContent = payload.message || 'Sem resposta disponível.';
        } catch (error) { output.textContent = error.message || 'Não foi possível consultar o assistente.'; }
    });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
