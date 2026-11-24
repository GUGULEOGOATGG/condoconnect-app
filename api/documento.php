<?php
// Entrega arquivos guardados em backend/storage, pasta que o .htaccess bloqueia na web.
//   ?tipo=certificado&id=<usuário>          certificado do síndico: administração ou o próprio síndico
//   ?tipo=portfolio&id=<usuário>&n=<índice>  foto do portfólio: quem pode ver o perfil
require_once dirname(__DIR__) . '/backend/includes/auth.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}
requireLogin();
$viewer = currentUser();
$kind = is_string($_GET['tipo'] ?? null) ? $_GET['tipo'] : '';
$target = findUserById(filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0);
$notFound = static function (): void { http_response_code(404); exit; };
if (!$target) $notFound();

$images = ['image/jpeg', 'image/png', 'image/webp'];
if ($kind === 'certificado') {
    if ($viewer['tipo_usuario'] !== 'admin' && (int)$viewer['id'] !== (int)$target['id']) $notFound();
    [$folder, $name, $allowed] = ['certificados', (string)($target['documento_certificado'] ?? ''), [...$images, 'application/pdf']];
} elseif ($kind === 'portfolio') {
    if (!canViewProfile($viewer, $target)) $notFound();
    $items = json_decode((string)($target['portfolio_imagens'] ?? '[]'), true);
    $index = filter_input(INPUT_GET, 'n', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    [$folder, $name, $allowed] = ['perfil', is_array($items) && is_int($index) && is_string($items[$index] ?? null) ? $items[$index] : '', $images];
} else {
    $notFound();
}

$name = basename($name);
$root = realpath(dirname(__DIR__) . '/backend/storage/' . $folder);
$path = $root && $name !== '' ? realpath($root . DIRECTORY_SEPARATOR . $name) : false;
if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) $notFound();
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, $allowed, true)) $notFound();

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
// PDF é baixado em vez de aberto dentro do site; imagens abrem na página.
header('Content-Disposition: ' . ($mime === 'application/pdf' ? 'attachment' : 'inline') . '; filename="' . $kind . '-' . (int)$target['id'] . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, no-store');
if ($method === 'GET') readfile($path);
