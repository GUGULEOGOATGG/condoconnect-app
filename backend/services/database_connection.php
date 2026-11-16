<?php
declare(strict_types=1);

final class DatabaseConfigurationException extends RuntimeException {}
final class DatabaseConnectionException extends RuntimeException {}

/** Validate settings before building a DSN; credentials never belong in errors. */
function databaseConnectionSettings(array $config): array
{
    $host = $config['host'] ?? '';
    $name = $config['name'] ?? '';
    $user = $config['user'] ?? '';
    $port = $config['port'] ?? '3306';
    $password = $config['password'] ?? '';
    foreach (['host' => $host, 'name' => $name, 'user' => $user, 'password' => $password] as $key => $value) {
        if (!is_string($value)) throw new DatabaseConfigurationException('Configuração DB_' . strtoupper($key === 'name' ? 'NAME' : ($key === 'password' ? 'PASS' : $key)) . ' inválida.');
    }
    $host = trim($host); $name = trim($name);
    if ($host === '' || preg_match('/[;\x00-\x20]/', $host)) throw new DatabaseConfigurationException('Configure DB_HOST com um host válido.');
    if ($name === '' || strlen($name) > 64 || preg_match('/[;\x00-\x20]/', $name)) throw new DatabaseConfigurationException('Configure DB_NAME com um nome válido.');
    if (trim($user) === '') throw new DatabaseConfigurationException('Configure DB_USER.');
    if ((!is_string($port) && !is_int($port)) || !preg_match('/^[0-9]+$/D', (string)$port) || (int)$port < 1 || (int)$port > 65535) {
        throw new DatabaseConfigurationException('Configure DB_PORT entre 1 e 65535.');
    }
    return ['host' => $host, 'port' => (int)$port, 'name' => $name, 'user' => $user, 'password' => $password];
}

/** An optional connector makes connection errors testable without a real server. */
function createDatabaseConnection(array $config, ?callable $connector = null): PDO
{
    $settings = databaseConnectionSettings($config);
    $dsn = 'mysql:host=' . $settings['host'] . ';port=' . $settings['port'] . ';dbname=' . $settings['name'] . ';charset=utf8mb4';
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    $connector ??= static fn(string $dsn, string $user, string $password, array $options): PDO => new PDO($dsn, $user, $password, $options);
    try {
        return $connector($dsn, $settings['user'], $settings['password'], $options);
    } catch (PDOException $exception) {
        throw new DatabaseConnectionException('Não foi possível conectar ao banco de dados. Verifique DB_HOST, DB_PORT, DB_NAME, DB_USER e DB_PASS.', 0, $exception);
    }
}
