<?php
require_once __DIR__ . '/_api.php';

function futureApisRegistry(): array
{
    return [
        'mercadopago' => [
            'name' => 'Mercado Pago',
            'category' => 'pagamentos',
            'status' => 'planejado',
            'required_env' => ['MERCADOPAGO_CLIENT_ID', 'MERCADOPAGO_CLIENT_SECRET', 'MERCADOPAGO_ACCESS_TOKEN', 'MERCADOPAGO_PUBLIC_KEY'],
            'actions' => ['status', 'oauth_token', 'create_payment', 'webhook'],
            'description' => 'Pagamentos de condomínio, mensalidades, taxas e cobranças recorrentes.',
            'use_case' => 'Mensalidade, multa, boleto, PIX e checkout do síndico.',
        ],
        'brevo' => [
            'name' => 'Brevo',
            'category' => 'email',
            'status' => 'ativo',
            'required_env' => ['BREVO_API_KEY', 'BREVO_FROM_EMAIL'],
            'actions' => ['send_template', 'send_email', 'verify'],
            'description' => 'Envio de e-mails para confirmação, recuperação de senha e notificações.',
            'use_case' => 'Confirmação de cadastro, autenticação e avisos ao usuário.',
        ],
        'whatsapp' => [
            'name' => 'WhatsApp Business API',
            'category' => 'mensageria',
            'status' => 'planejado',
            'required_env' => ['WHATSAPP_TOKEN', 'WHATSAPP_PHONE_ID', 'WHATSAPP_WEBHOOK_VERIFY_TOKEN'],
            'actions' => ['send_message', 'template', 'webhook'],
            'description' => 'Mensagens automáticas sobre chamados, pagamentos e alertas urgente.',
            'use_case' => 'Avisos de chamado aberto, senha redefinida e lembretes de pagamento.',
        ],
        'google_maps' => [
            'name' => 'Google Maps / Geocoding',
            'category' => 'localizacao',
            'status' => 'planejado',
            'required_env' => ['GOOGLE_MAPS_API_KEY'],
            'actions' => ['geocode', 'distance_matrix', 'reverse_geocode'],
            'description' => 'Localização de imóveis, rotas e busca por endereço.',
            'use_case' => 'Identificar endereço do condomínio, distância e mapas de áreas comuns.',
        ],
        'firebase' => [
            'name' => 'Firebase / FCM',
            'category' => 'push',
            'status' => 'planejado',
            'required_env' => ['FIREBASE_PROJECT_ID', 'FIREBASE_CLIENT_EMAIL', 'FIREBASE_PRIVATE_KEY'],
            'actions' => ['send_push', 'subscribe_device', 'list_tokens'],
            'description' => 'Notificações em tempo real em app mobile ou web.',
            'use_case' => 'Avisos de novo chamado, confirmação e lembrete de reunião.',
        ],
        'dropbox' => [
            'name' => 'Dropbox / Storage',
            'category' => 'arquivos',
            'status' => 'planejado',
            'required_env' => ['DROPBOX_TOKEN'],
            'actions' => ['upload_file', 'list_files', 'delete_file'],
            'description' => 'Armazenamento de anexos e documentos do condomínio.',
            'use_case' => 'Arquivos de contas, documentos de síndico e mídias de chamados.',
        ],
        'cielo' => [
            'name' => 'Cielo / Cartão',
            'category' => 'pagamentos',
            'status' => 'planejado',
            'required_env' => ['CIELO_MERCHANT_ID', 'CIELO_MERCHANT_KEY'],
            'actions' => ['create_token', 'create_payment', 'capture'],
            'description' => 'Pagamento com cartão para integradores que não usam Mercado Pago.',
            'use_case' => 'Cobrança de parcelas e serviços extras.',
        ],
        'sheets' => [
            'name' => 'Google Sheets / Planilhas',
            'category' => 'relatorios',
            'status' => 'planejado',
            'required_env' => ['GOOGLE_SERVICE_ACCOUNT', 'GOOGLE_SHEET_ID'],
            'actions' => ['append_row', 'read_sheet', 'sync_data'],
            'description' => 'Exportação de relatórios do condomínio e analítico.',
            'use_case' => 'Painel financeiro, relatórios de manutenção e indicadores.',
        ],
    ];
}

function futureApiEnvStatus(string $key): array
{
    $value = getenv($key);
    return [
        'name' => $key,
        'configured' => is_string($value) && trim($value) !== '',
        'value' => is_string($value) && trim($value) !== '' ? 'configured' : 'missing',
    ];
}

function futureApiStatusPayload(): array
{
    $registry = futureApisRegistry();
    $payload = [
        'project' => [
            'name' => 'CondoConnect',
            'type' => 'sistema de condomínio',
            'features' => ['cadastro', 'login', 'chamados', 'perfil', 'administração', 'notificações'],
        ],
        'apis' => [],
    ];

    foreach ($registry as $key => $api) {
        $resolved = [];
        foreach ($api['required_env'] as $envKey) {
            $resolved[] = futureApiEnvStatus($envKey);
        }

        $payload['apis'][$key] = [
            'name' => $api['name'],
            'category' => $api['category'],
            'status' => $api['status'],
            'description' => $api['description'],
            'use_case' => $api['use_case'],
            'actions' => $api['actions'],
            'required_env' => $resolved,
            'ready' => count(array_filter($resolved, static fn ($entry) => (bool) ($entry['configured'] ?? false))) >= 1,
        ];
    }

    return $payload;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    apiOptions();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiJson(405, ['error' => 'Método não permitido.']);
}

$data = apiReadJson();
$action = strtolower(trim((string) ($data['action'] ?? 'status')));
$registry = futureApisRegistry();

if ($action === 'list' || $action === 'status') {
    apiJson(200, futureApiStatusPayload());
}

if ($action === 'api') {
    $apiKey = strtolower(trim((string) ($data['api'] ?? '')));
    if (!isset($registry[$apiKey])) {
        apiJson(404, ['error' => 'API não encontrada.', 'available' => array_keys($registry)]);
    }

    $api = $registry[$apiKey];
    $envStatus = [];
    foreach ($api['required_env'] as $envKey) {
        $envStatus[] = futureApiEnvStatus($envKey);
    }

    apiJson(200, [
        'api' => $apiKey,
        'name' => $api['name'],
        'category' => $api['category'],
        'status' => $api['status'],
        'description' => $api['description'],
        'use_case' => $api['use_case'],
        'actions' => $api['actions'],
        'required_env' => $envStatus,
    ]);
}

if ($action === 'demo') {
    apiJson(200, [
        'message' => 'Arquivo de teste para APIs futuras do sistema de condomínio.',
        'suggested_apis' => array_keys($registry),
        'next_steps' => [
            'Configurar as chaves dos provedores reais.',
            'Criar endpoints específicos por módulo.',
            'Implementar webhooks e callbacks.',
            'Testar autenticação, logs e monitoramento.',
        ],
    ]);
}

apiJson(400, ['error' => 'Ação inválida.', 'available_actions' => ['status', 'list', 'api', 'demo']]);
