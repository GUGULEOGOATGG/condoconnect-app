<?php
require_once __DIR__ . '/../includes/auth.php';
requireVerifiedEmail();

$pageTitle = 'Conversas';
$metaDescription = 'Chat interno entre moradores, síndicos e prestadores.';
$bodyClass = 'dashboard-page chat-page';

$user = currentUser();
$role = (string) ($user['tipo_usuario'] ?? 'morador');
$errorText = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'open_chat') {
    try {
        $otherUser = filter_input(INPUT_POST, 'usuario_id', FILTER_VALIDATE_INT) ?: 0;
        $chatId = getOrCreateChat((int)$user['id'], $otherUser);
        redirect(getBasePath() . 'pages/chat.php?chat=' . $chatId);
    } catch (Throwable $e) { $errorText = friendlyError($e); }
}
$contacts = listChatContacts((int)$user['id']);
$chats = listUserChats((int) $user['id']);
$selectedChatId = isset($_GET['chat']) ? (int) $_GET['chat'] : 0;
$selectedMessages = [];
$selectedContact = null;
$selectedTicketId = null;

if ($selectedChatId > 0) {
    $selectedMessages = listChatMessages($selectedChatId, (int)$user['id']);
    foreach ($chats as $chatItem) {
        if ((int) $chatItem['id'] === $selectedChatId) {
            $selectedContact = [
                'id' => (int) $chatItem['contato_id'],
                'nome' => (string) $chatItem['contato_nome'],
                'tipo' => (string) $chatItem['contato_tipo'],
            ];
            $selectedTicketId = !empty($chatItem['chamado_id']) ? (int)$chatItem['chamado_id'] : null;
            break;
        }
    }
}

if ($selectedChatId > 0 && !userCanAccessChat($selectedChatId, (int)$user['id'])) {
    http_response_code(404);
    $selectedChatId = 0;
}

if ($selectedChatId <= 0 && !isset($_GET['chat']) && !empty($chats)) {
    $selectedChatId = (int) $chats[0]['id'];
    $selectedMessages = listChatMessages($selectedChatId, (int)$user['id']);
    $selectedContact = [
        'id' => (int) $chats[0]['contato_id'],
        'nome' => (string) $chats[0]['contato_nome'],
        'tipo' => (string) $chats[0]['contato_tipo'],
    ];
    $selectedTicketId = !empty($chats[0]['chamado_id']) ? (int)$chats[0]['chamado_id'] : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mensagem']) && $selectedChatId > 0) {
    if (!sendChatMessage($selectedChatId, (int) $user['id'], (string) $_POST['mensagem'])) {
        $_SESSION['chat_error'] = 'A mensagem deve conter até 4.000 caracteres e você precisa participar da conversa.';
    }
    header('Location: ' . getBasePath() . 'pages/chat.php?chat=' . $selectedChatId);
    exit;
}
$chats = listUserChats((int)$user['id']);
$selectedProfile = $selectedContact !== null ? findUserById((int) $selectedContact['id']) : null;
require __DIR__ . '/../includes/header.php';
?>
<main class="section section-single">
    <div class="container">
        <div class="section-header page-heading">
            <span>Conexões</span>
            <h1>Conversas</h1>
            <p>Fale com as pessoas que fazem parte da sua comunidade.</p>
        </div>
        <?php if ($errorText !== ''): ?><div class="alert alert-error" role="alert"><?= sanitize($errorText) ?></div><?php endif; ?>
        <div class="chat-shell <?= $selectedContact !== null ? 'chat-shell--selected' : '' ?>">
            <aside class="chat-sidebar" aria-label="Suas conversas">
                <div class="chat-header-box">
                    <h2>Suas conversas <span class="chat-total"><?= count($chats) ?></span></h2>
                    <p>Selecione um contato para começar.</p>
                </div>
                <?php if ($contacts): ?>
                    <form method="post" class="chat-new-conversation">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="open_chat">
                        <label for="chat-contact">Nova conversa</label>
                        <select id="chat-contact" name="usuario_id" required>
                            <option value="">Selecione um contato</option>
                            <?php foreach ($contacts as $contact): ?>
                                <option value="<?= (int) $contact['id'] ?>"><?= sanitize($contact['nome']) ?> — <?= sanitize(getUserRoleLabel($contact['tipo_usuario'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-primary"><?= condoIcon('chat') ?> Iniciar conversa</button>
                    </form>
                <?php else: ?>
                    <p class="chat-no-contacts">Nenhum contato disponível para o seu perfil.</p>
                <?php endif; ?>
                <nav class="chat-list" aria-label="Lista de conversas">
                    <?php if ($chats === []): ?>
                        <div class="empty-state compact"><p>Suas conversas aparecerão aqui.</p></div>
                    <?php else: ?>
                        <?php foreach ($chats as $chat): ?>
                            <a class="chat-item <?= ((int) $chat['id'] === $selectedChatId) ? 'active' : '' ?>" <?= ((int) $chat['id'] === $selectedChatId) ? 'aria-current="page"' : '' ?> href="<?= getBasePath() ?>pages/chat.php?chat=<?= (int) $chat['id'] ?>">
                                <span class="chat-avatar" aria-hidden="true"><?= sanitize(mb_strtoupper(mb_substr((string) $chat['contato_nome'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
                                <span class="chat-item-copy">
                                    <span class="chat-item-heading">
                                        <strong><?= sanitize($chat['contato_nome']) ?></strong>
                                        <?php if (!empty($chat['updated_at'])): ?><time datetime="<?= sanitize(date('c', strtotime((string) $chat['updated_at']))) ?>"><?= sanitize(date('H:i', strtotime((string) $chat['updated_at']))) ?></time><?php endif; ?>
                                    </span>
                                    <span class="chat-item-role"><?= sanitize(getUserRoleLabel($chat['contato_tipo'])) ?><?= !empty($chat['chamado_id']) ? ' · Chamado #' . (int) $chat['chamado_id'] : ' · Conversa direta' ?></span>
                                    <span class="chat-item-preview">
                                        <small><?= sanitize(trim((string) ($chat['ultimo_resumo'] ?? '')) ?: 'Nenhuma mensagem ainda') ?></small>
                                        <?php if ((int) $chat['nao_lidas'] > 0): ?><span class="chat-item-unread" aria-label="<?= (int) $chat['nao_lidas'] ?> mensagens não lidas"><?= (int) $chat['nao_lidas'] > 99 ? '99+' : (int) $chat['nao_lidas'] ?></span><?php endif; ?>
                                    </span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </nav>
            </aside>

            <section class="chat-content" aria-label="Conversa selecionada">
                <?php if ($selectedContact !== null): ?>
                    <div class="chat-panel-head">
                        <a class="chat-back" href="<?= getBasePath() ?>pages/chat.php?chat=0" aria-label="Voltar à lista de conversas"><?= condoIcon('arrow') ?></a>
                        <span class="chat-avatar" aria-hidden="true"><?= sanitize(mb_strtoupper(mb_substr($selectedContact['nome'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
                        <div class="chat-contact-info">
                            <strong><?= sanitize($selectedContact['nome']) ?></strong>
                            <small><?= sanitize(getUserRoleLabel($selectedContact['tipo'])) ?><?= $selectedTicketId !== null ? ' · Chamado #' . $selectedTicketId : ' · Conversa direta' ?></small>
                        </div>
                        <?php if ($selectedProfile && canViewProfile($user, $selectedProfile)): ?><a class="chat-profile-link" href="<?= getBasePath() ?>pages/perfil.php?id=<?= (int) $selectedContact['id'] ?>">Ver perfil</a><?php endif; ?>
                    </div>

                    <?php if (!empty($_SESSION['chat_error'])): ?><div class="alert alert-error" role="alert"><?= sanitize($_SESSION['chat_error']) ?></div><?php unset($_SESSION['chat_error']); endif; ?>
                    <div class="chat-messages" id="chat-messages" tabindex="0" aria-label="Histórico de mensagens" data-chat-id="<?= $selectedChatId ?>" data-endpoint="<?= getBasePath() ?>api/chat.php">
                        <?php foreach ($selectedMessages as $message): ?>
                            <div class="message-row <?= ((int) $message['remetente_id'] === (int) $user['id']) ? 'mine' : '' ?>">
                                <div class="bubble">
                                    <strong><?= sanitize($message['remetente_nome']) ?></strong>
                                    <p><?= sanitize($message['mensagem']) ?></p>
                                    <span><?= sanitize(date('d/m H:i', strtotime((string) $message['created_at']))) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="chat-feedback" role="status" aria-live="polite"></div>
                    <form class="chat-form" method="post" id="chat-form">
                        <?= csrfField() ?>
                        <label class="visually-hidden" for="chat-message">Sua mensagem</label>
                        <input id="chat-message" type="text" name="mensagem" maxlength="4000" placeholder="Escreva sua mensagem..." autocomplete="off" required>
                        <button type="submit" class="button button-primary">Enviar <?= condoIcon('arrow') ?></button>
                    </form>
                <?php else: ?>
                    <div class="chat-welcome">
                        <div class="chat-welcome__icon"><?= condoIcon('chat') ?></div>
                        <h2>Uma boa conversa começa aqui</h2>
                        <p>Selecione uma conversa ao lado ou escolha um contato para iniciar uma nova.</p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
