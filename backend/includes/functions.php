<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/community.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/tickets.php';
require_once __DIR__ . '/uploads.php';

function getBasePath(): string
{
    $override = getenv('APP_BASE_PATH');
    if ($override !== false) return '/' . (trim($override, '/') === '' ? '' : trim($override, '/') . '/');
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $file = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    if (strpos($file, $root . '/') === 0) {
        $relative = substr($file, strlen($root));
        if (substr($script, -strlen($relative)) === $relative) {
            return rtrim(substr($script, 0, -strlen($relative)), '/') . '/';
        }
    }
    return '/';
}

function sanitize($value): string
{
    if ($value !== null && !is_scalar($value) && !$value instanceof Stringable) {
        throw new InvalidArgumentException('Valor inválido para sanitização.');
    }
    return htmlspecialchars(trim((string) $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function getPDO(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        require_once __DIR__ . '/../services/database_connection.php';
        $pdo = createDatabaseConnection(require dirname(__DIR__) . '/config/database.php');
    }
    return $pdo;
}

function ensureUserProfileColumns(): void
{
    $pdo = getPDO();
    $columns = array_map(fn($row) => $row['Field'], $pdo->query('SHOW COLUMNS FROM users')->fetchAll());

    $definitions = [
        'avatar' => "VARCHAR(255) NULL",
        'bio' => 'TEXT NULL',
        'especialidades' => 'TEXT NULL',
        'servicos_prestados' => 'TEXT NULL',
        'portfolio_imagens' => 'TEXT NULL',
        'avaliacao_media' => 'DECIMAL(3,2) NOT NULL DEFAULT 0.00',
        'documento_certificado' => 'VARCHAR(255) NULL',
        'certificado_status' => "ENUM('pendente','aprovado','rejeitado') NOT NULL DEFAULT 'aprovado'",
        'verificado' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'email_verificado' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'two_factor_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'two_factor_code_hash' => 'VARCHAR(255) NULL',
        'two_factor_expires_at' => 'DATETIME NULL',
    ];

    foreach ($definitions as $field => $definition) {
        if (!in_array($field, $columns, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN ' . $field . ' ' . $definition);
        }
    }
}

function ensurePasswordResetTable(): void
{
    $pdo = getPDO();
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        token VARCHAR(255) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_verification_tokens (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        token VARCHAR(255) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function hashPassword(string $password): string
{
    if (str_contains($password, "\0") || !mb_check_encoding($password, 'UTF-8')) {
        throw new DomainException('A senha contém caracteres inválidos.');
    }
    $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    if ($algorithm === PASSWORD_BCRYPT && strlen($password) > 72) {
        throw new DomainException('Senhas com mais de 72 bytes exigem PHP com suporte a Argon2id.');
    }
    return password_hash($password, $algorithm);
}

function passwordMatches(string $password, string $hash): bool
{
    if (str_contains($password, "\0") || !mb_check_encoding($password, 'UTF-8')) return false;
    return password_verify($password, $hash);
}

function userFormString(array $data, string $field, string $default = ''): string
{
    $value = $data[$field] ?? $default;
    if (!is_string($value) && !is_numeric($value)) throw new DomainException('Campo inválido: ' . $field . '.');
    if (str_contains((string)$value, "\0") || !mb_check_encoding((string)$value, 'UTF-8')) throw new DomainException('Caracteres inválidos no campo ' . $field . '.');
    return (string)$value;
}

function normalizeRegistrationData(array $data): array
{
    $normalized = [];
    foreach (['nome', 'cpf', 'email', 'telefone', 'senha', 'tipo_usuario', 'condominio', 'unidade', 'documento_certificado'] as $field) {
        $value = userFormString($data, $field, $field === 'tipo_usuario' ? 'morador' : '');
        $normalized[$field] = $field === 'senha' ? $value : trim($value);
    }
    foreach (['nome', 'email', 'senha', 'condominio', 'telefone'] as $field) {
        if ($normalized[$field] === '') throw new DomainException('Preencha todos os campos obrigatórios.');
    }
    foreach (['nome' => 190, 'email' => 190, 'condominio' => 190, 'unidade' => 190, 'documento_certificado' => 255] as $field => $limit) {
        if (mb_strlen($normalized[$field], 'UTF-8') > $limit) throw new DomainException('O campo ' . $field . ' excede o limite permitido.');
    }
    $normalized['email'] = strtolower($normalized['email']);
    if (!filter_var($normalized['email'], FILTER_VALIDATE_EMAIL)) throw new DomainException('Informe um e-mail válido.');
    if (strlen($normalized['senha']) < 8 || strlen($normalized['senha']) > 255) throw new DomainException('Use uma senha entre 8 e 255 caracteres.');
    $normalized['telefone'] = preg_replace('/\D+/', '', $normalized['telefone']);
    if (strlen($normalized['telefone']) < 10 || strlen($normalized['telefone']) > 11) throw new DomainException('Informe um telefone válido com DDD e número.');
    if ($normalized['cpf'] !== '') {
        $normalized['cpf'] = preg_replace('/\D+/', '', $normalized['cpf']);
        if (strlen($normalized['cpf']) !== 11) throw new DomainException('Informe um CPF com 11 dígitos.');
    }
    if (!in_array($normalized['tipo_usuario'], ['morador', 'prestador', 'sindico'], true)) throw new DomainException('Perfil não permitido no cadastro público.');
    if ($normalized['tipo_usuario'] === 'sindico' && $normalized['documento_certificado'] === '') throw new DomainException('Síndicos devem anexar o certificado para aprovação.');
    return $normalized;
}

function createUser(array $data): int
{
    $data = normalizeRegistrationData($data);
    $pdo = getPDO();
    $senha = $data['senha'] ?? '';
    $senha = hashPassword($senha);

    $tipoUsuario = $data['tipo_usuario'] ?? 'morador';
    $building=dbRow("SELECT id,nome FROM condominios WHERE nome=? AND status='ativo'",[trim((string)($data['condominio']??''))]);
    if (!$building) throw new DomainException('Selecione um condomínio cadastrado.');
    if (findUserByEmail($data['email']) !== null) throw new DomainException('Este e-mail já está cadastrado.');
    if (dbRow('SELECT id FROM users WHERE telefone=? LIMIT 1', [$data['telefone']])) throw new DomainException('Este telefone já está cadastrado.');
    $certificadoStatus = in_array($tipoUsuario, ['sindico'], true) ? 'pendente' : 'aprovado';
    $documentoCertificado = trim((string) ($data['documento_certificado'] ?? ''));
    $verificado = $tipoUsuario === 'sindico' ? 0 : 1;
    if ($tipoUsuario === 'sindico') {
        $existing = $pdo->prepare("SELECT COUNT(*) FROM users WHERE tipo_usuario = 'sindico' AND status = 'ativo' AND verificado = 1 AND condominio = :condominio");
        $existing->execute([':condominio' => (string)($data['condominio'] ?? '')]);
        if ((int)$existing->fetchColumn() > 0) throw new RuntimeException('Este condomínio já possui um síndico ativo.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (nome, cpf, email, telefone, senha, tipo_usuario, condominio, condominio_id, unidade, documento_certificado, certificado_status, verificado, email_verificado) VALUES (:nome, :cpf, :email, :telefone, :senha, :tipo_usuario, :condominio, :condominio_id, :unidade, :documento_certificado, :certificado_status, :verificado, 0)'
    );
    $stmt->execute([
        ':nome' => $data['nome'] ?? '',
        ':cpf' => $data['cpf'] ?? '',
        ':email' => $data['email'] ?? '',
        ':telefone' => $data['telefone'] ?? '',
        ':senha' => $senha,
        ':tipo_usuario' => $tipoUsuario,
        ':condominio' => $data['condominio'] ?? '',
        ':condominio_id' => $building['id'],
        ':unidade' => $data['unidade'] ?? '',
        ':documento_certificado' => $documentoCertificado,
        ':certificado_status' => $certificadoStatus,
        ':verificado' => $verificado,
    ]);
    return (int) $pdo->lastInsertId();
}

function authenticateUser(string $email, string $password): ?array
{
    $normalizedEmail = strtolower(trim($email));
    $user = findUserByEmail($normalizedEmail);
    if ($user === null || ($user['status'] ?? '') !== 'ativo' || !demoLoginAllowed((string)$user['email'])) {
        return null;
    }

    if (!passwordMatches($password, (string) ($user['senha'] ?? ''))) {
        return null;
    }

    return $user;
}

function demoLoginAllowed(string $email): bool
{
    return !str_ends_with(strtolower($email), '@demo.example.test') || getenv('APP_DEMO_LOGIN') === '1';
}

function findUserByEmail(string $email): ?array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
    $stmt->execute([':email' => trim($email)]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function findUserById(int $id): ?array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function updateUserProfile(int $userId, array $data): bool
{
    if (findUserById($userId) === null) return false;
    $pdo = getPDO();
    $fields = [];
    $params = [':id' => $userId];

    foreach (['nome', 'telefone', 'email', 'senha'] as $field) {
        if (!array_key_exists($field, $data)) continue;
        $data[$field] = userFormString($data, $field);
        if ($field !== 'senha') $data[$field] = trim($data[$field]);
    }
    if (!empty($data['nome']) && mb_strlen($data['nome'], 'UTF-8') > 190) throw new DomainException('O nome excede o limite permitido.');
    if (!empty($data['email'])) {
        $data['email'] = strtolower($data['email']);
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || strlen($data['email']) > 190) throw new DomainException('Informe um e-mail válido.');
        $existing = findUserByEmail($data['email']);
        if ($existing && (int)$existing['id'] !== $userId) throw new DomainException('Este e-mail já está cadastrado.');
    }
    if (!empty($data['telefone'])) {
        $data['telefone'] = preg_replace('/\D+/', '', $data['telefone']);
        if (strlen($data['telefone']) < 10 || strlen($data['telefone']) > 11) throw new DomainException('Informe um telefone válido com DDD e número.');
        if (dbRow('SELECT id FROM users WHERE telefone=? AND id<>? LIMIT 1', [$data['telefone'], $userId])) throw new DomainException('Este telefone já está cadastrado.');
    }
    if (!empty($data['senha']) && (strlen($data['senha']) < 8 || strlen($data['senha']) > 255)) throw new DomainException('Use uma senha entre 8 e 255 caracteres.');

    if (!empty($data['nome'])) {
        $fields[] = 'nome = :nome';
        $params[':nome'] = $data['nome'];
    }
    if (!empty($data['telefone'])) {
        $fields[] = 'telefone = :telefone';
        $params[':telefone'] = $data['telefone'];
    }
    if (!empty($data['email'])) {
        $fields[] = 'email = :email';
        $params[':email'] = $data['email'];
    }
    if (!empty($data['senha'])) {
        $fields[] = 'senha = :senha';
        $params[':senha'] = hashPassword($data['senha']);
    }

    if ($fields === []) {
        return false;
    }

    $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

function saveUserProfileData(int $userId, array $data, ?array $avatarFile = null, array $portfolioFiles = []): bool
{
    $pdo = getPDO();
    $user = findUserById($userId);
    if ($user === null) {
        return false;
    }

    foreach (['nome', 'avatar', 'bio', 'especialidades', 'servicos_prestados', 'documento_certificado', 'certificado_status', 'verificado'] as $field) {
        if (array_key_exists($field, $data)) $data[$field] = trim(userFormString($data, $field));
    }
    $name = trim((string)($data['nome'] ?? $user['nome']));
    if ($name === '' || mb_strlen($name, 'UTF-8') > 190) throw new DomainException('Informe um nome de até 190 caracteres.');
    if (isset($data['certificado_status']) && !in_array($data['certificado_status'], ['pendente', 'aprovado', 'rejeitado'], true)) throw new DomainException('Status de certificado inválido.');
    if (isset($data['verificado']) && !in_array($data['verificado'], ['0', '1'], true)) throw new DomainException('Estado de verificação inválido.');

    if (isset($portfolioFiles['name']) && is_array($portfolioFiles['name'])) {
        $normalizedPortfolio = [];
        $count = max(1, count((array) $portfolioFiles['name']));
        for ($i = 0; $i < $count; $i++) {
            if (empty($portfolioFiles['name'][$i])) {
                continue;
            }
            $normalizedPortfolio[] = [
                'name' => $portfolioFiles['name'][$i],
                'tmp_name' => $portfolioFiles['tmp_name'][$i] ?? '',
                'error' => $portfolioFiles['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $portfolioFiles['size'][$i] ?? 0,
            ];
        }
        $portfolioFiles = $normalizedPortfolio;
    }

    $avatarName = trim((string) ($data['avatar'] ?? $user['avatar'] ?? ''));
    if (is_array($avatarFile)) {
        $avatarName = storeUpload($avatarFile, dirname(__DIR__) . '/storage/perfil') ?? $avatarName;
    }

    $portfolio = [];
    $existingPortfolio = json_decode((string) ($user['portfolio_imagens'] ?? '[]'), true);
    if (is_array($existingPortfolio)) {
        $portfolio = normalizePortfolioEntries($existingPortfolio);
    }
    if (isset($data['portfolio_imagens'])) {
        if (!is_array($data['portfolio_imagens'])) throw new DomainException('Portfólio inválido.');
        $portfolio = normalizePortfolioEntries($data['portfolio_imagens']);
    }

    foreach ($portfolioFiles as $file) {
        if (!is_array($file)) throw new DomainException('Arquivo inválido.');
        $name = storeUpload($file, dirname(__DIR__) . '/storage/perfil');
        if ($name !== null) $portfolio[] = $name;
    }

    $bio = trim((string) ($data['bio'] ?? $user['bio'] ?? ''));
    $especialidades = trim((string) ($data['especialidades'] ?? $user['especialidades'] ?? ''));
    $servicosPrestados = trim((string) ($data['servicos_prestados'] ?? $user['servicos_prestados'] ?? ''));
    $documentoCertificado = trim((string) ($data['documento_certificado'] ?? $user['documento_certificado'] ?? ''));
    $certificadoStatus = isset($data['certificado_status']) ? trim((string) $data['certificado_status']): (string) ($user['certificado_status'] ?? 'aprovado');
    $verificado = isset($data['verificado']) ? (int) $data['verificado'] : (int) ($user['verificado'] ?? ($certificadoStatus === 'aprovado' ? 1 : 0));

    $stmt = $pdo->prepare('UPDATE users SET nome = :nome, avatar = :avatar, bio = :bio, especialidades = :especialidades, servicos_prestados = :servicos_prestados, portfolio_imagens = :portfolio_imagens, documento_certificado = :documento_certificado, certificado_status = :certificado_status, verificado = :verificado WHERE id = :id');
    $ok = $stmt->execute([
        ':nome' => trim((string) ($data['nome'] ?? $user['nome'] ?? '')),
        ':avatar' => $avatarName,
        ':bio' => $bio,
        ':especialidades' => $especialidades,
        ':servicos_prestados' => $servicosPrestados,
        ':portfolio_imagens' => json_encode($portfolio, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ':documento_certificado' => $documentoCertificado,
        ':certificado_status' => $certificadoStatus,
        ':verificado' => $verificado,
        ':id' => $userId,
    ]);
    if ($ok) auditAction($userId, 'alteracao_perfil', 'usuario', $userId);
    return $ok;
}

function normalizePortfolioEntries(array $entries): array
{
    foreach ($entries as $entry) {
        if (!is_string($entry)) throw new DomainException('Arquivo de portfólio inválido.');
    }
    return array_values(array_filter($entries));
}

function searchProfilesByCategory(string $category): array
{
    $search = '%' . str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], trim($category)) . '%';
    return dbRows("SELECT u.id,u.nome,u.tipo_usuario,u.condominio,u.avatar,u.bio,u.especialidades,u.servicos_prestados,COALESCE(a.media,0) AS avaliacao_media,COALESCE(a.total,0) AS total_avaliacoes FROM users u LEFT JOIN (SELECT prestador_id,AVG(nota) AS media,COUNT(*) AS total FROM avaliacoes GROUP BY prestador_id) a ON a.prestador_id=u.id WHERE u.tipo_usuario='prestador' AND u.status='ativo' AND u.email_verificado=1 AND (u.nome LIKE ? OR u.especialidades LIKE ? OR u.servicos_prestados LIKE ?) ORDER BY u.nome,u.id",[$search,$search,$search]);
}

function sendBrevoSmtpMail(string $to, string $subject, string $body, string $fromName = 'CondoConnect', ?HttpTransport $httpTransport = null, ?SmtpConnector $smtpConnector = null): bool
{
    require_once __DIR__ . '/../services/email_service.php';
    if ($fromName === 'CondoConnect') $fromName = getenv('BREVO_FROM_NAME') ?: $fromName;
    return deliverBrevoMail($to, $subject, $body, $fromName, $httpTransport, $smtpConnector);
}

function createEmailVerificationToken(int $userId): string
{
    $pdo = getPDO();
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = :user_id')->execute([':user_id' => $userId]);
    $pdo->prepare('INSERT INTO email_verification_tokens (user_id, token, expires_at) VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 1 DAY))')
        ->execute([':user_id' => $userId, ':token' => $token]);
    return $token;
}

function verifyEmailToken(string $token): bool
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT user_id FROM email_verification_tokens WHERE token = :token AND expires_at > NOW() LIMIT 1');
    $stmt->execute([':token' => $token]);
    $userId = $stmt->fetchColumn();
    if ($userId === false) {
        return false;
    }
    $pdo->prepare('UPDATE users SET email_verificado = 1 WHERE id = :id')->execute([':id' => (int) $userId]);
    $pdo->prepare('DELETE FROM email_verification_tokens WHERE token = :token')->execute([':token' => $token]);
    return true;
}

function setTwoFactorCode(int $userId, string $code): void
{
    getPDO()->prepare('UPDATE users SET two_factor_code_hash = :hash, two_factor_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = :id')
        ->execute([':hash' => password_hash($code, PASSWORD_DEFAULT), ':id' => $userId]);
}

function verifyTwoFactorCode(int $userId, string $code): bool
{
    $user = findUserById($userId);
    // Expiration is checked atomically using the same database clock that issued it.
    if ($user === null || ($user['status'] ?? '') !== 'ativo' || empty($user['two_factor_code_hash']) || empty($user['two_factor_expires_at'])) {
        return false;
    }

    if (!password_verify($code, $user['two_factor_code_hash'])) {
        return false;
    }
    $consume = getPDO()->prepare("UPDATE users SET two_factor_code_hash = NULL, two_factor_expires_at = NULL WHERE id = :id AND two_factor_code_hash = :hash AND two_factor_expires_at > NOW() AND status='ativo'");
    $consume->execute([':id' => $userId, ':hash' => $user['two_factor_code_hash']]);
    return $consume->rowCount() === 1;
}

function setTwoFactorEnabled(int $userId, bool $enabled): void
{
    getPDO()->prepare('UPDATE users SET two_factor_enabled = :enabled, two_factor_code_hash = NULL, two_factor_expires_at = NULL WHERE id = :id')
        ->execute([':enabled' => $enabled ? 1 : 0, ':id' => $userId]);
}

function createPasswordResetToken(int $userId): string
{
    $pdo = getPDO();
    $token = bin2hex(random_bytes(32));

    $pdo->prepare('DELETE FROM password_resets WHERE user_id = :user_id')->execute([':user_id' => $userId]);
    $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 1 HOUR))')->execute([
        ':user_id' => $userId,
        ':token' => $token,
    ]);

    return $token;
}

function validatePasswordResetToken(string $token): ?array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT pr.*, u.email FROM password_resets pr INNER JOIN users u ON u.id = pr.user_id WHERE pr.token = :token AND pr.expires_at > NOW() LIMIT 1');
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function clearPasswordResetToken(string $token): void
{
    $pdo = getPDO();
    $pdo->prepare('DELETE FROM password_resets WHERE token = :token')->execute([':token' => $token]);
}

function resetUserPassword(string $token, string $password): bool
{
    if (strlen($password) < 8 || strlen($password) > 255) throw new DomainException('Use uma senha entre 8 e 255 caracteres.');
    return atomic(function () use ($token, $password): bool {
        $reset = dbRow('SELECT * FROM password_resets WHERE token=? AND expires_at>NOW() FOR UPDATE', [$token]);
        if (!$reset) return false;
        dbExecute('UPDATE users SET senha=?,two_factor_code_hash=NULL,two_factor_expires_at=NULL WHERE id=?', [hashPassword($password), $reset['user_id']]);
        dbExecute('DELETE FROM sessions WHERE user_id=?', [$reset['user_id']]);
        dbExecute('DELETE FROM password_resets WHERE user_id=?', [$reset['user_id']]);
        return true;
    });
}


function addChamadoAttachment(int $chamadoId, string $arquivo): void
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO anexos (chamado_id, arquivo) VALUES (:chamado_id, :arquivo)');
    $stmt->execute([':chamado_id' => $chamadoId, ':arquivo' => $arquivo]);
}

function getChamadosForUser(int $userId): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT * FROM chamados WHERE usuario_id = :usuario_id ORDER BY criado_em DESC');
    $stmt->execute([':usuario_id' => $userId]);
    return $stmt->fetchAll();
}

function getChamadoById(int $id): ?array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT * FROM chamados WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $chamado = $stmt->fetch();
    return $chamado ?: null;
}

function saveMessage(int $chamadoId, int $remetenteId, string $mensagem): void
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO mensagens (chamado_id, remetente_id, mensagem) VALUES (:chamado_id, :remetente_id, :mensagem)');
    $stmt->execute([':chamado_id' => $chamadoId, ':remetente_id' => $remetenteId, ':mensagem' => $mensagem]);
}

function listMessagesForChamado(int $chamadoId): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT m.*, u.nome AS remetente FROM mensagens m JOIN users u ON u.id = m.remetente_id WHERE m.chamado_id = :chamado_id ORDER BY m.criado_em ASC');
    $stmt->execute([':chamado_id' => $chamadoId]);
    return $stmt->fetchAll();
}

function createNotification(int $userId,string $titulo,string $mensagem,string $tipo='sistema',?int $chamadoId=null,?int $chatId=null): void
{
    dbExecute('INSERT INTO notificacoes (usuario_id,titulo,mensagem,tipo,chamado_id,chat_id) VALUES (?,?,?,?,?,?)',[$userId,mb_substr($titulo,0,190),$mensagem,$tipo,$chamadoId,$chatId]);
}

function listNotifications(int $userId): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT * FROM notificacoes WHERE usuario_id = :usuario_id ORDER BY criado_em DESC,id DESC LIMIT 100');
    $stmt->execute([':usuario_id' => $userId]);
    return $stmt->fetchAll();
}

function createReview(int $chamadoId, int $moradorId, int $prestadorId, int $nota, string $comentario): void
{
    if ($moradorId === $prestadorId) {
        throw new InvalidArgumentException('Um usuário não pode avaliar a si próprio.');
    }
    if ($nota < 1 || $nota > 5 || mb_strlen($comentario, 'UTF-8') > 2000) throw new InvalidArgumentException('A nota deve ser de 1 a 5 e o comentário pode ter até 2.000 caracteres.');
    $pdo = getPDO();
    $allowed = $pdo->prepare("SELECT COUNT(*) FROM chamados WHERE id = :chamado AND usuario_id = :morador AND prestador_id = :prestador AND status = 'concluido'");
    $allowed->execute([':chamado' => $chamadoId, ':morador' => $moradorId, ':prestador' => $prestadorId]);
    if ((int) $allowed->fetchColumn() !== 1) throw new RuntimeException('Somente o morador do chamado concluído pode avaliar o prestador.');
    $existingReview = $pdo->prepare('SELECT COUNT(*) FROM avaliacoes WHERE chamado_id = :chamado AND morador_id = :morador');
    $existingReview->execute([':chamado' => $chamadoId, ':morador' => $moradorId]);
    if ((int)$existingReview->fetchColumn() > 0) throw new RuntimeException('Este chamado já foi avaliado por este morador.');
    $stmt = $pdo->prepare('INSERT INTO avaliacoes (chamado_id, morador_id, prestador_id, nota, comentario) VALUES (:chamado, :morador, :prestador, :nota, :comentario)');
    $stmt->execute([':chamado' => $chamadoId, ':morador' => $moradorId, ':prestador' => $prestadorId, ':nota' => $nota, ':comentario' => $comentario]);
    $pdo->prepare('UPDATE users SET avaliacao_media = (SELECT AVG(nota) FROM avaliacoes WHERE prestador_id = :prestador) WHERE id = :id')->execute([':prestador' => $prestadorId, ':id' => $prestadorId]);
    auditAction($moradorId, 'avaliacao', 'chamado', $chamadoId);
}

function getUserStats(int $userId): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT COUNT(*) as total FROM chamados WHERE usuario_id = :usuario_id');
    $stmt->execute([':usuario_id' => $userId]);
    $total = (int) $stmt->fetchColumn();

    $abertos = $pdo->prepare('SELECT COUNT(*) FROM chamados WHERE usuario_id = :usuario_id AND status = "aberto"');
    $abertos->execute([':usuario_id' => $userId]);
    $emAndamento = $pdo->prepare('SELECT COUNT(*) FROM chamados WHERE usuario_id = :usuario_id AND status = "andamento"');
    $emAndamento->execute([':usuario_id' => $userId]);
    $concluidos = $pdo->prepare('SELECT COUNT(*) FROM chamados WHERE usuario_id = :usuario_id AND status = "concluido"');
    $concluidos->execute([':usuario_id' => $userId]);
    $cancelados = $pdo->prepare('SELECT COUNT(*) FROM chamados WHERE usuario_id = :usuario_id AND status = "cancelado"');
    $cancelados->execute([':usuario_id' => $userId]);

    return [
        'total' => $total,
        'abertos' => (int) $abertos->fetchColumn(),
        'andamento' => (int) $emAndamento->fetchColumn(),
        'concluidos' => (int) $concluidos->fetchColumn(),
        'cancelados' => (int) $cancelados->fetchColumn(),
    ];
}

function ensureDefaultAdminAccount(): void
{
    throw new LogicException('Contas administrativas devem ser provisionadas com credenciais individuais.');
}

function getUserRoleLabel(string $role): string
{
    return [
        'morador' => 'Morador',
        'prestador' => 'Prestador',
        'sindico' => 'Síndico',
        'admin' => 'Administrador',
    ][$role] ?? 'Morador';
}


function getUserRoleStats(int $userId): array
{
    $tickets = getVisibleChamadosForUser($userId);
    $counts = ['aberto' => 0, 'andamento' => 0, 'concluido' => 0, 'cancelado' => 0];
    foreach ($tickets as $ticket) {
        $status = (string) ($ticket['status'] ?? 'aberto');
        if (isset($counts[$status])) {
            $counts[$status]++;
        }

    }

    return [
        'total' => count($tickets),
        'abertos' => $counts['aberto'],
        'andamento' => $counts['andamento'],
        'concluidos' => $counts['concluido'],
        'cancelados' => $counts['cancelado'],
    ];
}

function listUsersForAdmin(string $filter = ''): array
{
    $pdo = getPDO();
    $filter = trim($filter);
    if ($filter === '') {
        return $pdo->query('SELECT id, nome, email, tipo_usuario, status, certificado_status, verificado, email_verificado, criado_em FROM users ORDER BY criado_em DESC, id DESC')->fetchAll();
    }
    $stmt = $pdo->prepare('SELECT id, nome, email, tipo_usuario, status, certificado_status, verificado, email_verificado, criado_em FROM users WHERE nome LIKE :nome OR email LIKE :email OR tipo_usuario = :tipo OR status = :status ORDER BY criado_em DESC, id DESC');
    $stmt->execute([
        ':nome' => '%' . $filter . '%',
        ':email' => '%' . $filter . '%',
        ':tipo' => strtolower($filter),
        ':status' => strtolower($filter),
    ]);
    return $stmt->fetchAll();
}

function listPendingSyndicVerifications(): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE tipo_usuario = :tipo AND certificado_status = :status ORDER BY criado_em DESC');
    $stmt->execute([':tipo' => 'sindico', ':status' => 'pendente']);
    return $stmt->fetchAll();
}

function approveSyndicVerification(int $userId,int $approverId,string $status): bool
{
    $admin=activeActor($approverId);
    if ($admin['tipo_usuario']!=='admin' || !in_array($status,['aprovado','rejeitado'],true)) return false;
    return atomic(function() use($userId,$approverId,$status) {
        $target=dbRow("SELECT * FROM users WHERE id=? AND tipo_usuario='sindico' FOR UPDATE",[$userId]);
        if (!$target || !$target['condominio_id']) return false;
        $cond=(int)$target['condominio_id'];
        dbRow('SELECT id FROM condominios WHERE id=? FOR UPDATE',[$cond]);
        $manager=dbRow('SELECT usuario_id FROM condominio_sindicos WHERE condominio_id=?',[$cond]);
        if ($status==='aprovado' && $manager && (int)$manager['usuario_id']!==$userId) throw new DomainException('Este condomínio já possui um síndico responsável.');
        dbExecute('UPDATE users SET certificado_status=?,verificado=?,aprovado_por=?,aprovado_em=NOW() WHERE id=?',[$status,(int)($status==='aprovado'),$approverId,$userId]);
        if ($status==='aprovado') dbExecute('INSERT INTO condominio_sindicos (condominio_id,usuario_id) VALUES (?,?) ON DUPLICATE KEY UPDATE usuario_id=VALUES(usuario_id)',[$cond,$userId]);
        else dbExecute('DELETE FROM condominio_sindicos WHERE condominio_id=? AND usuario_id=?',[$cond,$userId]);
        auditAction($approverId,'verificacao_'.$status,'usuario',$userId); return true;
    });
}

function auditAction(?int $userId, string $action, ?string $entity = null, ?int $entityId = null, ?string $details = null): void
{
    try { getPDO()->prepare('INSERT INTO auditoria (usuario_id, acao, entidade, entidade_id, detalhes, ip) VALUES (:user, :action, :entity, :id, :details, :ip)')->execute([':user' => $userId, ':action' => $action, ':entity' => $entity, ':id' => $entityId, ':details' => $details, ':ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]); }
    catch (Throwable $e) { error_log('AUDIT_WRITE_FAILED: ' . $e->getMessage()); }
}



function getAdminDashboardStats(): array
{
    $stats=dbRow("SELECT COUNT(*) AS total_usuarios,SUM(tipo_usuario='morador') AS moradores,SUM(tipo_usuario='prestador') AS profissionais,SUM(tipo_usuario='sindico') AS sindicos FROM users");
    $stats+=dbRow("SELECT COUNT(*) AS total_chamados,COALESCE(SUM(status IN ('aberto','andamento')),0) AS chamados_ativos,COALESCE(SUM(status='aberto'),0) AS chamados_abertos,COALESCE(SUM(status='concluido'),0) AS chamados_concluidos FROM chamados");
    foreach(['condominios','reservas','comunicados'] as $table) $stats[$table]=(int)dbRow('SELECT COUNT(*) AS n FROM '.$table)['n'];
    $stats['atividades']=dbRows('SELECT h.*,u.nome AS usuario_nome,c.titulo FROM chamado_historico h LEFT JOIN users u ON u.id=h.usuario_id JOIN chamados c ON c.id=h.chamado_id ORDER BY h.id DESC LIMIT 8');
    $stats['por_condominio']=dbRows("SELECT c.id,c.nome,(SELECT COUNT(*) FROM users u WHERE u.condominio_id=c.id) AS usuarios,(SELECT COUNT(*) FROM chamados t WHERE t.condominio_id=c.id AND t.status IN ('aberto','andamento')) AS chamados FROM condominios c ORDER BY c.nome");
    return $stats;
}
