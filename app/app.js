/* CondoConnect no celular.
 * Usa a API do aplicativo (api/mobile.php) no mesmo servidor. O nível de acesso vem da conta
 * autenticada; o servidor confere cada operação, e esta interface só escolhe o que mostrar. */
(() => {
  'use strict';

  const API = new URL('../api/mobile.php', location.href).href;
  const SITE = new URL('../', location.href).href;
  const SESSION_KEY = 'condoconnect.web.session.v1';
  const ROOTS = ['home', 'tickets', 'chats', 'users', 'profile'];
  const CATEGORIES = ['Elétrica', 'Hidráulica', 'Pintura', 'Limpeza', 'Elevadores', 'Jardinagem', 'Segurança', 'Reparos gerais'];
  const PRIORITIES = ['Baixa', 'Média', 'Alta', 'Urgente'];
  const STATUS = { aberto: 'Aberto', andamento: 'Em andamento', concluido: 'Concluído', cancelado: 'Cancelado' };
  const ROLES = { morador: 'Morador', prestador: 'Prestador', sindico: 'Síndico', admin: 'Administrador' };
  const NOTICE_STATUS = { publicado: 'Publicado', rascunho: 'Rascunho', arquivado: 'Arquivado' };
  const TITLES = {
    ticket: 'Detalhes do chamado', newTicket: 'Novo chamado', chat: 'Conversa', newChat: 'Nova conversa',
    editProfile: 'Editar perfil', notices: 'Comunicados', notice: 'Comunicado', noticeEditor: 'Comunicado',
    notifications: 'Notificações', reservations: 'Reservas', newReservation: 'Nova reserva', providers: 'Prestadores',
    community: 'Condomínio', editCommunity: 'Editar condomínio', verifications: 'Verificação de síndicos',
  };
  const ICONS = {
    home: '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
    tool: '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.5-2.5z"/>',
    chat: '<path d="M21 12a8 8 0 0 1-11.8 7L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
    bell: '<path d="M6 16v-5a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 21h4"/>',
    megaphone: '<path d="M3 10v4h3l8 5V5l-8 5z"/><path d="M18 9a4 4 0 0 1 0 6"/>',
    calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
    people: '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.6 3-6 7-6s7 2.4 7 6"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.3c2.4.7 4 2.7 4 5.7"/>',
    building: '<rect x="5" y="3" width="14" height="18" rx="1"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2M10 21v-3h4v3"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    chevron: '<path d="M9 6l6 6-6 6"/>',
    back: '<path d="M15 6l-6 6 6 6"/>',
    check: '<path d="M5 12l5 5 9-10"/>',
    shield: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
    logout: '<path d="M10 4H5v16h5"/><path d="M14 8l4 4-4 4M18 12H9"/>',
    star: '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    pulse: '<path d="M3 12h4l3-7 4 14 3-7h4"/>',
    chart: '<path d="M4 20V10M10 20V4M16 20v-8M21 20H3"/>',
    edit: '<path d="M4 20h4L19 9l-4-4L4 16z"/>',
    send: '<path d="M4 12l16-8-6 16-3-6z"/>',
    pin: '<path d="M12 21s7-6.2 7-11a7 7 0 0 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
  };

  const state = { token: null, data: null, cond: 0, loading: true, admin: null, auth: { step: 'login' }, ui: { filter: 'Todos', query: '' } };
  let poll = null, lastRoute = '', toastTimer = null;

  // ---------- utilidades ----------
  class ApiError extends Error { constructor(message, status) { super(message); this.status = status; } }
  const $app = () => document.getElementById('app');

  /** Cria elementos sem innerHTML: todo texto do servidor entra como texto, nunca como marcação. */
  function h(tag, props, ...kids) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(props || {})) {
      if (value === false || value == null) continue;
      if (key === 'class') node.className = value;
      else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
      else if (key === 'value') node.value = value;
      else node.setAttribute(key, value === true ? '' : value);
    }
    for (const kid of kids.flat(Infinity)) if (kid !== false && kid != null && kid !== '') node.append(kid.nodeType ? kid : String(kid));
    return node;
  }
  function icon(name, extra) {
    const span = h('span', { class: `icon ${extra || ''}`, 'aria-hidden': 'true' });
    span.innerHTML = `<svg viewBox="0 0 24 24">${ICONS[name] || ''}</svg>`; // constantes deste arquivo
    return span;
  }
  const pad = n => String(n).padStart(2, '0');
  const localDate = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const localDateTime = d => `${localDate(d)}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  function when(value, time) {
    const date = new Date(String(value || '').replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return 'Data indisponível';
    return date.toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', ...(time ? { hour: '2-digit', minute: '2-digit' } : {}) });
  }
  const fold = text => String(text).toLocaleLowerCase('pt-BR');
  const same = (a, b) => Number(a) === Number(b);

  function toast(message, error) {
    document.querySelector('.toast')?.remove(); clearTimeout(toastTimer);
    const node = h('div', { class: `toast ${error ? 'error' : ''}`, role: error ? 'alert' : 'status', onclick: () => node.remove() }, message);
    document.body.append(node); toastTimer = setTimeout(() => node.remove(), error ? 7000 : 4000);
  }
  function confirmSheet(title, text, okLabel, danger) {
    return new Promise(resolve => {
      const close = answer => { overlay.remove(); resolve(answer); };
      const ok = h('button', { class: `btn ${danger ? 'danger' : ''}`, type: 'button', onclick: () => close(true) }, okLabel);
      const overlay = h('div', { class: 'overlay', role: 'dialog', 'aria-modal': 'true', 'aria-label': title, onclick: e => { if (e.target === overlay) close(false); } },
        h('div', { class: 'sheet' }, h('h3', null, title), h('p', { class: 'muted' }, text), ok,
          h('button', { class: 'btn secondary', type: 'button', onclick: () => close(false) }, 'Voltar')));
      document.body.append(overlay); ok.focus();
    });
  }
  /** Executa uma ação de botão uma única vez por toque e mostra o erro do servidor, se houver. */
  async function act(button, work, success) {
    if (button.disabled) return;
    button.disabled = true; button.classList.add('busy');
    try { await work(); if (success) toast(success); }
    catch (error) { toast(error.message || 'Não foi possível concluir. Tente novamente.', true); }
    finally { button.disabled = false; button.classList.remove('busy'); }
  }

  // ---------- sessão e API ----------
  const session = {
    load() { try { const s = JSON.parse(localStorage.getItem(SESSION_KEY) || 'null'); return s && /^[a-f0-9]{64}$/.test(s.token) ? s.token : null; } catch { return null; } },
    save(token) { try { token ? localStorage.setItem(SESSION_KEY, JSON.stringify({ token })) : localStorage.removeItem(SESSION_KEY); } catch { /* navegação privada */ } },
  };
  function endSession() { state.token = null; state.data = null; state.admin = null; state.cond = 0; state.auth = { step: 'login' }; session.save(null); }

  async function api(action, body, query) {
    const params = new URLSearchParams({ action, ...(query || {}) });
    const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 20000);
    let response;
    try {
      response = await fetch(`${API}?${params}`, {
        method: body ? 'POST' : 'GET', signal: controller.signal, cache: 'no-store', credentials: 'omit',
        headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}), ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}) },
        ...(body ? { body: JSON.stringify(body) } : {}),
      });
    } catch (error) {
      throw new ApiError(error.name === 'AbortError' ? 'O servidor demorou a responder. Tente novamente.' : 'Não foi possível conectar. Confira sua internet.', 0);
    } finally { clearTimeout(timer); }
    let payload;
    try { payload = await response.json(); } catch { throw new ApiError('O servidor não retornou uma resposta válida. Avise a administração.', response.status); }
    if (!response.ok) {
      const message = payload && typeof payload.error === 'string' ? payload.error : 'Não foi possível concluir a operação.';
      if (response.status === 401 && state.token && state.data) { endSession(); render(); }
      throw new ApiError(message, response.status);
    }
    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) throw new ApiError('Resposta inválida do servidor.', 502);
    return payload;
  }
  async function loadData() {
    state.data = await api('bootstrap', null, state.cond ? { condominio_id: String(state.cond) } : undefined);
    document.body.dataset.role = state.data.user.tipo_usuario;
  }
  /** Atualiza os dados; só redesenha se a pessoa não estiver digitando. */
  async function refresh(force) {
    await loadData();
    if (force || !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName || '')) render();
  }
  async function mutate(action, body) { const result = await api(action, body); await refresh(true); return result; }

  const user = () => state.data.user;
  const role = () => user().tipo_usuario;
  /** Servidores com a API antiga não enviam "access": as telas de gestão ficam ocultas. */
  const access = () => state.data.access || { role: role(), manager: false, verified: Number(user().verificado) === 1, condominio_id: 0, condominios: [], pending_verifications: 0, legacy: true };
  const emailOk = () => Number(user().email_verificado) === 1;

  // ---------- navegação ----------
  function route() {
    const [name, id] = location.hash.replace(/^#\/?/, '').split('/');
    return { name: name || 'home', id: Number(id) || 0 };
  }
  function go(name, id) {
    const target = `#/${name}${id ? `/${id}` : ''}`;
    if (location.hash === target) render(); else location.hash = target;
  }
  function back() { if (history.length > 1) history.back(); else go('home'); }
  window.addEventListener('hashchange', () => {
    render();
    if (state.data && ROOTS.includes(route().name)) refresh().catch(() => {});
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden && state.data && route().name !== 'chat') refresh().catch(() => {}); });

  // ---------- componentes ----------
  const card = (props, ...kids) => h('section', { ...(props || {}), class: `card ${props?.class || ''}` }, ...kids);
  const head = (eyebrow, title, text) => h('div', { class: 'stack' }, h('span', { class: 'eyebrow' }, eyebrow), h('h2', { class: 'title' }, title), text && h('p', { class: 'muted' }, text));
  const empty = (name, title, text) => card({ class: 'empty' }, h('span', { class: 'icon-tile' }, icon(name)), h('h3', null, title), h('p', { class: 'muted' }, text));
  const badge = (key, label) => h('span', { class: `badge ${key}` }, label);
  function button(label, onclick, options = {}) {
    const node = h('button', { class: `btn ${options.kind || ''}`, type: 'button' }, options.icon && icon(options.icon), label);
    node.addEventListener('click', () => onclick(node));
    return node;
  }
  function field(label, props = {}, tag = 'input') {
    const input = h(tag, { autocomplete: 'off', ...props });
    return { node: h('label', { class: 'field' }, h('span', null, label), input), input, get value() { return input.value; } };
  }
  function select(label, options, value) {
    const input = h('select', null, options.map(([key, text]) => h('option', { value: key, selected: String(key) === String(value) }, text)));
    return { node: h('label', { class: 'field' }, h('span', null, label), input), input, get value() { return input.value; } };
  }
  function chips(values, selected, onSelect) {
    const box = h('div', { class: 'chips', role: 'group' });
    const paint = current => box.replaceChildren(...values.map(value => h('button', { class: 'chip', type: 'button', 'aria-pressed': String(value === current), onclick: () => { paint(value); onSelect(value); } }, value)));
    paint(selected); return box;
  }
  const linkRow = (name, title, subtitle, onclick, count) => h('button', { class: 'link-row', type: 'button', onclick },
    h('span', { class: 'icon-tile' }, icon(name)), h('span', { class: 'grow' }, h('b', null, title), subtitle && h('span', { class: 'muted small' }, subtitle)),
    count ? h('span', { class: 'badge novo' }, String(count)) : null, icon('chevron', 'sm chev'));
  const tile = (name, title, subtitle, target, count) => h('button', { class: 'tile', type: 'button', onclick: () => go(...[].concat(target)) },
    h('span', { class: 'icon-tile' }, icon(name)), h('b', null, title), h('small', null, subtitle), count ? h('span', { class: 'count' }, count > 9 ? '9+' : String(count)) : null);
  const stat = (name, tone, value, label) => h('div', { class: 'stat' }, h('span', { class: `dot ${tone}` }, icon(name, 'sm')), h('b', null, pad(value)), h('span', null, label));

  function ticketCard(ticket) {
    return h('button', { class: 'card', type: 'button', onclick: () => go('ticket', Number(ticket.id)) },
      h('div', { class: 'row between wrap' }, h('span', { class: 'cat' }, ticket.categoria), badge(ticket.status, STATUS[ticket.status] || ticket.status)),
      h('h3', null, ticket.titulo),
      h('div', { class: 'row muted small' }, icon('pin', 'sm'), h('span', { class: 'grow' }, ticket.local_problema), icon('chevron', 'sm')),
      h('div', { class: 'foot' }, h('span', null, ticket.protocolo), h('span', null, when(ticket.criado_em))));
  }
  function verifyEmailCard() {
    return card({ class: 'warn' }, h('h3', null, 'Confirme seu e-mail'),
      h('p', { class: 'muted' }, `Abra o link enviado para ${user().email}. Depois, volte aqui para liberar os recursos.`),
      button('Reenviar confirmação', node => act(node, async () => toast((await api('resend_verification', {})).message)), { kind: 'secondary' }),
      button('Já confirmei', node => act(node, () => refresh(true)), { kind: 'secondary' }));
  }
  /** Só a administração escolhe o condomínio; as demais contas ficam no seu. */
  function condominioPicker() {
    const list = access().condominios || [];
    if (role() !== 'admin' || list.length < 2) return null;
    const picker = select('Condomínio em gestão', list.map(c => [c.id, c.nome]), access().condominio_id);
    picker.input.addEventListener('change', async () => {
      state.cond = Number(picker.value);
      try { await refresh(true); } catch (error) { toast(error.message, true); }
    });
    return picker.node;
  }

  // ---------- acesso ----------
  function authView() {
    const step = state.auth.step;
    const brand = h('div', { class: 'row' }, h('span', { class: 'brand-mark' }, icon('building')), h('span', { class: 'brand big' }, 'CondoConnect', h('span', null, '.')));
    const wrap = h('main', { class: 'auth' }, brand);
    const set = next => { state.auth = { step: next }; render(); };

    if (step === 'login' || step === 'code') {
      const email = field('E-mail', { type: 'email', inputmode: 'email', autocomplete: 'username', autocapitalize: 'none', placeholder: 'seu@email.com', maxlength: 190, value: state.auth.email || '' });
      const password = field('Senha', { type: 'password', autocomplete: 'current-password', maxlength: 255 });
      const code = field('Código de verificação', { inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: 6, placeholder: '000000' });
      const submit = button(step === 'code' ? 'Verificar e entrar' : 'Entrar na minha conta', node => act(node, async () => {
        const credentials = step === 'code' ? state.auth : { email: email.value.trim().toLowerCase(), senha: password.value };
        if (!credentials.email || !credentials.senha) throw new Error('Informe seu e-mail e senha.');
        if (step === 'code' && !/^\d{6}$/.test(code.value)) throw new Error('Informe o código de 6 dígitos.');
        const result = await api('login', { email: credentials.email, senha: credentials.senha, codigo: step === 'code' ? code.value : '' });
        if (result.requires_code) { state.auth = { step: 'code', email: credentials.email, senha: credentials.senha }; render(); return; }
        if (!result.token) throw new Error('Resposta de autenticação inválida.');
        await enter(result.token);
      }));
      const form = h('form', { class: 'card', novalidate: true, onsubmit: e => e.preventDefault(), onkeydown: e => { if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); submit.click(); } } },
        step === 'code' ? code.node : [email.node, password.node], submit,
        step === 'code' ? button('Voltar ao login', () => set('login'), { kind: 'secondary' })
          : h('button', { class: 'text-link', type: 'button', onclick: () => set('forgot') }, 'Esqueci minha senha'));
      wrap.append(
        step === 'code' ? head('Só mais um passo', 'Confirme que é você.', 'Enviamos um código de 6 dígitos para seu e-mail. Ele expira em 10 minutos.')
          : h('div', { class: 'hero' }, h('span', { class: 'pill' }, 'MAIS PERTO. MAIS SIMPLES.'), h('h2', null, 'Seu condomínio. Na palma da mão.'), h('p', null, 'Entre com a mesma conta do site. O aplicativo abre as telas do seu nível de acesso.')),
        form,
        step === 'login' && h('div', { class: 'levels' },
          [['morador', 'Chamados, reservas e comunicados'], ['prestador', 'Atendimentos e conversas'], ['sindico', 'Gestão do condomínio'], ['admin', 'Painel, usuários e verificações']]
            .map(([key, text]) => h('div', { class: 'level', 'data-level': key }, h('b', null, ROLES[key]), h('span', null, text)))),
        step === 'login' && button('Ainda não tenho uma conta', () => set('register'), { kind: 'secondary' }),
        h('p', { class: 'row muted small' }, icon('shield', 'sm'), 'Sua senha não fica guardada neste aparelho.'));
      return wrap;
    }
    if (step === 'forgot') {
      const email = field('E-mail da conta', { type: 'email', inputmode: 'email', autocomplete: 'username', maxlength: 190 });
      wrap.append(head('Vamos ajudar', 'Recupere seu acesso.', 'Informe seu e-mail para receber um link de redefinição de senha.'),
        card(null, email.node, button('Enviar link de recuperação', node => act(node, async () => toast((await api('forgot_password', { email: email.value.trim().toLowerCase() })).message)))),
        button('Voltar ao login', () => set('login'), { kind: 'secondary' }));
      return wrap;
    }
    // Cadastro: morador ou prestador. Síndico exige certificado e continua no site.
    const form = { tipo_usuario: 'morador' };
    const inputs = {
      nome: field('Nome completo', { autocomplete: 'name', maxlength: 190 }),
      cpf: field('CPF', { inputmode: 'numeric', maxlength: 11, placeholder: 'Somente números' }),
      email: field('E-mail', { type: 'email', inputmode: 'email', autocomplete: 'email', maxlength: 190 }),
      telefone: field('Telefone com DDD', { type: 'tel', inputmode: 'tel', autocomplete: 'tel', maxlength: 20 }),
      condominio: field('Nome do condomínio', { maxlength: 190 }),
      unidade: field('Bloco e unidade', { maxlength: 190, placeholder: 'Ex.: Bloco A, 204' }),
      senha: field('Crie uma senha (mínimo 8 caracteres)', { type: 'password', autocomplete: 'new-password', maxlength: 255 }),
      confirmar_senha: field('Repita sua senha', { type: 'password', autocomplete: 'new-password', maxlength: 255 }),
    };
    wrap.append(head('Uma nova conexão', 'Faça parte da comunidade.', 'Vamos enviar um link de confirmação para seu e-mail.'),
      card(null, h('span', { class: 'eyebrow' }, 'Seu perfil'), chips(['Morador', 'Prestador'], 'Morador', value => { form.tipo_usuario = value === 'Morador' ? 'morador' : 'prestador'; }),
        Object.values(inputs).map(input => input.node),
        button('Criar minha conta', node => act(node, async () => {
          for (const [key, input] of Object.entries(inputs)) form[key] = key.includes('senha') ? input.value : input.value.trim();
          form.cpf = form.cpf.replace(/\D/g, ''); form.email = form.email.toLowerCase();
          if (Object.values(form).some(value => !value)) throw new Error('Preencha todos os campos.');
          if (!/^\d{11}$/.test(form.cpf)) throw new Error('Informe um CPF com 11 números.');
          if (form.senha.length < 8 || form.senha !== form.confirmar_senha) throw new Error('Use uma senha de pelo menos 8 caracteres e repita a mesma senha.');
          const result = await api('register', form);
          if (!result.token) throw new Error('Resposta de cadastro inválida.');
          await enter(result.token);
          if (!result.verification_sent) toast('Conta criada, mas o e-mail de confirmação não foi enviado. Toque em reenviar confirmação.', true);
        }))),
      h('p', { class: 'muted small' }, 'O cadastro de síndicos exige certificado e é feito pelo site. Contas de administrador são criadas pela equipe responsável.'),
      h('a', { class: 'btn secondary', href: `${SITE}pages/cadastro.php` }, 'Abrir cadastro de síndico no site'),
      button('Já tenho uma conta', () => set('login'), { kind: 'secondary' }));
    return wrap;
  }
  async function enter(token) {
    state.token = token; state.cond = 0; state.admin = null;
    try { await loadData(); } catch (error) { state.token = null; throw error; }
    session.save(token); state.auth = { step: 'login' }; state.ui = { filter: 'Todos', query: '' };
    if (location.hash && location.hash !== '#/home') { history.replaceState(null, '', '#/home'); }
    render();
  }

  // ---------- início por nível ----------
  function homeView() {
    const data = state.data, first = user().nome.split(' ')[0], tickets = data.tickets;
    const count = status => tickets.filter(t => t.status === status).length;
    const greeting = h('div', { class: 'stack' }, h('span', { class: 'eyebrow' }, `${access().role === 'admin' ? 'Administração' : 'Meu espaço'} · ${ROLES[role()]}`), h('h2', { class: 'title' }, `Olá, ${first}!`));
    const recent = (title, list, emptyTitle, emptyText) => [
      h('div', { class: 'row between' }, h('h3', { class: 'section-title' }, title), h('button', { class: 'text-link', type: 'button', onclick: () => go('tickets') }, 'Ver todos →')),
      list.length ? list.slice(0, 3).map(ticketCard) : empty('tool', emptyTitle, emptyText)];
    const banner = (title, text) => h('div', { class: 'banner' }, h('div', { class: 'grow stack' }, h('span', { class: 'eyebrow' }, 'CADA CONEXÃO FAZ A DIFERENÇA'), h('h2', null, title), h('p', null, text)), h('span', { class: 'orb' }, icon('building', 'lg')));
    if (!emailOk()) return [greeting, verifyEmailCard()];

    if (role() === 'admin') return adminHome(greeting);
    if (role() === 'prestador') {
      const available = tickets.filter(t => t.status === 'aberto' && !t.prestador_id), mine = tickets.filter(t => same(t.prestador_id, user().id));
      return [greeting, banner('Seu trabalho transforma o dia de alguém.', 'Aceite chamados e acompanhe seus atendimentos.'),
        h('div', { class: 'stats three' }, stat('clock', 'amber', available.length, 'Disponíveis'), stat('pulse', '', mine.filter(t => t.status === 'andamento').length, 'Em atendimento'), stat('check', 'green', mine.filter(t => t.status === 'concluido').length, 'Concluídos')),
        h('div', { class: 'tiles' }, tile('tool', 'Atendimentos', 'Disponíveis e seus', 'tickets'), tile('chat', 'Conversas', 'Fale com moradores', 'chats'), tile('megaphone', 'Comunicados', 'Avisos do condomínio', 'notices'), tile('building', 'Condomínio', 'Regras e contato', 'community')),
        recent('Chamados disponíveis', available, 'Nenhum chamado disponível', 'Novos pedidos de atendimento aparecerão aqui.')];
    }
    if (role() === 'sindico') {
      if (!access().manager) return [greeting, card({ class: 'warn' }, h('h3', null, access().legacy ? 'Atualização necessária' : 'Verificação pendente'),
        h('p', { class: 'muted' }, access().legacy ? 'O servidor ainda usa a versão anterior da API do aplicativo. Peça a atualização do arquivo api/mobile.php para liberar a gestão.'
          : 'Chamados e recursos de gestão serão liberados quando a administração aprovar seu certificado de síndico.')),
        h('div', { class: 'tiles' }, tile('megaphone', 'Comunicados', 'Avisos do condomínio', 'notices'), tile('building', 'Condomínio', 'Regras e contato', 'community'))];
      return [greeting, banner('O condomínio em boas mãos.', data.community?.nome || user().condominio || 'Gestão do seu condomínio.'),
        h('div', { class: 'stats three' }, stat('clock', 'amber', count('aberto'), 'Abertos'), stat('pulse', '', count('andamento'), 'Em andamento'), stat('check', 'green', count('concluido'), 'Concluídos')),
        h('h3', { class: 'section-title' }, 'Gestão do condomínio'),
        h('div', { class: 'tiles' }, tile('megaphone', 'Publicar comunicado', 'Avise os moradores', 'noticeEditor'), tile('tool', 'Chamados', 'Todos do condomínio', 'tickets'), tile('calendar', 'Reservas', 'Agenda dos espaços', 'reservations'), tile('edit', 'Condomínio', 'Editar regras e contato', 'editCommunity')),
        recent('Chamados do condomínio', tickets, 'Nenhum chamado no condomínio', 'Os pedidos dos moradores aparecerão aqui.')];
    }
    return [greeting, banner('Uma rotina mais leve começa aqui.', 'Seu condomínio inteiro, em um só lugar.'),
      h('div', { class: 'stats' }, stat('clock', 'amber', count('aberto'), 'Aguardando'), stat('pulse', '', count('andamento'), 'Em andamento')),
      h('h3', { class: 'section-title' }, 'Como podemos ajudar?'),
      h('div', { class: 'tiles' }, tile('tool', 'Chamados', 'Solicite e acompanhe', 'tickets'), tile('calendar', 'Reservas', 'Bons momentos', 'reservations'), tile('megaphone', 'Comunicados', 'Fique por dentro', 'notices', data.notices.filter(n => !n.lido_em).length), tile('people', 'Prestadores', 'Encontre quem ajuda', 'providers')),
      recent('Chamados recentes', tickets, 'Tudo tranquilo por aqui', 'Se algo precisar de atenção, abra seu primeiro chamado.'),
      button('Abrir um chamado', () => go('newTicket'), { icon: 'plus' })];
  }

  // ---------- administração ----------
  async function loadAdmin() { state.admin = await api('admin_overview'); }
  function adminHome(greeting) {
    if (access().legacy) return [greeting, card({ class: 'warn' }, h('h3', null, 'Atualização necessária'), h('p', { class: 'muted' }, 'O servidor ainda usa a versão anterior da API do aplicativo. Atualize o arquivo api/mobile.php para liberar o painel administrativo.'))];
    const box = h('div', { class: 'list wide' }, h('div', { class: 'spinner' }));
    const paint = () => {
      const overview = state.admin, totals = overview.totals;
      box.replaceChildren(
        overview.pending.length ? h('button', { class: 'card warn', type: 'button', onclick: () => go('verifications') },
          h('div', { class: 'row between' }, h('h3', null, overview.pending.length === 1 ? '1 verificação de síndico pendente' : `${overview.pending.length} verificações de síndico pendentes`), icon('chevron', 'sm')), h('p', { class: 'muted' }, 'Aprove ou rejeite os certificados enviados.')) : null,
        h('div', { class: 'stats' },
          [['total_usuarios', 'Usuários', 'people', ''], ['moradores', 'Moradores', 'user', ''], ['profissionais', 'Prestadores', 'tool', 'green'], ['sindicos', 'Síndicos', 'shield', ''],
            ['condominios', 'Condomínios', 'building', ''], ['chamados_abertos', 'Chamados abertos', 'clock', 'amber'], ['chamados_concluidos', 'Serviços concluídos', 'check', 'green'], ['reservas', 'Reservas', 'calendar', '']]
            .map(([key, label, name, tone]) => stat(name, tone, Number(totals[key]) || 0, label))),
        h('h3', { class: 'section-title' }, 'Ações administrativas'),
        h('div', { class: 'tiles' }, tile('people', 'Usuários', 'Contas e níveis', 'users'), tile('shield', 'Verificações', 'Certificados de síndico', 'verifications', overview.pending.length),
          tile('megaphone', 'Comunicados', 'Publicar e gerenciar', 'notices'), tile('tool', 'Chamados', 'Todos os condomínios', 'tickets')),
        card(null, h('div', { class: 'row between' }, h('h3', null, 'Operação por condomínio'), h('span', { class: 'badge novo' }, `${Number(totals.comunicados) || 0} comunicados`)),
          h('div', { class: 'table' }, h('div', { class: 'tr head' }, h('span', null, 'Condomínio'), h('span', null, 'Pessoas'), h('span', null, 'Ativos')),
            overview.by_condominio.map(row => h('div', { class: 'tr' }, h('span', null, row.nome), h('span', null, String(Number(row.usuarios))), h('span', null, String(Number(row.chamados))))))),
        card(null, h('h3', null, 'Atividades recentes'), overview.activity.length ? h('ol', { class: 'timeline' }, overview.activity.map(event =>
          h('li', null, h('b', null, event.observacao || event.status_novo), h('span', { class: 'muted small' }, `${event.titulo} · ${event.usuario_nome || 'Sistema'} · ${when(event.criado_em, true)}`))))
          : h('p', { class: 'muted' }, 'Nenhuma atividade registrada.')));
    };
    if (state.admin) paint();
    loadAdmin().then(() => { if (box.isConnected) paint(); }).catch(error => { if (box.isConnected && !state.admin) box.replaceChildren(card({ class: 'warn' }, h('p', null, error.message), button('Tentar novamente', () => render(), { kind: 'secondary' }))); });
    return [greeting, h('p', { class: 'muted' }, 'Indicadores reais para acompanhar pessoas, atendimentos e espaços.'), box];
  }
  function usersView() {
    if (role() !== 'admin') return [empty('shield', 'Acesso restrito', 'Esta área é exclusiva da administração.')];
    const list = h('div', { class: 'list' });
    const search = field('Buscar por nome ou e-mail', { type: 'search', placeholder: 'Ex.: Maria ou maria@email.com', maxlength: 190 });
    let filter = 'Todos', timer = null, ticket = 0;
    const load = async () => {
      const mine = ++ticket, level = Object.keys(ROLES).find(key => ROLES[key] === filter), query = search.value.trim();
      list.replaceChildren(h('div', { class: 'spinner' }));
      try {
        // O servidor filtra por nome, e-mail, tipo ou status; o nível escolhido é aplicado aqui também.
        const { users } = await api('admin_users', null, { q: query || level || '' });
        if (mine !== ticket) return;
        const rows = users.filter(item => !level || item.tipo_usuario === level);
        list.replaceChildren(h('p', { class: 'muted small' }, rows.length === 1 ? '1 conta' : `${rows.length} contas`), ...(rows.length ? rows.map(item => card(null,
          h('div', { class: 'row' }, h('span', { class: 'avatar' }, String(item.nome).charAt(0).toUpperCase()), h('div', { class: 'grow' }, h('h3', null, item.nome), h('span', { class: 'muted small' }, item.email))),
          h('div', { class: 'row wrap tags' }, badge('novo', ROLES[item.tipo_usuario] || item.tipo_usuario), badge(item.status, item.status === 'ativo' ? 'Ativa' : 'Bloqueada'),
            badge(Number(item.email_verificado) === 1 ? 'aprovado' : 'pendente', Number(item.email_verificado) === 1 ? 'E-mail confirmado' : 'E-mail pendente'),
            item.tipo_usuario === 'sindico' ? badge(item.certificado_status, `Certificado ${item.certificado_status}`) : null))) : [empty('people', 'Nenhuma conta encontrada', 'Ajuste a busca ou o nível selecionado.')]));
      } catch (error) { if (mine === ticket) list.replaceChildren(card({ class: 'warn' }, h('p', null, error.message))); }
    };
    search.input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 350); });
    load();
    return [head('Administração', 'Usuários', 'Consulte contas, níveis de acesso e situação de verificação.'), search.node,
      chips(['Todos', ...Object.values(ROLES)], 'Todos', value => { filter = value; load(); }), list];
  }
  function verificationsView() {
    if (role() !== 'admin') return [empty('shield', 'Acesso restrito', 'Esta área é exclusiva da administração.')];
    const list = h('div', { class: 'list' }, h('div', { class: 'spinner' }));
    const decide = (item, status) => async node => {
      const approve = status === 'aprovado';
      if (!await confirmSheet(approve ? 'Aprovar este síndico?' : 'Rejeitar este cadastro?', approve ? `${item.nome} passará a gerir o condomínio ${item.condominio}.` : `${item.nome} não terá acesso à gestão do condomínio.`, approve ? 'Aprovar' : 'Rejeitar', !approve)) return;
      act(node, async () => { await api('verify_syndic', { user_id: Number(item.id), status }); await loadAdmin(); await loadData(); paint(); }, approve ? 'Síndico aprovado.' : 'Cadastro rejeitado.');
    };
    const paint = () => list.replaceChildren(...(state.admin.pending.length ? state.admin.pending.map(item => card(null,
      h('div', { class: 'row' }, h('span', { class: 'avatar' }, String(item.nome).charAt(0).toUpperCase()), h('div', { class: 'grow' }, h('h3', null, item.nome), h('span', { class: 'muted small' }, item.email))),
      h('dl', { class: 'details' }, [['Condomínio', item.condominio || '—'], ['Unidade', item.unidade || '—'], ['Certificado', Number(item.tem_certificado) ? 'Enviado (consulte no site)' : 'Não enviado'], ['Cadastro', when(item.criado_em, true)]]
        .map(([label, value]) => h('div', null, h('dt', null, label), h('dd', null, value)))),
      h('div', { class: 'btn-row' }, button('Rejeitar', decide(item, 'rejeitado'), { kind: 'danger' }), button('Aprovar', decide(item, 'aprovado'), { icon: 'check' }))))
      : [empty('shield', 'Nenhuma verificação pendente', 'Novos cadastros de síndico aparecerão aqui.')]));
    loadAdmin().then(() => { if (list.isConnected) paint(); }).catch(error => { if (list.isConnected) list.replaceChildren(card({ class: 'warn' }, h('p', null, error.message))); });
    return [head('Administração', 'Verificação de síndicos', 'Aprove ou rejeite os certificados enviados para ativar a gestão do condomínio.'),
      h('a', { class: 'btn secondary', href: `${SITE}pages/admin-verificacoes.php` }, 'Ver certificados no site'), list];
  }

  // ---------- chamados ----------
  function ticketsView() {
    const data = state.data, list = h('div', { class: 'list' });
    const paint = () => {
      const rows = data.tickets.filter(t => (state.ui.filter === 'Todos' || STATUS[t.status] === state.ui.filter) && fold(`${t.titulo} ${t.categoria} ${t.protocolo}`).includes(fold(state.ui.query)));
      list.replaceChildren(...(rows.length ? rows.map(ticketCard) : [empty('tool', 'Nenhum chamado encontrado', 'Os chamados aparecerão aqui. Você também pode ajustar os filtros.')]));
    };
    const search = field('Buscar chamado', { type: 'search', placeholder: 'Título, categoria ou protocolo', value: state.ui.query });
    search.input.addEventListener('input', () => { state.ui.query = search.value; paint(); });
    paint();
    const titles = { morador: ['Do pedido à solução', 'Meus chamados'], prestador: ['Seus atendimentos', 'Chamados'], sindico: ['Gestão do condomínio', 'Chamados do condomínio'], admin: ['Administração', 'Todos os chamados'] }[role()];
    return [head(titles[0], titles[1], 'Acompanhe cada etapa do atendimento.'), role() === 'morador' && emailOk() && button('Novo chamado', () => go('newTicket'), { icon: 'plus' }),
      search.node, chips(['Todos', ...Object.values(STATUS)], state.ui.filter, value => { state.ui.filter = value; paint(); }), list];
  }
  function ticketView(id) {
    const ticket = state.data.tickets.find(t => same(t.id, id)), me = user();
    if (!ticket) return [empty('tool', 'Chamado indisponível', 'Atualize a lista e tente novamente.')];
    const active = ['aberto', 'andamento'].includes(ticket.status), owner = same(ticket.usuario_id, me.id), syndic = role() === 'sindico' && access().manager;
    const cancel = button('Cancelar chamado', async node => {
      if (await confirmSheet('Cancelar chamado?', 'O atendimento será encerrado como cancelado.', 'Cancelar chamado', true)) act(node, () => mutate('ticket_status', { id, status: 'cancelado' }), 'Chamado cancelado.');
    }, { kind: 'danger' });
    return [h('div', { class: 'stack' }, h('span', { class: 'eyebrow' }, ticket.protocolo), h('h2', { class: 'title' }, ticket.titulo)), h('div', null, badge(ticket.status, STATUS[ticket.status] || ticket.status)),
      card(null, h('h3', null, 'O que precisa de atenção'), h('p', { class: 'muted pre' }, ticket.descricao), h('div', { class: 'divider' }),
        h('dl', { class: 'details' }, [['Categoria', ticket.categoria], ['Prioridade', ticket.prioridade], ['Local', ticket.local_problema], ['Data desejada', when(ticket.data_servico)], ['Abertura', when(ticket.criado_em, true)], ['Prestador', ticket.prestador_id ? 'Atribuído' : 'Aguardando']]
          .map(([label, value]) => h('div', null, h('dt', null, label), h('dd', null, value))))),
      (owner || syndic) && active && cancel,
      role() === 'prestador' && ticket.status === 'aberto' && !ticket.prestador_id && button('Aceitar atendimento', node => act(node, async () => { const { chat_id } = await mutate('accept_ticket', { id }); go('chat', Number(chat_id)); }), { icon: 'check' }),
      role() === 'prestador' && same(ticket.prestador_id, me.id) && ticket.status === 'andamento' && button('Concluir atendimento', async node => {
        if (await confirmSheet('Concluir atendimento?', 'Confirme que o serviço foi finalizado.', 'Concluir')) act(node, () => mutate('ticket_status', { id, status: 'concluido' }), 'Atendimento concluído.');
      }, { icon: 'check' }),
      syndic && ticket.prestador_id && ['andamento', 'concluido'].includes(ticket.status) && button('Entrar na conversa do chamado', node => act(node, async () => { const { chat_id } = await mutate('join_ticket_chat', { id }); go('chat', Number(chat_id)); }), { kind: 'secondary', icon: 'chat' }),
      role() === 'admin' && h('p', { class: 'muted small center' }, 'A administração acompanha os chamados em modo de consulta.'),
      role() !== 'admin' && button('Ir para minhas conversas', () => go('chats'), { kind: 'secondary', icon: 'chat' })];
  }
  function newTicketView() {
    if (role() !== 'morador') return [empty('tool', 'Somente moradores abrem chamados', 'Seu nível de acesso acompanha os chamados existentes.')];
    const form = { categoria: CATEGORIES[0], prioridade: 'Média' };
    const title = field('Título do problema', { maxlength: 190, placeholder: 'Ex.: Lâmpada do corredor piscando' });
    const description = field('Descrição', { maxlength: 10000, placeholder: 'Descreva o que precisa de atenção...' }, 'textarea');
    const place = field('Local do problema', { maxlength: 255, placeholder: 'Ex.: Bloco A, 2º andar' });
    const date = field('Data desejada', { type: 'date', min: localDate(new Date()), value: localDate(new Date()) });
    return [head('Vamos cuidar disso', 'Novo chamado', 'Conte o que aconteceu. Quanto mais detalhes, melhor podemos ajudar.'),
      card(null, h('span', { class: 'eyebrow' }, 'Categoria'), chips(CATEGORIES, form.categoria, value => { form.categoria = value; }), title.node, description.node, place.node,
        h('span', { class: 'eyebrow' }, 'Prioridade'), chips(PRIORITIES, form.prioridade, value => { form.prioridade = value; }), date.node,
        button('Enviar chamado', node => act(node, async () => {
          const input = { ...form, titulo: title.value.trim(), descricao: description.value.trim(), local_problema: place.value.trim(), data_servico: date.value };
          if (!input.titulo || !input.descricao || !input.local_problema) throw new Error('Preencha o título, a descrição e o local.');
          if (!/^\d{4}-\d{2}-\d{2}$/.test(input.data_servico)) throw new Error('Selecione uma data válida.');
          await api('create_ticket', input); await loadData(); toast('Chamado enviado.'); history.replaceState(null, '', '#/tickets'); render();
        }), { icon: 'send' }))];
  }

  // ---------- conversas ----------
  function chatsView() {
    const chats = state.data.chats;
    return [head('Perto de quem importa', 'Conversas', 'Fale com moradores, prestadores e a administração.'),
      emailOk() && button('Nova conversa', () => go('newChat'), { icon: 'plus' }),
      chats.length ? chats.map(chat => h('button', { class: `card ${Number(chat.nao_lidas) ? 'unread' : ''}`, type: 'button', onclick: () => go('chat', Number(chat.id)) },
        h('div', { class: 'row' }, h('span', { class: 'avatar' }, String(chat.contato_nome || '?').charAt(0).toUpperCase()),
          h('div', { class: 'grow' }, h('h3', null, chat.contato_nome || 'Conversa'), h('span', { class: 'muted small' }, ROLES[chat.contato_tipo] || '')),
          Number(chat.nao_lidas) ? h('span', { class: 'badge novo' }, String(chat.nao_lidas)) : icon('chevron', 'sm')),
        chat.ultimo_resumo && h('p', { class: 'muted small' }, chat.ultimo_resumo)))
        : empty('chat', 'Nenhuma conversa ainda', 'Inicie uma conversa com um contato do seu condomínio.')];
  }
  function newChatView() {
    const contacts = state.data.contacts;
    return [head('Comece por aqui', 'Nova conversa', 'Escolha com quem você quer falar.'),
      contacts.length ? card({ class: 'links' }, contacts.map(person => {
        const row = linkRow('user', person.nome, ROLES[person.tipo_usuario] || '', () => act(row, async () => { const { chat_id } = await api('open_chat', { usuario_id: Number(person.id) }); await loadData(); history.replaceState(null, '', `#/chat/${Number(chat_id)}`); render(); }));
        return row;
      })) : empty('people', 'Nenhum contato disponível', 'Os contatos liberados para sua conta aparecerão aqui.'),
      role() !== 'prestador' && button('Buscar prestadores', () => go('providers'), { kind: 'secondary', icon: 'people' })];
  }
  function chatView(id) {
    const me = user(), list = h('div', { class: 'messages' }, h('div', { class: 'spinner' }));
    let signature = '';
    const load = async () => {
      const { messages } = await api('messages', null, { chat_id: String(id) });
      const next = `${messages.length}:${messages.length ? messages[messages.length - 1].id : 0}`;
      if (next === signature) return;
      signature = next;
      list.replaceChildren(...(messages.length ? messages.map(message => {
        const mine = same(message.remetente_id, me.id);
        return h('div', { class: `bubble ${mine ? 'mine' : ''}` }, !mine && h('div', { class: 'who' }, message.remetente_nome), message.mensagem, h('small', null, when(message.created_at, true)));
      }) : [h('p', { class: 'muted center small' }, 'Envie a primeira mensagem desta conversa.')]));
      window.scrollTo(0, document.body.scrollHeight);
    };
    load().catch(error => list.replaceChildren(h('p', { class: 'muted center' }, error.message)));
    poll = setInterval(() => { if (!document.hidden) load().catch(() => {}); }, 5000);
    const input = h('textarea', { rows: 1, maxlength: 4000, placeholder: 'Escreva uma mensagem', 'aria-label': 'Mensagem' });
    const send = button('', node => act(node, async () => {
      const text = input.value.trim();
      if (!text) throw new Error('Escreva uma mensagem.');
      await api('send_message', { chat_id: id, mensagem: text }); input.value = ''; await load();
    }), { icon: 'send' });
    send.setAttribute('aria-label', 'Enviar mensagem');
    return [list, h('form', { class: 'composer', onsubmit: e => { e.preventDefault(); send.click(); } }, input, send)];
  }

  // ---------- comunicados ----------
  function noticesView() {
    const manager = access().manager, notices = state.data.notices;
    return [head(manager ? 'Gestão do condomínio' : 'A comunidade acontece', 'Comunicados', manager ? 'Publique avisos e acompanhe o que os moradores recebem.' : 'As novidades do seu condomínio, em um só lugar.'),
      condominioPicker(), manager && button('Novo comunicado', () => go('noticeEditor'), { icon: 'plus' }),
      notices.length ? notices.map(notice => {
        const state_ = notice.status || 'publicado', unread = state_ === 'publicado' && !notice.lido_em && !manager;
        return h('button', { class: `card ${unread ? 'unread' : ''}`, type: 'button', onclick: () => go('notice', Number(notice.id)) },
          h('div', { class: 'row between' }, h('span', { class: 'icon-tile' }, icon('megaphone')), manager ? badge(state_, NOTICE_STATUS[state_] || state_) : unread ? badge('novo', 'Novo') : null),
          h('h3', null, notice.titulo), h('p', { class: 'muted small' }, String(notice.conteudo).slice(0, 140) + (String(notice.conteudo).length > 140 ? '…' : '')),
          h('div', { class: 'foot' }, h('span', null, notice.autor_nome), h('span', null, when(notice.criado_em))));
      }) : empty('megaphone', 'Nenhuma novidade por enquanto', manager ? 'Publique o primeiro comunicado do condomínio.' : 'Os comunicados da administração aparecerão aqui.')];
  }
  function noticeView(id) {
    const notice = state.data.notices.find(n => same(n.id, id)), manager = access().manager;
    if (!notice) return [empty('megaphone', 'Comunicado indisponível', 'Volte à lista e atualize os comunicados.')];
    const published = (notice.status || 'publicado') === 'publicado';
    return [h('div', { class: 'stack' }, h('span', { class: 'eyebrow' }, 'Comunicado do condomínio'), h('h2', { class: 'title' }, notice.titulo), h('p', { class: 'muted' }, `${notice.autor_nome} · ${when(notice.criado_em, true)}`)),
      manager && h('div', null, badge(notice.status || 'publicado', NOTICE_STATUS[notice.status] || 'Publicado')),
      card(null, h('p', { class: 'pre' }, notice.conteudo)),
      published && (notice.lido_em ? h('p', { class: 'row muted' }, icon('check', 'sm'), 'Leitura confirmada') : button('Confirmar leitura', node => act(node, () => mutate('read_notice', { id })), { icon: 'check' })),
      manager && h('div', { class: 'btn-row' },
        button('Excluir', async node => { if (await confirmSheet('Excluir este comunicado?', 'Ele deixa de aparecer para os moradores. O histórico é preservado.', 'Excluir', true)) act(node, async () => { await api('delete_notice', { id }); await loadData(); history.replaceState(null, '', '#/notices'); render(); }, 'Comunicado excluído.'); }, { kind: 'danger' }),
        button('Editar', () => go('noticeEditor', id), { kind: 'secondary', icon: 'edit' }))];
  }
  function noticeEditorView(id) {
    if (!access().manager) return [empty('shield', 'Acesso restrito', 'Somente o síndico responsável e a administração publicam comunicados.')];
    const current = id ? state.data.notices.find(n => same(n.id, id)) : null;
    if (id && !current) return [empty('megaphone', 'Comunicado indisponível', 'Volte à lista e atualize os comunicados.')];
    const title = field('Título', { maxlength: 190, value: current?.titulo || '', placeholder: 'Ex.: Manutenção dos elevadores' });
    const content = field('Conteúdo', { maxlength: 15000, placeholder: 'Escreva o aviso para os moradores...' }, 'textarea'); content.input.value = current?.conteudo || '';
    const status = select('Situação', Object.entries(NOTICE_STATUS), current?.status || 'publicado');
    return [head('Gestão do condomínio', current ? 'Editar comunicado' : 'Novo comunicado', `Os moradores de ${state.data.community?.nome || 'seu condomínio'} são notificados ao publicar.`),
      card(null, title.node, content.node, status.node, button(current ? 'Salvar alterações' : 'Publicar comunicado', node => act(node, async () => {
        const input = { id: id || 0, titulo: title.value.trim(), conteudo: content.value.trim(), status: status.value, condominio_id: Number(access().condominio_id) || 0 };
        if (!input.titulo || !input.conteudo) throw new Error('Informe o título e o conteúdo.');
        await api('save_notice', input); await loadData(); toast('Comunicado salvo.'); history.replaceState(null, '', '#/notices'); render();
      }), { icon: 'megaphone' }))];
  }

  // ---------- reservas ----------
  function reservationsView() {
    const data = state.data, manager = access().manager, canReserve = role() !== 'prestador' && emailOk();
    return [head(manager ? 'Agenda dos espaços' : 'Seu próximo bom momento', 'Reservas', manager ? 'Acompanhe e administre as reservas do condomínio.' : 'Um espaço para celebrar, encontrar e viver bem.'), condominioPicker(),
      h('h3', { class: 'section-title' }, 'Espaços do condomínio'),
      data.areas.length ? data.areas.map(area => card(null, h('div', { class: 'row' }, h('span', { class: 'icon-tile' }, icon('calendar')), h('div', { class: 'grow' }, h('h3', null, area.nome), h('span', { class: 'muted small' }, `Até ${Number(area.capacidade)} pessoas`))),
        area.descricao && h('p', { class: 'muted' }, area.descricao), area.regras && h('p', { class: 'muted small' }, `Regras: ${area.regras}`),
        canReserve && button('Reservar este espaço', () => go('newReservation', Number(area.id)), { kind: 'secondary' })))
        : empty('calendar', 'Nenhum espaço disponível', 'A administração ainda não cadastrou áreas para reservas.'),
      h('h3', { class: 'section-title' }, 'Agenda do condomínio'),
      data.reservations.length ? data.reservations.map(item => {
        const mine = same(item.usuario_id, user().id), confirmed = item.status === 'confirmada';
        return card(null, h('div', { class: 'row between wrap' }, h('h3', null, item.area_nome), badge(item.status, confirmed ? 'Confirmada' : 'Cancelada')),
          h('p', { class: 'muted' }, `${when(item.inicio, true)} até ${when(item.fim, true)}`), h('p', { class: 'muted small' }, mine ? 'Sua reserva' : item.responsavel),
          manager && item.observacao && h('p', { class: 'muted small' }, `Observação: ${item.observacao}`),
          confirmed && (mine || manager) && button('Cancelar reserva', async node => {
            if (await confirmSheet('Cancelar esta reserva?', mine ? 'O horário ficará disponível para outras pessoas.' : 'A pessoa responsável será notificada do cancelamento.', 'Cancelar reserva', true)) act(node, () => mutate('cancel_reservation', { id: Number(item.id) }), 'Reserva cancelada.');
          }, { kind: 'danger' }));
      }) : empty('calendar', 'Agenda livre por aqui', 'As reservas do condomínio aparecerão nesta lista.')];
  }
  function newReservationView(id) {
    const areas = state.data.areas;
    if (role() === 'prestador' || !areas.length) return [empty('calendar', 'Reserva indisponível', 'Reservas são exclusivas de moradores e da administração.')];
    const start = new Date(); start.setDate(start.getDate() + 1); start.setHours(15, 0, 0, 0);
    const end = new Date(start); end.setHours(18);
    const area = select('Espaço', areas.map(a => [a.id, a.nome]), id || areas[0].id);
    const from = field('Início', { type: 'datetime-local', value: localDateTime(start), min: localDateTime(new Date()) });
    const to = field('Término', { type: 'datetime-local', value: localDateTime(end), min: localDateTime(new Date()) });
    const note = field('Observação (opcional)', { maxlength: 500, placeholder: 'O que precisamos saber?' }, 'textarea');
    return [head('Planeje seu encontro', 'Nova reserva'), card(null, area.node, from.node, to.node, note.node,
      h('p', { class: 'muted small' }, 'O período deve ser futuro e ter até 24 horas. A disponibilidade é conferida ao confirmar.'),
      button('Confirmar reserva', node => act(node, async () => {
        const input = { area_id: Number(area.value), inicio: from.value.slice(0, 16), fim: to.value.slice(0, 16), observacao: note.value.trim() };
        const a = new Date(input.inicio), b = new Date(input.fim);
        if (!input.area_id || Number.isNaN(a.getTime()) || Number.isNaN(b.getTime())) throw new Error('Selecione um espaço e um período válido.');
        if (a <= new Date() || b <= a || b - a > 86400000) throw new Error('Escolha um período futuro de até 24 horas, com término após o início.');
        await api('reserve', input); await loadData(); toast('Reserva confirmada.'); history.replaceState(null, '', '#/reservations'); render();
      }), { icon: 'calendar' }))];
  }

  // ---------- prestadores, condomínio, notificações, perfil ----------
  function providersView() {
    const list = h('div', { class: 'list' });
    const search = field('Buscar prestador', { type: 'search', placeholder: 'Nome ou especialidade', maxlength: 190 });
    let timer = null, ticket = 0;
    const load = async () => {
      const mine = ++ticket; list.replaceChildren(h('div', { class: 'spinner' }));
      try {
        const { providers } = await api('providers', null, { q: search.value.trim() });
        if (mine !== ticket) return;
        list.replaceChildren(...(providers.length ? providers.map(person => card(null,
          h('div', { class: 'row' }, h('span', { class: 'avatar' }, String(person.nome).charAt(0).toUpperCase()), h('div', { class: 'grow' }, h('h3', null, person.nome), h('span', { class: 'muted small' }, person.especialidades || 'Serviços gerais'))),
          h('div', { class: 'row small' }, icon('star', 'sm fill'), h('b', null, Number(person.avaliacao_media || 0).toFixed(1)), h('span', { class: 'muted' }, `(${Number(person.total_avaliacoes || 0)} avaliações)`)),
          person.bio && h('p', { class: 'muted' }, person.bio),
          !same(person.id, user().id) && button('Iniciar conversa', node => act(node, async () => { const { chat_id } = await api('open_chat', { usuario_id: Number(person.id) }); await loadData(); go('chat', Number(chat_id)); }), { kind: 'secondary', icon: 'chat' })))
          : [empty('people', 'Nenhum prestador encontrado', 'Tente outro nome ou outra especialidade.')]));
      } catch (error) { if (mine === ticket) list.replaceChildren(card({ class: 'warn' }, h('p', null, error.message), button('Tentar novamente', load, { kind: 'secondary' }))); }
    };
    search.input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(load, 350); });
    load();
    return [head('Para cada necessidade', 'Uma boa conexão.', 'Encontre profissionais para cuidar do seu espaço.'), search.node, list];
  }
  function communityView() {
    const community = state.data.community;
    return [head('O seu lugar no mundo', community?.nome || user().condominio || 'Condomínio'), condominioPicker(),
      card(null, h('span', { class: 'icon-tile' }, icon('building')), h('h3', null, 'Bem-vindo à comunidade'), h('p', { class: 'muted pre' }, community?.descricao || 'Seu condomínio está conectado pelo CondoConnect.'),
        community?.endereco && h('p', { class: 'muted small' }, [community.endereco, community.cidade, community.estado].filter(Boolean).join(' · '))),
      card(null, h('h3', null, 'Convivência e regras'), h('p', { class: 'muted pre' }, community?.regras || 'A administração ainda não adicionou as regras de convivência.')),
      community?.contato && card(null, h('h3', null, 'Fale com a administração'), h('p', { class: 'muted' }, community.contato)),
      access().manager && community && button('Editar informações', () => go('editCommunity'), { icon: 'edit' })];
  }
  function editCommunityView() {
    const community = state.data.community;
    if (!access().manager || !community) return [empty('shield', 'Acesso restrito', 'Somente o síndico responsável e a administração editam o condomínio.')];
    const about = field('Apresentação', { maxlength: 15000 }, 'textarea'); about.input.value = community.descricao || '';
    const rules = field('Regras de convivência', { maxlength: 15000 }, 'textarea'); rules.input.value = community.regras || '';
    const contact = field('Contato da administração', { maxlength: 190, value: community.contato || '', placeholder: 'Telefone, e-mail ou horário de atendimento' });
    return [head('Gestão do condomínio', community.nome, 'Essas informações aparecem para todos os moradores.'), condominioPicker(),
      card(null, about.node, rules.node, contact.node, button('Salvar informações', node => act(node, async () => {
        await api('save_community', { descricao: about.value.trim(), regras: rules.value.trim(), contato: contact.value.trim(), condominio_id: Number(access().condominio_id) || 0 });
        await loadData(); toast('Informações do condomínio atualizadas.'); history.replaceState(null, '', '#/community'); render();
      }), { icon: 'check' }))];
  }
  function notificationsView() {
    const items = state.data.notifications;
    const open = item => async () => {
      try { if (!Number(item.lida)) { await api('read_notification', { id: Number(item.id) }); await loadData(); } } catch { /* a navegação continua */ }
      if (item.chat_id) go('chat', Number(item.chat_id)); else if (item.chamado_id) go('ticket', Number(item.chamado_id));
      else if (item.tipo === 'reserva') go('reservations'); else if (item.tipo === 'comunicado') go('notices'); else render();
    };
    return [head('Você fica por dentro', 'Notificações'),
      items.some(n => !Number(n.lida)) && button('Marcar todas como lidas', node => act(node, () => mutate('read_notification', { id: 0 })), { kind: 'secondary' }),
      items.length ? items.map(item => h('button', { class: `card ${Number(item.lida) ? '' : 'unread'}`, type: 'button', onclick: open(item) },
        h('div', { class: 'row' }, icon(item.chat_id ? 'chat' : 'bell', 'sm'), h('h3', { class: 'grow' }, item.titulo)), h('p', { class: 'muted' }, item.mensagem), h('span', { class: 'muted small' }, when(item.criado_em, true))))
        : empty('bell', 'Tudo em dia', 'As atualizações da sua conta aparecerão aqui.')];
  }
  function profileView() {
    const me = user(), manager = access().manager, admin = role() === 'admin';
    const level = { morador: 'Abre chamados, faz reservas e acompanha os comunicados.', prestador: 'Aceita chamados e conduz os atendimentos.', sindico: manager ? 'Gerencia comunicados, reservas, chamados e as informações do condomínio.' : 'Gestão liberada após a aprovação do certificado.', admin: 'Acompanha todos os condomínios, usuários e verificações.' }[role()];
    return [head('Seu espaço, do seu jeito', 'Meu perfil'),
      card({ class: 'empty' }, h('span', { class: 'avatar big' }, String(me.nome).charAt(0).toUpperCase()), h('h3', null, me.nome), h('span', { class: 'role-chip' }, ROLES[role()]),
        h('p', { class: 'muted small' }, [me.unidade, me.condominio].filter(Boolean).join(' · ')), h('p', { class: 'muted small' }, me.email), button('Editar meu perfil', () => go('editProfile'), { kind: 'secondary' })),
      card(null, h('div', { class: 'row' }, icon('shield', 'sm'), h('h3', null, `Nível de acesso: ${ROLES[role()]}`)), h('p', { class: 'muted small' }, level)),
      card({ class: 'links' },
        admin && linkRow('people', 'Usuários', 'Contas e níveis de acesso', () => go('users')),
        admin && linkRow('shield', 'Verificações', 'Certificados de síndico', () => go('verifications'), Number(access().pending_verifications)),
        linkRow('building', manager ? 'Condomínio' : 'Meu condomínio', state.data.community?.nome || me.condominio, () => go('community')),
        role() !== 'prestador' && linkRow('calendar', 'Reservas', 'Espaços e agenda', () => go('reservations')),
        linkRow('megaphone', 'Comunicados', manager ? 'Publicar e gerenciar' : 'Novidades da comunidade', () => go('notices')),
        role() !== 'prestador' && linkRow('people', 'Prestadores', 'Encontre profissionais', () => go('providers')),
        linkRow('bell', 'Notificações', null, () => go('notifications'), state.data.notifications.filter(n => !Number(n.lida)).length)),
      h('a', { class: 'btn secondary', href: SITE }, 'Abrir o site completo'),
      button('Sair da minha conta', async () => {
        if (!await confirmSheet('Sair do aplicativo?', 'Sua conta será desconectada neste aparelho.', 'Sair')) return;
        api('logout', {}).catch(() => {}); endSession(); delete document.body.dataset.role; history.replaceState(null, '', '#/home'); render();
      }, { kind: 'secondary', icon: 'logout' }),
      h('p', { class: 'muted small center' }, 'CondoConnect · versão 2.0.0')];
  }
  function editProfileView() {
    const name = field('Nome completo', { autocomplete: 'name', maxlength: 190, value: user().nome || '' });
    const phone = field('Telefone com DDD', { type: 'tel', inputmode: 'tel', autocomplete: 'tel', maxlength: 20, value: user().telefone || '' });
    return [head('Vamos manter tudo em dia', 'Editar perfil'), card(null, name.node, phone.node, button('Salvar alterações', node => act(node, async () => {
      if (!name.value.trim() || !/^\d{10,11}$/.test(phone.value.replace(/\D/g, ''))) throw new Error('Informe seu nome e um telefone com DDD.');
      await api('profile', { nome: name.value.trim(), telefone: phone.value }); await loadData(); toast('Perfil atualizado.'); history.replaceState(null, '', '#/profile'); render();
    })))];
  }

  // ---------- moldura ----------
  const VIEWS = {
    home: homeView, tickets: ticketsView, ticket: ticketView, newTicket: newTicketView, chats: chatsView, newChat: newChatView, chat: chatView,
    notices: noticesView, notice: noticeView, noticeEditor: noticeEditorView, reservations: reservationsView, newReservation: newReservationView,
    providers: providersView, community: communityView, editCommunity: editCommunityView, notifications: notificationsView,
    profile: profileView, editProfile: editProfileView, users: usersView, verifications: verificationsView,
  };
  function tabsFor() {
    const unread = state.data.chats.reduce((sum, chat) => sum + Number(chat.nao_lidas || 0), 0);
    if (role() === 'admin') return [['home', 'Painel', 'chart'], ['tickets', 'Chamados', 'tool'], ['users', 'Usuários', 'people'], ['chats', 'Conversas', 'chat', unread], ['profile', 'Perfil', 'user']];
    return [['home', 'Início', 'home'], ['tickets', role() === 'prestador' ? 'Atendimentos' : 'Chamados', 'tool'], ['chats', 'Conversas', 'chat', unread], ['profile', 'Perfil', 'user']];
  }
  function shell(current) {
    const isRoot = ROOTS.includes(current.name), unread = state.data.notifications.filter(n => !Number(n.lida)).length;
    const chat = current.name === 'chat' ? state.data.chats.find(c => same(c.id, current.id)) : null;
    const top = h('header', { class: 'topbar' },
      isRoot ? [h('span', { class: 'brand-mark' }, icon('building')), h('div', { class: 'grow' }, h('div', { class: 'brand' }, 'CondoConnect', h('span', null, '.')), h('span', { class: 'role-chip' }, ROLES[role()]))]
        : [h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Voltar', onclick: back }, icon('back')), h('h1', { class: 'grow' }, chat?.contato_nome || TITLES[current.name] || 'CondoConnect')],
      h('button', { class: 'icon-btn', type: 'button', 'aria-label': `Notificações${unread ? `, ${unread} não lidas` : ''}`, onclick: () => go('notifications') }, icon('bell'), unread ? h('span', { class: 'count' }, unread > 9 ? '9+' : String(unread)) : null));
    const view = VIEWS[current.name] || homeView;
    const main = h('main', { class: `screen ${current.name === 'chat' ? 'chat' : ''}` }, view(current.id));
    const tabs = isRoot ? h('nav', { class: 'tabs', 'aria-label': 'Navegação principal' }, tabsFor().map(([name, label, glyph, count]) =>
      h('button', { class: 'tab', type: 'button', 'aria-current': name === current.name ? 'page' : null, onclick: () => go(name) }, icon(glyph), label, count ? h('span', { class: 'count' }, count > 9 ? '9+' : String(count)) : null))) : null;
    return [top, main, tabs];
  }
  function render() {
    if (poll) { clearInterval(poll); poll = null; }
    document.querySelector('.overlay')?.remove();
    const root = $app();
    if (state.loading) return;
    const current = route(), key = `${state.data ? 'in' : 'out'}:${current.name}:${current.id}:${state.auth.step}`;
    root.replaceChildren(...(state.data ? shell(current) : [authView()]).flat(Infinity).filter(Boolean));
    if (key !== lastRoute) { window.scrollTo(0, 0); lastRoute = key; }
    document.title = state.data ? `${TITLES[current.name] || 'CondoConnect'} · CondoConnect` : 'Entrar · CondoConnect';
  }

  // ---------- partida ----------
  (async () => {
    const token = session.load();
    if (token) {
      state.token = token;
      try { await loadData(); }
      catch (error) { if (error.status === 401) endSession(); else { state.token = null; toast(error.message, true); } }
    }
    state.loading = false; render();
  })();
})();
