<?php
declare(strict_types=1);

interface HttpTransport
{
    /** @param list<string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 30): HttpResponse;
}

final class HttpResponse
{
    public function __construct(public readonly int $status, public readonly string $body) {}
}

final class HttpTransportException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'network')
    {
        parent::__construct($message);
    }
}

/** The executor seam lets unit tests inspect TLS options without contacting a service. */
interface CurlExecutor
{
    public function execute(string $url, array $options): CurlExecutionResult;
}

final class CurlExecutionResult
{
    public function __construct(public readonly int $status, public readonly string|false $body, public readonly int $errorNumber = 0) {}
}

final class NativeCurlExecutor implements CurlExecutor
{
    public function execute(string $url, array $options): CurlExecutionResult
    {
        if (!function_exists('curl_init')) {
            throw new HttpTransportException('A extensão cURL do PHP não está disponível.', 'configuration');
        }
        $handle = curl_init($url);
        if ($handle === false) throw new HttpTransportException('Não foi possível iniciar a conexão.');
        try {
            if (!curl_setopt_array($handle, $options)) throw new HttpTransportException('Não foi possível configurar a conexão.');
            $body = curl_exec($handle);
            return new CurlExecutionResult((int) curl_getinfo($handle, CURLINFO_HTTP_CODE), $body, curl_errno($handle));
        } finally {
            curl_close($handle);
        }
    }
}

final class CurlHttpTransport implements HttpTransport
{
    public function __construct(private readonly ?CurlExecutor $executor = null) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 30): HttpResponse
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new InvalidArgumentException('O serviço externo deve usar uma URL HTTPS válida, sem credenciais.');
        }
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
            throw new InvalidArgumentException('Método HTTP inválido.');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 120) throw new InvalidArgumentException('Tempo limite inválido.');
        foreach ($headers as $header) {
            if (!is_string($header) || !preg_match('/^[!#$%&\x27*+.^_`|~A-Za-z0-9-]+:[^\r\n\x00]*$/D', $header)) {
                throw new InvalidArgumentException('Cabeçalho HTTP inválido.');
            }
        }
        if (!function_exists('curl_init')) {
            throw new HttpTransportException('A extensão cURL do PHP não está disponível.', 'configuration');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ];
        if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
        if ($method === 'HEAD') $options[CURLOPT_NOBODY] = true;
        $result = ($this->executor ?? new NativeCurlExecutor())->execute($url, $options);
        if ($result->body === false || $result->errorNumber !== 0 || $result->status < 100 || $result->status > 599) {
            $reason = $result->errorNumber === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'network';
            throw new HttpTransportException('Não foi possível consultar o serviço externo.', $reason);
        }
        return new HttpResponse($result->status, $result->body);
    }
}
