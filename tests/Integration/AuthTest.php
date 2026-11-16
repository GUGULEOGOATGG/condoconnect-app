<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/DatabaseTestCase.php';

final class AuthTest extends DatabaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once dirname(__DIR__, 2) . '/backend/includes/auth.php';
    }

    public function testSessionUsesRoleFromDatabaseAndRevokesWhenPasswordChanges(): void
    {
        $id = $this->ids['resident'];
        establishUserSession(findUserById($id));
        $_SESSION['user_role'] = 'admin';
        self::assertFalse(isAdminUser()); self::assertSame('morador', currentUser()['tipo_usuario']);
        dbExecute('UPDATE users SET senha=? WHERE id=?', [hashPassword('Nova123!'), $id]);
        self::assertNull(currentUser()); self::assertFalse(isLoggedIn());
        self::assertArrayNotHasKey('user_id', $_SESSION);
        self::assertSame($id, (int)authenticateUser('resident@example.test', 'Nova123!')['id']);
    }

    public function testMalformedSessionsFailClosedWithoutPhpErrors(): void
    {
        foreach ([['user_id' => []], ['user_id' => '1admin'], ['user_id' => -1], ['user_id' => new stdClass()], ['user_id' => $this->ids['resident'], '_password_version' => []]] as $session) {
            $_SESSION = $session;
            self::assertNull(currentUser()); self::assertArrayNotHasKey('user_id', $_SESSION);
        }
    }

    public function testDeletedAndExpiredAccountsLoseSessionAccess(): void
    {
        $user = findUserById($this->ids['resident']);
        establishUserSession($user);
        expireIdleSession(time() + 7201);
        self::assertNull(currentUser()); self::assertArrayNotHasKey('user_id', $_SESSION);
        establishUserSession($user);
        dbExecute('DELETE FROM users WHERE id=?', [$user['id']]);
        self::assertNull(currentUser()); self::assertFalse(isLoggedIn());
    }

    public function testAtomicOperationRollsBackItsOwnWritesOnFailure(): void
    {
        getPDO()->rollBack();
        try {
            atomic(static function (): void {
                dbExecute('INSERT INTO condominios (nome,endereco,cidade,estado,latitude,longitude) VALUES (?,?,?,?,0,0)', ['Rollback', 'Rua', 'Cidade', 'SP']);
                throw new DomainException('Operação interrompida');
            });
            self::fail('A transação deve propagar sua causa.');
        } catch (DomainException $error) {
            self::assertSame('Operação interrompida', $error->getMessage());
            self::assertNull(dbRow('SELECT id FROM condominios WHERE nome=?', ['Rollback']));
            self::assertFalse(getPDO()->inTransaction());
        }
    }
}
