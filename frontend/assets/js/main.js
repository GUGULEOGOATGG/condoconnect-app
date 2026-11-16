// Back/forward can restore a form from before logout or session expiration.
window.addEventListener('pageshow', function (event) {
  if (event.persisted) window.location.reload();
});

document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('app-sidebar');
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    const backdrop = document.querySelector('.sidebar-backdrop');
    const narrow = window.matchMedia('(max-width:850px)');
    let collapsed = false;
    try { collapsed = localStorage.getItem('condoconnect.sidebar') === 'collapsed'; } catch (_) {}
    const updateSidebar = (open = false) => {
        if (!sidebar || !sidebarToggle) return;
        document.body.classList.toggle('sidebar-collapsed', !narrow.matches && collapsed);
        document.body.classList.toggle('sidebar-open', narrow.matches && open);
        sidebar.inert = narrow.matches && !open;
        if (backdrop) backdrop.hidden = !narrow.matches || !open;
        sidebarToggle.setAttribute('aria-expanded', String(narrow.matches ? open : !collapsed));
        sidebarToggle.setAttribute('aria-label', narrow.matches ? (open ? 'Fechar menu' : 'Abrir menu') : (collapsed ? 'Expandir menu' : 'Recolher menu'));
        sidebar.querySelectorAll('nav a').forEach(link => { link.title = link.textContent.trim(); });
    };
    sidebarToggle?.addEventListener('click', () => {
        if (narrow.matches) {
            const open = !document.body.classList.contains('sidebar-open');
            updateSidebar(open); if (open) sidebar.querySelector('button,a')?.focus();
        } else {
            collapsed = !collapsed;
            try { localStorage.setItem('condoconnect.sidebar', collapsed ? 'collapsed' : 'expanded'); } catch (_) {}
            updateSidebar();
        }
    });
    const closeSidebar = () => { updateSidebar(false); sidebarToggle?.focus(); };
    backdrop?.addEventListener('click', closeSidebar);
    document.querySelector('.sidebar-close')?.addEventListener('click', closeSidebar);
    narrow.addEventListener('change', () => updateSidebar());
    document.addEventListener('keydown', event => {
        if (!narrow.matches || !document.body.classList.contains('sidebar-open')) return;
        if (event.key === 'Escape') closeSidebar();
        if (event.key === 'Tab') {
            const focusables = [...sidebar.querySelectorAll('button,a')].filter(item => item.getClientRects().length);
            const first = focusables[0], last = focusables.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    updateSidebar();
    document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    }));
    document.querySelectorAll('form').forEach(form => form.addEventListener('submit', event => {
        if (event.defaultPrevented || form.id === 'chat-form' || form.id.includes('assistant')) return;
        // Keep the submit button enabled until the browser has captured name/value.
        form.setAttribute('aria-busy', 'true');
        window.setTimeout(() => form.querySelectorAll('button[type="submit"]:not(:disabled),button:not([type]):not(:disabled)').forEach(button => {
            button.dataset.submitDisabled = 'true'; button.disabled = true;
        }), 0);
    }));
    window.addEventListener('pageshow', () => document.querySelectorAll('form[aria-busy]').forEach(form => {
        form.removeAttribute('aria-busy'); form.querySelectorAll('button[data-submit-disabled]').forEach(button => {
            button.disabled = false; delete button.dataset.submitDisabled;
        });
    }));
    const region = document.getElementById('toast-region');
    const api = document.body.dataset.apiBase;
    const toast = (title, message, url) => {
        if (!region) return;
        const card = document.createElement('div'); card.className = 'app-toast';
        const link = document.createElement('a');
        const destination = new URL(url, window.location.href);
        link.href = destination.origin === window.location.origin ? destination.href : '#';
        const heading = document.createElement('strong'); heading.textContent = title;
        const text = document.createElement('p'); text.textContent = message;
        const close = document.createElement('button'); close.type = 'button'; close.textContent = '×'; close.setAttribute('aria-label','Fechar notificação'); close.onclick = () => card.remove();
        link.append(heading, text); card.append(link, close); region.append(card);
        if (region.children.length > 4) region.firstElementChild.remove();
        window.setTimeout(() => card.remove(), 12000);
    };
    let cursor = Number(region?.dataset.notificationCursor || 0), notificationsBusy = false;
    const refreshNotifications = async () => {
        if (!region || notificationsBusy || document.hidden) return;
        notificationsBusy = true;
        try {
            const response = await fetch(`${api}/notificacoes.php?after=${cursor}`, { headers: { Accept:'application/json' } });
            if (!response.ok) return;
            const data = await response.json();
            if (!data || !Number.isInteger(Number(data.nao_lidas)) || Number(data.nao_lidas) < 0 ||
                !Number.isInteger(Number(data.mensagens_nao_lidas)) || Number(data.mensagens_nao_lidas) < 0 ||
                !Array.isArray(data.novas) || data.novas.some(notice => !notice || !Number.isInteger(Number(notice.id)) ||
                    Number(notice.id) < 1 || typeof notice.titulo !== 'string' || typeof notice.mensagem !== 'string' || typeof notice.url !== 'string')) return;
            document.querySelectorAll('.notification-count').forEach(badge => { badge.hidden = Number(data.nao_lidas) === 0; badge.textContent = Number(data.nao_lidas) > 99 ? '99+' : data.nao_lidas; });
            document.querySelectorAll('.chat-unread-count').forEach(badge => { badge.hidden = Number(data.mensagens_nao_lidas) === 0; badge.textContent = data.mensagens_nao_lidas; });
            data.novas.forEach(notice => { cursor = Math.max(cursor, Number(notice.id)); toast(notice.titulo, notice.mensagem, notice.url); });
        } catch (_) { /* The next poll retries without disrupting the current page. */ }
        finally { notificationsBusy = false; }
    };
    if (region) { refreshNotifications(); window.setInterval(refreshNotifications, 8000); }
    const chatBox = document.getElementById('chat-messages');
    const chatForm = document.getElementById('chat-form');
    if (chatBox && chatForm) {
        const feedback = document.getElementById('chat-feedback');
        let busy = false, sending = false;
        const chatResponse = async response => {
            let result;
            try { result = await response.json(); }
            catch (_) { throw new Error('Resposta inválida do servidor. Tente novamente.'); }
            if (!response.ok) throw new Error(typeof result?.error === 'string' ? result.error : 'Não foi possível acessar a conversa.');
            if (!result || typeof result !== 'object') throw new Error('Resposta inválida do servidor. Tente novamente.');
            return result;
        };
        const refreshChat = async () => {
            if (busy || document.hidden) return;
            busy = true;
            try {
                const response = await fetch(`${chatBox.dataset.endpoint}?chat_id=${encodeURIComponent(chatBox.dataset.chatId)}`, { headers: { Accept:'application/json' } });
                const data = await chatResponse(response);
                if (!Array.isArray(data.messages) || data.messages.some(message => !message ||
                    typeof message.remetente_nome !== 'string' || typeof message.mensagem !== 'string' || typeof message.hora !== 'string' ||
                    typeof message.mine !== 'boolean' || typeof message.lida !== 'boolean')) throw new Error('Resposta inválida do servidor. Tente novamente.');
                const atBottom = chatBox.scrollTop + chatBox.clientHeight >= chatBox.scrollHeight - 80;
                const fragment = document.createDocumentFragment();
                data.messages.forEach(message => {
                    const row = document.createElement('div'); row.className = 'message-row' + (message.mine ? ' mine' : '');
                    const bubble = document.createElement('div'); bubble.className = 'bubble';
                    const sender = document.createElement('strong'); sender.textContent = message.remetente_nome;
                    const content = document.createElement('p'); content.textContent = message.mensagem;
                    const time = document.createElement('span'); time.textContent = message.hora + (message.mine ? (message.lida ? ' · Lida' : ' · Enviada') : '');
                    bubble.append(sender, content, time); row.append(bubble); fragment.append(row);
                });
                chatBox.replaceChildren(fragment); if (atBottom) chatBox.scrollTop = chatBox.scrollHeight;
            } catch(error) { feedback.textContent = error.message; }
            finally { busy = false; }
        };
        chatForm.addEventListener('submit', async event => {
            event.preventDefault();
            if (sending) return;
            if (chatForm.elements.mensagem.value.trim() === '') { feedback.textContent = 'Digite uma mensagem.'; return; }
            sending = true; const button = chatForm.querySelector('button'); button.disabled = true;
            feedback.textContent = 'Enviando...';
            const data = new FormData(chatForm); data.set('chat_id', chatBox.dataset.chatId); data.set('action', 'send');
            try {
                const response = await fetch(chatBox.dataset.endpoint, { method:'POST', body:data, headers:{ Accept:'application/json' } });
                const result = await chatResponse(response);
                if (result.ok !== true) throw new Error('Resposta inválida do servidor. Tente novamente.');
                chatForm.elements.mensagem.value = ''; feedback.textContent = 'Mensagem enviada.';
                await refreshChat(); refreshNotifications();
            } catch(error) { feedback.textContent = error.message; }
            finally { sending = false; button.disabled = false; chatForm.elements.mensagem.focus(); }
        });
        chatBox.scrollTop = chatBox.scrollHeight; window.setInterval(refreshChat, 4000);
    }
    const toggle = document.querySelector('.menu-toggle');
    const nav = document.querySelector('.main-nav');

    if (toggle && nav) {
        const publicNarrow = window.matchMedia('(max-width:850px)');
        const setPublicMenu = open => {
            nav.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', String(open));
            toggle.setAttribute('aria-label', open ? 'Fechar navegação' : 'Abrir navegação');
        };
        toggle.addEventListener('click', function () {
            setPublicMenu(!nav.classList.contains('is-open'));
        });

        nav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                setPublicMenu(false);
            });
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && nav.classList.contains('is-open')) {
                setPublicMenu(false);
                toggle.focus();
            }
        });
        publicNarrow.addEventListener('change', () => setPublicMenu(false));
        setPublicMenu(false);
    }

    const passwordInput = document.querySelector('[data-password]');
    const togglePassword = document.querySelector('[data-toggle-password]');
    if (passwordInput && togglePassword) {
        togglePassword.addEventListener('click', function () {
            const hidden = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', hidden ? 'text' : 'password');
            togglePassword.textContent = hidden ? 'Ocultar' : 'Mostrar';
        });
    }
});
