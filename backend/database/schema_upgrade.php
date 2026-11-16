<?php
declare(strict_types=1);

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $q->execute([$table, $column]);
    return (int) $q->fetchColumn() > 0;
}

function hasIndex(PDO $pdo, string $table, string $index): bool
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $q->execute([$table, $index]);
    return (int) $q->fetchColumn() > 0;
}

function hasConstraint(PDO $pdo, string $table, string $constraint): bool
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?');
    $q->execute([$table, $constraint]);
    return (int) $q->fetchColumn() > 0;
}

function hasNormalizedChatPairs(PDO $pdo): bool
{
    $duplicates = (int)$pdo->query('SELECT COUNT(*) FROM (SELECT LEAST(user_a_id,user_b_id), GREATEST(user_a_id,user_b_id) FROM chats GROUP BY LEAST(user_a_id,user_b_id), GREATEST(user_a_id,user_b_id) HAVING COUNT(*) > 1) d')->fetchColumn();
    return $duplicates === 0;
}

function upgradeBusinessSchema(PDO $pdo): void
{
$tables = [
    "CREATE TABLE IF NOT EXISTS chats (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_a_id BIGINT UNSIGNED NOT NULL,
        user_b_id BIGINT UNSIGNED NOT NULL,
        pair_min_id BIGINT UNSIGNED GENERATED ALWAYS AS (LEAST(user_a_id, user_b_id)) STORED,
        pair_max_id BIGINT UNSIGNED GENERATED ALWAYS AS (GREATEST(user_a_id, user_b_id)) STORED,
        chamado_id BIGINT UNSIGNED NULL,
        ultimo_resumo VARCHAR(255) NULL,
        status ENUM('ativo','encerrado') NOT NULL DEFAULT 'ativo',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_chat_pair (user_a_id, user_b_id), UNIQUE KEY uk_chat_normalized_pair (pair_min_id, pair_max_id), KEY idx_chats_updated (updated_at), KEY idx_chats_chamado (chamado_id),
        CONSTRAINT fk_chat_user_a FOREIGN KEY (user_a_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_chat_user_b FOREIGN KEY (user_b_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_chat_chamado FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS chat_participantes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, chat_id BIGINT UNSIGNED NOT NULL, usuario_id BIGINT UNSIGNED NOT NULL,
        entrou_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, saiu_em DATETIME NULL, ativo TINYINT(1) NOT NULL DEFAULT 1, ultima_mensagem_lida_id BIGINT UNSIGNED NULL,
        UNIQUE KEY uk_chat_participante (chat_id, usuario_id), KEY idx_participante_usuario (usuario_id, ativo),
        CONSTRAINT fk_part_chat FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
        CONSTRAINT fk_part_user FOREIGN KEY (usuario_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS chat_mensagens (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, chat_id BIGINT UNSIGNED NOT NULL, remetente_id BIGINT UNSIGNED NOT NULL,
        mensagem TEXT NOT NULL, lida TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_chat_mensagens_chat (chat_id), KEY idx_chat_mensagens_remetente (remetente_id), KEY idx_chat_mensagens_data (created_at),
        CONSTRAINT fk_chat_msg_chat FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
        CONSTRAINT fk_chat_msg_user FOREIGN KEY (remetente_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS auditoria (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, usuario_id BIGINT UNSIGNED NULL, acao VARCHAR(100) NOT NULL,
        entidade VARCHAR(100) NULL, entidade_id BIGINT UNSIGNED NULL, detalhes TEXT NULL, ip VARCHAR(45) NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_auditoria_usuario (usuario_id), KEY idx_auditoria_data (criado_em),
        CONSTRAINT fk_auditoria_user FOREIGN KEY (usuario_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS chamado_historico (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, chamado_id BIGINT UNSIGNED NOT NULL, usuario_id BIGINT UNSIGNED NULL,
        status_anterior VARCHAR(30) NULL, status_novo VARCHAR(30) NOT NULL, observacao VARCHAR(500) NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_historico_chamado (chamado_id, criado_em),
        CONSTRAINT fk_historico_chamado FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE,
        CONSTRAINT fk_historico_user FOREIGN KEY (usuario_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS password_resets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, token VARCHAR(255) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS email_verification_tokens (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, token VARCHAR(255) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_email_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($tables as $sql) $pdo->exec($sql);

// Existing chat tables remain intact; add only missing compatible fields.
$alter = [
    ['chats', 'chamado_id', 'BIGINT UNSIGNED NULL'], ['chats', 'status', "ENUM('ativo','encerrado') NOT NULL DEFAULT 'ativo'"],
    ['chats', 'criado_em', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
    ['chats', 'ultimo_resumo', 'VARCHAR(255) NULL'],
    ['chats', 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'],
    ['chats', 'pair_min_id', 'BIGINT UNSIGNED GENERATED ALWAYS AS (LEAST(user_a_id,user_b_id)) STORED'],
    ['chats', 'pair_max_id', 'BIGINT UNSIGNED GENERATED ALWAYS AS (GREATEST(user_a_id,user_b_id)) STORED'],
    ['chat_participantes', 'ultima_mensagem_lida_id', 'BIGINT UNSIGNED NULL'],
    ['chat_mensagens', 'lida', 'TINYINT(1) NOT NULL DEFAULT 0'],
    ['chat_mensagens', 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'], ['chat_mensagens', 'criado_em', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
    ['chamados', 'prestador_id', 'BIGINT UNSIGNED NULL'],
    ['users', 'bio', 'TEXT NULL'], ['users', 'especialidades', 'TEXT NULL'], ['users', 'servicos_prestados', 'TEXT NULL'],
    ['users', 'portfolio_imagens', 'TEXT NULL'], ['users', 'avaliacao_media', 'DECIMAL(3,2) NOT NULL DEFAULT 0.00'],
    ['users', 'avatar', 'VARCHAR(255) NULL'], ['users', 'documento_certificado', 'VARCHAR(255) NULL'],
    ['users', 'certificado_status', "ENUM('pendente','aprovado','rejeitado') NOT NULL DEFAULT 'aprovado'"],
    ['users', 'verificado', 'TINYINT(1) NOT NULL DEFAULT 1'], ['users', 'aprovado_por', 'BIGINT UNSIGNED NULL'],
    ['users', 'aprovado_em', 'DATETIME NULL'], ['users', 'email_verificado', 'TINYINT(1) NOT NULL DEFAULT 1'],
    ['users', 'two_factor_enabled', 'TINYINT(1) NOT NULL DEFAULT 0'], ['users', 'two_factor_code_hash', 'VARCHAR(255) NULL'],
    ['users', 'two_factor_expires_at', 'DATETIME NULL'],
];
foreach ($alter as [$table, $column, $definition]) {
    if (!hasColumn($pdo, $table, $column)) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}
$roleColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'tipo_usuario'")->fetch();
if ($roleColumn && stripos((string)$roleColumn['Type'], 'admin') === false) {
    $pdo->exec("ALTER TABLE users MODIFY tipo_usuario ENUM('morador','prestador','sindico','admin') NOT NULL DEFAULT 'morador'");
}
if (!hasIndex($pdo, 'chamados', 'idx_chamados_prestador')) $pdo->exec('ALTER TABLE chamados ADD INDEX idx_chamados_prestador (prestador_id)');
if (!hasIndex($pdo, 'chat_mensagens', 'idx_chat_mensagens_chat')) $pdo->exec('ALTER TABLE chat_mensagens ADD INDEX idx_chat_mensagens_chat (chat_id)');
if (!hasIndex($pdo, 'chat_mensagens', 'idx_chat_mensagens_remetente')) $pdo->exec('ALTER TABLE chat_mensagens ADD INDEX idx_chat_mensagens_remetente (remetente_id)');
if (!hasIndex($pdo, 'chat_mensagens', 'idx_chat_mensagens_data')) $pdo->exec('ALTER TABLE chat_mensagens ADD INDEX idx_chat_mensagens_data (created_at)');
if (!hasConstraint($pdo, 'chamados', 'fk_chamados_prestador')) $pdo->exec('ALTER TABLE chamados ADD CONSTRAINT fk_chamados_prestador FOREIGN KEY (prestador_id) REFERENCES users(id) ON DELETE SET NULL');
if (!hasConstraint($pdo, 'chats', 'fk_chat_chamado')) $pdo->exec('ALTER TABLE chats ADD CONSTRAINT fk_chat_chamado FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE RESTRICT');
$normalizedPairsSafe = hasNormalizedChatPairs($pdo);
if (!hasIndex($pdo, 'chats', 'uk_chat_contexto')) {
    if (!hasIndex($pdo, 'chats', 'uk_chat_normalized_pair') && $normalizedPairsSafe) $pdo->exec('ALTER TABLE chats ADD UNIQUE KEY uk_chat_normalized_pair (pair_min_id, pair_max_id)');
    elseif (!$normalizedPairsSafe) echo "MIGRATION_WARNING: chats legados têm pares duplicados; índice normalizado não foi aplicado para preservar dados.\n";
}
if (!hasIndex($pdo, 'avaliacoes', 'uk_avaliacao_chamado_morador')) {
    $dupes = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT chamado_id, morador_id FROM avaliacoes GROUP BY chamado_id, morador_id HAVING COUNT(*) > 1) d')->fetchColumn();
    if ($dupes === 0) $pdo->exec('ALTER TABLE avaliacoes ADD UNIQUE KEY uk_avaliacao_chamado_morador (chamado_id, morador_id)');
    else echo "MIGRATION_WARNING: avaliações legadas duplicadas; índice único não foi aplicado para preservar registros.\n";
}
// Restore participant membership for legacy pair chats, without changing messages or chat rows.
$pdo->exec('INSERT IGNORE INTO chat_participantes (chat_id, usuario_id, ativo) SELECT id, user_a_id, 1 FROM chats');
$pdo->exec('INSERT IGNORE INTO chat_participantes (chat_id, usuario_id, ativo) SELECT id, user_b_id, 1 FROM chats');
echo "MIGRATION_OK\n";
}

function upgradeCommunitySchema(PDO $pdo): void
{
upgradeBusinessSchema($pdo);
// A generated context column cannot depend on a SET NULL foreign-key action.
$keys=$pdo->query("SELECT DISTINCT k.CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='chats' AND k.COLUMN_NAME='chamado_id' AND r.DELETE_RULE='SET NULL'")->fetchAll(PDO::FETCH_COLUMN);
foreach($keys as $key) $pdo->exec('ALTER TABLE chats DROP FOREIGN KEY `'.str_replace('`','``',$key).'`');
if (!hasConstraint($pdo,'chats','fk_chat_chamado')) $pdo->exec('ALTER TABLE chats ADD CONSTRAINT fk_chat_chamado FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE RESTRICT');
foreach ([
    ['users','condominio_id','BIGINT UNSIGNED NULL'],
    ['users','unidade_id','BIGINT UNSIGNED NULL'],
    ['condominios','descricao','TEXT NULL'], ['condominios','regras','TEXT NULL'],
    ['condominios','contato','VARCHAR(190) NULL'],
    ['chamados','condominio_id','BIGINT UNSIGNED NULL'],
    ['notificacoes','tipo',"VARCHAR(60) NOT NULL DEFAULT 'sistema'"],
    ['notificacoes','chamado_id','BIGINT UNSIGNED NULL'],
    ['notificacoes','chat_id','BIGINT UNSIGNED NULL'],
    ['notificacoes','lida_em','DATETIME NULL'],
    ['chats','contexto_chamado_id','BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(chamado_id,0)) STORED'],
] as [$table,$column,$definition]) {
    if (!hasColumn($pdo,$table,$column)) $pdo->exec("ALTER TABLE `$table` ADD `$column` $definition");
}
foreach (explode(';', file_get_contents(__DIR__ . '/community_schema.sql')) as $sql) {
    if (trim($sql) !== '') $pdo->exec($sql);
}
// Canceled reservations must not prevent booking the same period again.
// createReserva serializes overlap checks with a lock on the common area.
if (hasIndex($pdo, 'reservas', 'uk_reserva_usuario_horario')) {
    $pdo->exec('ALTER TABLE reservas DROP INDEX uk_reserva_usuario_horario');
}
if (!hasIndex($pdo, 'reservas', 'idx_reserva_usuario_horario')) {
    $pdo->exec('ALTER TABLE reservas ADD INDEX idx_reserva_usuario_horario (area_id,usuario_id,inicio)');
}
// Legacy text is kept for older clients; authorization uses the IDs from now on.
$pdo->exec("INSERT INTO condominios (nome,endereco,cidade,estado,latitude,longitude) SELECT DISTINCT TRIM(u.condominio),'A confirmar','A confirmar','--',0,0 FROM users u LEFT JOIN condominios c ON c.nome=TRIM(u.condominio) WHERE TRIM(u.condominio)<>'' AND c.id IS NULL");
$pdo->exec('UPDATE users u JOIN condominios c ON c.nome=TRIM(u.condominio) SET u.condominio_id=c.id WHERE u.condominio_id IS NULL');
$pdo->exec('UPDATE chamados c JOIN users u ON u.id=c.usuario_id SET c.condominio_id=u.condominio_id WHERE c.condominio_id IS NULL');
$pdo->exec("INSERT IGNORE INTO condominio_sindicos (condominio_id,usuario_id) SELECT condominio_id,MIN(id) FROM users WHERE tipo_usuario='sindico' AND verificado=1 AND status='ativo' AND condominio_id IS NOT NULL GROUP BY condominio_id");
foreach ([
 ['users','fk_users_condominio','FOREIGN KEY (condominio_id) REFERENCES condominios(id)'],
 ['users','fk_users_unidade','FOREIGN KEY (unidade_id,condominio_id) REFERENCES unidades(id,condominio_id)'],
 ['chamados','fk_chamados_condominio','FOREIGN KEY (condominio_id) REFERENCES condominios(id)'],
 ['notificacoes','fk_notice_ticket','FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE SET NULL'],
 ['notificacoes','fk_notice_chat','FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE SET NULL'],
] as [$table,$name,$definition]) {
    if (!hasConstraint($pdo,$table,$name)) $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` $definition");
}
$duplicates=(int)$pdo->query('SELECT COUNT(*) FROM (SELECT pair_min_id,pair_max_id,contexto_chamado_id FROM chats GROUP BY pair_min_id,pair_max_id,contexto_chamado_id HAVING COUNT(*)>1) d')->fetchColumn();
if ($duplicates) throw new RuntimeException('Existem conversas duplicadas no mesmo contexto; reconcilie os participantes e mensagens antes de criar o índice. Nenhuma conversa foi removida.');
// Legacy uk_chat_pair may be the only index supporting the participant FK.
// Keep separate indexes before removing uniqueness across different contexts.
foreach (['idx_chats_user_a'=>'user_a_id','idx_chats_user_b'=>'user_b_id'] as $index=>$column) {
    if (!hasIndex($pdo,'chats',$index)) $pdo->exec("ALTER TABLE chats ADD INDEX `$index` (`$column`)");
}
if (!hasIndex($pdo,'chats','uk_chat_contexto')) $pdo->exec('ALTER TABLE chats ADD UNIQUE KEY uk_chat_contexto (pair_min_id,pair_max_id,contexto_chamado_id)');
foreach (['uk_chat_pair','uk_chat_normalized_pair'] as $index) {
    if (hasIndex($pdo,'chats',$index)) $pdo->exec("ALTER TABLE chats DROP INDEX `$index`");
}
echo "COMMUNITY_MIGRATION_OK\n";
}
