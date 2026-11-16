<?php
declare(strict_types=1);

/** Fill empty community sections with labeled examples, preserving existing records. */
function seedCommunityExamples(): void
{
    foreach (dbRows('SELECT * FROM condominios ORDER BY id') as $building) {
        $cond=(int)$building['id'];
        $author=dbRow("SELECT id,email FROM users WHERE status='ativo' AND email_verificado=1 AND ((tipo_usuario='sindico' AND condominio_id=?) OR tipo_usuario='admin') ORDER BY (tipo_usuario='sindico') DESC,id LIMIT 1",[$cond]);
        if (!$author) continue;
        dbExecute("UPDATE condominios SET descricao=IF(COALESCE(TRIM(descricao),'')='',?,descricao),regras=IF(COALESCE(TRIM(regras),'')='',?,regras),contato=IF(COALESCE(TRIM(contato),'')='',?,contato) WHERE id=?",[
            'Comunidade '.$building['nome'].'. Consulte aqui os comunicados e a agenda das áreas comuns.',
            'Consulte a administração sobre as regras vigentes antes de utilizar os espaços compartilhados.',$author['email'],$cond,
        ]);
        if (!dbRow('SELECT id FROM areas_comuns WHERE condominio_id=?',[$cond])) {
            foreach (['Salão de festas'=>40,'Churrasqueira'=>20,'Sala de convivência'=>12] as $name=>$capacity) {
                dbExecute('INSERT INTO areas_comuns (condominio_id,nome,descricao,capacidade,regras) VALUES (?,?,?,?,?)',[
                    $cond,$name,'Espaço de exemplo para demonstração da agenda. Confirme a disponibilidade real com a administração.',$capacity,
                    'Cadastro de demonstração. A administração deve revisar as regras e a capacidade antes de liberar o uso.',
                ]);
            }
        }
        if (!dbRow('SELECT id FROM comunicados WHERE condominio_id=?',[$cond])) {
            foreach ([
                'Bem-vindo à comunidade — exemplo'=>'Este comunicado demonstra o mural da comunidade. A administração pode editar o conteúdo e publicar as orientações do condomínio.',
                'Como consultar a agenda — exemplo'=>'Abra Reservas para consultar os espaços e os horários disponíveis. Os cadastros de exemplo devem ser revisados pela administração antes do uso real.',
            ] as $title=>$content) {
                dbExecute("INSERT INTO comunicados (condominio_id,autor_id,titulo,conteudo,status,publicado_em) VALUES (?,?,?,?,'publicado',NOW())",[$cond,$author['id'],$title,$content]);
                $id=(int)getPDO()->lastInsertId();
                dbExecute("INSERT INTO comunicado_historico (comunicado_id,usuario_id,titulo,conteudo,status) VALUES (?,?,?,?,'publicado')",[$id,$author['id'],$title,$content]);
            }
        }
        if (!dbRow('SELECT id FROM reservas WHERE condominio_id=?',[$cond])) {
            $area=dbRow('SELECT id FROM areas_comuns WHERE condominio_id=? ORDER BY id LIMIT 1',[$cond]);
            if ($area) dbExecute("INSERT INTO reservas (area_id,condominio_id,usuario_id,inicio,fim,status,observacao) VALUES (?,?,?,TIMESTAMP(DATE_SUB(CURRENT_DATE,INTERVAL 1 DAY),'10:00:00'),TIMESTAMP(DATE_SUB(CURRENT_DATE,INTERVAL 1 DAY),'12:00:00'),'cancelada','Exemplo de reserva cancelada. Não bloqueia a agenda nem representa uma solicitação real.')",[$area['id'],$cond,$author['id']]);
        }
    }
}
