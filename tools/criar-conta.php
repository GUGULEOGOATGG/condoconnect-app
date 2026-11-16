<?php
declare(strict_types=1);

// Cria uma conta já liberada em um dos níveis de acesso: morador, prestador, sindico ou admin.
// Uso, no servidor, a partir da raiz do projeto:
//   php tools/criar-conta.php --nivel=admin --nome="Nome Completo" --email=nome@dominio.com \
//       --telefone=11999990000 --condominio="Nome exato do condomínio" [--unidade="Bloco A · 101"] [--cpf=00000000000]
// A senha é pedida no terminal (ou lida da variável de ambiente CONDO_SENHA) para não ficar no histórico.
// Somente linha de comando: a pasta tools/ é bloqueada na web e este arquivo recusa execução por HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/includes/functions.php';

$options = getopt('', ['nivel:', 'nome:', 'email:', 'telefone:', 'condominio:', 'unidade::', 'cpf::']);
$level = (string)($options['nivel'] ?? '');
if (!in_array($level, ['morador', 'prestador', 'sindico', 'admin'], true)) {
    fwrite(STDERR, "Informe --nivel=morador, prestador, sindico ou admin, além de --nome, --email, --telefone e --condominio.\n");
    exit(2);
}
$password = getenv('CONDO_SENHA');
if ($password === false || $password === '') {
    fwrite(STDOUT, 'Senha da nova conta (mínimo 8 caracteres): ');
    $password = rtrim((string)fgets(STDIN), "\r\n");
}

try {
    $id = atomic(static function () use ($options, $level, $password): int {
        // O cadastro público cria morador ou prestador; síndico e administrador são promovidos em seguida,
        // na mesma transação, para que uma falha não deixe a conta pela metade.
        $id = createUser([
            'nome' => (string)($options['nome'] ?? ''), 'email' => (string)($options['email'] ?? ''),
            'telefone' => (string)($options['telefone'] ?? ''), 'cpf' => (string)($options['cpf'] ?? ''),
            'condominio' => (string)($options['condominio'] ?? ''), 'unidade' => (string)($options['unidade'] ?? ''),
            'senha' => $password, 'tipo_usuario' => $level === 'prestador' ? 'prestador' : 'morador',
        ]);
        $user = findUserById($id);
        if ($level === 'sindico') {
            $building = (int)$user['condominio_id'];
            dbRow('SELECT id FROM condominios WHERE id=? FOR UPDATE', [$building]);
            if (dbRow('SELECT usuario_id FROM condominio_sindicos WHERE condominio_id=?', [$building])) {
                throw new DomainException('Este condomínio já possui um síndico responsável.');
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
    echo "CONTA_CRIADA id={$id} nivel={$level}\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'CONTA_NAO_CRIADA: ' . friendlyError($error) . "\n");
    exit(1);
}
