<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/DatabaseTestCase.php';

final class UserFunctionsTest extends DatabaseTestCase
{
    private function registration(array $changes = []): array
    {
        return $changes + ['nome' => ' Novo Morador ', 'email' => ' NOVO@EXAMPLE.TEST ', 'telefone' => '(11) 98765-4321',
            'cpf' => '123.456.789-00', 'senha' => 'Seguro123!', 'condominio' => ' Alpha ', 'unidade' => ' 202 '];
    }

    public function testRegistrationPersistsNormalizedContactsAndRejectsDuplicatesWithoutPartialRows(): void
    {
        $id = createUser($this->registration());
        $user = findUserById($id);
        self::assertSame('Novo Morador', $user['nome']);
        self::assertSame('novo@example.test', $user['email']);
        self::assertSame('11987654321', $user['telefone']);
        self::assertSame('12345678900', $user['cpf']);
        self::assertSame('202', $user['unidade']);
        self::assertSame($this->buildings[0], (int)$user['condominio_id']);
        self::assertTrue(passwordMatches('Seguro123!', $user['senha']));
        $count = (int)dbRow('SELECT COUNT(*) AS n FROM users')['n'];
        $this->rejects(fn() => createUser($this->registration(['telefone' => '11987654322'])), 'e-mail');
        $this->rejects(fn() => createUser($this->registration(['email' => 'other@example.test'])), 'telefone');
        self::assertSame($count, (int)dbRow('SELECT COUNT(*) AS n FROM users')['n']);
    }

    public function testInvalidInputsAndInactiveCondominiumCannotCreateAccount(): void
    {
        $count = (int)dbRow('SELECT COUNT(*) AS n FROM users')['n'];
        $this->rejects(fn() => createUser($this->registration(['email' => null])));
        dbExecute("UPDATE condominios SET status='inativo' WHERE id=?", [$this->buildings[0]]);
        $this->rejects(fn() => createUser($this->registration()), 'condomínio');
        self::assertSame($count, (int)dbRow('SELECT COUNT(*) AS n FROM users')['n']);
    }

    public function testSyndicRegistrationRequiresApprovalAndRejectsExistingActiveSyndic(): void
    {
        $data = $this->registration(['tipo_usuario' => 'sindico', 'documento_certificado' => 'certificate.pdf']);
        try { createUser($data); self::fail('Condomínio já possui síndico ativo.'); }
        catch (RuntimeException $error) { self::assertStringContainsString('síndico ativo', $error->getMessage()); }
        dbExecute('UPDATE users SET verificado=0 WHERE id=?', [$this->ids['syndic']]);
        $id = createUser($data);
        $user = findUserById($id);
        self::assertSame('pendente', $user['certificado_status']);
        self::assertSame('certificate.pdf', $user['documento_certificado']);
        self::assertSame(0, (int)$user['verificado']);
        self::assertSame(0, (int)$user['email_verificado']);
    }

    public function testProfileValidationRejectsAllChangesBeforeWritingAndMissingUserReturnsFalse(): void
    {
        $id = $this->ids['resident'];
        self::assertFalse(updateUserProfile(999999, ['nome' => 'Ausente']));
        self::assertFalse(updateUserProfile($id, ['nome' => ' ', 'email' => null, 'senha' => '']));
        foreach ([['email' => 'invalid'], ['email' => 'provider@example.test'], ['nome' => []], ['nome' => str_repeat('x', 191)], ['telefone' => '123'], ['senha' => 'short']] as $changes) {
            $this->rejects(fn() => updateUserProfile($id, $changes + ['nome' => 'Não deve alterar']));
            self::assertSame('resident', findUserById($id)['nome']);
        }
        self::assertTrue(updateUserProfile($id, ['nome' => ' Editado ', 'email' => ' EDITADO@EXAMPLE.TEST ', 'telefone' => '(11) 99999-2222']));
        $user = findUserById($id);
        self::assertSame('Editado', $user['nome']);
        self::assertSame('editado@example.test', $user['email']);
        self::assertSame('11999992222', $user['telefone']);
        self::assertTrue(updateUserProfile($id, ['email' => ' EDITADO@EXAMPLE.TEST ']));
    }

    public function testProviderSearchTreatsWildcardsAsLiteralAndOmitsUnverifiedProviders(): void
    {
        $provider = $this->ids['provider'];
        dbExecute("UPDATE users SET nome='Manutenção 100%',especialidades='reparo_A' WHERE id=?", [$provider]);
        self::assertSame([$provider], array_map(fn($row) => (int)$row['id'], searchProfilesByCategory('%')));
        self::assertSame([$provider], array_map(fn($row) => (int)$row['id'], searchProfilesByCategory('_')));
        self::assertSame([], searchProfilesByCategory('\\'));
        dbExecute('UPDATE users SET email_verificado=0 WHERE id=?', [$provider]);
        self::assertSame([], searchProfilesByCategory('%'));
        dbExecute("UPDATE users SET email_verificado=1,status='bloqueado' WHERE id=?", [$provider]);
        self::assertSame([], searchProfilesByCategory('_'));
    }

    public function testProfileMetadataRejectsMalformedDataAndPreservesExistingPortfolio(): void
    {
        $id = $this->ids['provider'];
        dbExecute('UPDATE users SET portfolio_imagens=? WHERE id=?', ['["existing.png"]', $id]);
        foreach ([['bio' => []], ['nome' => ''], ['certificado_status' => 'unknown'], ['verificado' => 2], ['portfolio_imagens' => [['nested']]], ['portfolio_imagens' => 'invalid']] as $changes) {
            $this->rejects(fn() => saveUserProfileData($id, $changes));
            self::assertSame('["existing.png"]', findUserById($id)['portfolio_imagens']);
        }
        self::assertTrue(saveUserProfileData($id, ['bio' => ' Bio atualizada ']));
        $user = findUserById($id);
        self::assertSame('Bio atualizada', $user['bio']);
        self::assertSame('["existing.png"]', $user['portfolio_imagens']);
        self::assertTrue(saveUserProfileData($id, ['portfolio_imagens' => ['replacement.jpg', '']]));
        self::assertSame('["replacement.jpg"]', findUserById($id)['portfolio_imagens']);
    }

    public function testEmailVerificationRotationKeepsOnlyNewestTokenForThatUser(): void
    {
        $id = $this->ids['resident'];
        dbExecute('UPDATE users SET email_verificado=0 WHERE id=?', [$id]);
        $first = createEmailVerificationToken($id);
        $second = createEmailVerificationToken($id);
        self::assertNotSame($first, $second);
        self::assertSame(1, (int)dbRow('SELECT COUNT(*) AS n FROM email_verification_tokens WHERE user_id=?', [$id])['n']);
        self::assertFalse(verifyEmailToken($first));
        self::assertSame(0, (int)findUserById($id)['email_verificado']);
        self::assertTrue(verifyEmailToken($second));
        self::assertSame(1, (int)findUserById($id)['email_verificado']);
        self::assertSame(0, (int)dbRow('SELECT COUNT(*) AS n FROM email_verification_tokens WHERE user_id=?', [$id])['n']);
    }

    public function testNotificationsLimitRecentRowsAndTruncateUtf8TitleWithoutMixingUsers(): void
    {
        $id = $this->ids['resident'];
        self::assertSame([], listNotifications($id));
        $title = str_repeat('á', 191);
        for ($i = 0; $i < 101; $i++) createNotification($id, $title, 'Mensagem ' . $i);
        createNotification($this->ids['outsider'], 'Outro usuário', 'Não deve aparecer');
        $notifications = listNotifications($id);
        self::assertCount(100, $notifications);
        self::assertSame('Mensagem 100', $notifications[0]['mensagem']);
        self::assertSame('Mensagem 1', $notifications[99]['mensagem']);
        self::assertSame(190, mb_strlen($notifications[0]['titulo'], 'UTF-8'));
        self::assertSame('Outro usuário', listNotifications($this->ids['outsider'])[0]['titulo']);
    }
}
