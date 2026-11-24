<?php
declare(strict_types=1);
require_once __DIR__ . '/HttpTestCase.php';

final class HttpFlowsTest extends HttpTestCase
{
    public function testAdminCommunitySelectionAndAuthenticatedLoginRedirect(): void
    {
        self::assertSame(302,$this->login('admin')['status']);
        $login=$this->request('/pages/login.php');
        self::assertSame(302,$login['status']);
        self::assertStringNotContainsString('auth-frame',$login['body']);
        foreach(['comunicados','reservas','condominio','chat','admin-dashboard','admin-usuarios','admin-verificacoes'] as $page) {
            $response=$this->request('/pages/'.$page.'.php?condominio_id='.$this->buildings[1]);
            self::assertSame(200,$response['status'],$page);
            self::assertStringNotContainsString('Não foi possível concluir a operação',$response['body'],$page);
        }
        $response=$this->post('/pages/comunicados.php?condominio_id='.$this->buildings[1],['action'=>'salvar','titulo'=>'Aviso da administração','conteudo'=>'Encontro da comunidade','status'=>'publicado']);
        self::assertSame(302,$response['status']);
        $page=$this->request('/pages/comunicados.php?condominio_id='.$this->buildings[1]);
        self::assertStringContainsString('Aviso da administração',$page['body']);
    }
    public function testConversationCanBeOpenedFromTheChatPageAndMessagesAreExchanged(): void
    {
        $this->login('resident');
        $page = $this->request('/pages/chat.php');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Iniciar conversa', $page['body']);
        self::assertStringContainsString('value="' . $this->ids['provider'] . '"', $page['body']);
        $opened = $this->post('/pages/chat.php', ['action'=>'open_chat', 'usuario_id'=>$this->ids['provider']]);
        self::assertSame(302, $opened['status']);
        $chat = (int)dbRow('SELECT id FROM chats WHERE user_a_id=? OR user_b_id=?', [$this->ids['resident'],$this->ids['resident']])['id'];
        self::assertGreaterThan(0, $chat);
        self::assertSame(200, $this->post('/api/chat.php', ['chat_id'=>$chat,'mensagem'=>'Olá pelo chat'])['status']);
        $response = $this->request('/api/chat.php?chat_id=' . $chat);
        self::assertSame(200, $response['status']);
        self::assertSame('Olá pelo chat', json_decode($response['body'], true)['messages'][0]['mensagem']);
        self::assertSame(400, $this->post('/api/chat.php', ['action'=>'invalid','chat_id'=>$chat,'mensagem'=>'Não salvar'])['status']);
        self::assertSame(1, (int)dbRow('SELECT COUNT(*) AS n FROM chat_mensagens WHERE chat_id=?', [$chat])['n']);
    }

    public function testLegacyCookieDoesNotReplaceApplicationSession(): void
    {
        $page = $this->request('/pages/login.php');
        self::assertStringContainsString($this->csrf, $page['body']);
        self::assertStringContainsString('SISCSESSID', file_get_contents($this->cookieFile));
        file_put_contents($this->cookieFile, "127.0.0.1\tFALSE\t/\tFALSE\t0\tPHPSESSID\tforeign-session\n", FILE_APPEND);
        self::assertSame(302, $this->login('resident')['status']);
        self::assertSame(200, $this->request('/pages/dashboard.php')['status']);
    }

    public function testOldFormAfterLogoutIsRejectedAndFreshFormWorks(): void
    {
        self::assertSame(302, $this->login('resident')['status']);
        self::assertSame(302, $this->post('/logout.php')['status']);
        $expired = $this->post('/pages/login.php', ['email' => 'resident@example.test', 'senha' => 'Seguro123!']);
        self::assertSame(403, $expired['status']);
        self::assertStringContainsString('href="/pages/login.php"', $expired['body']);
        $page = $this->request('/pages/login.php');
        preg_match('/name="csrf" value="([a-f0-9]{64})"/', $page['body'], $matches);
        self::assertNotSame($this->csrf, $matches[1]);
        $this->csrf = $matches[1];
        self::assertSame(302, $this->login('resident')['status']);
    }

    public function testCsrfSessionsLoginLogoutAndProtectedPages(): void
    {
        $tab = $this->request('/pages/login.php'); self::assertStringContainsString($this->csrf, $tab['body']);
        self::assertSame(403, $this->request('/api/notificacoes.php', 'POST', ['csrf' => []])['status']);
        self::assertSame(401, $this->request('/api/notificacoes.php')['status']);
        self::assertSame(401, $this->request('/api/chat.php')['status']);
    }

    public function testLoginAndAllAuthenticatedPages(): void
    {
        self::assertSame(302, $this->request('/pages/dashboard.php')['status']);
        self::assertSame(403, $this->request('/pages/login.php', 'POST', ['email' => 'resident@example.test', 'senha' => 'Seguro123!'])['status']);
        self::assertSame(403, $this->request('/pages/login.php', 'POST', ['csrf' => []])['status']);
        self::assertSame(200, $this->post('/pages/login.php', ['email' => 'resident@example.test', 'senha' => 'wrong'])['status']);
        self::assertSame(302, $this->login('resident')['status']);
        foreach (['dashboard','perfil','meus-chamados','novo-chamado','chat','comunicados','reservas','condominio','notificacoes','pesquisa','assistente','pagamentos','integracoes'] as $page) self::assertSame(200, $this->request('/pages/' . $page . '.php')['status'], $page);
        self::assertSame(403, $this->request('/pages/admin-dashboard.php')['status']);
        $this->post('/pages/perfil.php', ['nome' => 'Novo nome', 'bio' => 'Nova bio', 'two_factor_enabled' => '0']); self::assertSame('Nova bio', findUserById($this->ids['resident'])['bio']);
        $response = $this->post('/pages/novo-chamado.php', ['categoria' => 'Hidráulica', 'titulo' => 'Vazamento', 'descricao' => 'Água vazando', 'local_problema' => 'Cozinha', 'prioridade' => 'Média', 'data_servico' => date('Y-m-d', strtotime('+1 day'))]); self::assertSame(302, $response['status']);
        $ticket = getChamadosForUser($this->ids['resident'])[0]; self::assertSame('aberto', $ticket['status']); self::assertSame(200, $this->request('/pages/detalhes-chamado.php?id=' . $ticket['id'])['status']);
        self::assertSame(302, $this->post('/pages/notificacoes.php', ['action' => 'mark_read'])['status']); self::assertSame(0, (int)notificationSummary($this->ids['resident'])['nao_lidas']);
        self::assertSame(200, $this->request('/api/avatar.php?id=' . $this->ids['resident'])['status']); self::assertSame(404, $this->request('/api/avatar.php?id=' . $this->ids['outsider'])['status']); self::assertSame(404, $this->request('/api/anexo.php?id=999999')['status']);
        self::assertSame(405, $this->request('/logout.php')['status']); self::assertSame(403, $this->request('/logout.php', 'POST')['status']); self::assertSame(302, $this->post('/logout.php')['status']); self::assertSame(302, $this->request('/pages/dashboard.php')['status']);
    }

    public function testJsonCsrfAssistantAndNotificationEndpoints(): void
    {
        self::assertSame(302, $this->login('resident')['status']);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $response = $this->request('/api/openai.php', 'POST', json_encode(['question' => 'Vazamento']), $headers); self::assertSame(403, $response['status']); self::assertSame('csrf_invalid', json_decode($response['body'], true)['code']);
        $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        self::assertSame(400, $this->request('/api/openai.php', 'POST', '{"question":[]}', $headers)['status']);
        self::assertSame(400, $this->post('/pages/perfil.php', ['nome' => ['invalid']])['status']);
        $response = $this->request('/api/openai.php', 'POST', json_encode(['question' => 'Vazamento']), $headers); self::assertSame(200, $response['status']); self::assertSame('mock', json_decode($response['body'], true)['mode']);
        self::assertSame(400, $this->request('/api/openai.php', 'POST', '{}', $headers)['status']); self::assertSame(400, $this->request('/api/openai.php', 'POST', '{bad', $headers)['status']); self::assertSame(400, $this->request('/api/openai.php', 'POST', '[]', $headers)['status']); self::assertSame(405, $this->request('/api/openai.php')['status']); self::assertSame(204, $this->request('/api/openai.php', 'OPTIONS')['status']);
        createNotification($this->ids['resident'], 'Aviso', 'Teste');
        self::assertSame(200, $this->request('/api/notificacoes.php')['status']); self::assertSame(403, $this->request('/api/notificacoes.php', 'POST')['status']); self::assertSame(200, $this->post('/api/notificacoes.php')['status']); self::assertSame(0, (int)notificationSummary($this->ids['resident'])['nao_lidas']); self::assertSame(405, $this->request('/api/notificacoes.php', 'HEAD')['status']);
        self::assertSame(404, $this->request('/api/chat.php?chat_id=999999')['status']); self::assertSame(405, $this->request('/api/chat.php', 'HEAD')['status']); self::assertSame(422, $this->post('/api/chat.php', ['chat_id' => 999999, 'mensagem' => 'Oi'])['status']); self::assertSame(403, $this->post('/api/chat.php', ['action' => 'accept_ticket'])['status']);
    }

    public function testBearerApisRejectForgedTokensAndPersistTickets(): void
    {
        $json = ['Content-Type: application/json'];
        self::assertSame(405, $this->request('/api/login.php')['status']); self::assertSame(204, $this->request('/api/login.php', 'OPTIONS')['status']);
        self::assertSame(400, $this->request('/api/login.php', 'POST', '{}', $json)['status']); self::assertSame(401, $this->request('/api/login.php', 'POST', '{"email":"resident@example.test","senha":"wrong"}', $json)['status']);
        $response = $this->request('/api/login.php', 'POST', '{"email":"resident@example.test","senha":"Seguro123!"}', $json); self::assertSame(200, $response['status']);
        $payload = json_decode($response['body'], true); $token = $payload['token']; self::assertArrayNotHasKey('senha', $payload['user']); self::assertNotSame((string)$this->ids['resident'], $token);
        $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
        self::assertSame(401, $this->request('/api/me.php')['status']); self::assertSame(401, $this->request('/api/me.php', 'GET', null, ['Authorization: Bearer ' . $this->ids['resident']])['status']); self::assertSame(200, $this->request('/api/me.php', 'GET', null, $headers)['status']); self::assertSame(405, $this->request('/api/me.php', 'POST')['status']); self::assertSame(204, $this->request('/api/me.php', 'OPTIONS')['status']);
        self::assertSame(401, $this->request('/api/tickets.php')['status']); self::assertSame(401, $this->request('/api/tickets.php', 'GET', null, ['Authorization: Bearer invalid'])['status']); self::assertSame(204, $this->request('/api/tickets.php', 'OPTIONS')['status']); self::assertSame(405, $this->request('/api/tickets.php', 'HEAD', null, $headers)['status']);
        $ticket = ['categoria' => 'Hidráulica', 'titulo' => 'Vazamento', 'descricao' => 'Água vazando', 'localProblema' => 'Cozinha', 'prioridade' => 'Média', 'dataServico' => date('Y-m-d', strtotime('+1 day'))];
        foreach ([[], ['categoria' => 'inexistente'] + $ticket, ['prioridade' => 'inexistente'] + $ticket, ['dataServico' => '2026-02-30'] + $ticket] as $bad) self::assertSame(400, $this->request('/api/tickets.php', 'POST', json_encode((object)$bad), $headers)['status']);
        self::assertSame(201, $this->request('/api/tickets.php', 'POST', json_encode($ticket), $headers)['status']); self::assertCount(1, json_decode($this->request('/api/tickets.php', 'GET', null, $headers)['body'], true)['tickets']);
        dbExecute('UPDATE sessions SET created_at=DATE_SUB(NOW(),INTERVAL 8 DAY)'); self::assertSame(401, $this->request('/api/me.php', 'GET', null, $headers)['status']);
        dbExecute('UPDATE users SET two_factor_enabled=1 WHERE id=?', [$this->ids['resident']]); self::assertSame(403, $this->request('/api/login.php', 'POST', '{"email":"resident@example.test","senha":"Seguro123!"}', $json)['status']);
    }

    public function testRegistrationApiValidationAndPublicPages(): void
    {
        foreach (['cadastro','login','recuperar-senha','redefinir-senha','sobre','servicos','contato','faq'] as $page) self::assertSame(200, $this->request('/pages/' . $page . '.php')['status'], $page);
        self::assertSame(405, $this->request('/api/register.php')['status']); self::assertSame(204, $this->request('/api/register.php', 'OPTIONS')['status']);
        $data = ['nome' => 'Novo', 'email' => 'novo@example.test', 'telefone' => '11987654321', 'cpf' => '12345678900', 'senha' => 'Seguro123!', 'confirmarSenha' => 'Seguro123!', 'condominio' => 'Alpha', 'unidade' => '101'];
        foreach ([[], ['confirmarSenha' => 'wrong'] + $data, ['senha' => '123', 'confirmarSenha' => '123'] + $data, ['email' => 'invalid'] + $data, ['tipoUsuario' => 'admin'] + $data, ['email' => 'resident@example.test'] + $data] as $bad) {
            $status = $this->request('/api/register.php', 'POST', json_encode((object)$bad), ['Content-Type: application/json'])['status']; self::assertContains($status, [400, 409]);
        }
        self::assertSame(201, $this->request('/api/register.php', 'POST', json_encode($data), ['Content-Type: application/json'])['status']); self::assertSame(0, (int)findUserByEmail('novo@example.test')['email_verificado']);
        self::assertSame(200, $this->post('/pages/cadastro.php', ['nome' => 'Incompleto'])['status']); self::assertSame(200, $this->post('/pages/recuperar-senha.php', ['email' => 'missing@example.test'])['status']);
        self::assertSame(302, $this->post('/pages/contato.php', ['nome' => 'Contato', 'email' => 'contato@example.test', 'assunto' => 'Assunto', 'mensagem' => 'Mensagem'])['status']); self::assertSame(1, (int)dbRow('SELECT COUNT(*) n FROM contatos')['n']);
    }

    public function testManagementFormsAndApiRegistry(): void
    {
        self::assertSame(302, $this->login('syndic')['status']);
        self::assertSame(302, $this->post('/pages/comunicados.php', ['action' => 'salvar', 'titulo' => 'Aviso', 'conteudo' => 'Texto'])['status']);
        self::assertCount(1, listComunicados($this->ids['resident'])); $notice = listComunicados($this->ids['resident'])[0];
        self::assertSame(302, $this->post('/pages/comunicados.php', ['action' => 'salvar', 'id' => $notice['id'], 'titulo' => 'Editado', 'conteudo' => 'Novo texto'])['status']);
        self::assertSame(302, $this->post('/pages/condominio.php', ['descricao' => 'Descrição', 'regras' => 'Regras', 'contato' => 'Contato'])['status']);
        self::assertSame('Descrição', userCondominio($this->ids['resident'])['descricao']);
        $this->post('/logout.php'); $this->request('/pages/login.php'); preg_match('/name="csrf" value="([a-f0-9]{64})"/', $this->request('/pages/login.php')['body'], $matches); $this->csrf = $matches[1];
        self::assertSame(302, $this->login('admin')['status']); foreach (['admin-dashboard','admin-usuarios','admin-verificacoes'] as $page) self::assertSame(200, $this->request('/pages/' . $page . '.php')['status']);
        foreach (['status','list','demo','api&api=brevo','unknown'] as $action) { $response = $this->request('/api/teste_apis.php', 'POST', json_encode(['action' => $action]), ['Content-Type: application/json']); self::assertContains($response['status'], [200, 400]); }
        self::assertSame(204, $this->request('/api/teste_apis.php', 'OPTIONS')['status']); self::assertSame(405, $this->request('/api/teste_apis.php', 'HEAD')['status']);
        self::assertSame(200, $this->request('/api/mercadopago.php')['status']); self::assertSame(200, $this->request('/api/mercadopago.php', 'POST', '{"action":"status"}', ['Content-Type: application/json'])['status']); self::assertSame(204, $this->request('/api/mercadopago.php', 'OPTIONS')['status']); self::assertSame(405, $this->request('/api/mercadopago.php', 'HEAD')['status']);
        self::assertSame(400, $this->request('/api/mercadopago.php', 'POST', '{"action":"unknown"}', ['Content-Type: application/json'])['status']);
    }

    public function testProviderChatEndpointsAndResidentReview(): void
    {
        $ticket = $this->ticket(); self::assertSame(302, $this->login('provider')['status']);
        $response = $this->post('/api/chat.php', ['action' => 'accept_ticket', 'chamado_id' => $ticket]); self::assertSame(200, $response['status']); $chat = json_decode($response['body'], true)['chat_id'];
        self::assertSame(200, $this->post('/api/chat.php', ['chat_id' => $chat, 'mensagem' => 'Resposta'])['status']); self::assertSame(200, $this->request('/api/chat.php?chat_id=' . $chat)['status']);
        self::assertSame(200, $this->request('/pages/detalhes-chamado.php?id=' . $ticket)['status']);
        self::assertSame(302, $this->post('/pages/detalhes-chamado.php?id=' . $ticket, ['service_action' => 'responder', 'resposta' => 'Proposta'])['status']);
        self::assertSame(302, $this->post('/pages/atualizar-chamado.php', ['chamado_id' => $ticket, 'status' => 'concluido'])['status']);
        $this->post('/logout.php'); preg_match('/name="csrf" value="([a-f0-9]{64})"/', $this->request('/pages/login.php')['body'], $matches); $this->csrf = $matches[1]; self::assertSame(302, $this->login('resident')['status']);
        self::assertSame(200, $this->post('/pages/avaliar-chamado.php?chamado_id=' . $ticket, ['chamado_id' => $ticket, 'nota' => 5, 'comentario' => 'Bom'])['status']); self::assertSame('5.00', findUserById($this->ids['provider'])['avaliacao_media']);
    }

    public function testReservationsAnnouncementsAndUploadedImagesThroughForms(): void
    {
        self::assertSame(302, $this->login('resident')['status']);
        $r = $this->ids['resident']; $cond = $this->buildings[0];
        dbExecute('INSERT INTO areas_comuns (condominio_id,nome) VALUES (?,?)', [$cond, 'Salão']); $area = (int)getPDO()->lastInsertId();
        self::assertSame(302, $this->post('/pages/reservas.php', ['area_id' => $area, 'inicio' => date('Y-m-d\TH:i', strtotime('+2 days')), 'fim' => date('Y-m-d\TH:i', strtotime('+2 days +1 hour'))])['status']);
        $reservation = listReservas($r)[0]; self::assertSame(302, $this->post('/pages/reservas.php', ['action' => 'cancelar', 'id' => $reservation['id']])['status']); self::assertSame('cancelada', listReservas($r)[0]['status']);
        $notice = saveComunicado($this->ids['syndic'], $cond, ['titulo' => 'Aviso', 'conteudo' => 'Texto']);
        self::assertSame(302, $this->post('/pages/comunicados.php', ['action' => 'ler', 'id' => $notice])['status']); self::assertNotNull(listComunicados($r)[0]['lido_em']);
        $file = tempnam(sys_get_temp_dir(), 'sisc-image-'); $savedFiles = [];
        try {
            file_put_contents($file, '<?php echo "bad";');
            $response = $this->request('/pages/perfil.php', 'POST', ['csrf' => $this->csrf, 'nome' => 'resident', 'avatar' => new CURLFile($file, 'image/jpeg', 'fake.jpg')]);
            self::assertSame(200, $response['status']); self::assertStringContainsString('Formato de arquivo inválido', $response['body']); self::assertEmpty(findUserById($r)['avatar']);
            file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOlsAAAAASUVORK5CYII='));
            self::assertSame(200, $this->request('/pages/perfil.php', 'POST', ['csrf' => $this->csrf, 'nome' => 'resident', 'avatar' => new CURLFile($file, 'image/png', 'avatar.png'), 'portfolio[]' => new CURLFile($file, 'image/png', 'portfolio.png')])['status']);
            $user = findUserById($r); self::assertNotEmpty($user['avatar']); $savedFiles[] = dirname(__DIR__) . '/backend/storage/perfil/' . $user['avatar'];
            foreach (json_decode($user['portfolio_imagens'], true) as $name) $savedFiles[] = dirname(__DIR__) . '/backend/storage/perfil/' . $name;
            $response = $this->request('/api/avatar.php?id=' . $r); self::assertSame(200, $response['status']); self::assertStringContainsString('image/png', $response['headers']);
            // O portfólio sai por api/documento.php: backend/storage é bloqueada na web.
            $profile = $this->request('/pages/perfil.php'); self::assertStringContainsString('api/documento.php?tipo=portfolio&amp;id=' . $r . '&amp;n=0', $profile['body']); self::assertStringNotContainsString('backend/storage', $profile['body']);
            $response = $this->request('/api/documento.php?tipo=portfolio&id=' . $r . '&n=0'); self::assertSame(200, $response['status']); self::assertSame(file_get_contents($file), $response['body']);
            self::assertSame(404, $this->request('/api/documento.php?tipo=portfolio&id=' . $r . '&n=5')['status']);
            $ticket = ['csrf' => $this->csrf, 'categoria' => 'Hidráulica', 'titulo' => 'Vazamento', 'descricao' => 'Teste', 'local_problema' => 'Cozinha', 'prioridade' => 'Média', 'data_servico' => date('Y-m-d', strtotime('+2 days')), 'anexo' => new CURLFile($file, 'image/png', 'anexo.png')];
            self::assertSame(302, $this->request('/pages/novo-chamado.php', 'POST', $ticket)['status']);
            $attachment = dbRow('SELECT * FROM anexos ORDER BY id DESC LIMIT 1'); $savedFiles[] = dirname(__DIR__) . '/backend/storage/uploads/' . $attachment['arquivo'];
            $response = $this->request('/api/anexo.php?id=' . $attachment['id']); self::assertSame(200, $response['status']); self::assertSame(file_get_contents($file), $response['body']);
            $this->post('/logout.php'); preg_match('/name="csrf" value="([a-f0-9]{64})"/', $this->request('/pages/login.php')['body'], $matches); $this->csrf = $matches[1]; $this->login('outsider'); self::assertSame(404, $this->request('/api/anexo.php?id=' . $attachment['id'])['status']);
        } finally { unlink($file); foreach ($savedFiles as $path) if (is_file($path)) unlink($path); }
    }
    public function testResetFormAndPaymentAuthorization(): void
    {
        $id = $this->ids['resident']; $token = createPasswordResetToken($id);
        self::assertSame(200, $this->request('/pages/redefinir-senha.php?token=' . $token)['status']);
        $response = $this->post('/pages/redefinir-senha.php', ['token' => $token, 'nova_senha' => 'NovaSenha123!', 'confirmar_senha' => 'NovaSenha123!']);
        self::assertSame(200, $response['status']); self::assertStringContainsString('Senha redefinida com sucesso', $response['body']); self::assertNull(validatePasswordResetToken($token));
        self::assertSame($id, (int)authenticateUser('resident@example.test', 'NovaSenha123!')['id']);
        $jsonHeaders = ['Content-Type: application/json', 'X-CSRF-Token: ' . $this->csrf];
        self::assertSame(403, $this->request('/api/mercadopago.php', 'POST', '{"action":"oauth_token"}', $jsonHeaders)['status']);
        self::assertSame(403, $this->request('/api/mercadopago.php', 'POST', '{"action":"create_payment"}', $jsonHeaders)['status']);
        self::assertSame(403, $this->request('/api/mercadopago.php', 'POST', '{"action":"create_payment"}', ['Content-Type: application/json'])['status']);
        self::assertSame(302, $this->login('admin')['status']);
        self::assertSame(400, $this->request('/api/mercadopago.php', 'POST', '{"action":"oauth_token"}', $jsonHeaders)['status']);
        self::assertSame(400, $this->request('/api/mercadopago.php', 'POST', '{"action":"create_payment"}', $jsonHeaders)['status']);
        self::assertSame(200, $this->request('/api/teste_apis.php', 'POST', '{"action":"api","api":"brevo"}', ['Content-Type: application/json'])['status']);
        self::assertSame(404, $this->request('/api/teste_apis.php', 'POST', '{"action":"api","api":"invalid"}', ['Content-Type: application/json'])['status']);
    }
    public function testWebRegistrationAndEmailFailuresStayReviewable(): void
    {
        $response = $this->post('/pages/cadastro.php', ['nome' => 'Nova conta', 'email' => 'nova@example.test', 'cpf' => '12345678900', 'telefone' => '11987654321', 'senha' => 'Seguro123!', 'confirmar_senha' => 'Seguro123!', 'condominio' => 'Alpha', 'unidade' => '101']);
        self::assertSame(200, $response['status']); self::assertStringContainsString('Conta criada', $response['body']);
        $user = findUserByEmail('nova@example.test'); self::assertNotNull($user); self::assertSame(0, (int)$user['email_verificado']);
        $token = dbRow('SELECT token FROM email_verification_tokens WHERE user_id=?', [$user['id']])['token'];
        $response = $this->request('/pages/verificar-email.php?token=' . $token); self::assertContains($response['status'], [200, 302]); self::assertSame(1, (int)findUserById((int)$user['id'])['email_verificado']);
        dbExecute('UPDATE users SET two_factor_enabled=1 WHERE id=?', [$this->ids['provider']]);
        $response = $this->login('provider'); self::assertSame(200, $response['status']); self::assertStringContainsString('Não foi possível enviar o código', $response['body']); self::assertStringNotContainsString('name="codigo_2fa"', $response['body']);
        self::assertSame(302, $this->login('admin')['status']);
        dbExecute("UPDATE users SET condominio_id=?,verificado=0,certificado_status='pendente' WHERE id=?", [$this->buildings[0], $this->ids['otherSyndic']]);
        self::assertSame(302, $this->post('/pages/admin-verificacoes.php', ['user_id' => $this->ids['otherSyndic'], 'status' => 'aprovado'])['status']);
        self::assertSame(0, (int)findUserById($this->ids['otherSyndic'])['verificado']);
    }
}
