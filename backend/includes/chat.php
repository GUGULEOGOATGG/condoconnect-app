<?php
declare(strict_types=1);

function listChatContacts(int $userId): array {
    $actor = activeActor($userId);
    if ($actor['tipo_usuario']==='admin') return dbRows("SELECT id,nome,tipo_usuario FROM users WHERE id<>? AND status='ativo' AND email_verificado=1 ORDER BY nome,id",[$userId]);
    // Keep contact discovery under the same permissions as opening a conversation.
    $candidates = dbRows("SELECT u.id,u.nome,u.tipo_usuario FROM users u WHERE u.id<>? AND u.status='ativo' AND u.email_verificado=1 AND (
        u.tipo_usuario='admin' OR (u.tipo_usuario='prestador' AND ? IN ('morador','sindico')) OR
        (u.tipo_usuario IN ('morador','sindico') AND ?='prestador') OR
        (u.tipo_usuario='sindico' AND ?='morador' AND u.verificado=1 AND EXISTS(SELECT 1 FROM condominio_sindicos s WHERE s.usuario_id=u.id AND s.condominio_id=?)) OR
        (u.tipo_usuario='morador' AND ?='sindico' AND EXISTS(SELECT 1 FROM condominio_sindicos s JOIN users manager ON manager.id=s.usuario_id WHERE s.usuario_id=? AND manager.verificado=1 AND s.condominio_id=u.condominio_id))
    ) ORDER BY u.nome,u.id", [$userId,$actor['tipo_usuario'],$actor['tipo_usuario'],$actor['tipo_usuario'],(int)($actor['condominio_id']??0),$actor['tipo_usuario'],$userId]);
    return $candidates;
}

function canUsersChat(int $userAId,int $userBId,?int $chamadoId=null): bool {
    if ($userAId===$userBId) return false;
    $a=findUserById($userAId); $b=findUserById($userBId);
    if (!$a || !$b || $a['status']!=='ativo' || $b['status']!=='ativo' || !(int)$a['email_verificado'] || !(int)$b['email_verificado']) return false;
    if ($chamadoId!==null) {
        $ticket=getChamadoById($chamadoId);
        return $ticket && (int)$ticket['prestador_id']>0 && in_array($ticket['status'],['andamento','concluido'],true)
            && in_array($userAId,[(int)$ticket['usuario_id'],(int)$ticket['prestador_id']],true)
            && in_array($userBId,[(int)$ticket['usuario_id'],(int)$ticket['prestador_id']],true);
    }
    $roles=[$a['tipo_usuario'],$b['tipo_usuario']];
    if (in_array('admin',$roles,true)) return true;
    if (in_array('prestador',$roles,true) && (in_array('morador',$roles,true) || in_array('sindico',$roles,true))) return true;
    return ($a['tipo_usuario']==='morador' && managesCondominio($userBId,(int)($a['condominio_id']??0)))
        || ($b['tipo_usuario']==='morador' && managesCondominio($userAId,(int)($b['condominio_id']??0)));
}
function getOrCreateChat(int $userAId,int $userBId,?int $chamadoId=null): int {
    if (!canUsersChat($userAId,$userBId,$chamadoId)) throw new DomainException('Esta conversa não está disponível para estes participantes.');
    return atomic(function() use($userAId,$userBId,$chamadoId) {
        $a=min($userAId,$userBId); $b=max($userAId,$userBId);
        dbExecute('INSERT INTO chats (user_a_id,user_b_id,chamado_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$a,$b,$chamadoId]);
        $id=(int)getPDO()->lastInsertId();
        foreach([$a,$b] as $user) dbExecute('INSERT INTO chat_participantes (chat_id,usuario_id,ativo) VALUES (?,?,1) ON DUPLICATE KEY UPDATE ativo=1,saiu_em=NULL',[$id,$user]);
        return $id;
    });
}
function userCanAccessChat(int $chatId,int $userId): bool {
    $row=dbRow("SELECT c.chamado_id,u.tipo_usuario FROM chat_participantes p JOIN users u ON u.id=p.usuario_id JOIN chats c ON c.id=p.chat_id WHERE p.chat_id=? AND p.usuario_id=? AND p.ativo=1 AND u.status='ativo' AND u.email_verificado=1",[$chatId,$userId]);
    if (!$row) return false;
    if ($row['tipo_usuario']==='sindico' && $row['chamado_id']) {
        $ticket=getChamadoById((int)$row['chamado_id']);
        return $ticket && managesCondominio($userId,(int)$ticket['condominio_id']);
    }
    return true;
}
function joinTicketChatAsSyndic(int $chatId,int $syndicId): bool {
    $row=dbRow('SELECT c.condominio_id FROM chats t JOIN chamados c ON c.id=t.chamado_id WHERE t.id=?',[$chatId]);
    if (!$row || !managesCondominio($syndicId,(int)$row['condominio_id'])) return false;
    dbExecute('INSERT INTO chat_participantes (chat_id,usuario_id) VALUES (?,?) ON DUPLICATE KEY UPDATE ativo=1,saiu_em=NULL',[$chatId,$syndicId]); return true;
}
function sendChatMessage(int $chatId,int $senderId,string $message): bool {
    $message=trim($message);
    if ($message==='' || mb_strlen($message)>4000 || !userCanAccessChat($chatId,$senderId)) return false;
    return atomic(function() use($chatId,$senderId,$message) {
        $chat=dbRow("SELECT * FROM chats WHERE id=? AND status='ativo' FOR UPDATE",[$chatId]);
        if (!$chat) return false;
        dbExecute('INSERT INTO chat_mensagens (chat_id,remetente_id,mensagem) VALUES (?,?,?)',[$chatId,$senderId,$message]);
        dbExecute('UPDATE chats SET updated_at=NOW(),ultimo_resumo=? WHERE id=?',[mb_substr($message,0,120),$chatId]);
        foreach(dbRows('SELECT usuario_id FROM chat_participantes WHERE chat_id=? AND usuario_id<>? AND ativo=1',[$chatId,$senderId]) as $recipient) {
            if (userCanAccessChat($chatId,(int)$recipient['usuario_id'])) createNotification((int)$recipient['usuario_id'],'Nova mensagem','Você recebeu uma mensagem na conversa.','mensagem',$chat['chamado_id']?(int)$chat['chamado_id']:null,$chatId);
        }
        auditAction($senderId,'envio_mensagem','chat',$chatId); return true;
    });
}
function listUserChats(int $userId): array {
    $rows=dbRows('SELECT c.*,u.id AS contato_id,u.nome AS contato_nome,u.tipo_usuario AS contato_tipo,(SELECT COUNT(*) FROM chat_mensagens m WHERE m.chat_id=c.id AND m.remetente_id<>? AND m.id>COALESCE(p.ultima_mensagem_lida_id,0)) AS nao_lidas FROM chats c JOIN chat_participantes p ON p.chat_id=c.id AND p.usuario_id=? AND p.ativo=1 JOIN users u ON u.id=(CASE WHEN c.user_a_id=? THEN c.user_b_id ELSE c.user_a_id END) ORDER BY c.updated_at DESC,c.id DESC',[$userId,$userId,$userId]);
    return array_values(array_filter($rows,static fn($row)=>userCanAccessChat((int)$row['id'],$userId)));
}
function listChatMessages(int $chatId,int $userId): array {
    if (!userCanAccessChat($chatId,$userId)) return [];
    // Capture the returned messages first. A concurrent arrival must remain unread.
    $rows=dbRows('SELECT m.*,u.nome AS remetente_nome,NOT EXISTS(SELECT 1 FROM chat_participantes p WHERE p.chat_id=m.chat_id AND p.ativo=1 AND p.usuario_id<>m.remetente_id AND COALESCE(p.ultima_mensagem_lida_id,0)<m.id) AS lida_por_todos FROM chat_mensagens m JOIN users u ON u.id=m.remetente_id WHERE m.chat_id=? ORDER BY m.id',[$chatId]);
    $last=$rows ? (int)$rows[array_key_last($rows)]['id'] : 0;
    dbExecute('UPDATE chat_participantes SET ultima_mensagem_lida_id=GREATEST(COALESCE(ultima_mensagem_lida_id,0),?) WHERE chat_id=? AND usuario_id=?',[$last,$chatId,$userId]);
    dbExecute('UPDATE chat_mensagens m SET lida=1 WHERE m.chat_id=? AND m.id<=? AND m.remetente_id<>? AND NOT EXISTS (SELECT 1 FROM chat_participantes p WHERE p.chat_id=m.chat_id AND p.ativo=1 AND p.usuario_id<>m.remetente_id AND COALESCE(p.ultima_mensagem_lida_id,0)<m.id)',[$chatId,$last,$userId]);
    return $rows;
}
