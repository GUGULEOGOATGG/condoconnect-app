<?php
declare(strict_types=1);

/** Load literal dotenv values without executing or interpolating their contents. */
function loadEnvFile(string $path): void
{
    $values = [];
    if (is_file($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Não foi possível ler o arquivo de ambiente.');
        }
        foreach ($lines as $index => $line) {
            $line = trim($index === 0 ? preg_replace('/^\xEF\xBB\xBF/', '', $line) : $line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $match)) {
                throw new RuntimeException('Linha inválida no arquivo de ambiente: ' . ($index + 1));
            }
            $value = $match[2];
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                if (!preg_match('/^([\'"])((?:\\\\.|(?!\1).)*)\1\s*(?:#.*)?$/', $value, $quoted)) {
                    throw new RuntimeException('Aspas inválidas no arquivo de ambiente: ' . ($index + 1));
                }
                $value = $quoted[2];
                if ($quoted[1] === '"') {
                    $value = strtr($value, ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\']);
                }
            } else {
                $value = rtrim(preg_replace('/\s+#.*$/', '', $value));
            }
            $values[$match[1]] = $value;
        }
    }

    $groups = [
        ['DB_NAME', 'DB_DATABASE'],
        ['DB_USER', 'DB_USERNAME'],
        ['DB_PASS', 'DB_PASSWORD'],
        ['BREVO_FROM_EMAIL', 'BREVO_SENDER_EMAIL'],
        ['BREVO_FROM_NAME', 'BREVO_SENDER_NAME'],
    ];
    $aliasKeys = array_merge(...$groups);
    foreach (array_keys($values) as $key) {
        if (!in_array($key, $aliasKeys, true)) $groups[] = [$key];
    }
    foreach ($groups as $keys) {
        $value = null;
        // The server environment wins over the file, including across aliases.
        foreach ($keys as $key) {
            $candidate = getenv($key);
            if ($candidate !== false) { $value = $candidate; break; }
        }
        foreach ([$_ENV, $_SERVER, $values] as $source) {
            if ($value !== null) break;
            foreach ($keys as $key) {
                if (isset($source[$key]) && is_scalar($source[$key])) {
                    $value = (string)$source[$key];
                    break;
                }
            }
        }
        if ($value === null) continue;
        foreach ($keys as $key) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }
}

/** Prefer .env; support the legacy filename only when .env is absent. */
function loadProjectEnv(string $root): void
{
    $path = rtrim($root, '/\\') . '/.env';
    if (!is_file($path)) $path = rtrim($root, '/\\') . '/vai.env';
    loadEnvFile($path);
}

loadProjectEnv(dirname(__DIR__, 2));
