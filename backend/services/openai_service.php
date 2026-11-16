<?php

require_once __DIR__ . '/http_client.php';

function openAiSettings(): array
{
    return [
        'api_key' => trim((string) getenv('OPENAI_API_KEY')),
        'model' => trim((string) getenv('OPENAI_MODEL')) ?: 'gpt-4o-mini',
    ];
}

function openAiEnabled(): bool
{
    return openAiSettings()['api_key'] !== '' && openAiSettings()['api_key'] !== 'replace_with_openai_key';
}

function openAiAssistantReply(string $question, array $context = [], ?HttpTransport $transport = null): array
{
    $question = trim($question);
    if ($question === '') {
        return [
            'success' => false,
            'mode' => 'validation',
            'message' => 'Informe uma pergunta ou uma descrição do problema para receber ajuda do assistente.',
        ];
    }

    $settings = openAiSettings();
    if (!openAiEnabled()) {
        return [
            'success' => true,
            'mode' => 'mock',
            'message' => 'Assistente em modo local. Configure OPENAI_API_KEY para ativar a IA real.',
            'suggestion' => [
                'category' => 'Hidráulica',
                'description' => $question,
                'priority' => 'Média',
                'note' => 'Esta é uma sugestão local e precisa ser confirmada antes de abrir o chamado.',
            ],
        ];
    }

    $payload = [
        'model' => $settings['model'],
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Você é o Assistente do Condomínio do CondoConnect. Responda com ajuda útil para moradores sobre manutenção, chamados, regras e uso da plataforma. Nunca dê diagnóstico técnico definitivo e sempre sinalize que a sugestão precisa ser confirmada por um profissional ou pelo síndico quando for relevante. Nunca invente regras do condomínio; se não tiver informação, diga que precisa de confirmação.',
            ],
            [
                'role' => 'user',
                'content' => $question,
            ],
        ],
        'temperature' => 0.2,
    ];

    try {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = ($transport ?? new CurlHttpTransport())->request('POST', 'https://api.openai.com/v1/chat/completions', [
            'Authorization: Bearer ' . $settings['api_key'],
            'Content-Type: application/json',
        ], $body, 30);
    } catch (JsonException $error) {
        return ['success' => false, 'mode' => 'validation', 'message' => 'A pergunta contém caracteres inválidos.'];
    } catch (HttpTransportException | InvalidArgumentException $error) {
        return [
            'success' => false,
            'mode' => 'error',
            'message' => $error instanceof HttpTransportException && $error->reason === 'timeout'
                ? 'O tempo limite para consultar a OpenAI foi excedido. Tente novamente.'
                : 'Não foi possível consultar a OpenAI.',
        ];
    }
    if ($response->status < 200 || $response->status >= 300) {
        return ['success' => false, 'mode' => 'error', 'message' => 'Não foi possível consultar a OpenAI.'];
    }
    try {
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        return ['success' => false, 'mode' => 'error', 'message' => 'A OpenAI retornou uma resposta inválida.'];
    }
    $content = is_array($decoded) ? ($decoded['choices'][0]['message']['content'] ?? null) : null;
    if (!is_string($content) || trim($content) === '') {
        return ['success' => false, 'mode' => 'error', 'message' => 'A OpenAI retornou uma resposta inválida.'];
    }

    return [
        'success' => true,
        'mode' => 'openai',
        'message' => trim((string) $content),
        'raw' => $decoded,
    ];
}
