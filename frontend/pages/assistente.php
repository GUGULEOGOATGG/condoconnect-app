<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pageTitle = 'Assistente do Condomínio';
$metaDescription = 'Perguntas rápidas sobre manutenção, chamados e regras do condomínio.';
$bodyClass = 'auth-page';
require __DIR__ . '/../includes/header.php';
?>
<main class="auth-frame">
    <div class="container">
        <div class="auth-card assistant-card">
            <p class="eyebrow">Assistente IA</p>
            <h1>Assistente do Condomínio</h1>
            <p>Use a IA para tirar dúvidas sobre chamados, manutenção e procedimentos da plataforma. A sugestão deve sempre ser revisada pelo usuário antes de enviar.</p>

            <form id="assistant-form">
                <label>
                    <span>Como posso ajudar?</span>
                    <textarea id="assistant-question" rows="6" placeholder="Ex.: A pia da cozinha está vazando e molhando o armário..." required></textarea>
                </label>
                <button type="submit" class="button button-primary">Consultar assistente</button>
            </form>

            <div id="assistant-response" class="alert assistant-response"></div>
        </div>
    </div>
</main>

<script>
    const form = document.getElementById('assistant-form');
    const questionInput = document.getElementById('assistant-question');
    const responseBox = document.getElementById('assistant-response');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const question = questionInput.value.trim();
        if (!question) return;

        responseBox.style.display = 'block';
        responseBox.className = 'alert alert-info';
        responseBox.textContent = 'Consultando o assistente...';

        try {
            const response = await fetch('<?= getBasePath() ?>api/openai.php', {
                method: 'POST',
                headers: { 'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ question })
            });

            const payload = await response.json();
            if (!response.ok) {
                throw new Error(payload.error || 'Não foi possível consultar o assistente.');
            }

            const text = payload.message || 'Sem resposta disponível.';
            responseBox.className = 'alert alert-info';
            responseBox.textContent = text;

            if (payload.suggestion) {
                const suggestion = payload.suggestion;
                const title = document.createElement('strong');
                title.textContent = 'Sugestão';
                const details = document.createElement('p');
                details.textContent = `Categoria: ${suggestion.category || '—'} · Descrição: ${suggestion.description || '—'} · Prioridade: ${suggestion.priority || '—'}`;
                const note = document.createElement('small');
                note.textContent = suggestion.note || 'A sugestão deve ser confirmada antes de enviar.';
                responseBox.replaceChildren(title, details, note);
            }
        } catch (error) {
            responseBox.className = 'alert alert-error';
            responseBox.textContent = error.message || 'Erro ao consultar o assistente.';
        }
    });
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
