<?php
declare(strict_types=1);

abstract class DatabaseTestCase extends PHPUnit\Framework\TestCase
{
    protected array $ids;
    protected array $buildings;

    public static function setUpBeforeClass(): void
    {
        $pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT'), getenv('DB_USER'), getenv('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $name = getenv('DB_NAME');
        if (!preg_match('/^sisc_test(?:_[a-z0-9]+)?$/D', $name)) throw new RuntimeException('Banco de testes obrigatório.');
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . $name . '`');
        // No demo accounts: import the complete production schema up to the seed.
        $sql = explode('-- DEMO DATA:', file_get_contents(dirname(__DIR__) . '/backend/database/db_sisc.sql'))[0];
        $sql = preg_replace('/^(CREATE DATABASE.*|USE .*);\s*$/m', '', $sql);
        if (!(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'")->fetchColumn()) {
            foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
        }
        // Apply the reservation fix to an already initialized test database too.
        $index = $pdo->query("SHOW INDEX FROM reservas WHERE Key_name='uk_reserva_usuario_horario'")->fetch();
        if ($index) $pdo->exec('ALTER TABLE reservas DROP INDEX uk_reserva_usuario_horario, ADD INDEX idx_reserva_usuario_horario (area_id,usuario_id,inicio)');
    }

    protected function setUp(): void
    {
        getPDO()->beginTransaction();
        $this->buildings = [];
        foreach (['Alpha', 'Beta'] as $name) {
            dbExecute('INSERT INTO condominios (nome,endereco,cidade,estado,latitude,longitude) VALUES (?,?,?,?,0,0)', [$name, 'Rua de teste', 'São Paulo', 'SP']);
            $this->buildings[] = (int)getPDO()->lastInsertId();
        }
        $this->ids = [];
        foreach (['resident' => 'morador', 'outsider' => 'morador', 'provider' => 'prestador', 'otherProvider' => 'prestador', 'syndic' => 'sindico', 'otherSyndic' => 'sindico', 'admin' => 'admin'] as $key => $role) {
            $cond = in_array($key, ['outsider', 'otherSyndic'], true) ? 1 : 0;
            dbExecute('INSERT INTO users (nome,cpf,email,telefone,senha,tipo_usuario,condominio,condominio_id,unidade) VALUES (?,?,?,?,?,?,?,?,?)', [$key, '12345678900', $key . '@example.test', $key, hashPassword('Seguro123!'), $role, $cond ? 'Beta' : 'Alpha', $this->buildings[$cond], '101']);
            $this->ids[$key] = (int)getPDO()->lastInsertId();
        }
        foreach (['syndic' => 0, 'otherSyndic' => 1] as $key => $index) dbExecute('INSERT INTO condominio_sindicos (condominio_id,usuario_id) VALUES (?,?)', [$this->buildings[$index], $this->ids[$key]]);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (getPDO()->inTransaction()) getPDO()->rollBack();
        $_SESSION = [];
    }

    protected function ticket(array $changes = []): int
    {
        return createChamado($changes + ['usuario_id' => $this->ids['resident'], 'categoria' => 'Hidráulica', 'titulo' => 'Vazamento', 'descricao' => 'Torneira vazando', 'local_problema' => 'Cozinha', 'prioridade' => 'Média', 'data_servico' => date('Y-m-d', strtotime('+2 days')), 'protocolo' => bin2hex(random_bytes(12))]);
    }

    protected function rejects(callable $operation, string $message = ''): void
    {
        try { $operation(); self::fail('A operação deveria ser rejeitada.'); }
        catch (DomainException|InvalidArgumentException $e) { self::assertNotSame('', $e->getMessage()); if ($message !== '') self::assertStringContainsString($message, $e->getMessage()); }
    }
}
