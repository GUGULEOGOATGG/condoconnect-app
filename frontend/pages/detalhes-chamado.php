<?php
require_once __DIR__ . '/../includes/auth.php';
requireVerifiedEmail();
$user = currentUser();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$visible = getVisibleChamadosForUser((int)$user['id']);
$ticket = null;
foreach ($visible as $candidate) if ((int)$candidate['id'] === $id) { $ticket = $candidate; break; }
if (!$ticket) { http_response_code(404); $ticket = null; }
$pageTitle = 'Detalhes do chamado'; $bodyClass = 'dashboard-page';
$serviceError='';
if ($ticket && $_SERVER['REQUEST_METHOD']==='POST') {
    try {
        $action=(string)($_POST['service_action']??''); $actor=(int)$user['id'];
        switch($action) {
            case 'responder': saveTicketProposal($actor,$id,$_POST); break;
            case 'selecionar': selectTicketProvider($actor,$id,filter_input(INPUT_POST,'prestador_id',FILTER_VALIDATE_INT)?:0); break;
            case 'assumir': acceptChamado($id,$actor); break;
            case 'concluir': case 'cancelar':
                if(!updateChamadoStatus($id,$actor,$action==='concluir'?'concluido':'cancelado')) throw new DomainException('Alteração não permitida.'); break;
            default: updateTicketService($actor,$id,$action,$_POST);
        }
        flashMessage('Atendimento atualizado. Os envolvidos foram notificados.');
        redirect(getBasePath().'pages/detalhes-chamado.php?id='.$id);
    } catch(Throwable $e) { $serviceError=friendlyError($e); }
}
if ($ticket) {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT a.id, a.arquivo, a.criado_em FROM anexos a WHERE a.chamado_id = :id ORDER BY a.criado_em');
    $stmt->execute([':id'=>$id]); $attachments = $stmt->fetchAll();
    $messages = listMessagesForChamado($id);
    $historyStmt = $pdo->prepare('SELECT h.*, u.nome AS usuario_nome FROM chamado_historico h LEFT JOIN users u ON u.id=h.usuario_id WHERE h.chamado_id=:id ORDER BY h.criado_em');
    $historyStmt->execute([':id'=>$id]); $history = $historyStmt->fetchAll();
    $relatedChats = array_values(array_filter(listUserChats((int)$user['id']), static fn($chat) => (int)($chat['chamado_id'] ?? 0) === $id));
}
require __DIR__ . '/../includes/header.php';
?>
<main class="section section-single"><div class="container">
<?php if($serviceError): ?><div class="alert alert-error" role="alert"><?= sanitize($serviceError) ?></div><?php endif; ?>
<?php if (!$ticket): ?><div class="empty-state"><h1>Chamado não encontrado</h1><p>Este registro não existe ou sua conta não tem acesso a ele.</p><a class="button button-primary" href="<?= getBasePath() ?>pages/meus-chamados.php">Voltar aos chamados</a></div>
<?php else: ?>
    <div class="section-header page-heading"><a class="back-link" href="<?= getBasePath() ?>pages/meus-chamados.php">← Meus chamados</a><div class="page-title-row"><div><span><?= sanitize($ticket['protocolo']) ?></span><h1><?= sanitize($ticket['titulo']) ?></h1></div><span class="badge badge-<?= sanitize($ticket['status']) ?>"><?= sanitize(ucfirst($ticket['status'])) ?></span></div></div>
    <div class="panel-grid details-grid"><section class="panel-card"><h2>Detalhes</h2><dl class="detail-list"><div><dt>Categoria</dt><dd><?= sanitize($ticket['categoria']) ?></dd></div><div><dt>Descrição</dt><dd><?= nl2br(sanitize($ticket['descricao'])) ?></dd></div><div><dt>Local</dt><dd><?= sanitize($ticket['local_problema']) ?></dd></div><div><dt>Prioridade</dt><dd><?= sanitize($ticket['prioridade']) ?></dd></div><div><dt>Data solicitada</dt><dd><?= sanitize(date('d/m/Y', strtotime((string)$ticket['data_servico']))) ?></dd></div><div><dt>Criado em</dt><dd><?= sanitize(date('d/m/Y H:i', strtotime((string)$ticket['criado_em']))) ?></dd></div></dl>
        <?php if ($attachments): ?><h3>Anexos</h3><div class="attachment-list"><?php foreach($attachments as $attachment): ?><a class="button button-secondary" href="<?= getBasePath() ?>api/anexo.php?id=<?= (int)$attachment['id'] ?>" target="_blank" rel="noopener">Visualizar anexo</a><?php endforeach; ?></div><?php endif; ?>
        <?php if ($relatedChats): ?><h3>Conversa do atendimento</h3><a class="button button-primary" href="<?= getBasePath() ?>pages/chat.php?chat=<?= (int)$relatedChats[0]['id'] ?>">Conversar</a><?php endif; ?>
        <?php if (($user['tipo_usuario'] ?? '') === 'morador' && $ticket['status'] === 'concluido' && !empty($ticket['prestador_id'])): ?><a class="button button-secondary" href="<?= getBasePath() ?>pages/avaliar-chamado.php?id=<?= (int)$id ?>">Avaliar atendimento</a><?php endif; ?>
    </section><aside class="panel-card"><h2>Histórico</h2><?php if (!$history): ?><p>O histórico será exibido conforme o chamado for atualizado.</p><?php else: ?><ol class="timeline"><?php foreach($history as $event): ?><li><strong><?= sanitize(ucfirst((string)$event['status_novo'])) ?></strong><span><?= sanitize($event['observacao'] ?: 'Atualização do chamado') ?></span><small><?= sanitize($event['usuario_nome'] ?? 'Sistema') ?> · <?= sanitize(date('d/m/Y H:i',strtotime((string)$event['criado_em']))) ?></small></li><?php endforeach; ?></ol><?php endif; ?></aside></div>
    <?php require __DIR__.'/../includes/ticket-service.php'; ?>
    <?php if ($messages): ?><section class="panel-card ticket-message-history"><h2>Mensagens do chamado</h2><div class="notice-list"><?php foreach($messages as $message): ?><article class="notice-item"><div><strong><?= sanitize($message['remetente']) ?></strong><p><?= nl2br(sanitize($message['mensagem'])) ?></p><small><?= sanitize(date('d/m/Y H:i',strtotime((string)$message['criado_em']))) ?></small></div></article><?php endforeach; ?></div></section><?php endif; ?>
<?php endif; ?>
</div></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
