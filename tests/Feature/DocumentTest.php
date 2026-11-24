<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/HttpTestCase.php';

/** Certificados de síndico ficam em backend/storage (bloqueada na web) e saem só por api/documento.php. */
final class DocumentTest extends HttpTestCase
{
    private string $certificate = '';

    protected function tearDown(): void
    {
        if ($this->certificate !== '' && is_file($this->certificate)) unlink($this->certificate);
        parent::tearDown();
    }

    private function relogin(string $role): void
    {
        $this->post('/logout.php');
        preg_match('/name="csrf" value="([a-f0-9]{64})"/', $this->request('/pages/login.php')['body'], $matches);
        $this->csrf = $matches[1];
        self::assertSame(302, $this->login($role)['status'], $role);
    }

    public function testSyndicCertificateIsVisibleToAdministratorsAndItsOwnerOnly(): void
    {
        $folder = dirname(__DIR__, 2) . '/backend/storage/certificados';
        if (!is_dir($folder)) mkdir($folder, 0750, true);
        $name = bin2hex(random_bytes(16)) . '.pdf';
        $this->certificate = $folder . '/' . $name;
        file_put_contents($this->certificate, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $syndic = $this->ids['otherSyndic'];
        dbExecute("UPDATE users SET documento_certificado=?, certificado_status='pendente', verificado=0 WHERE id=?", [$name, $syndic]);
        dbExecute('DELETE FROM condominio_sindicos WHERE usuario_id=?', [$syndic]);
        $url = '/api/documento.php?tipo=certificado&id=' . $syndic;

        $this->login('admin');
        $page = $this->request('/pages/admin-verificacoes.php');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('api/documento.php?tipo=certificado&amp;id=' . $syndic, $page['body']);
        self::assertStringNotContainsString('backend/storage', $page['body']);
        $file = $this->request($url);
        self::assertSame(200, $file['status']);
        self::assertStringContainsString('application/pdf', $file['headers']);
        self::assertStringContainsString('attachment', $file['headers']);
        self::assertSame(file_get_contents($this->certificate), $file['body']);

        $this->relogin('resident');
        self::assertSame(404, $this->request($url)['status']);
        $this->relogin('syndic');
        self::assertSame(404, $this->request($url)['status']);
        $this->relogin('otherSyndic');
        self::assertSame(200, $this->request($url)['status']);
        self::assertSame(404, $this->request('/api/documento.php?tipo=outro&id=' . $syndic)['status']);
        self::assertSame(405, $this->request($url, 'POST', ['csrf' => $this->csrf])['status']);
    }

    public function testAdministratorCanApproveASyndicFromTheSite(): void
    {
        $syndic = $this->ids['otherSyndic'];
        dbExecute("UPDATE users SET certificado_status='pendente', verificado=0 WHERE id=?", [$syndic]);
        dbExecute('DELETE FROM condominio_sindicos WHERE usuario_id=?', [$syndic]);
        $this->login('admin');
        $page = $this->request('/pages/admin-verificacoes.php');
        preg_match('/<form method="post" class="inline-actions">\s*<input type="hidden" name="csrf" value="([a-f0-9]{64})">/', $page['body'], $form);
        self::assertNotEmpty($form, 'O formulário de aprovação precisa levar o CSRF.');
        $response = $this->request('/pages/admin-verificacoes.php', 'POST', ['csrf' => $form[1], 'user_id' => $syndic, 'status' => 'aprovado']);
        self::assertContains($response['status'], [200, 302]);
        self::assertSame('aprovado', findUserById($syndic)['certificado_status']);
        self::assertTrue(managesCondominio($syndic, $this->buildings[1]));
    }
}
