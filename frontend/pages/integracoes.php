<?php
$pageTitle = 'Integrações';
$metaDescription = 'Integrações prontas para pagamentos, notificações, IA e automações do CondoConnect.';
$bodyClass = 'public-page';
require __DIR__ . '/../includes/header.php';
?>

<main class="section section-single">
    <div class="container">
        <div class="section-header">
            <span>Integrações</span>
            <h2>Infraestrutura de automação para o próximo nível do condomínio.</h2>
            <p>O sistema já foi pensado para receber pagamentos, campanhas de comunicação, notificações e automações inteligentes sem quebrar o fluxo operacional.</p>
        </div>

        <div class="cards-grid">
            <article class="service-card">
                <div class="icon icon-blue">💳</div>
                <h3>Mercado Pago</h3>
                <p>Pix, boleto, cartão e cobrança para mensalidade, multa e serviços extras.</p>
            </article>
            <article class="service-card">
                <div class="icon icon-green">📨</div>
                <h3>Brevo</h3>
                <p>Confirmações de cadastro, recuperação de senha e notificações automáticas por e-mail.</p>
            </article>
            <article class="service-card">
                <div class="icon icon-teal">💬</div>
                <h3>WhatsApp</h3>
                <p>Envio de lembretes, alertas de chamados e comunicação direta com moradores e síndicos.</p>
            </article>
            <article class="service-card">
                <div class="icon icon-purple">🤖</div>
                <h3>IA e automações</h3>
                <p>Resumo de chamados, triagem de solicitações, FAQ e sugestões de resposta.</p>
            </article>
            <article class="service-card">
                <div class="icon icon-orange">📍</div>
                <h3>Google Maps</h3>
                <p>Geolocalização, endereços e logística para prestadores e áreas comuns.</p>
            </article>
            <article class="service-card">
                <div class="icon icon-red">🔔</div>
                <h3>Firebase / FCM</h3>
                <p>Push notifications para alertas, lembretes e status em tempo real.</p>
            </article>
            <article class="service-card">
                <div class="icon icon-gray">📦</div>
                <h3>Dropbox / Storage</h3>
                <p>Armazenamento seguro de documentos, fotos e anexos do condomínio.</p>
            </article>
            <article class="service-card">
                <div class="icon icon-blue">📊</div>
                <h3>Google Sheets</h3>
                <p>Relatórios financeiros, indicadores operacionais e exportações de dados.</p>
            </article>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
