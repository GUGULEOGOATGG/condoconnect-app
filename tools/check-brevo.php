<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/config/env.php';
require_once __DIR__.'/../backend/services/http_client.php';
$key=getenv('BREVO_API_KEY') ?: '';
$from=getenv('BREVO_FROM_EMAIL') ?: '';
echo 'BREVO_API_KEY: '.($key!==''?'configurada':'ausente').PHP_EOL;
echo 'BREVO_FROM_EMAIL: '.(filter_var($from,FILTER_VALIDATE_EMAIL)?'válido':'ausente ou inválido').PHP_EOL;
if ($key==='' || !filter_var($from,FILTER_VALIDATE_EMAIL)) exit(1);
try {
    $http=new CurlHttpTransport();
    $account=$http->request('GET','https://api.brevo.com/v3/account',['accept: application/json','api-key: '.$key],null,15);
    echo 'BREVO_ACCOUNT_HTTP: '.$account->status.PHP_EOL;
    if ($account->status!==200) exit(1);
    $response=$http->request('GET','https://api.brevo.com/v3/senders',['accept: application/json','api-key: '.$key],null,15);
    if ($response->status!==200) { echo 'BREVO_SENDERS_HTTP: '.$response->status.PHP_EOL; exit(1); }
    $data=json_decode($response->body,true,512,JSON_THROW_ON_ERROR);
    foreach ($data['senders']??[] as $sender) {
        if (strcasecmp((string)($sender['email']??''),$from)===0 && !empty($sender['active'])) {
            echo "BREVO_OK: chave aceita e remetente ativo. Nenhum e-mail foi enviado.\n"; exit(0);
        }
    }
    echo "BREVO_SENDER_ERROR: remetente não encontrado ou não confirmado na conta.\n"; exit(1);
} catch(Throwable $error) {
    echo 'BREVO_CHECK_FAILED: '.($error instanceof HttpTransportException?$error->reason:'resposta inválida').PHP_EOL;
    exit(1);
}
