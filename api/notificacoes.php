<?php
require_once __DIR__.'/../backend/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$user=currentUser();
if (!$user || !(int)$user['email_verificado']) { http_response_code(401); echo json_encode(['error'=>'Entre novamente.']); exit; }
try {
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if ($method==='POST') {
        $id=filter_input(INPUT_POST,'notification_id',FILTER_VALIDATE_INT)?:0;
        markNotificationRead((int)$user['id'],$id);
    } elseif ($method!=='GET') { http_response_code(405); exit; }
    $after=max(0,filter_input(INPUT_GET,'after',FILTER_VALIDATE_INT)?:0);
    $payload=notificationSummary((int)$user['id'],$after);
    foreach($payload['novas'] as &$notice) $notice['url']=notificationUrl($notice);
    unset($notice);
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(Throwable $e) { http_response_code(500); echo json_encode(['error'=>friendlyError($e)]); }
