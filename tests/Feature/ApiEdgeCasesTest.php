<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/HttpTestCase.php';

final class ApiEdgeCasesTest extends HttpTestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) if (is_file($file) || is_link($file)) unlink($file);
        parent::tearDown();
    }

    private function registration(array $changes = []): array
    {
        return $changes + ['nome' => 'Nova conta', 'email' => 'edge-new@example.test', 'telefone' => '11987654321', 'cpf' => '12345678900', 'senha' => 'Seguro123!', 'confirmarSenha' => 'Seguro123!', 'condominio' => 'Alpha', 'unidade' => '101'];
    }

    private function register(array $data): array
    {
        return $this->request('/api/register.php', 'POST', json_encode($data, JSON_THROW_ON_ERROR), ['Content-Type: application/json']);
    }

    private function assertJsonError(array $response, int $status): array
    {
        self::assertSame($status, $response['status']);
        self::assertStringContainsString('application/json', $response['headers']);
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertNotSame('', $payload['error']);
        self::assertArrayNotHasKey('token', $payload);
        self::assertStringNotContainsString('SQLSTATE', $response['body']);
        self::assertStringNotContainsString('INSERT INTO', $response['body']);
        return $payload;
    }

    public function testDuplicatePhoneProducesConflictWithoutCreatingPartialAccountOrSession(): void
    {
        dbExecute('UPDATE users SET telefone=? WHERE id=?', ['11922223333', $this->ids['resident']]);
        $payload = $this->assertJsonError($this->register($this->registration(['telefone' => '(11) 92222-3333'])), 409);
        self::assertStringContainsString('telefone', $payload['error']);
        self::assertNull(findUserByEmail('edge-new@example.test'));
        self::assertSame(0, (int) dbRow('SELECT COUNT(*) AS total FROM sessions')['total']);
        self::assertSame(7, (int) dbRow('SELECT COUNT(*) AS total FROM users')['total']);
    }

    public function testPhoneCannotBeMissingNullBlankOrMalformed(): void
    {
        $missing = $this->registration(); unset($missing['telefone']);
        foreach ([$missing, $this->registration(['telefone' => null]), $this->registration(['telefone' => '   ']), $this->registration(['telefone' => []])] as $data) {
            $this->assertJsonError($this->register($data), 400);
        }
        self::assertNull(findUserByEmail('edge-new@example.test'));
    }

    public function testRegistrationLimitsMatchDatabaseColumnsAndAcceptBoundaryValues(): void
    {
        foreach (['nome' => 190, 'email' => 190, 'telefone' => 30, 'cpf' => 20, 'condominio' => 190, 'unidade' => 190] as $field => $limit) {
            $payload = $this->assertJsonError($this->register($this->registration([$field => str_repeat('á', $limit + 1)])), 400);
            self::assertStringContainsString($field, $payload['error']);
        }
        self::assertNull(findUserByEmail('edge-new@example.test'));
        $response = $this->register($this->registration(['nome' => str_repeat('á', 190), 'telefone' => str_repeat('1', 11), 'cpf' => str_repeat('1', 11), 'unidade' => str_repeat('1', 190)]));
        self::assertSame(201, $response['status']);
        $user = findUserByEmail('edge-new@example.test');
        self::assertSame(190, mb_strlen($user['nome']));
        self::assertSame(str_repeat('1', 11), $user['telefone']);
        self::assertSame(0, (int) $user['email_verificado']);
        self::assertTrue(passwordMatches('Seguro123!', $user['senha']));
        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('senha', $payload['user']);
        self::assertArrayNotHasKey('cpf', $payload['user']);
        self::assertNotSame($payload['token'], dbRow('SELECT token FROM sessions WHERE user_id=?', [$user['id']])['token']);
    }

    public function testNonexistentOrInactiveBuildingIsRejectedWithoutCreatingUser(): void
    {
        $payload = $this->assertJsonError($this->register($this->registration(['condominio' => 'Inexistente'])), 422);
        self::assertStringContainsString('condomínio', $payload['error']);
        dbExecute("UPDATE condominios SET status='inativo' WHERE id=?", [$this->buildings[0]]);
        $this->assertJsonError($this->register($this->registration()), 422);
        self::assertNull(findUserByEmail('edge-new@example.test'));
    }

    private function attachment(int $ticket, string $name): int
    {
        dbExecute('INSERT INTO anexos (chamado_id,arquivo) VALUES (?,?)', [$ticket, $name]);
        return (int) getPDO()->lastInsertId();
    }

    private function uploadFile(string $contents): string
    {
        $directory = dirname(__DIR__, 2) . '/backend/storage/uploads';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Diretório de uploads indisponível.');
        $path = $directory . '/edge-' . bin2hex(random_bytes(12)) . '.png';
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;
        return $path;
    }

    private function switchUser(string $role): void
    {
        self::assertSame(302, $this->post('/logout.php')['status']);
        preg_match('/name="csrf" value="([a-f0-9]{64})"/', $this->request('/pages/login.php')['body'], $matches);
        $this->csrf = $matches[1];
        self::assertSame(302, $this->login($role)['status']);
    }

    public function testAttachmentRejectsInvalidIdAndExistingRowWithMissingFile(): void
    {
        self::assertSame(302, $this->request('/api/anexo.php?id=1')['status']);
        self::assertSame(302, $this->login('resident')['status']);
        foreach (['', '?id=0', '?id=-1', '?id=invalid', '?id[]=1', '?id=999999'] as $query) {
            $response = $this->request('/api/anexo.php' . $query);
            self::assertSame(404, $response['status']);
            self::assertSame('', $response['body']);
        }
        $id = $this->attachment($this->ticket(), 'edge-missing-' . bin2hex(random_bytes(12)) . '.png');
        self::assertSame(404, $this->request('/api/anexo.php?id=' . $id)['status']);
    }

    public function testAttachmentRejectsDisguisedScriptAndDoesNotReturnContents(): void
    {
        $contents = '<?php echo "PRIVATE SCRIPT";';
        $path = $this->uploadFile($contents);
        $id = $this->attachment($this->ticket(), basename($path));
        self::assertSame(302, $this->login('resident')['status']);
        $response = $this->request('/api/anexo.php?id=' . $id);
        self::assertSame(404, $response['status']);
        self::assertSame('', $response['body']);
        self::assertStringNotContainsString('PRIVATE SCRIPT', $response['body']);
    }

    public function testAttachmentVisibilityMatchesTicketAuthorizationAndServesTrustedMime(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOlsAAAAASUVORK5CYII=', true);
        $path = $this->uploadFile($png);
        $id = $this->attachment($this->ticket(), basename($path));
        self::assertSame(302, $this->login('resident')['status']);
        $response = $this->request('/api/anexo.php?id=' . $id);
        self::assertSame(200, $response['status']);
        self::assertSame($png, $response['body']);
        self::assertStringContainsString('Content-Type: image/png', $response['headers']);
        self::assertStringContainsString('X-Content-Type-Options: nosniff', $response['headers']);
        self::assertStringContainsString('private, no-store', $response['headers']);
        $this->switchUser('outsider');
        $response = $this->request('/api/anexo.php?id=' . $id);
        self::assertSame(404, $response['status']);
        self::assertSame('', $response['body']);
        $this->switchUser('otherSyndic');
        self::assertSame(404, $this->request('/api/anexo.php?id=' . $id)['status']);
        $this->switchUser('syndic');
        self::assertSame(200, $this->request('/api/anexo.php?id=' . $id)['status']);
        $this->switchUser('admin');
        self::assertSame(200, $this->request('/api/anexo.php?id=' . $id)['status']);
    }

    public function testAttachmentSymlinkOutsideUploadDirectoryCannotBeRead(): void
    {
        $target = tempnam(sys_get_temp_dir(), 'sisc-private-image-');
        file_put_contents($target, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOlsAAAAASUVORK5CYII='));
        $this->temporaryFiles[] = $target;
        $link = $this->uploadFile('');
        unlink($link);
        $warning = '';
        set_error_handler(static function(int $severity, string $message) use (&$warning): bool { $warning = $message; return true; });
        try { $linked = symlink($target, $link); }
        finally { restore_error_handler(); }
        if (!$linked) self::markTestSkipped('O ambiente não permite criar symlink: ' . $warning);
        $id = $this->attachment($this->ticket(), basename($link));
        self::assertSame(302, $this->login('resident')['status']);
        $response = $this->request('/api/anexo.php?id=' . $id);
        self::assertSame(404, $response['status']);
        self::assertSame('', $response['body']);
    }

    public function testImageEndpointsRejectMutationMethodsAndHeadDoesNotSendContents(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOlsAAAAASUVORK5CYII=', true);
        $path = $this->uploadFile($png);
        $id = $this->attachment($this->ticket(), basename($path));
        self::assertSame(302, $this->login('resident')['status']);
        $urls = ['/api/anexo.php?id=' . $id, '/api/avatar.php?id=' . $this->ids['resident']];
        foreach ($urls as $url) {
            $head = $this->request($url, 'HEAD');
            self::assertSame(200, $head['status']);
            self::assertSame('', $head['body']);
            self::assertStringContainsString('Content-Type: image/', $head['headers']);
            foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
                $response = $this->request($url, $method, null, ['X-CSRF-Token: ' . $this->csrf]);
                self::assertSame(405, $response['status']);
                self::assertStringContainsString('Allow: GET, HEAD', $response['headers']);
                self::assertSame('', $response['body']);
            }
        }
    }
}
