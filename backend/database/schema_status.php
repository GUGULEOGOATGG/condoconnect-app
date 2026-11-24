<?php
declare(strict_types=1);

/** Version recorded in app_migrations by update_application.php (and on the first authenticated visit to the site). */
const APP_SCHEMA_VERSION = '20261002_chat_admin_demo_v2';

/** Every table created by db_sisc.sql. The site, the web app and the APK need all of them. */
const APP_REQUIRED_TABLES = [
    'users', 'condominios', 'chamados', 'anexos', 'mensagens', 'notificacoes', 'avaliacoes', 'sessions', 'contatos',
    'password_resets', 'email_verification_tokens', 'chats', 'chat_participantes', 'chat_mensagens', 'auditoria',
    'chamado_historico', 'blocos', 'unidades', 'condominio_sindicos', 'areas_comuns', 'reservas', 'comunicados',
    'comunicado_leituras', 'comunicado_historico', 'chamado_propostas',
];

/**
 * Which required tables are missing and whether this release's migration was applied.
 * Only table names from this project are reported: never hosts, users or driver messages.
 */
function databaseSchemaStatus(PDO $pdo): array
{
    $present = array_map('strtolower', $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_diff(APP_REQUIRED_TABLES, $present));
    $migrated = false;
    if (in_array('app_migrations', $present, true)) {
        $query = $pdo->prepare('SELECT COUNT(*) FROM app_migrations WHERE version = ?');
        $query->execute([APP_SCHEMA_VERSION]);
        $migrated = (int)$query->fetchColumn() > 0;
    }
    return ['missing_tables' => $missing, 'migrated' => $migrated];
}

/** Divide um arquivo SQL em comandos, respeitando ponto e vírgula dentro de textos e comentários "-- ". */
function splitSqlStatements(string $sql): array {
    $statements = []; $current = ''; $quote = null; $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        if ($quote !== null) {
            $current .= $char;
            if ($char === '\\' && $i + 1 < $length) { $current .= $sql[++$i]; continue; }
            if ($char === $quote) {
                if ($i + 1 < $length && $sql[$i + 1] === $quote) { $current .= $sql[++$i]; continue; }
                $quote = null;
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') { $quote = $char; $current .= $char; continue; }
        if ($char === '-' && substr($sql, $i, 3) === '-- ') {
            $end = strpos($sql, "\n", $i);
            $i = $end === false ? $length : $end;
            $current .= "\n";
            continue;
        }
        if ($char === ';') { if (trim($current) !== '') $statements[] = trim($current); $current = ''; continue; }
        $current .= $char;
    }
    if (trim($current) !== '') $statements[] = trim($current);
    return $statements;
}
