<?php
declare(strict_types=1);

// Native Android client. Bearer authentication is independent from browser sessions.
require_once __DIR__ . '/_api.php';
require_once __DIR__ . '/../backend/includes/security.php';
header('Cache-Control: no-store, private');

function mobileUser(array $user): array {
    return array_intersect_key($user, array_flip([
        'id', 'nome', 'email', 'telefone', 'tipo_usuario', 'condominio', 'unidade',
        'email_verificado', 'verificado', 'avaliacao_media', 'bio', 'especialidades', 'certificado_status',
    ]));
}
/** Optional condominium chosen by an administrator; other roles are limited to their own by the backend. */
function mobileCondominioParam(array $source): ?int {
    $value = $source['condominio_id'] ?? null;
    if ($value === null || $value === '' || $value === 0 || $value === '0') return null;
    if (!is_scalar($value) || !preg_match('/^[1-9]\d{0,17}$/D', (string)$value)) apiJson(400, ['error' => 'Condomínio inválido.']);
    return (int)$value;
}
/** What this account may do. The app only uses it to choose screens; every operation is checked again below. */
function mobileAccess(array $user, int $condominioId): array {
    $uid = (int)$user['id'];
    $role = (string)($user['tipo_usuario'] ?? 'morador');
    $active = (int)($user['email_verificado'] ?? 0) === 1;
    $admin = $active && $role === 'admin';
    return [
        'role' => $role,
        'manager' => $active && $condominioId > 0 && canManageCommunity($uid, $condominioId),
        'verified' => (int)($user['verificado'] ?? 0) === 1,
        'condominio_id' => $condominioId,
        'condominios' => $admin ? dbRows('SELECT id,nome FROM condominios ORDER BY nome,id') : [],
        'pending_verifications' => $admin ? count(listPendingSyndicVerifications()) : 0,
    ];
}
function mobileRequireAdmin(array $user): void {
    if ((string)($user['tipo_usuario'] ?? '') !== 'admin') apiJson(403, ['error' => 'Acesso restrito aos administradores.']);
}
function mobileVerificationMail(array $user): bool {
    $token = createEmailVerificationToken((int)$user['id']);
    $url = applicationOrigin() . getBasePath() . 'pages/verificar-email.php?token=' . urlencode($token);
    return sendBrevoSmtpMail((string)$user['email'], 'Confirme seu e-mail - CondoConnect',
        '<p>Olá, ' . sanitize($user['nome']) . '.</p><p><a href="' . sanitize($url) . '">Confirmar meu e-mail</a></p>');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') apiOptions();
if (!in_array($method, ['GET', 'POST'], true)) apiJson(405, ['error' => 'Método não permitido.']);
$action = $_GET['action'] ?? 'bootstrap';
if (!is_string($action) || !preg_match('/^[a-z_]{1,40}$/D', $action)) apiJson(400, ['error' => 'Operação inválida.']);
$readActions = ['ping', 'bootstrap', 'messages', 'providers', 'admin_overview', 'admin_users'];
if (($method === 'GET') !== in_array($action, $readActions, true)) {
    apiJson(405, ['error' => 'Método não permitido para esta operação.']);
}
$data = $method === 'POST' ? apiReadJson() : [];

if ($action === 'ping') apiJson(200, ['app' => 'CondoConnect', 'mobile_api' => 1]);
if ($action === 'login') {
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $password = (string)($data['senha'] ?? '');
    if ($email === '' || $password === '' || strlen($email) > 190 || strlen($password) > 255) {
        apiJson(400, ['error' => 'Informe e-mail e senha válidos.']);
    }
    $user = authenticateUser($email, $password);
    if (!$user) apiJson(401, ['error' => 'E-mail ou senha inválidos.']);
    if ((int)($user['two_factor_enabled'] ?? 0) === 1) {
        $code = trim((string)($data['codigo'] ?? ''));
        if ($code === '') {
            $code = (string)random_int(100000, 999999);
            setTwoFactorCode((int)$user['id'], $code);
            if (!sendBrevoSmtpMail($email, 'Código de acesso - CondoConnect', '<p>Seu código é <strong>' . $code . '</strong>. Ele expira em 10 minutos.</p>')) {
                apiJson(503, ['error' => 'Não foi possível enviar o código. Tente novamente mais tarde.']);
            }
            apiJson(200, ['requires_code' => true]);
        }
        if (!preg_match('/^\d{6}$/D', $code) || !verifyTwoFactorCode((int)$user['id'], $code)) {
            apiJson(401, ['error' => 'Código inválido ou expirado.']);
        }
    }
    auditAction((int)$user['id'], 'login_mobile', 'usuario', (int)$user['id']);
    apiJson(200, ['user' => mobileUser($user), 'token' => apiIssueToken((int)$user['id'])]);
}
if ($action === 'register') {
    if (!in_array((string)($data['tipo_usuario'] ?? ''), ['morador', 'prestador'], true)) {
        apiJson(400, ['error' => 'Selecione morador ou prestador. O cadastro de síndicos está disponível no site.']);
    }
    if ((string)($data['senha'] ?? '') !== (string)($data['confirmar_senha'] ?? '')) {
        apiJson(400, ['error' => 'As senhas não conferem.']);
    }
    $registration = normalizeRegistrationData($data);
    if (findUserByEmail($registration['email']) || dbRow('SELECT id FROM users WHERE telefone=? LIMIT 1', [$registration['telefone']])) {
        apiJson(409, ['error' => 'Este e-mail ou telefone já está cadastrado.']);
    }
    $id = createUser($registration);
    $user = findUserById($id);
    $sent = mobileVerificationMail($user);
    apiJson(201, ['user' => mobileUser($user), 'token' => apiIssueToken($id), 'verification_sent' => $sent]);
}
if ($action === 'forgot_password') {
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) apiJson(400, ['error' => 'Informe um e-mail válido.']);
    $user = findUserByEmail($email);
    if ($user) {
        $token = createPasswordResetToken((int)$user['id']);
        $url = applicationOrigin() . getBasePath() . 'pages/redefinir-senha.php?token=' . urlencode($token);
        if (!sendBrevoSmtpMail($email, 'Redefinir senha - CondoConnect', '<p><a href="' . sanitize($url) . '">Redefinir minha senha</a></p>')) {
            apiJson(503, ['error' => 'Não foi possível enviar o e-mail de recuperação. Tente novamente mais tarde.']);
        }
    }
    apiJson(200, ['message' => 'Se o e-mail estiver cadastrado, você receberá um link de recuperação.']);
}

$authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/Di', $authorization, $matches)) apiJson(401, ['error' => 'Entre novamente para continuar.']);
$token = $matches[1];
$user = apiTokenUser($token);
if (!$user) apiJson(401, ['error' => 'Sua sessão expirou. Entre novamente.']);
$uid = (int)$user['id'];

if ($action === 'logout') {
    dbExecute('DELETE FROM sessions WHERE user_id=? AND token=?', [$uid, hash('sha256', $token)]);
    apiJson(200, ['ok' => true]);
}
if ($action === 'resend_verification') {
    if ((int)($user['email_verificado'] ?? 0) === 1) apiJson(200, ['message' => 'Seu e-mail já foi confirmado.']);
    if (!mobileVerificationMail($user)) apiJson(503, ['error' => 'Não foi possível enviar a confirmação. Tente novamente mais tarde.']);
    apiJson(200, ['message' => 'Enviamos um novo link para seu e-mail.']);
}
if ($action === 'bootstrap' && (int)($user['email_verificado'] ?? 0) !== 1) {
    apiJson(200, ['user' => mobileUser($user), 'tickets' => [], 'chats' => [], 'contacts' => [],
        'notices' => [], 'notifications' => [], 'areas' => [], 'reservations' => [], 'community' => null,
        'access' => mobileAccess($user, 0)]);
}
// Every data operation keeps the same authorization as the website.
activeActor($uid);

switch ($action) {
    case 'bootstrap':
        $cond = communityCondominioId($uid, mobileCondominioParam($_GET));
        apiJson(200, [
            'user' => mobileUser($user), 'tickets' => getVisibleChamadosForUser($uid),
            'chats' => listUserChats($uid), 'contacts' => listChatContacts($uid),
            'notices' => listComunicados($uid, $cond), 'notifications' => listNotifications($uid),
            'areas' => listAreas($uid, $cond), 'reservations' => listReservas($uid, $cond),
            'community' => userCondominio($uid, $cond), 'access' => mobileAccess($user, $cond),
        ]);
    case 'providers':
        apiJson(200, ['providers' => searchProfilesByCategory(mb_substr((string)($_GET['q'] ?? ''), 0, 190))]);
    case 'create_ticket':
        $category = (string)($data['categoria'] ?? '');
        $priority = (string)($data['prioridade'] ?? '');
        if (!in_array($category, ['Elétrica','Hidráulica','Pintura','Limpeza','Elevadores','Jardinagem','Segurança','Reparos gerais'], true)
            || !in_array($priority, ['Baixa','Média','Alta','Urgente'], true)) apiJson(400, ['error' => 'Categoria ou prioridade inválida.']);
        $payload = array_intersect_key($data, array_flip(['titulo','descricao','local_problema','data_servico']));
        $payload += ['usuario_id' => $uid, 'categoria' => $category, 'prioridade' => $priority,
            'protocolo' => 'CP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6)))];
        $id = createChamado($payload);
        apiJson(201, ['ticket' => getChamadoById($id)]);
    case 'ticket_status':
        if (!updateChamadoStatus((int)($data['id'] ?? 0), $uid, (string)($data['status'] ?? ''))) {
            apiJson(403, ['error' => 'Você não pode alterar este chamado.']);
        }
        apiJson(200, ['ok' => true]);
    case 'accept_ticket':
        $chat = acceptChamado((int)($data['id'] ?? 0), $uid);
        apiJson(200, ['chat_id' => $chat]);
    case 'open_chat':
        $chat = getOrCreateChat($uid, (int)($data['usuario_id'] ?? 0));
        apiJson(200, ['chat_id' => $chat]);
    case 'messages':
        $chat = (int)($_GET['chat_id'] ?? 0);
        if (!userCanAccessChat($chat, $uid)) apiJson(404, ['error' => 'Conversa não encontrada.']);
        apiJson(200, ['messages' => listChatMessages($chat, $uid)]);
    case 'send_message':
        if (!sendChatMessage((int)($data['chat_id'] ?? 0), $uid, (string)($data['mensagem'] ?? ''))) {
            apiJson(422, ['error' => 'Mensagem vazia, muito longa ou conversa indisponível.']);
        }
        apiJson(201, ['ok' => true]);
    case 'reserve':
        $id = createReserva($uid, (int)($data['area_id'] ?? 0), (string)($data['inicio'] ?? ''),
            (string)($data['fim'] ?? ''), (string)($data['observacao'] ?? ''));
        apiJson(201, ['id' => $id]);
    case 'cancel_reservation':
        cancelReserva($uid, (int)($data['id'] ?? 0));
        apiJson(200, ['ok' => true]);
    case 'read_notice':
        readComunicado($uid, (int)($data['id'] ?? 0));
        apiJson(200, ['ok' => true]);
    case 'read_notification':
        markNotificationRead($uid, (int)($data['id'] ?? 0));
        apiJson(200, ['ok' => true]);
    case 'profile':
        $name = trim((string)($data['nome'] ?? ''));
        if ($name === '') apiJson(400, ['error' => 'Informe seu nome.']);
        updateUserProfile($uid, ['nome' => $name, 'telefone' => (string)($data['telefone'] ?? '')]);
        apiJson(200, ['user' => mobileUser(findUserById($uid))]);
    // Síndico responsável e administração: as funções do site conferem a permissão sobre o condomínio.
    case 'save_notice':
        $cond = communityCondominioId($uid, mobileCondominioParam($data));
        $id = saveComunicado($uid, $cond, $data, max(0, (int)($data['id'] ?? 0)));
        apiJson(200, ['id' => $id]);
    case 'delete_notice':
        deleteComunicado($uid, (int)($data['id'] ?? 0));
        apiJson(200, ['ok' => true]);
    case 'save_community':
        $cond = communityCondominioId($uid, mobileCondominioParam($data));
        saveCondominio($uid, $cond, $data);
        apiJson(200, ['ok' => true]);
    case 'join_ticket_chat':
        if ((string)$user['tipo_usuario'] !== 'sindico') apiJson(403, ['error' => 'Somente o síndico responsável pode entrar nesta conversa.']);
        $chat = dbRow("SELECT id FROM chats WHERE chamado_id=? AND status='ativo' LIMIT 1", [(int)($data['id'] ?? 0)]);
        if (!$chat || !joinTicketChatAsSyndic((int)$chat['id'], $uid)) {
            apiJson(403, ['error' => 'Não foi possível entrar nesta conversa. Verifique o condomínio e o chamado.']);
        }
        apiJson(200, ['chat_id' => (int)$chat['id']]);
    // Administração da plataforma.
    case 'admin_overview':
        mobileRequireAdmin($user);
        $stats = getAdminDashboardStats();
        $totals = [];
        foreach (['total_usuarios','moradores','profissionais','sindicos','condominios','total_chamados','chamados_ativos',
            'chamados_abertos','chamados_concluidos','reservas','comunicados'] as $key) $totals[$key] = (int)($stats[$key] ?? 0);
        $fields = array_flip(['id','nome','email','telefone','condominio','unidade','certificado_status','criado_em']);
        $pending = [];
        foreach (listPendingSyndicVerifications() as $row) {
            $pending[] = array_intersect_key($row, $fields) + ['tem_certificado' => (int)!empty($row['documento_certificado'])];
        }
        apiJson(200, ['totals' => $totals, 'by_condominio' => $stats['por_condominio'], 'activity' => $stats['atividades'], 'pending' => $pending]);
    case 'admin_users':
        mobileRequireAdmin($user);
        apiJson(200, ['users' => array_slice(listUsersForAdmin(mb_substr(is_string($_GET['q'] ?? null) ? $_GET['q'] : '', 0, 190)), 0, 300)]);
    case 'verify_syndic':
        mobileRequireAdmin($user);
        if (!approveSyndicVerification((int)($data['user_id'] ?? 0), $uid, (string)($data['status'] ?? ''))) {
            apiJson(422, ['error' => 'A verificação não foi permitida.']);
        }
        apiJson(200, ['ok' => true]);
    default:
        apiJson(404, ['error' => 'Operação não encontrada.']);
}
