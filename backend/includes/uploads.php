<?php
declare(strict_types=1);

function storeUpload(array $file, string $directory, bool $document = false): ?string
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) return null;
    if ($error !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
        || !is_uploaded_file($file['tmp_name'])) throw new DomainException('Falha no envio do arquivo.');
    $size = filesize($file['tmp_name']);
    if ($size === false || $size <= 0 || $size > ($document ? 5242880 : 2097152)) {
        throw new DomainException($document ? 'Envie um documento de até 5 MB.' : 'Envie uma imagem de até 2 MB.');
    }
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if ($document) $types['application/pdf'] = 'pdf';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($types[$mime])) throw new DomainException('Formato de arquivo inválido.');
    if (!is_dir($directory) && !mkdir($directory, 0750, true)) throw new RuntimeException('Não foi possível preparar a pasta de arquivos.');
    $name = bin2hex(random_bytes(16)) . '.' . $types[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $name)) throw new RuntimeException('Não foi possível salvar o arquivo.');
    return $name;
}
