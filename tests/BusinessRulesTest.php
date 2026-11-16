<?php
declare(strict_types=1);
require_once __DIR__ . '/DatabaseTestCase.php';

final class BusinessRulesTest extends DatabaseTestCase
{
    public function testAdministratorCanManageSelectedCommunityWithoutLeakingOtherResidentsData(): void
    {
        $admin=$this->ids['admin']; $resident=$this->ids['resident']; $cond=$this->buildings[1];
        self::assertSame($cond,communityCondominioId($admin,$cond));
        $this->rejects(fn()=>communityCondominioId($resident,$cond));
        $notice=saveComunicado($admin,$cond,['titulo'=>'Aviso administrativo','conteudo'=>'Agenda da comunidade']);
        self::assertCount(1,listComunicados($admin,$cond));
        self::assertSame([],listComunicados($resident));
        readComunicado($admin,$notice);
        dbExecute('INSERT INTO areas_comuns (condominio_id,nome,descricao,regras) VALUES (?,?,?,?)',[$cond,'Salão','Espaço da comunidade','Organizar após o uso']);
        $area=(int)getPDO()->lastInsertId();
        $start=date('Y-m-d\TH:i',strtotime('+4 days')); $end=date('Y-m-d\TH:i',strtotime('+4 days +1 hour'));
        $reservation=createReserva($admin,$area,$start,$end,'Reunião administrativa');
        self::assertCount(1,listReservas($admin,$cond));
        self::assertSame([],listReservas($resident));
        $this->rejects(fn()=>createReserva($resident,$area,$start,$end));
        cancelReserva($admin,$reservation);
        self::assertSame('cancelada',listReservas($admin,$cond)[0]['status']);
        $chat=getOrCreateChat($admin,$resident);
        self::assertTrue(sendChatMessage($chat,$admin,'Olá, posso ajudar?'));
        self::assertSame('Olá, posso ajudar?',listChatMessages($chat,$resident)[0]['mensagem']);
    }
    public function testChatContactsRespectCondominiumRolesAndAccountStatus(): void
    {
        $resident = $this->ids['resident'];
        $contacts = array_column(listChatContacts($resident), 'id');
        self::assertContains($this->ids['provider'], $contacts);
        self::assertContains($this->ids['syndic'], $contacts);
        self::assertNotContains($this->ids['otherSyndic'], $contacts);
        self::assertNotContains($this->ids['outsider'], $contacts);
        self::assertContains($this->ids['admin'], $contacts);
        foreach ($contacts as $contact) self::assertTrue(canUsersChat($resident, (int)$contact));
        dbExecute("UPDATE users SET status='bloqueado' WHERE id=?", [$this->ids['provider']]);
        dbExecute('UPDATE users SET verificado=0 WHERE id=?', [$this->ids['syndic']]);
        $contacts = array_column(listChatContacts($resident), 'id');
        self::assertNotContains($this->ids['provider'], $contacts);
        self::assertNotContains($this->ids['syndic'], $contacts);
        self::assertNotEmpty(listChatContacts($this->ids['admin']));
    }

    public function testAuthenticationSessionAndBlockedAccounts(): void
    {
        require_once dirname(__DIR__) . '/backend/includes/auth.php';
        $id = $this->ids['resident'];
        self::assertSame($id, (int)authenticateUser(' RESIDENT@example.test ', 'Seguro123!')['id']);
        self::assertNull(authenticateUser('resident@example.test', 'wrong'));
        self::assertNull(authenticateUser('missing@example.test', 'Seguro123!'));
        self::assertNull(currentUser()); self::assertFalse(isLoggedIn());
        establishUserSession(findUserById($id)); self::assertTrue(isLoggedIn()); self::assertFalse(isAdminUser());
        requireLogin(); requireVerifiedEmail();
        dbExecute("UPDATE users SET status='bloqueado' WHERE id=?", [$id]);
        self::assertNull(authenticateUser('resident@example.test', 'Seguro123!')); self::assertNull(currentUser());
        $this->rejects(fn() => activeActor($id)); $this->rejects(fn() => activeActor(999999));
        establishUserSession(findUserById($this->ids['admin'])); self::assertTrue(isAdminUser()); self::assertSame($this->ids['admin'], (int)requireAdmin()['id']);
    }

    public function testRegistrationAndProfilePersistence(): void
    {
        $data = ['nome' => 'Novo', 'email' => 'novo@example.test', 'telefone' => '11987654321', 'cpf' => '12345678900', 'senha' => 'Seguro123!', 'condominio' => 'Alpha', 'unidade' => '202'];
        $id = createUser($data); $user = findUserById($id);
        self::assertSame(0, (int)$user['email_verificado']); self::assertTrue(passwordMatches('Seguro123!', $user['senha']));
        self::assertSame($this->buildings[0], (int)$user['condominio_id']);
        self::assertFalse(updateUserProfile($id, []));
        self::assertTrue(updateUserProfile($id, ['nome' => 'Editado', 'telefone' => '11987654322', 'email' => 'editado@example.test', 'senha' => 'NovaSenha!']));
        self::assertSame($id, (int)authenticateUser('editado@example.test', 'NovaSenha!')['id']);
        self::assertFalse(saveUserProfileData(999999, []));
        self::assertTrue(saveUserProfileData($id, ['bio' => ' Minha bio ', 'especialidades' => 'Hidráulica', 'servicos_prestados' => 'Reparos'], ['error' => UPLOAD_ERR_NO_FILE], ['name' => [''], 'tmp_name' => ['']]));
        self::assertSame('Minha bio', findUserById($id)['bio']);
        self::assertSame('[]', findUserById($id)['portfolio_imagens']);
        self::assertNotEmpty(searchProfilesByCategory('')); self::assertSame([], searchProfilesByCategory('inexistente'));
        $this->rejects(fn() => createUser(['tipo_usuario' => 'admin'] + $data));
        $this->rejects(fn() => createUser(['condominio' => 'inexistente'] + $data));
    }

    public function testVerificationResetAndTwoFactorTokens(): void
    {
        $id = $this->ids['resident'];
        dbExecute('UPDATE users SET email_verificado=0 WHERE id=?', [$id]);
        $this->rejects(fn() => activeActor($id));
        $token = createEmailVerificationToken($id); self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertFalse(verifyEmailToken('wrong')); self::assertTrue(verifyEmailToken($token)); self::assertFalse(verifyEmailToken($token));
        $expired = createEmailVerificationToken($id); dbExecute('UPDATE email_verification_tokens SET expires_at=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE user_id=?', [$id]); self::assertFalse(verifyEmailToken($expired));
        $reset = createPasswordResetToken($id); self::assertSame($id, (int)validatePasswordResetToken($reset)['user_id']);
        $next = createPasswordResetToken($id); self::assertNull(validatePasswordResetToken($reset)); clearPasswordResetToken($next); self::assertNull(validatePasswordResetToken($next));
        $expired = createPasswordResetToken($id); dbExecute('UPDATE password_resets SET expires_at=DATE_SUB(NOW(),INTERVAL 1 HOUR)'); self::assertNull(validatePasswordResetToken($expired));
        self::assertFalse(verifyTwoFactorCode($id, '123456')); self::assertFalse(verifyTwoFactorCode(999999, '123456'));
        setTwoFactorEnabled($id, true); setTwoFactorCode($id, '123456'); self::assertFalse(verifyTwoFactorCode($id, '000000')); self::assertTrue(verifyTwoFactorCode($id, '123456')); self::assertFalse(verifyTwoFactorCode($id, '123456'));
        setTwoFactorCode($id, '123456'); dbExecute('UPDATE users SET two_factor_expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=?', [$id]); self::assertFalse(verifyTwoFactorCode($id, '123456')); setTwoFactorEnabled($id, false);
    }

    public function testTicketProposalServiceChatAndReviewLifecycle(): void
    {
        $r = $this->ids['resident']; $p = $this->ids['provider']; $s = $this->ids['syndic']; $o = $this->ids['outsider'];
        $id = $this->ticket(); self::assertSame('aberto', getChamadoById($id)['status']);
        self::assertTrue(canViewTicket($r, $id)); self::assertTrue(canViewTicket($s, $id)); self::assertTrue(canViewTicket($p, $id)); self::assertTrue(canViewTicket($this->ids['admin'], $id)); self::assertFalse(canViewTicket($o, $id));
        self::assertSame([], getVisibleChamadosForUser(999999));
        saveTicketProposal($p, $id, ['resposta' => 'Conserto a torneira']); saveTicketProposal($p, $id, ['resposta' => 'Proposta revisada']);
        self::assertSame('Proposta revisada', ticketProposals($r, $id)[0]['resposta']); self::assertCount(1, ticketProposals($p, $id)); self::assertSame([], ticketProposals($o, $id));
        $chat = selectTicketProvider($r, $id, $p); self::assertSame($chat, acceptChamado($id, $p));
        self::assertNotSame($chat, getOrCreateChat($r, $p)); self::assertSame($chat, getOrCreateChat($p, $r, $id));
        self::assertTrue(joinTicketChatAsSyndic($chat, $s)); self::assertFalse(joinTicketChatAsSyndic($chat, $this->ids['otherSyndic']));
        self::assertTrue(userCanAccessChat($chat, $r)); self::assertFalse(userCanAccessChat($chat, $o)); self::assertSame([], listChatMessages($chat, $o));
        self::assertFalse(sendChatMessage($chat, $p, '')); self::assertFalse(sendChatMessage($chat, $p, str_repeat('a', 4001))); self::assertFalse(sendChatMessage($chat, $o, 'Intrusão'));
        self::assertTrue(sendChatMessage($chat, $p, ' Olá morador '));
        self::assertSame(1, notificationSummary($r)['mensagens_nao_lidas']); self::assertNotEmpty(listUserChats($r));
        self::assertSame('Olá morador', listChatMessages($chat, $r)[0]['mensagem']); listChatMessages($chat, $s);
        self::assertSame(0, notificationSummary($r)['mensagens_nao_lidas']); self::assertSame(1, (int)dbRow('SELECT lida FROM chat_mensagens WHERE chat_id=?', [$chat])['lida']);
        updateTicketService($p, $id, 'visita', ['visita_em' => date('Y-m-d\TH:i', strtotime('+3 days'))]);
        updateTicketService($p, $id, 'taxa', ['valor' => '15,50']);
        updateTicketService($p, $id, 'orcamento', ['valor' => '100.00', 'descricao' => 'Reparo']);
        updateTicketService($r, $id, 'recusar_orcamento', []); self::assertSame('recusado', ticketProposals($r, $id)[0]['orcamento_status']);
        updateTicketService($p, $id, 'orcamento', ['valor' => '90', 'descricao' => 'Reparo revisado']); updateTicketService($r, $id, 'aceitar_orcamento', []);
        $this->rejects(fn() => updateTicketService($p, $id, 'orcamento', ['valor' => '120', 'descricao' => 'Alteração']));
        self::assertFalse(updateChamadoStatus($id, $o, 'concluido')); self::assertTrue(updateChamadoStatus($id, $s, 'andamento', 'Acompanhamento')); self::assertTrue(updateChamadoStatus($id, $p, 'concluido', 'Consertado')); self::assertFalse(updateChamadoStatus($id, $p, 'concluido'));
        createReview($id, $r, $p, 5, 'Ótimo'); self::assertSame('5.00', findUserById($p)['avaliacao_media']);
        try { createReview($id, $r, $p, 4, 'Duplicada'); self::fail('Avaliação duplicada'); } catch (RuntimeException $e) { self::assertStringContainsString('já foi avaliado', $e->getMessage()); }
        addChamadoAttachment($id, 'imagem.png'); self::assertSame('imagem.png', dbRow('SELECT arquivo FROM anexos WHERE chamado_id=?', [$id])['arquivo']);
        saveMessage($id, $r, 'Mensagem legada'); self::assertSame('Mensagem legada', listMessagesForChamado($id)[0]['mensagem']);
        self::assertSame(1, getUserStats($r)['concluidos']); self::assertSame(1, getUserRoleStats($p)['concluidos']);
        $notice = listNotifications($r)[0]; markNotificationRead($o, (int)$notice['id']); self::assertSame(0, (int)dbRow('SELECT lida FROM notificacoes WHERE id=?', [$notice['id']])['lida']);
        markNotificationRead($r, (int)$notice['id']); markNotificationRead($r); self::assertSame(0, (int)notificationSummary($r)['nao_lidas']);
        dbExecute("UPDATE chats SET status='encerrado' WHERE id=?", [$chat]); self::assertFalse(sendChatMessage($chat, $p, 'Mensagem'));
        dbExecute('UPDATE users SET verificado=0 WHERE id=?', [$s]); self::assertFalse(userCanAccessChat($chat, $s));
    }

    public function testTicketPermissionsAndValidation(): void
    {
        $r = $this->ids['resident']; $p = $this->ids['provider']; $o = $this->ids['outsider'];
        $this->rejects(fn() => $this->ticket(['usuario_id' => $p])); $this->rejects(fn() => $this->ticket(['titulo' => ''])); $this->rejects(fn() => $this->ticket(['data_servico' => '2026-02-30']));
        $id = $this->ticket();
        $this->rejects(fn() => saveTicketProposal($r, $id, ['resposta' => 'Não permitido'])); $this->rejects(fn() => saveTicketProposal($p, $id, ['resposta' => '']));
        $this->rejects(fn() => saveTicketProposal($p, 999999, ['resposta' => 'Proposta'])); $this->rejects(fn() => acceptChamado($id, $r)); $this->rejects(fn() => acceptChamado(999999, $p));
        $this->rejects(fn() => selectTicketProvider($o, $id, $p)); $this->rejects(fn() => updateTicketService($p, $id, 'taxa', ['valor' => '10']));
        acceptChamado($id, $p); $this->rejects(fn() => acceptChamado($id, $this->ids['otherProvider'])); $this->rejects(fn() => saveTicketProposal($this->ids['otherProvider'], $id, ['resposta' => 'Intrusão']));
        $this->rejects(fn() => updateTicketService($o, $id, 'taxa', ['valor' => '1'])); $this->rejects(fn() => updateTicketService($o, $id, 'aceitar_orcamento', [])); $this->rejects(fn() => updateTicketService($r, $id, 'aceitar_orcamento', []));
        foreach (['abc', '-1', '0', '10.123'] as $value) $this->rejects(fn() => updateTicketService($p, $id, 'orcamento', ['valor' => $value]));
        $this->rejects(fn() => updateTicketService($p, $id, 'orcamento', ['valor' => '10', 'descricao' => ''])); $this->rejects(fn() => updateTicketService($p, $id, 'visita', ['visita_em' => 'wrong'])); $this->rejects(fn() => updateTicketService($p, $id, 'unknown', []));
        self::assertFalse(updateChamadoStatus($id, $r, 'concluido')); self::assertFalse(updateChamadoStatus(999999, $p, 'concluido')); self::assertFalse(updateChamadoStatus($id, $p, 'concluido', str_repeat('x', 501))); self::assertTrue(updateChamadoStatus($id, $r, 'cancelado'));
        $this->rejects(fn() => createReview($id, $r, $r, 5, '')); $this->rejects(fn() => createReview($id, $r, $p, 0, ''));
        try { createReview($id, $r, $p, 5, ''); self::fail('Chamado cancelado'); } catch (RuntimeException $e) { self::assertStringContainsString('concluído', $e->getMessage()); }
        self::assertFalse(canUsersChat($r, $o)); self::assertFalse(canUsersChat(999999, $p)); self::assertFalse(canUsersChat($r, $p, $id)); self::assertTrue(canUsersChat($r, $this->ids['syndic'])); self::assertTrue(canUsersChat($this->ids['syndic'], $r)); self::assertTrue(canUsersChat($p, $r));
        $this->rejects(fn() => getOrCreateChat($r, $o));
    }

    public function testAnnouncementsIsolationHistoryAndReadReceipts(): void
    {
        $r = $this->ids['resident']; $s = $this->ids['syndic']; $o = $this->ids['outsider']; $cond = $this->buildings[0];
        self::assertSame($cond, (int)userCondominio($r)['id']); self::assertTrue(managesCondominio($s, $cond)); self::assertFalse(managesCondominio($o, $cond));
        self::assertTrue(canViewProfile(findUserById($s), findUserById($r))); self::assertFalse(canViewProfile(findUserById($o), findUserById($r)));
        $this->rejects(fn() => saveComunicado($r, $cond, ['titulo' => 'Aviso', 'conteudo' => 'Teste'])); $this->rejects(fn() => saveComunicado($s, $cond, ['titulo' => '', 'conteudo' => 'Teste']));
        $id = saveComunicado($s, $cond, ['titulo' => 'Aviso', 'conteudo' => 'Teste', 'status' => 'rascunho']); self::assertSame([], listComunicados($r)); self::assertCount(1, listComunicados($s));
        saveComunicado($s, $cond, ['titulo' => 'Aviso', 'conteudo' => 'Teste'], $id); self::assertCount(1, listComunicados($r)); self::assertSame([], listComunicados($o));
        $this->rejects(fn() => readComunicado($o, $id)); readComunicado($r, $id); readComunicado($r, $id); self::assertNotNull(listComunicados($r)[0]['lido_em']);
        saveComunicado($s, $cond, ['titulo' => 'Atualizado', 'conteudo' => 'Novo texto'], $id); self::assertNull(listComunicados($r)[0]['lido_em']);
        self::assertSame(3, (int)dbRow('SELECT COUNT(*) AS n FROM comunicado_historico WHERE comunicado_id=?', [$id])['n']);
        $this->rejects(fn() => saveComunicado($s, $cond, ['titulo' => 'Aviso', 'conteudo' => 'Teste'], 999999)); $this->rejects(fn() => deleteComunicado($o, $id)); $this->rejects(fn() => deleteComunicado($s, 999999));
        deleteComunicado($s, $id); self::assertSame([], listComunicados($r)); self::assertSame([], listComunicados($s));
        saveCondominio($s, $cond, ['descricao' => 'Nova descrição', 'regras' => 'Regras', 'contato' => 'admin@example.test']); self::assertSame('Nova descrição', userCondominio($r)['descricao']);
        $this->rejects(fn() => saveCondominio($o, $cond, [])); $this->rejects(fn() => saveCondominio($s, $cond, ['contato' => str_repeat('x', 191)]));
    }

    public function testReservationsRejectOverlapAndForeignCondominium(): void
    {
        $r = $this->ids['resident']; $o = $this->ids['outsider']; $s = $this->ids['syndic'];
        dbExecute('INSERT INTO areas_comuns (condominio_id,nome) VALUES (?,?)', [$this->buildings[0], 'Salão']); $area = (int)getPDO()->lastInsertId();
        $start = date('Y-m-d\TH:i', strtotime('+2 days')); $end = date('Y-m-d\TH:i', strtotime('+2 days +2 hours'));
        self::assertCount(1, listAreas($r)); self::assertSame([], listAreas($o));
        $this->rejects(fn() => createReserva($this->ids['provider'], $area, $start, $end)); $this->rejects(fn() => createReserva($r, $area, 'invalid', $end)); $this->rejects(fn() => createReserva($r, $area, $end, $start)); $this->rejects(fn() => createReserva($o, $area, $start, $end));
        $id = createReserva($r, $area, $start, $end, 'Festa'); self::assertSame('confirmada', listReservas($r)[0]['status']); self::assertSame('resident', listReservas($s)[0]['responsavel']); self::assertSame([], listReservas($o));
        $this->rejects(fn() => createReserva($r, $area, $start, $end)); $this->rejects(fn() => cancelReserva($o, $id)); $this->rejects(fn() => cancelReserva($r, 999999));
        cancelReserva($s, $id); cancelReserva($r, $id); self::assertSame('cancelada', listReservas($r)[0]['status']);
        self::assertGreaterThan($id, createReserva($r, $area, $start, $end));
    }

    public function testAdminApprovalAndStatistics(): void
    {
        $admin = $this->ids['admin']; $cond = $this->buildings[0];
        self::assertCount(7, listUsersForAdmin()); self::assertCount(2, listUsersForAdmin('provider')); self::assertCount(2, getAdminDashboardStats()['por_condominio']) ;
        self::assertFalse(approveSyndicVerification($this->ids['resident'], $admin, 'aprovado')); self::assertFalse(approveSyndicVerification($this->ids['syndic'], $admin, 'invalid')); self::assertFalse(approveSyndicVerification($this->ids['syndic'], $this->ids['resident'], 'aprovado'));
        dbExecute("UPDATE users SET certificado_status='pendente',verificado=0 WHERE id=?", [$this->ids['syndic']]); self::assertCount(1, listPendingSyndicVerifications());
        self::assertTrue(approveSyndicVerification($this->ids['syndic'], $admin, 'rejeitado')); self::assertFalse(managesCondominio($this->ids['syndic'], $cond)); self::assertTrue(approveSyndicVerification($this->ids['syndic'], $admin, 'aprovado')); self::assertTrue(managesCondominio($this->ids['syndic'], $cond));
        self::assertSame(7, (int)getAdminDashboardStats()['total_usuarios']);
    }

    public function testTransactionRollbackAndUploadValidation(): void
    {
        $r = $this->ids['resident'];
        $this->rejects(fn() => atomic(function() use ($r) { createNotification($r, 'Rollback', 'Teste'); throw new DomainException('Reverter'); }));
        // A nested transaction belongs to its caller, so the caller must roll it back.
        self::assertTrue(getPDO()->inTransaction());
        self::assertSame('Teste', friendlyError(new DomainException('Teste')));
        flashMessage('Salvo'); self::assertSame('Salvo', $_SESSION['flash_message']);
        self::assertNull(storeUpload(['error' => UPLOAD_ERR_NO_FILE], '/unused'));
        $this->rejects(fn() => storeUpload(['error' => UPLOAD_ERR_PARTIAL], '/unused'));
        $this->rejects(fn() => storeUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__], '/unused'));
        $this->expectException(LogicException::class); ensureDefaultAdminAccount();
    }
    public function testPasswordResetConsumesTokenAndRevokesExistingSessions(): void
    {
        require_once dirname(__DIR__) . '/backend/includes/auth.php';
        require_once dirname(__DIR__) . '/api/_api.php';
        $id = $this->ids['resident']; establishUserSession(findUserById($id));
        $bearer = apiIssueToken($id); self::assertSame($id, (int)apiTokenUser($bearer)['id']);
        $reset = createPasswordResetToken($id); self::assertFalse(resetUserPassword('invalid', 'NovaSenha123!'));
        self::assertTrue(resetUserPassword($reset, 'NovaSenha123!')); self::assertFalse(resetUserPassword($reset, 'OutraSenha123!'));
        self::assertNull(apiTokenUser($bearer)); self::assertNull(currentUser()); self::assertNull(authenticateUser('resident@example.test', 'Seguro123!')); self::assertSame($id, (int)authenticateUser('resident@example.test', 'NovaSenha123!')['id']);
        $this->rejects(fn() => resetUserPassword('invalid', '123'));
    }
    public function testDemoCredentialsRequireExplicitDemoMode(): void
    {
        $id = $this->ids['resident']; dbExecute('UPDATE users SET email=? WHERE id=?', ['person@demo.example.test', $id]);
        self::assertTrue(demoLoginAllowed('resident@example.test')); self::assertFalse(demoLoginAllowed('PERSON@DEMO.EXAMPLE.TEST'));
        self::assertNull(authenticateUser('person@demo.example.test', 'Seguro123!'));
        try {
            putenv('APP_DEMO_LOGIN=1'); self::assertSame($id, (int)authenticateUser('person@demo.example.test', 'Seguro123!')['id']);
            establishUserSession(findUserById($id)); $token = apiIssueToken($id);
            putenv('APP_DEMO_LOGIN=0'); self::assertNull(currentUser()); self::assertNull(apiTokenUser($token));
        } finally { putenv('APP_DEMO_LOGIN=0'); }
    }
}
