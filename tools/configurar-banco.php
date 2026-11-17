<?php
declare(strict_types=1);

// Configura e testa a conexão do CondoConnect com o MySQL, em um só comando.
// Uso, no servidor, dentro da pasta do site (ex.: cd /var/www/html/sisc-test):
//   php tools/configurar-banco.php
// Pergunta host, porta, banco, usuário e senha, testa a conexão, diz exatamente o que está
// errado quando falha e, se der certo, grava o .env (guardando uma cópia do anterior).
// Somente linha de comando: a pasta tools/ é bloqueada na web e este arquivo recusa execução por HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$envPath = $root . '/.env';

function out(string $text = ''): void { fwrite(STDOUT, $text . PHP_EOL); }
function fail(string $text): void { fwrite(STDERR, PHP_EOL . 'ERRO: ' . $text . PHP_EOL); exit(1); }
function ask(string $label, string $default, bool $secret = false): string {
    fwrite(STDOUT, $label . ($default !== '' && !$secret ? " [{$default}]" : ($secret && $default !== '' ? ' [Enter mantém a atual]' : '')) . ': ');
    $hide = $secret && DIRECTORY_SEPARATOR === '/' && posix_isatty_safe();
    if ($hide) shell_exec('stty -echo');
    $line = fgets(STDIN);
    if ($hide) { shell_exec('stty echo'); fwrite(STDOUT, PHP_EOL); }
    $line = $line === false ? '' : rtrim($line, "\r\n");
    return $line === '' ? $default : $line;
}
function posix_isatty_safe(): bool { return function_exists('posix_isatty') ? posix_isatty(STDIN) : (function_exists('stream_isatty') && stream_isatty(STDIN)); }

/** Lê o .env atual só para sugerir valores; não executa nada do arquivo. */
function currentEnv(string $path): array {
    $values = [];
    if (!is_file($path)) return $values;
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (!preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) continue;
        $value = trim($m[2]);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'") && strlen($value) > 1 && substr($value, -1) === $value[0]) {
            $value = substr($value, 1, -1);
            if ($m[2][0] === '"') $value = strtr($value, ['\\"' => '"', '\\\\' => '\\']);
        } else {
            $value = rtrim((string)preg_replace('/\s+#.*$/', '', $value));
        }
        $values[$m[1]] = $value;
    }
    return $values;
}
function pick(array $env, array $keys, string $default): string {
    foreach ($keys as $key) if (isset($env[$key]) && $env[$key] !== '') return $env[$key];
    return $default;
}
function quoteEnv(string $value): string { return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"']) . '"'; }

/** Troca só as linhas do banco, remove aliases duplicados e preserva todo o resto do .env. */
function envWithDatabase(array $lines, array $new): array {
    $written = [];
    $result = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*(?:export\s+)?(DB_HOST|DB_PORT|DB_NAME|DB_USER|DB_PASS|DB_DATABASE|DB_USERNAME|DB_PASSWORD)\s*=/', $line, $m)) {
            $key = ['DB_DATABASE' => 'DB_NAME', 'DB_USERNAME' => 'DB_USER', 'DB_PASSWORD' => 'DB_PASS'][$m[1]] ?? $m[1];
            if (!isset($written[$key])) $result[] = $key . '=' . quoteEnv($new[$key]);
            $written[$key] = true;
            continue;
        }
        $result[] = $line;
    }
    foreach ($new as $key => $value) if (!isset($written[$key])) $result[] = $key . '=' . quoteEnv($value);
    return $result;
}

/** Traduz o código do MySQL para o que precisa ser feito. */
function explainFailure(PDOException $e, array $c): string {
    $code = (int)($e->errorInfo[1] ?? 0);
    if ($code === 0 && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) $code = (int)$m[1];
    return match (true) {
        $code === 1045 => "Usuário ou senha do MySQL incorretos para '{$c['user']}' a partir de '{$c['host']}'. Confira a senha ou crie o usuário:\n"
            . "  sudo mysql -e \"CREATE USER IF NOT EXISTS '{$c['user']}'@'localhost' IDENTIFIED BY 'SENHA_FORTE'; GRANT ALL PRIVILEGES ON `{$c['name']}`.* TO '{$c['user']}'@'localhost'; FLUSH PRIVILEGES;\"\n"
            . "  (se o MySQL estiver em outra máquina ou contêiner, troque 'localhost' pelo endereço de onde o PHP conecta, ou por '%')",
        $code === 1044 || $code === 1142 => "O usuário '{$c['user']}' existe, mas não tem permissão no banco '{$c['name']}'. Libere com:\n"
            . "  sudo mysql -e \"GRANT ALL PRIVILEGES ON `{$c['name']}`.* TO '{$c['user']}'@'localhost'; FLUSH PRIVILEGES;\"",
        $code === 1049 => "O banco '{$c['name']}' não existe nesse servidor MySQL. Crie e importe as tabelas com:\n"
            . "  mysql -h {$c['host']} -P {$c['port']} -u {$c['user']} -p < db_sisc.sql\n"
            . "  (o arquivo cria o banco db_sisc; se o seu banco tiver outro nome, use DB_NAME=db_sisc ou ajuste o arquivo)",
        in_array($code, [2002, 2003, 2005, 2006], true) => "Não há MySQL respondendo em {$c['host']}:{$c['port']}. Verifique:\n"
            . "  - se o MySQL está ligado:  sudo systemctl status mysql   (ou mariadb)\n"
            . "  - se o host está certo: no mesmo servidor use 127.0.0.1; com Docker, use o nome do serviço do banco no docker-compose\n"
            . "  - se a porta é 3306 ou outra configurada",
        $code === 1698 => "O usuário 'root' do MySQL só entra pelo sudo (autenticação por socket) e não serve para o site. Crie um usuário próprio:\n"
            . "  sudo mysql -e \"CREATE USER 'condo'@'localhost' IDENTIFIED BY 'SENHA_FORTE'; GRANT ALL PRIVILEGES ON `{$c['name']}`.* TO 'condo'@'localhost'; FLUSH PRIVILEGES;\"\n"
            . "  e rode este comando de novo usando o usuário condo.",
        default => 'O MySQL recusou a conexão (código ' . ($code ?: 'desconhecido') . '). Mensagem do driver: ' . $e->getMessage(),
    };
}

out('CondoConnect · configuração do banco de dados');
out('Pasta do site: ' . $root);
if (!extension_loaded('pdo_mysql')) {
    fail("O PHP deste servidor não tem o driver MySQL (pdo_mysql). Instale e reinicie o Apache:\n  sudo apt install php-mysql && sudo systemctl restart apache2");
}
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
    if (getenv($key) !== false) out("Aviso: a variável {$key} está definida no ambiente do servidor e tem prioridade sobre o .env.");
}

$env = currentEnv($envPath);
out(is_file($envPath) ? 'Arquivo .env encontrado; os valores atuais aparecem entre colchetes.' : 'Ainda não existe .env; ele será criado.');
out();
$c = [
    'host' => trim(ask('Endereço do MySQL (DB_HOST)', pick($env, ['DB_HOST'], '127.0.0.1'))),
    'port' => trim(ask('Porta (DB_PORT)', pick($env, ['DB_PORT'], '3306'))),
    'name' => trim(ask('Nome do banco (DB_NAME)', pick($env, ['DB_NAME', 'DB_DATABASE'], 'db_sisc'))),
    'user' => trim(ask('Usuário do MySQL (DB_USER)', pick($env, ['DB_USER', 'DB_USERNAME'], ''))),
];
$c['password'] = ask('Senha do MySQL (DB_PASS, não aparece ao digitar)', pick($env, ['DB_PASS', 'DB_PASSWORD'], ''), true);

if ($c['host'] === '' || preg_match('/[;\s]/', $c['host'])) fail('Informe um endereço de MySQL válido.');
if (!preg_match('/^\d{1,5}$/D', $c['port']) || (int)$c['port'] < 1 || (int)$c['port'] > 65535) fail('Informe uma porta entre 1 e 65535.');
if ($c['name'] === '' || strlen($c['name']) > 64 || preg_match('/[;\s`]/', $c['name'])) fail('Informe um nome de banco válido.');
if ($c['user'] === '') fail('Informe o usuário do MySQL. Não use root: crie um usuário só para o site.');
if (strtolower($c['user']) === 'root') out('Aviso: usar root no site não é recomendado. Funciona, mas crie um usuário próprio quando puder.');

$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 8];
out();
out('Testando a conexão...');
try {
    $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['password'], $options);
} catch (PDOException $e) {
    fail(explainFailure($e, $c) . "\n\nNada foi gravado. Corrija e rode de novo: php tools/configurar-banco.php");
}
out('Conexão OK: MySQL ' . $pdo->query('SELECT VERSION()')->fetchColumn());

// Grava o .env: troca só as linhas do banco e preserva todo o resto (Brevo, Mercado Pago etc.).
$lines = is_file($envPath) ? (file($envPath, FILE_IGNORE_NEW_LINES) ?: []) : (is_file($root . '/.env.example') ? (file($root . '/.env.example', FILE_IGNORE_NEW_LINES) ?: []) : []);
$new = ['DB_HOST' => $c['host'], 'DB_PORT' => $c['port'], 'DB_NAME' => $c['name'], 'DB_USER' => $c['user'], 'DB_PASS' => $c['password']];
$result = envWithDatabase($lines, $new);

if (is_file($envPath)) {
    $backup = $envPath . '.bak-' . date('Ymd-His');
    if (!copy($envPath, $backup)) fail('Não foi possível guardar a cópia do .env atual. Nada foi alterado.');
    out('Cópia do .env anterior: ' . basename($backup));
}
$temporary = $envPath . '.tmp-' . getmypid();
if (file_put_contents($temporary, implode(PHP_EOL, $result) . PHP_EOL, LOCK_EX) === false) fail('Não foi possível gravar o .env nesta pasta. Rode com sudo.');
if (is_file($envPath)) { @chmod($temporary, fileperms($envPath) & 0777); @chown($temporary, fileowner($envPath)); @chgrp($temporary, filegroup($envPath)); }
else @chmod($temporary, 0644);
if (!rename($temporary, $envPath)) { @unlink($temporary); fail('Não foi possível substituir o .env.'); }
out('.env gravado.');

// Confere se as tabelas do sistema existem.
$tables = array_map('strtolower', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
$missing = array_values(array_diff(['users', 'chamados', 'chats', 'condominios', 'comunicados', 'areas_comuns', 'reservas'], $tables));
out();
if (in_array('users', $missing, true)) {
    out('O banco está vazio: faltam as tabelas do CondoConnect. Importe uma vez com:');
    out("  mysql -h {$c['host']} -P {$c['port']} -u {$c['user']} -p {$c['name']} < db_sisc.sql");
    out('Depois rode: php backend/database/update_application.php');
    exit(1);
}
if ($missing) {
    out('Faltam tabelas de versões mais novas: ' . implode(', ', $missing) . '. Atualize com (faça backup antes):');
    out('  php backend/database/update_application.php');
    exit(1);
}
out('Usuários cadastrados: ' . $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
out();
out('BANCO_OK — o site, o app web e o APK usam esta mesma configuração.');
out('Se o site ainda mostrar "banco indisponível", reinicie o Apache: sudo systemctl restart apache2');
