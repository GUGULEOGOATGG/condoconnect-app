<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    protected function setUp(): void { $_SESSION = []; }
    public function testAutoStartedForeignSessionIsNotUsedByApplication(): void
    {
        session_write_close();
        session_name('PHPSESSID');
        session_id('');
        self::assertTrue(session_start());
        $_SESSION['foreign_user'] = 99;
        $_SESSION['csrf'] = str_repeat('a', 64);
        startAppSession();
        self::assertSame('SISCSESSID', session_name());
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertArrayNotHasKey('foreign_user', $_SESSION);
        self::assertFalse(csrfIsValid(str_repeat('a', 64)));
        self::assertSame(csrfToken(), csrfToken());
    }

    public function testStableRandomTokenAndOpenTabs(): void
    {
        $token = csrfToken();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame($token, csrfToken());
        self::assertStringContainsString('name="csrf" value="' . $token . '"', csrfField());
        self::assertTrue(csrfIsValid($token));
        self::assertFalse(csrfIsValid(str_repeat('0', 64)));
        foreach ([null, [], 123, '', 'bad'] as $bad) self::assertFalse(csrfIsValid($bad));
        $_SESSION = [];
        self::assertFalse(csrfIsValid($token));
        self::assertNotSame($token, csrfToken());
    }
    public function testCorruptedTokenIsReplaced(): void
    {
        foreach ([[], 123, '', '<script>'] as $bad) { $_SESSION['csrf'] = $bad; self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', csrfToken()); }
    }
    public function testSafeMethodsAndValidHeaderOrForm(): void
    {
        $server = $_SERVER; $post = $_POST;
        try {
            foreach (['GET', 'HEAD', 'OPTIONS'] as $method) { $_SERVER['REQUEST_METHOD'] = $method; validateCsrfRequest(); }
            $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST['csrf'] = csrfToken(); validateCsrfRequest();
            $_POST = []; $_SERVER['HTTP_X_CSRF_TOKEN'] = csrfToken();
            foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) { $_SERVER['REQUEST_METHOD'] = $method; validateCsrfRequest(); }
            self::assertTrue(csrfIsValid($_SERVER['HTTP_X_CSRF_TOKEN']));
        } finally { $_SERVER = $server; $_POST = $post; }
    }
    public function testSessionRenewalPreservesOpenFormsAndPreventsFixation(): void
    {
        $token = csrfToken(); $old = session_id();
        establishUserSession(['id' => 7, 'status' => 'ativo', 'tipo_usuario' => 'morador']);
        self::assertNotSame($old, session_id()); self::assertSame(7, $_SESSION['user_id']); self::assertSame($token, csrfToken());
        expireIdleSession(time() + 7199); self::assertSame(7, $_SESSION['user_id']);
        expireIdleSession(time() + 14400); self::assertArrayNotHasKey('user_id', $_SESSION); self::assertFalse(csrfIsValid($token));
        $this->expectException(DomainException::class); establishUserSession(['status' => 'bloqueado']);
    }
    public function testHttpsCookieDetectionIgnoresUntrustedForwardedHeaders(): void
    {
        $server = $_SERVER; $env = getenv('APP_HTTPS');
        try {
            putenv('APP_HTTPS'); unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https'; self::assertFalse(requestIsHttps());
            $_SERVER['HTTPS'] = 'on'; self::assertTrue(requestIsHttps());
            $_SERVER['HTTPS'] = 'off'; self::assertFalse(requestIsHttps());
            $_SERVER['SERVER_PORT'] = '443'; self::assertTrue(requestIsHttps());
            unset($_SERVER['SERVER_PORT']); putenv('APP_HTTPS=1'); self::assertTrue(requestIsHttps());
            $params = session_get_cookie_params(); self::assertTrue($params['httponly']); self::assertSame('Lax', $params['samesite']);
        } finally { $_SERVER = $server; putenv($env === false ? 'APP_HTTPS' : 'APP_HTTPS=' . $env); }
    }
    public function testEmailLinksUseConfiguredOriginAndIgnoreHostHeader(): void
    {
        $server = $_SERVER; $env = getenv('APP_URL'); $https = getenv('APP_HTTPS');
        try {
            putenv('APP_HTTPS=0'); putenv('APP_URL'); $_SERVER['HTTPS'] = 'off'; $_SERVER['SERVER_NAME'] = 'localhost'; $_SERVER['SERVER_PORT'] = '8080'; $_SERVER['HTTP_HOST'] = 'attacker.test';
            self::assertSame('http://localhost:8080', applicationOrigin());
            putenv('APP_URL=https://condo.example.test/'); self::assertSame('https://condo.example.test', applicationOrigin());
            putenv('APP_URL=javascript:bad'); $this->expectException(RuntimeException::class); applicationOrigin();
        } finally { $_SERVER = $server; putenv($env === false ? 'APP_URL' : 'APP_URL=' . $env); putenv($https === false ? 'APP_HTTPS' : 'APP_HTTPS=' . $https); }
    }
}
