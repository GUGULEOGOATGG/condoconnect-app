<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/HttpTestCase.php';

/** API do APK e do app web (api/mobile.php) contra um banco real: saúde, login e níveis de acesso. */
final class MobileApiTest extends HttpTestCase
{
    private function mobile(string $action, ?array $body = null, ?string $token = null, array $query = []): array
    {
        $headers = ['Accept: application/json'];
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
        $path = '/api/mobile.php?' . http_build_query(['action' => $action] + $query);
        $response = $this->request($path, $body === null ? 'GET' : 'POST', $body === null ? null : json_encode($body === [] ? new stdClass() : $body, JSON_THROW_ON_ERROR), $headers);
        self::assertStringContainsString('application/json', $response['headers'], $action);
        self::assertStringNotContainsString('SQLSTATE', $response['body'], $action);
        return ['status' => $response['status'], 'json' => json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)];
    }

    private function token(string $key): string
    {
        $login = $this->mobile('login', ['email' => $key . '@example.test', 'senha' => 'Seguro123!']);
        self::assertSame(200, $login['status'], $key . ': ' . json_encode($login['json']));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $login['json']['token']);
        self::assertArrayNotHasKey('senha', $login['json']['user']);
        return $login['json']['token'];
    }

    public function testHealthReportsDatabaseAndTablesWithoutConnectionDetails(): void
    {
        $health = $this->mobile('health');
        self::assertSame(200, $health['status'], json_encode($health['json']));
        self::assertSame('ok', $health['json']['database']);
        self::assertSame('ok', $health['json']['tables']);
        self::assertSame([], $health['json']['missing_tables']);
        self::assertTrue($health['json']['ok']);
        $raw = json_encode($health['json']);
        foreach ([getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_NAME')] as $secret) {
            if ($secret !== false && $secret !== '') self::assertStringNotContainsString((string)$secret, $raw);
        }
        self::assertSame(405, $this->mobile('health', [])['status']);
    }

    public function testHealthNamesMissingTablesAndAnswers503(): void
    {
        getPDO()->exec('RENAME TABLE comunicado_leituras TO comunicado_leituras_ausente');
        try {
            $health = $this->mobile('health');
            self::assertSame(503, $health['status']);
            self::assertSame('ok', $health['json']['database']);
            self::assertSame('missing', $health['json']['tables']);
            self::assertSame(['comunicado_leituras'], $health['json']['missing_tables']);
            self::assertSame('database_outdated', $health['json']['code']);
        } finally {
            getPDO()->exec('RENAME TABLE comunicado_leituras_ausente TO comunicado_leituras');
        }
    }

    public function testEachAccessLevelReceivesItsOwnScreens(): void
    {
        $expected = [
            'resident' => ['role' => 'morador', 'manager' => false, 'buildings' => 0],
            'provider' => ['role' => 'prestador', 'manager' => false, 'buildings' => 0],
            'syndic' => ['role' => 'sindico', 'manager' => true, 'buildings' => 0],
            'admin' => ['role' => 'admin', 'manager' => true, 'buildings' => 2],
        ];
        foreach ($expected as $key => $access) {
            $data = $this->mobile('bootstrap', null, $this->token($key));
            self::assertSame(200, $data['status'], $key);
            self::assertSame($access['role'], $data['json']['user']['tipo_usuario'], $key);
            self::assertSame($access['role'], $data['json']['access']['role'], $key);
            self::assertSame($access['manager'], $data['json']['access']['manager'], $key);
            self::assertCount($access['buildings'], $data['json']['access']['condominios'], $key);
        }
    }

    public function testServerRefusesOperationsFromAnotherAccessLevel(): void
    {
        $resident = $this->token('resident'); $provider = $this->token('provider');
        $syndic = $this->token('syndic'); $admin = $this->token('admin');
        $ticket = ['titulo' => 'Lâmpada', 'descricao' => 'Queimada', 'local_problema' => 'Hall', 'categoria' => 'Elétrica', 'prioridade' => 'Baixa', 'data_servico' => date('Y-m-d', strtotime('+3 days'))];
        $notice = ['titulo' => 'Assembleia', 'conteudo' => 'Dia 20, às 19h.', 'status' => 'publicado'];

        // Morador: abre chamado; não administra o condomínio nem a plataforma.
        self::assertSame(201, $this->mobile('create_ticket', $ticket, $resident)['status']);
        $ticketId = (int)dbRow('SELECT id FROM chamados ORDER BY id DESC LIMIT 1')['id'];
        self::assertSame(403, $this->mobile('admin_overview', null, $resident)['status']);
        self::assertSame(403, $this->mobile('admin_users', null, $resident)['status']);
        self::assertSame(403, $this->mobile('verify_syndic', ['user_id' => $this->ids['syndic'], 'status' => 'rejeitado'], $resident)['status']);
        self::assertSame(422, $this->mobile('save_notice', $notice, $resident)['status']);
        self::assertSame(422, $this->mobile('save_community', ['descricao' => 'x'], $resident)['status']);
        self::assertSame(422, $this->mobile('accept_ticket', ['id' => $ticketId], $resident)['status']);
        self::assertSame(403, $this->mobile('join_ticket_chat', ['id' => $ticketId], $resident)['status']);

        // Prestador: aceita chamado; não abre chamado, não reserva e não administra.
        self::assertSame(422, $this->mobile('create_ticket', $ticket, $provider)['status']);
        self::assertSame(422, $this->mobile('reserve', ['area_id' => 1, 'inicio' => date('Y-m-d\TH:i', strtotime('+1 day')), 'fim' => date('Y-m-d\TH:i', strtotime('+1 day 1 hour'))], $provider)['status']);
        self::assertSame(422, $this->mobile('save_notice', $notice, $provider)['status']);
        self::assertSame(403, $this->mobile('admin_overview', null, $provider)['status']);
        $accepted = $this->mobile('accept_ticket', ['id' => $ticketId], $provider);
        self::assertSame(200, $accepted['status'], json_encode($accepted['json']));

        // Síndico: administra só o próprio condomínio; não acessa a administração da plataforma.
        self::assertSame(200, $this->mobile('save_notice', $notice, $syndic)['status']);
        self::assertSame(422, $this->mobile('save_notice', $notice + ['condominio_id' => $this->buildings[1]], $syndic)['status']);
        self::assertSame(422, $this->mobile('create_ticket', $ticket, $syndic)['status']);
        self::assertSame(403, $this->mobile('admin_overview', null, $syndic)['status']);
        self::assertSame(403, $this->mobile('verify_syndic', ['user_id' => $this->ids['otherSyndic'], 'status' => 'rejeitado'], $syndic)['status']);
        self::assertSame(200, $this->mobile('join_ticket_chat', ['id' => $ticketId], $syndic)['status']);

        // Administrador: painel e usuários da plataforma; qualquer condomínio; não abre chamado como morador.
        $overview = $this->mobile('admin_overview', null, $admin);
        self::assertSame(200, $overview['status']);
        self::assertSame(7, $overview['json']['totals']['total_usuarios']);
        self::assertSame(200, $this->mobile('admin_users', null, $admin)['status']);
        self::assertSame(200, $this->mobile('save_notice', $notice + ['condominio_id' => $this->buildings[1]], $admin)['status']);
        self::assertSame(422, $this->mobile('create_ticket', $ticket, $admin)['status']);
        self::assertSame(403, $this->mobile('join_ticket_chat', ['id' => $ticketId], $admin)['status']);
    }

    public function testExpiredOrForeignTokensAreRejected(): void
    {
        self::assertSame(401, $this->mobile('bootstrap', null, str_repeat('a', 64))['status']);
        $token = $this->token('resident');
        self::assertSame(200, $this->mobile('logout', [], $token)['status']);
        self::assertSame(401, $this->mobile('bootstrap', null, $token)['status']);
        self::assertSame(401, $this->mobile('login', ['email' => 'resident@example.test', 'senha' => 'errada123'])['status']);
    }
}
