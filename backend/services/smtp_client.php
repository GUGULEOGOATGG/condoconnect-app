<?php
declare(strict_types=1);

interface SmtpConnection
{
    public function readLine(): string|false;
    public function write(string $data): void;
    public function upgradeTls(): void;
    public function close(): void;
}

interface SmtpConnector
{
    public function connect(string $host, int $port, int $timeoutSeconds): SmtpConnection;
}

final class StreamSmtpConnection implements SmtpConnection
{
    /** @param resource $socket */
    public function __construct(private $socket) {}

    public function readLine(): string|false
    {
        $line = $this->perform(fn() => fgets($this->socket, 515));
        if ($line === false && (stream_get_meta_data($this->socket)['timed_out'] ?? false)) {
            throw new RuntimeException('Tempo limite ao receber resposta SMTP.');
        }
        return $line;
    }

    public function write(string $data): void
    {
        while ($data !== '') {
            $written = $this->perform(fn() => fwrite($this->socket, $data));
            if ($written === false || $written === 0) throw new RuntimeException('Falha ao enviar comando SMTP.');
            $data = substr($data, $written);
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) fclose($this->socket);
    }

    public function upgradeTls(): void
    {
        if ($this->perform(fn() => stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) !== true) {
            throw new RuntimeException('O servidor SMTP não estabeleceu TLS verificado.');
        }
    }

    private function perform(callable $operation): mixed
    {
        if (!is_resource($this->socket)) throw new RuntimeException('A conexão SMTP foi encerrada.');
        set_error_handler(static function (int $severity, string $message): never { throw new RuntimeException($message); });
        try { return $operation(); }
        finally { restore_error_handler(); }
    }
}

final class TlsSmtpConnector implements SmtpConnector
{
    public function connect(string $host, int $port, int $timeoutSeconds): SmtpConnection
    {
        self::validateEndpoint($host, $port);
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]);
        // Convert connection warnings into a failure; never continue without verified TLS.
        set_error_handler(static function (int $severity, string $message): never {
            throw new RuntimeException($message);
        });
        try {
            $endpoint = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $host . ']' : $host;
            // Port 587 upgrades immediately with mandatory STARTTLS; no credentials are sent in cleartext.
            $protocol = $port === 587 ? 'tcp://' : 'tls://';
            $socket = stream_socket_client($protocol . $endpoint . ':' . $port, $errorCode, $errorMessage, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
            if ($socket === false) throw new RuntimeException('Não foi possível estabelecer TLS com o servidor SMTP.');
            stream_set_timeout($socket, $timeoutSeconds);
            return new StreamSmtpConnection($socket);
        } finally {
            restore_error_handler();
        }
    }

    public static function validateEndpoint(string $host, int $port): void
    {
        if ($host === '' || (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/iD', $host))) {
            throw new InvalidArgumentException('Host SMTP inválido.');
        }
        if ($port < 1 || $port > 65535 || $port === 25) {
            throw new InvalidArgumentException('Configure SMTP com TLS implícito (465) ou STARTTLS obrigatório (587).');
        }
    }
}

final class SmtpClient
{
    public function __construct(private readonly SmtpConnector $connector = new TlsSmtpConnector()) {}

    public function send(array $configuration, string $to, string $subject, string $body, string $fromName): bool
    {
        $encodedName = self::headerValue($fromName);
        $encodedSubject = self::headerValue($subject);
        if (!filter_var($configuration['from'], FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Endereço de e-mail inválido.');
        }
        $connection = $this->connector->connect($configuration['host'], $configuration['port'], 20);
        try {
            $this->expect($connection, 220);
            $connection->write("EHLO localhost\r\n");
            $this->expect($connection, 250);
            if ($configuration['port'] === 587) {
                $connection->write("STARTTLS\r\n");
                $this->expect($connection, 220);
                $connection->upgradeTls();
                $connection->write("EHLO localhost\r\n");
                $this->expect($connection, 250);
            }
            foreach ([
                ['AUTH LOGIN', 334],
                [base64_encode($configuration['username']), 334],
                [base64_encode($configuration['password']), 235],
                ['MAIL FROM:<' . $configuration['from'] . '>', 250], ['RCPT TO:<' . $to . '>', 250], ['DATA', 354],
            ] as [$command, $expected]) {
                $connection->write($command . "\r\n");
                $this->expect($connection, $expected);
            }
            $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
            $normalizedBody = preg_replace('/^\./m', '..', $normalizedBody);
            $message = 'From: ' . $encodedName . ' <' . $configuration['from'] . ">\r\n";
            $message .= 'To: <' . $to . ">\r\nSubject: " . $encodedSubject . "\r\n";
            $message .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
            $message .= str_replace("\n", "\r\n", $normalizedBody) . "\r\n.\r\n";
            $connection->write($message);
            $this->expect($connection, 250);
            $connection->write("QUIT\r\n");
            return true;
        } finally {
            $connection->close();
        }
    }

    private static function headerValue(string $value): string
    {
        if (preg_match('/[\r\n\x00]/', $value)) throw new InvalidArgumentException('Cabeçalho de e-mail inválido.');
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function expect(SmtpConnection $connection, int $expected): void
    {
        for ($lineNumber = 0; $lineNumber < 100; $lineNumber++) {
            $line = $connection->readLine();
            if ($line === false || !preg_match('/^(\d{3})([ -])/', $line, $matches) || (int)$matches[1] !== $expected) {
                throw new RuntimeException('Resposta SMTP inesperada; esperado ' . $expected . '.');
            }
            if ($matches[2] === ' ') return;
        }
        throw new RuntimeException('Resposta SMTP excedeu o limite de linhas.');
    }
}
