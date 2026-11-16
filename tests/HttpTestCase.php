<?php
declare(strict_types=1);
require_once __DIR__ . '/DatabaseTestCase.php';

abstract class HttpTestCase extends DatabaseTestCase
{
    private $serverProcess;
    private string $baseUrl;
    protected string $cookieFile;
    private string $coverageFile;
    private string $logFile;
    protected string $csrf;

    protected function setUp(): void
    {
        parent::setUp(); getPDO()->commit();
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) throw new RuntimeException($error);
        $address = stream_socket_get_name($socket, false); fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'sisc-cookie-');
        $this->coverageFile = tempnam(sys_get_temp_dir(), 'sisc-cover-');
        $this->logFile = tempnam(sys_get_temp_dir(), 'sisc-log-');
        $sessions = dirname(__DIR__) . '/test-results/http-sessions';
        if (!is_dir($sessions)) mkdir($sessions, 0700, true);
        $environment = getenv(); $environment['SISC_HTTP_COVERAGE'] = $this->coverageFile;
        $this->serverProcess = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), '-d', 'auto_prepend_file=' . __DIR__ . '/http-coverage.php', '-d', 'session.save_path=' . $sessions, '-d', 'upload_tmp_dir=' . $sessions, '-d', 'display_errors=0', '-S', $address, '-t', dirname(__DIR__)], [0 => ['pipe', 'r'], 1 => ['file', $this->logFile, 'a'], 2 => ['file', $this->logFile, 'a']], $pipes, dirname(__DIR__), $environment);
        if (!is_resource($this->serverProcess)) throw new RuntimeException('Servidor PHP indisponível.');
        fclose($pipes[0]);
        $ready = false;
        for ($i = 0; $i < 100; $i++) {
            $probe = curl_init($this->baseUrl . '/pages/login.php');
            curl_setopt_array($probe, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 100]);
            $result = curl_exec($probe); curl_close($probe);
            if ($result !== false) { $ready = true; break; }
            usleep(10000);
        }
        self::assertTrue($ready, 'Servidor PHP iniciou.');
        $response = $this->request('/pages/login.php');
        self::assertSame(200, $response['status']);
        preg_match('/name="csrf" value="([a-f0-9]{64})"/', $response['body'], $matches);
        $this->csrf = $matches[1] ?? ''; self::assertNotSame('', $this->csrf);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) { proc_terminate($this->serverProcess); proc_close($this->serverProcess); }
        $log = file_get_contents($this->logFile);
        try { self::assertDoesNotMatchRegularExpression('/PHP (?:Warning|Notice|Fatal error|Parse error)|Uncaught |SQLSTATE\[/', $log, $log); }
        finally {
            foreach ([$this->cookieFile, $this->coverageFile, $this->logFile] as $file) if (is_file($file)) unlink($file);
            // Only the explicit reserved test database is ever cleared.
            if (!preg_match('/^sisc_test(?:_[a-z0-9]+)?$/D', getenv('DB_NAME'))) throw new RuntimeException('Banco de testes obrigatório.');
            $pdo = getPDO(); $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try { foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '``', $table) . '`'); }
            finally { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); }
            parent::tearDown();
        }
    }

    protected function request(string $path, string $method = 'GET', array|string|null $data = null, array $headers = []): array
    {
        file_put_contents($this->coverageFile, '');
        $curl = curl_init($this->baseUrl . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 10, CURLOPT_COOKIEJAR => $this->cookieFile, CURLOPT_COOKIEFILE => $this->cookieFile]);
        if ($method === 'HEAD') curl_setopt($curl, CURLOPT_NOBODY, true);
        if ($data !== null) {
            $multipart = is_array($data) && count(array_filter($data, static fn($value) => $value instanceof CURLFile)) > 0;
            curl_setopt($curl, CURLOPT_POSTFIELDS, is_array($data) && !$multipart ? http_build_query($data) : $data);
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($curl);
        if ($raw === false) throw new RuntimeException(curl_error($curl));
        $length = curl_getinfo($curl, CURLINFO_HEADER_SIZE); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        $coverage = PHPUnit\Runner\CodeCoverage::instance();
        if ($coverage->isActive() && filesize($this->coverageFile) > 0) {
            $class = class_exists('PHPUnitPHAR\SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData') ? 'PHPUnitPHAR\SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData' : 'SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData';
            $rawCoverage = unserialize(file_get_contents($this->coverageFile), ['allowed_classes' => false]);
            $coverage->codeCoverage()->append($class::fromXdebugWithoutPathCoverage($rawCoverage), static::class . '::' . $this->name());
        }
        $body = substr($raw, $length);
        if (str_contains(substr($raw, 0, $length), 'text/html')) {
            preg_match_all('/<form\b[^>]*method=["\']post["\'][^>]*>(.*?)<\/form>/is', $body, $forms);
            foreach ($forms[1] as $form) self::assertMatchesRegularExpression('/name=["\']csrf["\']/', $form, 'Todo formulário POST renderizado precisa de CSRF: ' . $path);
            preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $body, $scripts, PREG_SET_ORDER);
            foreach ($scripts as $script) {
                if (str_contains($script[1], 'src=') || trim($script[2]) === '') continue;
                $file = tempnam(sys_get_temp_dir(), 'sisc-script-');
                file_put_contents($file, $script[2]);
                try {
                    $process = proc_open(['node', '--check', '--input-type=commonjs'], [0 => ['file', $file, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                    self::assertSame(0, proc_close($process), 'JavaScript inline: ' . $path . ' ' . $output);
                } finally { unlink($file); }
            }
        }
        return ['status' => $status, 'headers' => substr($raw, 0, $length), 'body' => $body];
    }

    protected function login(string $role): array
    {
        return $this->request('/pages/login.php', 'POST', ['csrf' => $this->csrf, 'email' => $role . '@example.test', 'senha' => 'Seguro123!']);
    }

    protected function post(string $path, array $data = []): array
    {
        return $this->request($path, 'POST', ['csrf' => $this->csrf] + $data);
    }
}
