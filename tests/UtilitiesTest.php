<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UtilitiesTest extends TestCase
{
    private string|false $basePath;
    private array $server;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/backend/includes/functions.php';
        $this->basePath = getenv('APP_BASE_PATH');
        $this->server = $_SERVER;
        putenv('APP_BASE_PATH=/sisc/');
    }

    protected function tearDown(): void
    {
        putenv($this->basePath === false ? 'APP_BASE_PATH' : 'APP_BASE_PATH=' . $this->basePath);
        $_SERVER = $this->server;
    }

    public function testSanitizeEscapesHtmlAndTrimsWhitespace(): void
    {
        self::assertSame('&lt;b title=&quot;x&quot;&gt;João &amp; &#039;Ana&#039;&lt;/b&gt;', sanitize('  <b title="x">João & \'Ana\'</b>  '));
        self::assertSame('', sanitize(null));
        self::assertSame('42', sanitize(42));
    }

    public function testPasswordHashAcceptsOnlyTheCorrectPassword(): void
    {
        $password = 'Senha-segura-123!';
        $hash = hashPassword($password);
        self::assertNotSame($password, $hash);
        self::assertTrue(password_verify($password, $hash));
        self::assertTrue(passwordMatches($password, $hash));
        self::assertFalse(passwordMatches('senha-incorreta', $hash));
        self::assertFalse(passwordMatches($password, 'invalid-hash'));
    }

    #[DataProvider('roles')]
    public function testRoleLabels(string $role, string $expected): void
    {
        self::assertSame($expected, getUserRoleLabel($role));
    }

    public static function roles(): array
    {
        return [
            ['morador', 'Morador'], ['prestador', 'Prestador'],
            ['sindico', 'Síndico'], ['admin', 'Administrador'],
            ['unknown', 'Morador'],
        ];
    }

    #[DataProvider('basePaths')]
    public function testBasePathOverride(string $override, string $expected): void
    {
        putenv('APP_BASE_PATH=' . $override);
        self::assertSame($expected, getBasePath());
    }

    public static function basePaths(): array
    {
        return [['', '/'], ['/', '/'], ['sisc', '/sisc/'], ['/sisc/', '/sisc/']];
    }

    public function testBasePathIsDetectedFromScriptLocation(): void
    {
        putenv('APP_BASE_PATH');
        $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/frontend/pages/login.php';
        $_SERVER['SCRIPT_NAME'] = '/sisc/frontend/pages/login.php';
        self::assertSame('/sisc/', getBasePath());
    }

    public function testBasePathFallsBackToRootWithoutScriptInformation(): void
    {
        putenv('APP_BASE_PATH');
        unset($_SERVER['SCRIPT_FILENAME'], $_SERVER['SCRIPT_NAME']);
        self::assertSame('/', getBasePath());
    }

    public function testProfileImageUsesApplicationPathAndNumericUserId(): void
    {
        self::assertSame('/sisc/api/avatar.php?id=7', profileImageUrl(['id' => '7']));
    }

    #[DataProvider('notifications')]
    public function testNotificationDestination(array $notice, string $expected): void
    {
        self::assertSame('/sisc/pages/' . $expected, notificationUrl($notice));
    }

    public static function notifications(): array
    {
        return [
            'chat takes priority' => [['chat_id' => '8', 'chamado_id' => 9], 'chat.php?chat=8'],
            'ticket' => [['chat_id' => 0, 'chamado_id' => '9'], 'detalhes-chamado.php?id=9'],
            'announcement' => [['tipo' => 'comunicado'], 'comunicados.php'],
            'reservation' => [['tipo' => 'reserva'], 'reservas.php'],
            'unknown type' => [['tipo' => 'unknown'], 'notificacoes.php'],
            'empty' => [[], 'notificacoes.php'],
        ];
    }

    #[DataProvider('profilePermissions')]
    public function testProfilePermissionsWithoutCondominium(array $viewer, array $target, bool $expected): void
    {
        self::assertSame($expected, canViewProfile($viewer, $target));
    }

    public static function profilePermissions(): array
    {
        $resident = ['id' => 1, 'tipo_usuario' => 'morador'];
        return [
            'own profile' => [$resident, ['id' => '1'], true],
            'administrator' => [['id' => 3, 'tipo_usuario' => 'admin'], ['id' => 2], true],
            'active provider' => [$resident, ['id' => 2, 'tipo_usuario' => 'prestador', 'status' => 'ativo'], true],
            'inactive provider' => [$resident, ['id' => 2, 'tipo_usuario' => 'prestador', 'status' => 'inativo'], false],
            'unrelated resident' => [$resident, ['id' => 2, 'tipo_usuario' => 'morador'], false],
        ];
    }

    public function testUserCannotChatWithThemself(): void
    {
        self::assertFalse(canUsersChat(7, 7));
        self::assertFalse(canUsersChat(7, 7, 12));
    }
}
