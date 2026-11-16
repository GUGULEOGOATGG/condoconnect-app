<?php
declare(strict_types=1);

function dbRows(string $sql, array $params = []): array {
    $q = getPDO()->prepare($sql); $q->execute($params); return $q->fetchAll();
}
function dbRow(string $sql, array $params = []): ?array { return dbRows($sql, $params)[0] ?? null; }
function dbExecute(string $sql, array $params = []): int {
    $q = getPDO()->prepare($sql); $q->execute($params); return $q->rowCount();
}
function atomic(callable $work): mixed {
    $pdo = getPDO(); $owner = !$pdo->inTransaction();
    if ($owner) $pdo->beginTransaction();
    try { $result = $work(); if ($owner) $pdo->commit(); return $result; }
    catch (Throwable $e) { if ($owner && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function activeActor(int $id): array {
    $user = findUserById($id);
    if (!$user || $user['status'] !== 'ativo' || !(int)$user['email_verificado']) throw new DomainException('Conta sem acesso a esta operação.');
    return $user;
}
function managesCondominio(int $actorId, int $condominioId): bool {
    return $condominioId > 0 && dbRow("SELECT s.usuario_id FROM condominio_sindicos s JOIN users u ON u.id=s.usuario_id WHERE s.condominio_id=? AND s.usuario_id=? AND u.tipo_usuario='sindico' AND u.status='ativo' AND u.verificado=1 AND u.email_verificado=1", [$condominioId,$actorId]) !== null;
}
function requireCondominioManager(int $actorId, int $condominioId): void {
    if (!canManageCommunity($actorId,$condominioId)) throw new DomainException('Somente a administração responsável pode administrar este condomínio.');
}
function canManageCommunity(int $actorId, int $condominioId): bool {
    $actor=activeActor($actorId);
    return ($actor['tipo_usuario']==='admin' && dbRow('SELECT id FROM condominios WHERE id=?',[$condominioId])!==null)
        || managesCondominio($actorId,$condominioId);
}
function communityCondominioId(int $actorId, ?int $requested=null): int {
    $actor=activeActor($actorId); $own=(int)($actor['condominio_id']??0);
    if ($actor['tipo_usuario']!=='admin') {
        if ($requested!==null && $requested!==$own) throw new DomainException('Condomínio indisponível para sua conta.');
        return $own;
    }
    $id=$requested ?? ($own ?: (int)(dbRow('SELECT id FROM condominios ORDER BY nome,id LIMIT 1')['id']??0));
    if ($id && !dbRow('SELECT id FROM condominios WHERE id=?',[$id])) throw new DomainException('Condomínio não encontrado.');
    return $id;
}
function userCondominio(int $actorId, ?int $condominioId=null): ?array {
    return dbRow('SELECT * FROM condominios WHERE id=?', [communityCondominioId($actorId,$condominioId)]);
}
function canViewProfile(array $viewer, array $target): bool {
    return (int)$viewer['id']===(int)$target['id'] || $viewer['tipo_usuario']==='admin'
        || ($target['tipo_usuario']==='prestador' && $target['status']==='ativo')
        || managesCondominio((int)$viewer['id'], (int)($target['condominio_id'] ?? 0));
}
function profileImageUrl(array $user): string {
    return getBasePath().'api/avatar.php?id='.(int)$user['id'];
}
function friendlyError(Throwable $e): string {
    if ($e instanceof DomainException || $e instanceof InvalidArgumentException) return $e->getMessage();
    error_log((string)$e);
    if ($e instanceof PDOException && in_array((string)$e->getCode(), ['42S02','42S22'], true)) {
        return 'O banco de dados precisa ser atualizado. Solicite ao administrador a execução da migração da aplicação no servidor.';
    }
    if ($e instanceof DatabaseConfigurationException || $e instanceof DatabaseConnectionException) {
        return 'O banco de dados está indisponível no momento. Tente novamente mais tarde.';
    }
    return 'Não foi possível concluir a operação. Tente novamente.';
}
function flashMessage(string $message): void { $_SESSION['flash_message'] = $message; }

function listComunicados(int $actorId, ?int $condominioId=null): array {
    $cond=communityCondominioId($actorId,$condominioId);
    $manager=canManageCommunity($actorId,$cond);
    return dbRows("SELECT c.*,u.nome AS autor_nome,l.lido_em FROM comunicados c JOIN users u ON u.id=c.autor_id LEFT JOIN comunicado_leituras l ON l.comunicado_id=c.id AND l.usuario_id=? WHERE c.condominio_id=? AND " . ($manager ? "c.status<>'excluido'" : "c.status='publicado'") . ' ORDER BY c.criado_em DESC,c.id DESC',[$actorId,$cond]);
}
function saveComunicado(int $actorId, int $condominioId, array $data, int $id=0): int {
    requireCondominioManager($actorId,$condominioId);
    $title=trim((string)($data['titulo']??'')); $content=trim((string)($data['conteudo']??'')); $status=(string)($data['status']??'publicado');
    if ($title==='' || mb_strlen($title)>190 || $content==='' || mb_strlen($content)>15000 || !in_array($status,['rascunho','publicado','arquivado'],true)) throw new DomainException('Informe título, conteúdo e um status válido.');
    return atomic(function() use ($actorId,$condominioId,$title,$content,$status,$id) {
        $old=$id ? dbRow('SELECT * FROM comunicados WHERE id=? AND condominio_id=? AND status<>\'excluido\' FOR UPDATE',[$id,$condominioId]) : null;
        if ($id && !$old) throw new DomainException('Comunicado não encontrado neste condomínio.');
        if ($id) {
            dbExecute('UPDATE comunicados SET titulo=?,conteudo=?,status=?,publicado_em=IF(?=\'publicado\',COALESCE(publicado_em,NOW()),publicado_em) WHERE id=?',[$title,$content,$status,$status,$id]);
            if ($title!==$old['titulo'] || $content!==$old['conteudo']) dbExecute('DELETE FROM comunicado_leituras WHERE comunicado_id=?',[$id]);
        } else {
            dbExecute('INSERT INTO comunicados (condominio_id,autor_id,titulo,conteudo,status,publicado_em) VALUES (?,?,?,?,?,IF(?=\'publicado\',NOW(),NULL))',[$condominioId,$actorId,$title,$content,$status,$status]);
            $id=(int)getPDO()->lastInsertId();
        }
        dbExecute('INSERT INTO comunicado_historico (comunicado_id,usuario_id,titulo,conteudo,status) VALUES (?,?,?,?,?)',[$id,$actorId,$title,$content,$status]);
        if ($status==='publicado' && (!$old || $old['status']!=='publicado' || $old['conteudo']!==$content || $old['titulo']!==$title)) {
            foreach(dbRows("SELECT id FROM users WHERE condominio_id=? AND status='ativo' AND tipo_usuario='morador'",[$condominioId]) as $resident) createNotification((int)$resident['id'],'Comunicado: '.$title,'Um comunicado foi publicado ou atualizado no seu condomínio.','comunicado');
        }
        auditAction($actorId,'salvar_comunicado','comunicado',$id); return $id;
    });
}
function deleteComunicado(int $actorId,int $id): void {
    atomic(function() use ($actorId,$id) {
        $row=dbRow('SELECT * FROM comunicados WHERE id=? FOR UPDATE',[$id]);
        if (!$row) throw new DomainException('Comunicado não encontrado.');
        requireCondominioManager($actorId,(int)$row['condominio_id']);
        dbExecute("UPDATE comunicados SET status='excluido' WHERE id=?",[$id]);
        dbExecute("INSERT INTO comunicado_historico (comunicado_id,usuario_id,titulo,conteudo,status) VALUES (?,?,?,?,'excluido')",[$id,$actorId,$row['titulo'],$row['conteudo']]);
    });
}
function readComunicado(int $actorId,int $id): void {
    $actor=activeActor($actorId);
    $notice=dbRow("SELECT id FROM comunicados WHERE id=? AND (condominio_id=? OR ?='admin') AND status='publicado'",[$id,(int)($actor['condominio_id']??0),$actor['tipo_usuario']]);
    if (!$notice) throw new DomainException('Comunicado indisponível.');
    dbExecute('INSERT IGNORE INTO comunicado_leituras (comunicado_id,usuario_id) VALUES (?,?)',[$id,$actorId]);
}
function listAreas(int $actorId, ?int $condominioId=null): array {
    return dbRows('SELECT * FROM areas_comuns WHERE condominio_id=? AND ativo=1 ORDER BY nome',[communityCondominioId($actorId,$condominioId)]);
}
function listReservas(int $actorId, ?int $condominioId=null): array {
    $cond=communityCondominioId($actorId,$condominioId);
    $manager=canManageCommunity($actorId,$cond);
    return dbRows('SELECT r.*,a.nome AS area_nome,CASE WHEN r.usuario_id=? OR ?=1 THEN u.nome ELSE \'Morador do condomínio\' END AS responsavel FROM reservas r JOIN areas_comuns a ON a.id=r.area_id JOIN users u ON u.id=r.usuario_id WHERE r.condominio_id=? ORDER BY r.inicio DESC',[$actorId,(int)$manager,$cond]);
}
function createReserva(int $actorId,int $areaId,string $start,string $end,string $note=''): int {
    $actor=activeActor($actorId);
    if (!in_array($actor['tipo_usuario'],['morador','sindico','admin'],true)) throw new DomainException('Reservas são exclusivas de moradores e da administração.');
    $from=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$start); $to=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$end);
    if (!$from || !$to || $from->format('Y-m-d\TH:i')!==$start || $to->format('Y-m-d\TH:i')!==$end || $from<=new DateTimeImmutable() || $to<=$from || $to->getTimestamp()-$from->getTimestamp()>86400 || mb_strlen($note)>500) throw new DomainException('Escolha um período futuro de até 24 horas, com término após o início.');
    return atomic(function() use($actor,$actorId,$areaId,$from,$to,$note) {
        $area=dbRow("SELECT * FROM areas_comuns WHERE id=? AND (condominio_id=? OR ?='admin') AND ativo=1 FOR UPDATE",[$areaId,(int)($actor['condominio_id']??0),$actor['tipo_usuario']]);
        if (!$area) throw new DomainException('Área indisponível para seu condomínio.');
        $start=$from->format('Y-m-d H:i:s'); $end=$to->format('Y-m-d H:i:s');
        if (dbRow("SELECT id FROM reservas WHERE area_id=? AND status='confirmada' AND inicio<? AND fim>? FOR UPDATE",[$areaId,$end,$start])) throw new DomainException('Este horário já está reservado. Escolha outro período.');
        dbExecute('INSERT INTO reservas (area_id,condominio_id,usuario_id,inicio,fim,observacao) VALUES (?,?,?,?,?,?)',[$areaId,$area['condominio_id'],$actorId,$start,$end,$note]);
        $id=(int)getPDO()->lastInsertId(); createNotification($actorId,'Reserva realizada',$area['nome'].' · '.$from->format('d/m/Y H:i'),'reserva'); return $id;
    });
}
function cancelReserva(int $actorId,int $id): void {
    activeActor($actorId);
    atomic(function() use($actorId,$id) {
        $row=dbRow('SELECT * FROM reservas WHERE id=? FOR UPDATE',[$id]);
        if (!$row || ((int)$row['usuario_id']!==$actorId && !canManageCommunity($actorId,(int)$row['condominio_id']))) throw new DomainException('Reserva indisponível.');
        if ($row['status']==='cancelada') return;
        dbExecute("UPDATE reservas SET status='cancelada' WHERE id=?",[$id]);
        createNotification((int)$row['usuario_id'],'Reserva cancelada','Sua reserva foi cancelada.','reserva');
    });
}
function saveCondominio(int $actorId,int $id,array $data): void {
    requireCondominioManager($actorId,$id);
    $values=[]; foreach(['descricao','regras','contato'] as $field) {
        $value=trim((string)($data[$field]??''));
        if (mb_strlen($value)>($field==='contato'?190:15000)) throw new DomainException('Texto muito longo.');
        $values[]=$value;
    }
    dbExecute('UPDATE condominios SET descricao=?,regras=?,contato=? WHERE id=?',[...$values,$id]);
    auditAction($actorId,'editar_condominio','condominio',$id);
}
function notificationSummary(int $actorId,int $after=0): array {
    $summary=dbRow('SELECT COUNT(*) AS total,COALESCE(SUM(lida=0),0) AS nao_lidas,COALESCE(MAX(id),0) AS ultimo_id FROM notificacoes WHERE usuario_id=?',[$actorId]);
    $summary['novas']=dbRows('SELECT id,titulo,mensagem,tipo,chamado_id,chat_id,criado_em FROM notificacoes WHERE usuario_id=? AND id>? ORDER BY id LIMIT 30',[$actorId,$after]);
    $summary['mensagens_nao_lidas']=(int)(dbRow('SELECT COUNT(*) AS n FROM chat_mensagens m JOIN chat_participantes p ON p.chat_id=m.chat_id WHERE p.usuario_id=? AND p.ativo=1 AND m.remetente_id<>? AND m.id>COALESCE(p.ultima_mensagem_lida_id,0)',[$actorId,$actorId])['n']??0);
    return $summary;
}
function markNotificationRead(int $actorId,int $id=0): void {
    if ($id>0) dbExecute('UPDATE notificacoes SET lida=1,lida_em=COALESCE(lida_em,NOW()) WHERE usuario_id=? AND id=?',[$actorId,$id]);
    else dbExecute('UPDATE notificacoes SET lida=1,lida_em=COALESCE(lida_em,NOW()) WHERE usuario_id=?',[$actorId]);
}
function notificationUrl(array $notice): string {
    if (!empty($notice['chat_id'])) return getBasePath().'pages/chat.php?chat='.(int)$notice['chat_id'];
    if (!empty($notice['chamado_id'])) return getBasePath().'pages/detalhes-chamado.php?id='.(int)$notice['chamado_id'];
    return getBasePath().'pages/'.(['comunicado'=>'comunicados.php','reserva'=>'reservas.php'][$notice['tipo']??'']??'notificacoes.php');
}
