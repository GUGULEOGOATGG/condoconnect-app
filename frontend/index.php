<?php
$pageTitle = 'Seu condomínio, mais conectado';
$metaDescription = 'Menos complicação, mais qualidade de vida. Conecte moradores, síndicos e prestadores com o CondoConnect.';
$bodyClass = 'home-page';
require __DIR__ . '/includes/header.php';
?>
<main id="main-content">
    <section class="home-hero">
        <div class="container home-hero__grid">
            <div class="home-hero__copy">
                <span class="pill-label"><span class="status-dot"></span> UMA NOVA FORMA DE VIVER EM COMUNIDADE</span>
                <h1>Seu condomínio.<br>Mais simples.<br><em>Mais conectado.</em></h1>
                <p>Menos complicação no dia a dia. Mais tempo para viver bem. Conecte pessoas, organize serviços e cuide do lugar que você chama de lar.</p>
                <div class="hero-actions">
                    <a class="button button-primary button-lg" href="<?= $basePath ?>pages/cadastro.php">Quero fazer parte <?= condoIcon('arrow') ?></a>
                    <a class="button button-text button-lg" href="#como-funciona">Conhecer a plataforma <span aria-hidden="true">↗</span></a>
                </div>
                <div class="hero-reassurance"><?= condoIcon('shield') ?><span>Moradores, síndicos e prestadores. <strong>Juntos.</strong></span></div>
            </div>
            <div class="home-hero__visual">
                <img class="hero-residence" src="<?= $basePath ?>frontend/assets/images/condominio-home-hd.jpg" alt="Condomínio contemporâneo com varandas iluminadas, jardins e caminho de acesso ao anoitecer" width="1254" height="1254" fetchpriority="high" decoding="async">
                <div class="residence-caption"><span class="mini-label">O SEU LUGAR NO MUNDO</span><strong>Bem-vindo a uma<br>vida mais tranquila.</strong></div>
                <div class="floating-update"><span class="update-icon"><?= condoIcon('check') ?></span><div><span class="mini-label">TUDO BEM POR AQUI</span><strong>Mais cuidado com o seu lar</strong><p>Da solicitação à solução, em um só lugar.</p></div></div>
                <div class="visual-note"><?= condoIcon('building') ?><span>Conexões que fazem<br><strong>a diferença.</strong></span></div>
            </div>
        </div>
    </section>
    <div class="connection-strip"><div class="container"><span>PENSADO PARA A VIDA REAL</span><div><?= condoIcon('ticket') ?> Serviços organizados</div><div><?= condoIcon('chat') ?> Comunicação próxima</div><div><?= condoIcon('calendar') ?> Rotina mais leve</div><div><?= condoIcon('shield') ?> Mais transparência</div></div></div>
    <section class="section home-section" id="plataforma">
        <div class="container">
            <div class="section-heading-split"><div class="section-header"><span>UM ESPAÇO. MUITAS SOLUÇÕES.</span><h2>Tudo o que importa.<br><em>Bem perto de você.</em></h2></div><p>Uma plataforma para conectar o condomínio inteiro e deixar as pequenas e grandes tarefas mais simples.</p></div>
            <div class="feature-bento">
                <article class="feature-main"><span class="feature-icon"><?= condoIcon('ticket') ?></span><h3>Um chamado aberto.<br>Um problema a menos.</h3><p>Registre o que precisa de atenção, envie fotos e acompanhe cada etapa do atendimento.</p><a class="text-link" href="<?= $basePath ?>pages/novo-chamado.php">Organizar meus chamados <?= condoIcon('arrow') ?></a><div class="ticket-preview" aria-label="Exemplo de acompanhamento de chamado"><div class="ticket-preview__head"><span><?= condoIcon('tool') ?> Manutenção da área comum</span><small>EXEMPLO</small></div><div class="ticket-progress"><span class="done">1</span><i></i><span class="done">2</span><i></i><span>3</span></div><div class="ticket-progress-labels"><span>Solicitado</span><span>Em atendimento</span><span>Resolvido</span></div></div></article>
                <article class="feature-tile"><span class="feature-icon tone-sand"><?= condoIcon('chat') ?></span><h3>Boas conversas.<br>Melhores soluções.</h3><p>Fale com quem pode ajudar e mantenha as conversas do condomínio organizadas.</p><a class="text-link" href="<?= $basePath ?>pages/chat.php">Abrir conversas <?= condoIcon('arrow') ?></a></article>
                <article class="feature-tile feature-tile--mint"><span class="feature-icon"><?= condoIcon('calendar') ?></span><h3>Seu próximo bom momento.</h3><p>Consulte os espaços comuns e organize suas reservas com praticidade.</p><a class="text-link" href="<?= $basePath ?>pages/reservas.php">Explorar reservas <?= condoIcon('arrow') ?></a></article>
                <article class="feature-tile feature-tile--wide"><span class="feature-icon tone-sand"><?= condoIcon('notice') ?></span><div><h3>O condomínio acontece. Você fica por dentro.</h3><p>Comunicados e novidades reunidos para não perder o que é importante.</p></div><a class="round-link" href="<?= $basePath ?>pages/comunicados.php" aria-label="Ver comunicados"><?= condoIcon('arrow') ?></a></article>
            </div>
        </div>
    </section>
    <section class="section home-section services-section">
        <div class="container">
            <div class="section-heading-split"><div class="section-header"><span>CUIDADO EM CADA DETALHE</span><h2>Para cada necessidade,<br>uma boa conexão.</h2></div><a class="button button-secondary" href="<?= $basePath ?>pages/servicos.php">Conhecer os serviços <?= condoIcon('arrow') ?></a></div>
            <div class="home-services">
                <?php foreach ([['Elétrica','bolt','Instalações e reparos'],['Hidráulica','drop','Tudo fluindo bem'],['Pintura','paint','Novos ares e cores'],['Limpeza','clean','Cuidado e conservação'],['Elevadores','lift','Manutenção e inspeção'],['Jardinagem','leaf','Mais verde, mais vida'],['Segurança','shield','Proteção no dia a dia'],['Reparos gerais','tool','Soluções para sua rotina']] as [$category,$icon,$description]): ?>
                <a class="home-service" href="<?= $basePath ?>pages/pesquisa.php?categoria=<?= urlencode($category) ?>"><span class="feature-icon"><?= condoIcon($icon) ?></span><h3><?= $category ?></h3><p><?= $description ?></p><span class="service-arrow" aria-hidden="true">↗</span></a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <section class="section home-section" id="como-funciona">
        <div class="container">
            <div class="section-header centered"><span>SIMPLES DESDE O PRIMEIRO PASSO</span><h2>Uma rotina melhor<br>começa com uma conexão.</h2><p>Do cadastro ao atendimento, cada etapa tem seu lugar.</p></div>
            <div class="journey-grid">
                <article><span class="journey-number">01</span><h3>Encontre seu espaço</h3><p>Crie sua conta como morador, síndico ou prestador e faça parte da comunidade.</p></article>
                <article><span class="journey-number">02</span><h3>Conecte o que precisa</h3><p>Abra chamados, encontre profissionais, converse e reserve espaços do condomínio.</p></article>
                <article><span class="journey-number">03</span><h3>Acompanhe com tranquilidade</h3><p>Veja as atualizações, consulte o histórico e avalie o atendimento recebido.</p></article>
            </div>
            <div class="home-cta"><div><span class="mini-label">SEU CONDOMÍNIO PODE SER MAIS.</span><h2>Mais conexão.<br>Mais tempo para <em>viver bem.</em></h2><p>Faça parte de uma comunidade mais próxima e organizada.</p></div><a class="button button-lime button-lg" href="<?= $basePath ?>pages/cadastro.php">Vamos começar <?= condoIcon('arrow') ?></a><span class="cta-decoration" aria-hidden="true"><?= condoIcon('building') ?></span></div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
