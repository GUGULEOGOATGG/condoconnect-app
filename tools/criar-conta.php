<?php
declare(strict_types=1);

// Cria uma conta já liberada em um dos níveis de acesso: morador, prestador, sindico ou admin.
// Uso, no servidor, a partir da raiz do projeto:
//   php tools/criar-conta.php --nivel=admin --nome="Nome Completo" --email=nome@dominio.com \
//       --telefone=11999990000 --condominio="Nome exato do condomínio" [--unidade="Bloco A · 101"] [--cpf=00000000000]
// --criar-condominio   cria o condomínio com esse nome se ele ainda não existir
//                      (opcionais: --endereco="Rua X, 10" --cidade="São Paulo" --estado=SP).
// --listar-condominios mostra os condomínios cadastrados e sai.
// A senha é pedida no terminal (ou lida da variável de ambiente CONDO_SENHA) para não ficar no histórico.
// Somente linha de comando: a pasta tools/ é bloqueada na web e este arquivo recusa execução por HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/includes/functions.php';

$options = getopt('', ['nivel:', 'nome:', 'email:', 'telefone:', 'condominio:', 'unidade::', 'cpf::', 'criar-condominio', 'endereco::', 'cidade::', 'estado::', 'listar-condominios']);

/** Condomínios cadastrados e se já têm síndico responsável, para a pessoa escolher o nome exato. */
function listCondominios(): string {
    $rows = dbRows("SELECT c.nome, c.status, (SELECT COUNT(*) FROM condominio_sindicos s WHERE s.condominio_id=c.id) AS sindicos FROM condominios c ORDER BY c.nome");
    if (!$rows) return "  (nenhum condomínio cadastrado)\n";
    return implode('', array_map(static fn(array $row): string => '  - ' . $row['nome'] . ($row['status'] !== 'ativo' ? ' (inativo)' : '') . ((int)$row['sindicos'] ? ' · já tem síndico' : ' · sem síndico') . "\n", $rows));
}

try {
    if (isset($options['listar-condominios'])) { echo "Condomínios cadastrados:\n" . listCondominios(); exit(0); }
} catch (Throwable $error) {
    fwrite(STDERR, 'CONTA_NAO_CRIADA: ' . friendlyError($error) . "\n"); exit(1);
}

$level = (string)($options['nivel'] ?? '');
if (!in_array($level, ['morador', 'prestador', 'sindico', 'admin'], true)) {
    fwrite(STDERR, "Informe --nivel=morador, prestador, sindico ou admin, além de --nome, --email, --telefone e --condominio.\n"
        . "Veja os condomínios com: php tools/criar-conta.php --listar-condominios\n");
    exit(2);
}
$password = getenv('CONDO_SENHA');
if ($password === false || $password === '') {
    fwrite(STDOUT, 'Senha da nova conta (mínimo 8 caracteres): ');
    $password = rtrim((string)fgets(STDIN), "\r\n");
}

try {
    $createdBuilding = '';
    $id = atomic(static function () use ($options, $level, $password, &$createdBuilding): int {
        $buildingName = trim((string)($options['condominio'] ?? ''));
        if (isset($options['criar-condominio']) && $buildingName !== '' && !dbRow('SELECT id FROM condominios WHERE nome=?', [$buildingName])) {
            $field = static fn(string $key, string $default): string => trim((string)($options[$key] ?? '')) ?: $default;
            if (mb_strlen($buildingName) > 190) throw new DomainException('O nome do condomínio pode ter até 190 caracteres.');
            dbExecute('INSERT INTO condominios (nome,endereco,cidade,estado,latitude,longitude) VALUES (?,?,?,?,0,0)',
                [$buildingName, mb_substr($field('endereco', 'A confirmar'), 0, 255), mb_substr($field('cidade', 'A confirmar'), 0, 190), mb_substr($field('estado', '--'), 0, 190)]);
            $createdBuilding = $buildingName;
        }
        // O cadastro público cria morador ou prestador; síndico e administrador são promovidos em seguida,
        // na mesma transação, para que uma falha não deixe a conta pela metade.
        $id = createUser([
            'nome' => (string)($options['nome'] ?? ''), 'email' => (string)($options['email'] ?? ''),
            'telefone' => (string)($options['telefone'] ?? ''), 'cpf' => (string)($options['cpf'] ?? ''),
            'condominio' => $buildingName, 'unidade' => (string)($options['unidade'] ?? ''),
            'senha' => $password, 'tipo_usuario' => $level === 'prestador' ? 'prestador' : 'morador',
        ]);
        $user = findUserById($id);
        if ($level === 'sindico') {
            $building = (int)$user['condominio_id'];
            dbRow('SELECT id FROM condominios WHERE id=? FOR UPDATE', [$building]);
            if (dbRow('SELECT usuario_id FROM condominio_sindicos WHERE condominio_id=?', [$building])) {
                throw new DomainException('Este condomínio já possui um síndico responsável. Use outro condomínio (ou crie um com --criar-condominio).');
            }
            dbExecute("UPDATE users SET tipo_usuario='sindico',certificado_status='aprovado',verificado=1,aprovado_em=NOW() WHERE id=?", [$id]);
            dbExecute('INSERT INTO condominio_sindicos (condominio_id,usuario_id) VALUES (?,?)', [$building, $id]);
        } elseif ($level === 'admin') {
            dbExecute("UPDATE users SET tipo_usuario='admin',verificado=1 WHERE id=?", [$id]);
        }
        dbExecute('UPDATE users SET email_verificado=1 WHERE id=?', [$id]);
        auditAction(null, 'conta_criada_cli_' . $level, 'usuario', $id);
        return $id;
    });
    if ($createdBuilding !== '') echo "CONDOMINIO_CRIADO {$createdBuilding}\n";
    echo "CONTA_CRIADA id={$id} nivel={$level}\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'CONTA_NAO_CRIADA: ' . friendlyError($error) . "\n");
    if ($error instanceof DomainException && str_contains($error->getMessage(), 'condomínio cadastrado')) {
        try { fwrite(STDERR, "Use o nome exato de um destes condomínios, ou acrescente --criar-condominio:\n" . listCondominios()); } catch (Throwable) {}
    }
    exit(1);
}
