<?php
require_once __DIR__.'/../backend/includes/auth.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}
requireLogin();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT)?:0;
$target=findUserById($id);
if (!$target || !canViewProfile(currentUser(),$target)) { http_response_code(404); exit; }
$name=basename((string)($target['avatar']??''));
if ($name!=='' && !str_starts_with($name,'demo-')) {
    $root=realpath(__DIR__.'/../backend/storage/perfil');
    $path=$root ? realpath($root.DIRECTORY_SEPARATOR.$name) : false;
    if ($root && $path && str_starts_with($path,$root.DIRECTORY_SEPARATOR) && is_file($path)) {
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (in_array($mime,['image/jpeg','image/png','image/webp'],true)) {
            header('Content-Type: '.$mime); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private,max-age=300'); if ($method === 'GET') readfile($path); exit;
        }
    }
}
header('Content-Type: image/svg+xml; charset=utf-8');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'');
header('Cache-Control: private,max-age=300');
if ($method === 'HEAD') exit;
$initial=htmlspecialchars(mb_strtoupper(mb_substr($target['nome'],0,1)),ENT_QUOTES|ENT_XML1,'UTF-8');
$colors=['#1d4ed8','#0f766e','#7c3aed','#b45309']; $color=$colors[$id%4];
echo '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96"><rect width="96" height="96" rx="24" fill="'.$color.'"/><text x="48" y="62" text-anchor="middle" font-family="sans-serif" font-size="42" fill="white">'.$initial.'</text></svg>';
