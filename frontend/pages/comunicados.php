<?php
require_once __DIR__.'/../includes/auth.php'; requireVerifiedEmail();
$user=currentUser(); $uid=(int)$user['id']; $cond=communityCondominioId($uid,isset($_GET['condominio_id'])?(int)$_GET['condominio_id']:null);
$manager=canManageCommunity($uid,$cond); $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        $id=filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT)?:0;
        switch($_POST['action']??'') {
            case 'salvar': saveComunicado($uid,$cond,$_POST,$id); flashMessage('Comunicado salvo.'); break;
            case 'excluir': deleteComunicado($uid,$id); flashMessage('Comunicado excluído. Histórico preservado.'); break;
            case 'ler': readComunicado($uid,$id); flashMessage('Leitura registrada.'); break;
            default: throw new DomainException('Ação inválida.');
        }
        redirect(getBasePath().'pages/comunicados.php?condominio_id='.$cond);
    } catch(Throwable $e) { $error=friendlyError($e); }
}
$items=listComunicados($uid,$cond); $edit=null; $editId=filter_input(INPUT_GET,'editar',FILTER_VALIDATE_INT)?:0;
if ($editId && $manager) foreach($items as $item) if ((int)$item['id']===$editId) $edit=$item;
if ($editId && !$edit) { http_response_code(404); $error='Comunicado indisponível.'; }
$pageTitle='Comunicados'; $bodyClass='dashboard-page'; require __DIR__.'/../includes/header.php'; ?>
<main class="section section-single"><div class="container"><div class="page-title-row"><div class="section-header"><span>Seu condomínio</span><h1>Comunicados</h1><p>Informações da administração, com publicação e leitura registradas.</p></div><?php if($manager): ?><a class="button button-primary" href="#editor-comunicado">Novo comunicado</a><?php endif; ?></div>
<?php require __DIR__.'/../includes/community-selector.php'; ?>
<?php if($error): ?><div class="alert alert-error" role="alert"><?= sanitize($error) ?></div><?php endif; ?>
<?php if(!$items): ?><div class="empty-state"><h2>Nenhum comunicado publicado</h2><p>Os avisos do seu condomínio aparecerão aqui.</p></div><?php endif; ?>
<div class="notice-list"><?php foreach($items as $item): ?><article class="panel-card <?= !$item['lido_em'] && $item['status']==='publicado'?'notice-unread':'' ?>"><div class="panel-title-row"><h2><?= sanitize($item['titulo']) ?></h2><span class="badge"><?= !$item['lido_em'] && $item['status']==='publicado'?'Novo':sanitize($item['status']) ?></span></div><p><?= sanitize($item['autor_nome']) ?> · <?= sanitize(date('d/m/Y H:i',strtotime($item['publicado_em']??$item['criado_em']))) ?></p><div class="rich-copy"><?= nl2br(sanitize($item['conteudo'])) ?></div><div class="action-row">
<?php if($item['status']==='publicado' && !$item['lido_em']): ?><form method="post"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="button button-secondary" name="action" value="ler">Marcar como lido</button></form><?php elseif($item['lido_em']): ?><small>Lido em <?= sanitize(date('d/m/Y H:i',strtotime($item['lido_em']))) ?></small><?php endif; ?>
<?php if($manager): ?><a class="button button-secondary" href="?condominio_id=<?= $cond ?>&amp;editar=<?= (int)$item['id'] ?>#editor-comunicado">Editar</a><form method="post" data-confirm="Excluir este comunicado? O histórico será preservado."><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="button button-secondary" name="action" value="excluir">Excluir</button></form><?php endif; ?></div>
<?php if($manager): ?><details><summary>Histórico e leituras</summary><p><?= (int)(dbRow('SELECT COUNT(*) AS n FROM comunicado_leituras WHERE comunicado_id=?',[$item['id']])['n']??0) ?> leituras registradas</p><ol class="timeline"><?php foreach(dbRows('SELECT h.*,u.nome FROM comunicado_historico h JOIN users u ON u.id=h.usuario_id WHERE h.comunicado_id=? ORDER BY h.id DESC',[$item['id']]) as $event): ?><li><strong><?= sanitize($event['status'].' · '.$event['titulo']) ?></strong><small><?= sanitize($event['nome'].' · '.$event['criado_em']) ?></small><p><?= nl2br(sanitize($event['conteudo'])) ?></p></li><?php endforeach; ?></ol></details><?php endif; ?></article><?php endforeach; ?></div>
<?php if($manager): ?><section class="panel-card profile-section" id="editor-comunicado"><h2><?= $edit?'Editar comunicado':'Publicar comunicado' ?></h2><form method="post"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>"><input type="hidden" name="action" value="salvar"><label>Título<input name="titulo" maxlength="190" value="<?= sanitize($edit['titulo']??'') ?>" required></label><label>Conteúdo<textarea name="conteudo" rows="6" maxlength="15000" required><?= sanitize($edit['conteudo']??'') ?></textarea></label><label>Status<select name="status"><?php foreach(['publicado'=>'Publicado','rascunho'=>'Rascunho','arquivado'=>'Arquivado'] as $value=>$label): ?><option value="<?= $value ?>" <?= ($edit['status']??'publicado')===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label><button class="button button-primary">Salvar comunicado</button></form></section><?php endif; ?>
</div></main><?php require __DIR__.'/../includes/footer.php'; ?>
