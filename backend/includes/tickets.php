<?php
declare(strict_types=1);

function getVisibleChamadosForUser(int $userId): array {
    $user=findUserById($userId); if (!$user || $user['status']!=='ativo') return [];
    return match($user['tipo_usuario']) {
        'admin'=>dbRows('SELECT * FROM chamados ORDER BY criado_em DESC,id DESC'),
        'prestador'=>dbRows("SELECT * FROM chamados WHERE (status='aberto' AND prestador_id IS NULL) OR prestador_id=? ORDER BY criado_em DESC,id DESC",[$userId]),
        'sindico'=>dbRows("SELECT c.* FROM chamados c JOIN condominio_sindicos s ON s.condominio_id=c.condominio_id JOIN users u ON u.id=s.usuario_id WHERE s.usuario_id=? AND u.verificado=1 ORDER BY c.criado_em DESC,c.id DESC",[$userId]),
        default=>getChamadosForUser($userId),
    };
}
function canViewTicket(int $actorId,int $id): bool {
    foreach(getVisibleChamadosForUser($actorId) as $ticket) if ((int)$ticket['id']===$id) return true;
    return false;
}
function ticketEvent(int $ticketId,int $actorId,string $type,string $text): void {
    $ticket=getChamadoById($ticketId); if (!$ticket) throw new DomainException('Chamado não encontrado.');
    dbExecute('INSERT INTO chamado_historico (chamado_id,usuario_id,status_anterior,status_novo,observacao) VALUES (?,?,?,?,?)',[$ticketId,$actorId,$ticket['status'],$ticket['status'],$text]);
    $recipients=[(int)$ticket['usuario_id'],(int)($ticket['prestador_id']??0)];
    foreach(dbRows('SELECT usuario_id FROM condominio_sindicos WHERE condominio_id=?',[(int)$ticket['condominio_id']]) as $s) $recipients[]=(int)$s['usuario_id'];
    foreach(array_unique($recipients) as $recipient) if ($recipient>0 && ($recipient!==$actorId || $type==='criado')) createNotification($recipient,$text,'Chamado '.$ticket['protocolo'].' · '.$ticket['titulo'],$type,$ticketId);
}
function createChamado(array $data): int {
    $actor=activeActor((int)$data['usuario_id']);
    if ($actor['tipo_usuario']!=='morador' || empty($actor['condominio_id'])) throw new DomainException('Somente moradores vinculados a um condomínio podem abrir chamados.');
    foreach(['titulo'=>190,'descricao'=>10000,'local_problema'=>255,'categoria'=>100,'prioridade'=>20] as $field=>$limit) if (trim((string)($data[$field]??''))==='' || mb_strlen($data[$field])>$limit) throw new DomainException('Preencha os campos do chamado respeitando os limites.');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',(string)($data['data_servico']??''));
    if (!$date || $date->format('Y-m-d')!==$data['data_servico']) throw new DomainException('Data inválida.');
    return atomic(function() use($actor,$data) {
        dbExecute("INSERT INTO chamados (usuario_id,condominio_id,categoria,titulo,descricao,local_problema,prioridade,data_servico,protocolo,status) VALUES (?,?,?,?,?,?,?,?,?,'aberto')",[$actor['id'],$actor['condominio_id'],$data['categoria'],$data['titulo'],$data['descricao'],$data['local_problema'],$data['prioridade'],$data['data_servico'],$data['protocolo']]);
        $id=(int)getPDO()->lastInsertId(); ticketEvent($id,(int)$actor['id'],'criado','Chamado criado'); auditAction((int)$actor['id'],'criacao_chamado','chamado',$id); return $id;
    });
}
function acceptChamado(int $chamadoId,int $providerId): int {
    $provider=activeActor($providerId);
    if ($provider['tipo_usuario']!=='prestador') throw new DomainException('Apenas prestadores podem aceitar chamados.');
    return atomic(function() use($chamadoId,$providerId) {
        $ticket=dbRow('SELECT * FROM chamados WHERE id=? FOR UPDATE',[$chamadoId]);
        if (!$ticket) throw new DomainException('Chamado indisponível.');
        if ((int)$ticket['prestador_id']===$providerId && $ticket['status']==='andamento') return getOrCreateChat((int)$ticket['usuario_id'],$providerId,$chamadoId);
        if ($ticket['status']!=='aberto' || $ticket['prestador_id']) throw new DomainException('Outro prestador já assumiu este chamado.');
        dbExecute("UPDATE chamados SET prestador_id=?,status='andamento' WHERE id=?",[$providerId,$chamadoId]);
        ticketEvent($chamadoId,$providerId,'prestador_selecionado','Prestador selecionado');
        return getOrCreateChat((int)$ticket['usuario_id'],$providerId,$chamadoId);
    });
}
function selectTicketProvider(int $actorId,int $ticketId,int $providerId): int {
    $actor=activeActor($actorId);
    return atomic(function() use($actor,$actorId,$ticketId,$providerId) {
        $ticket=dbRow('SELECT * FROM chamados WHERE id=? FOR UPDATE',[$ticketId]);
        if (!$ticket || (int)$ticket['usuario_id']!==$actorId || !dbRow('SELECT id FROM chamado_propostas WHERE chamado_id=? AND prestador_id=?',[$ticketId,$providerId])) throw new DomainException('Proposta indisponível.');
        $chat=acceptChamado($ticketId,$providerId);
        createNotification($providerId,'Você foi selecionado','O morador selecionou sua proposta.','prestador_selecionado',$ticketId,$chat); return $chat;
    });
}
function updateChamadoStatus(int $chamadoId,int $actorId,string $newStatus,string $note=''): bool {
    $actor=activeActor($actorId);
    return atomic(function() use($chamadoId,$actorId,$newStatus,$note,$actor) {
        $ticket=dbRow('SELECT * FROM chamados WHERE id=? FOR UPDATE',[$chamadoId]);
        if (!$ticket || !in_array($ticket['status'],['aberto','andamento'],true) || mb_strlen($note)>500) return false;
        $allowed=($newStatus==='cancelado' && ((int)$ticket['usuario_id']===$actorId || managesCondominio($actorId,(int)$ticket['condominio_id'])))
            || ($newStatus==='concluido' && $ticket['status']==='andamento' && $actor['tipo_usuario']==='prestador' && (int)$ticket['prestador_id']===$actorId)
            || ($newStatus==='andamento' && $ticket['prestador_id'] && managesCondominio($actorId,(int)$ticket['condominio_id']));
        if (!$allowed) return false;
        dbExecute('UPDATE chamados SET status=? WHERE id=?',[$newStatus,$chamadoId]);
        $text=['concluido'=>'Serviço concluído','cancelado'=>'Chamado cancelado','andamento'=>'Serviço em andamento'][$newStatus];
        ticketEvent($chamadoId,$actorId,$newStatus,$text.($note!==''?' · '.mb_substr($note,0,400):''));
        auditAction($actorId,'status_'.$newStatus,'chamado',$chamadoId); return true;
    });
}
function ticketProposals(int $actorId,int $ticketId): array {
    if (!canViewTicket($actorId,$ticketId)) return [];
    $actor=activeActor($actorId);
    return dbRows('SELECT p.*,u.nome AS prestador_nome FROM chamado_propostas p JOIN users u ON u.id=p.prestador_id WHERE p.chamado_id=?'.($actor['tipo_usuario']==='prestador'?' AND p.prestador_id=?':'').' ORDER BY p.id', $actor['tipo_usuario']==='prestador'?[$ticketId,$actorId]:[$ticketId]);
}
function saveTicketProposal(int $actorId,int $ticketId,array $data): void {
    $actor=activeActor($actorId);
    if ($actor['tipo_usuario']!=='prestador') throw new DomainException('Apenas prestadores podem responder.');
    $response=trim((string)($data['resposta']??''));
    if ($response==='' || mb_strlen($response)>4000) throw new DomainException('Descreva sua proposta em até 4.000 caracteres.');
    atomic(function() use($actorId,$ticketId,$response) {
        $ticket=dbRow('SELECT * FROM chamados WHERE id=? FOR UPDATE',[$ticketId]);
        if (!$ticket || !in_array($ticket['status'],['aberto','andamento'],true) || ($ticket['prestador_id'] && (int)$ticket['prestador_id']!==$actorId)) throw new DomainException('Chamado indisponível.');
        dbExecute('INSERT INTO chamado_propostas (chamado_id,prestador_id,resposta) VALUES (?,?,?) ON DUPLICATE KEY UPDATE resposta=VALUES(resposta)',[$ticketId,$actorId,$response]);
        ticketEvent($ticketId,$actorId,'resposta','Prestador respondeu ao chamado');
    });
}
function updateTicketService(int $actorId,int $ticketId,string $action,array $data): void {
    activeActor($actorId);
    atomic(function() use($actorId,$ticketId,$action,$data) {
        $ticket=dbRow('SELECT * FROM chamados WHERE id=? FOR UPDATE',[$ticketId]);
        if (!$ticket || $ticket['status']!=='andamento') throw new DomainException('O chamado precisa estar em atendimento.');
        $provider=(int)$ticket['prestador_id'];
        if (in_array($action,['aceitar_orcamento','recusar_orcamento'],true)) {
            if ((int)$ticket['usuario_id']!==$actorId) throw new DomainException('Somente o solicitante pode decidir sobre o orçamento.');
            $proposal=dbRow('SELECT * FROM chamado_propostas WHERE chamado_id=? AND prestador_id=?',[$ticketId,$provider]);
            if (!$proposal || !$proposal['orcamento_valor'] || $proposal['orcamento_status']!=='pendente') throw new DomainException('Não há orçamento pendente.');
            $status=$action==='aceitar_orcamento'?'aceito':'recusado';
            dbExecute('UPDATE chamado_propostas SET orcamento_status=? WHERE id=?',[$status,$proposal['id']]);
            ticketEvent($ticketId,$actorId,'orcamento_'.$status,'Orçamento '.$status); return;
        }
        if ($provider!==$actorId) throw new DomainException('Somente o prestador selecionado pode atualizar o atendimento.');
        dbExecute("INSERT IGNORE INTO chamado_propostas (chamado_id,prestador_id,resposta) VALUES (?,?,'Atendimento iniciado')",[$ticketId,$provider]);
        if ($action==='visita') {
            $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)($data['visita_em']??''));
            if (!$date || $date->format('Y-m-d\TH:i')!==($data['visita_em']??'') || $date<new DateTimeImmutable()) throw new DomainException('Informe data e horário futuros válidos.');
            dbExecute('UPDATE chamado_propostas SET visita_em=? WHERE chamado_id=? AND prestador_id=?',[$date->format('Y-m-d H:i:s'),$ticketId,$provider]);
            ticketEvent($ticketId,$actorId,'visita','Visita marcada para '.$date->format('d/m/Y H:i'));
        } elseif ($action==='taxa' || $action==='orcamento') {
            $value=str_replace(',','.',trim((string)($data['valor']??'')));
            if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/',$value) || (float)$value<($action==='taxa'?0:0.01)) throw new DomainException('Informe um valor monetário válido.');
            if ($action==='taxa') dbExecute('UPDATE chamado_propostas SET taxa_visita=? WHERE chamado_id=? AND prestador_id=?',[$value,$ticketId,$provider]);
            else {
                $description=trim((string)($data['descricao']??''));
                if ($description==='' || mb_strlen($description)>4000) throw new DomainException('Descreva os serviços do orçamento.');
                $proposal=dbRow('SELECT orcamento_status FROM chamado_propostas WHERE chamado_id=? AND prestador_id=?',[$ticketId,$provider]);
                if (($proposal['orcamento_status']??'')==='aceito') throw new DomainException('Um orçamento aceito não pode ser alterado.');
                dbExecute("UPDATE chamado_propostas SET orcamento_valor=?,orcamento_descricao=?,orcamento_status='pendente' WHERE chamado_id=? AND prestador_id=?",[$value,$description,$ticketId,$provider]);
            }
            ticketEvent($ticketId,$actorId,$action,$action==='taxa'?'Taxa de visita informada':'Orçamento enviado');
        } else throw new DomainException('Ação inválida.');
    });
}
