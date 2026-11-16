<?php
require_once dirname(__DIR__) . '/backend/includes/auth.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}
requireVerifiedEmail();
$user = currentUser();
$attachmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($attachmentId < 1) { http_response_code(404); exit; }
$stmt = getPDO()->prepare('SELECT a.id, a.arquivo, a.chamado_id FROM anexos a WHERE a.id = :id LIMIT 1');
$stmt->execute([':id' => $attachmentId]);
$attachment = $stmt->fetch();
if (!$attachment) { http_response_code(404); exit; }
$authorized = false;
foreach (getVisibleChamadosForUser((int)$user['id']) as $ticket) if ((int)$ticket['id'] === (int)$attachment['chamado_id']) { $authorized = true; break; }
if (!$authorized) { http_response_code(404); exit; }
$name = basename((string)$attachment['arquivo']);
$path = realpath(dirname(__DIR__) . '/backend/storage/uploads/' . $name);
$root = realpath(dirname(__DIR__) . '/backend/storage/uploads');
if (!$path || !$root || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) { http_response_code(404); exit; }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true)) { http_response_code(404); exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="anexo-' . (int)$attachment['id'] . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
if ($method === 'GET') readfile($path);
