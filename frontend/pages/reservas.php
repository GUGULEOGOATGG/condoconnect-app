<?php
require_once __DIR__.'/../includes/auth.php'; requireVerifiedEmail();
$user=currentUser(); $uid=(int)$user['id']; $error='';
$cond=communityCondominioId($uid,isset($_GET['condominio_id'])?(int)$_GET['condominio_id']:null);
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if(($_POST['action']??'')==='cancelar') { cancelReserva($uid,filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT)?:0); flashMessage('Reserva cancelada.'); }
        else { createReserva($uid,filter_input(INPUT_POST,'area_id',FILTER_VALIDATE_INT)?:0,(string)($_POST['inicio']??''),(string)($_POST['fim']??''),trim((string)($_POST['observacao']??''))); flashMessage('Reserva realizada.'); }
        redirect(getBasePath().'pages/reservas.php?condominio_id='.$cond);
    } catch(Throwable $e) { $error=friendlyError($e); }
}
$areas=listAreas($uid,$cond); $reservations=listReservas($uid,$cond); $manager=canManageCommunity($uid,$cond);
$pageTitle='Reservas'; $bodyClass='dashboard-page'; require __DIR__.'/../includes/header.php'; ?>
<main class="section section-single"><div class="container"><div class="section-header"><span>Áreas comuns</span><h1>Reservas</h1><p>Planeje seu próximo encontro e consulte a agenda do condomínio.</p></div>
<?php require __DIR__.'/../includes/community-selector.php'; ?>
<?php if($error): ?><div class="alert alert-error" role="alert"><?= sanitize($error) ?></div><?php endif; ?>
<div class="service-list"><?php foreach($areas as $area): ?><article class="panel-card"><div class="icon"><?= condoIcon('calendar') ?></div><h2><?= sanitize($area['nome']) ?></h2><p><?= sanitize($area['descricao']) ?></p><p>Até <?= (int)$area['capacidade'] ?> pessoas</p><details><summary>Regras do espaço</summary><p><?= nl2br(sanitize($area['regras'])) ?></p></details></article><?php endforeach; ?></div>
<?php if(!$areas): ?><div class="empty-state"><h2>Nenhuma área disponível</h2><p>A administração ainda não cadastrou áreas para seu condomínio.</p></div><?php elseif(in_array($user['tipo_usuario'],['morador','sindico','admin'],true)): ?><section class="panel-card profile-section"><h2>Fazer reserva</h2><form method="post"><?= csrfField() ?><div class="profile-form-grid"><label>Área<select name="area_id" required><?php foreach($areas as $area): ?><option value="<?= (int)$area['id'] ?>"><?= sanitize($area['nome']) ?></option><?php endforeach; ?></select></label><label>Início<input type="datetime-local" name="inicio" min="<?= date('Y-m-d\TH:i') ?>" required></label><label>Término<input type="datetime-local" name="fim" min="<?= date('Y-m-d\TH:i') ?>" required></label></div><label>Observação<input name="observacao" maxlength="500"></label><button class="button button-primary">Confirmar reserva</button></form></section><?php endif; ?>
<section class="panel-card profile-section"><h2>Agenda do condomínio</h2><?php if(!$reservations): ?><p>Nenhuma reserva registrada.</p><?php else: ?><div class="table-wrapper"><table><thead><tr><th>Espaço</th><th>Responsável</th><th>Início</th><th>Término</th><th>Status</th><th>Ação</th></tr></thead><tbody><?php foreach($reservations as $reservation): ?><tr><td><?= sanitize($reservation['area_nome']) ?></td><td><?= sanitize($reservation['responsavel']) ?></td><td><?= sanitize(date('d/m/Y H:i',strtotime($reservation['inicio']))) ?></td><td><?= sanitize(date('d/m/Y H:i',strtotime($reservation['fim']))) ?></td><td><span class="badge"><?= sanitize($reservation['status']) ?></span></td><td><?php if($reservation['status']==='confirmada' && ((int)$reservation['usuario_id']===$uid || $manager)): ?><form method="post" data-confirm="Cancelar esta reserva?"><?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$reservation['id'] ?>"><button class="button button-secondary" name="action" value="cancelar">Cancelar</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</div></main><?php require __DIR__.'/../includes/footer.php'; ?>
