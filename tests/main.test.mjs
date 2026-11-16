import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { JSDOM, VirtualConsole } from 'jsdom';

const root = path.resolve(import.meta.dirname, '..');
const main = 'frontend/assets/js/main.js';
const response = (body, ok = true) => ({ ok, json: async () => body });
const settle = async () => { await new Promise(resolve => setImmediate(resolve)); };

async function page(t, html = '', options = {}) {
  const errors = [], virtualConsole = new VirtualConsole();
  virtualConsole.on('jsdomError', error => errors.push(error.message));
  const dom = new JSDOM(`<body data-api-base="/sisc-test/api">${html}</body>`, {
    url: 'https://example.test/sisc-test/pages/chat.php', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole,
  });
  const { window } = dom;
  if (window.document.readyState === 'loading') await new Promise(resolve => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  const media = new window.EventTarget(); media.matches = options.mobile ?? false;
  window.matchMedia = () => media;
  const intervals = new Map(), timers = [], requests = [];
  window.setInterval = (callback, delay) => { intervals.set(delay, callback); return delay; };
  window.setTimeout = (callback, delay) => { timers.push({ callback, delay }); return timers.length; };
  window.fetch = async (url, init) => {
    requests.push({ url, init });
    if (!options.fetch) throw new Error('Unexpected fetch in a browser unit test');
    return options.fetch(url, init, requests.length);
  };
  if (options.storageDenied) {
    Object.defineProperty(window, 'localStorage', { value: { getItem() { throw new Error('Storage blocked'); }, setItem() { throw new Error('Storage blocked'); } } });
  } else if (options.collapsed) window.localStorage.setItem('condoconnect.sidebar', 'collapsed');
  const filename = path.join(root, options.script ?? main);
  vm.runInContext(fs.readFileSync(filename, 'utf8'), dom.getInternalVMContext(), { filename });
  window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
  await settle();
  t.after(() => { dom.window.close(); assert.deepEqual(errors, [], 'Browser handlers must not throw'); });
  return {
    window, document: window.document, media, requests, timers,
    async poll(delay) { const callback = intervals.get(delay); assert.equal(typeof callback, 'function'); await callback(); await settle(); },
    submit(form) { const event = new window.Event('submit', { bubbles: true, cancelable: true }); form.dispatchEvent(event); return event; },
    key(key, shiftKey = false) { const event = new window.KeyboardEvent('keydown', { key, shiftKey, bubbles: true, cancelable: true }); window.document.dispatchEvent(event); return event; },
  };
}

const navigation = '<button class="menu-toggle">Menu</button><nav class="main-nav"><a href="#destino">Página</a></nav><input data-password type="password"><button data-toggle-password>Mostrar</button>';
for (const script of [main, 'assets/js/main.js']) {
  test(`${script}: menu links close navigation and password visibility can be restored`, async t => {
    const p = await page(t, navigation, { script });
    const toggle = p.document.querySelector('.menu-toggle'), nav = p.document.querySelector('.main-nav');
    toggle.click(); assert.equal(nav.classList.contains('is-open'), true);
    nav.querySelector('a').click(); assert.equal(nav.classList.contains('is-open'), false);
    const password = p.document.querySelector('[data-password]'), button = p.document.querySelector('[data-toggle-password]');
    button.click(); assert.equal(password.type, 'text'); assert.equal(button.textContent, 'Ocultar');
    button.click(); assert.equal(password.type, 'password'); assert.equal(button.textContent, 'Mostrar');
  });
  test(`${script}: pages without optional widgets do not fail`, async t => {
    const p = await page(t, '', { script }); assert.equal(p.requests.length, 0);
  });
}

const sidebar = '<button class="sidebar-toggle">Menu</button><aside id="app-sidebar"><button class="sidebar-close">Fechar</button><nav><a href="#primeiro"> Primeiro </a><a href="#ultimo">Último</a></nav></aside><div class="sidebar-backdrop" hidden></div>';
test('desktop sidebar restores preference and mobile sidebar traps focus and closes safely', async t => {
  const p = await page(t, sidebar, { collapsed: true });
  const toggle = p.document.querySelector('.sidebar-toggle'), panel = p.document.getElementById('app-sidebar'), backdrop = p.document.querySelector('.sidebar-backdrop');
  assert.equal(p.document.body.classList.contains('sidebar-collapsed'), true); assert.equal(toggle.getAttribute('aria-expanded'), 'false');
  assert.equal(panel.querySelector('a').title, 'Primeiro');
  toggle.click(); assert.equal(p.window.localStorage.getItem('condoconnect.sidebar'), 'expanded');
  toggle.click(); assert.equal(p.window.localStorage.getItem('condoconnect.sidebar'), 'collapsed');
  p.media.matches = true; p.media.dispatchEvent(new p.window.Event('change'));
  assert.equal(panel.inert, true); assert.equal(backdrop.hidden, true);
  panel.querySelectorAll('button,a').forEach(item => { item.getClientRects = () => [{}]; });
  toggle.click(); assert.equal(panel.inert, false); assert.equal(backdrop.hidden, false); assert.equal(toggle.getAttribute('aria-label'), 'Fechar menu');
  const first = panel.querySelector('button'), last = panel.querySelector('nav a:last-child');
  first.focus(); assert.equal(p.key('Tab', true).defaultPrevented, true); assert.equal(p.document.activeElement, last);
  assert.equal(p.key('Tab').defaultPrevented, true); assert.equal(p.document.activeElement, first);
  first.focus(); assert.equal(p.key('x').defaultPrevented, false);
  p.key('Escape'); assert.equal(panel.inert, true); assert.equal(p.document.activeElement, toggle);
  toggle.click(); backdrop.click(); assert.equal(p.document.body.classList.contains('sidebar-open'), false);
  toggle.click(); first.click(); assert.equal(p.document.body.classList.contains('sidebar-open'), false);
});

test('blocked browser storage does not prevent the sidebar from operating', async t => {
  const p = await page(t, sidebar, { storageDenied: true });
  p.document.querySelector('.sidebar-toggle').click(); assert.equal(p.document.body.classList.contains('sidebar-collapsed'), true);
  p.key('Escape'); assert.equal(p.document.body.classList.contains('sidebar-collapsed'), true);
});

test('confirmation and restored forms preserve originally disabled buttons', async t => {
  const p = await page(t, '<form id="delete" data-confirm="Excluir?"><button name="action" value="delete">Excluir</button><button type="submit" disabled>Restrito</button></form><form id="assistant-form"><button>Enviar</button></form>');
  const form = p.document.getElementById('delete'), enabled = form.querySelector('button'), restricted = form.querySelector('button[disabled]');
  p.window.confirm = () => false;
  assert.equal(p.submit(form).defaultPrevented, true); assert.equal(form.hasAttribute('aria-busy'), false);
  p.window.confirm = () => true; p.submit(form);
  assert.equal(form.getAttribute('aria-busy'), 'true'); assert.equal(enabled.disabled, false, 'The submit name/value remains available to native submission');
  p.timers.filter(timer => timer.delay === 0).forEach(timer => timer.callback());
  assert.equal(enabled.disabled, true);
  p.window.dispatchEvent(new p.window.Event('pageshow'));
  assert.equal(enabled.disabled, false); assert.equal(restricted.disabled, true); assert.equal(form.hasAttribute('aria-busy'), false);
  const assistant = p.document.getElementById('assistant-form'); p.submit(assistant); assert.equal(assistant.hasAttribute('aria-busy'), false);
});

test('restored BFcache pages reload forms while ordinary pageshow events do not', () => {
  const listeners = new Map(); let reloads = 0;
  const window = { addEventListener(name, handler) { listeners.set(name, handler); }, location: { reload() { reloads++; } } };
  const document = { addEventListener() {} };
  vm.runInNewContext(fs.readFileSync(path.join(root, main), 'utf8'), { window, document }, { filename: path.join(root, main) });
  listeners.get('pageshow')({ persisted: false }); assert.equal(reloads, 0);
  listeners.get('pageshow')({ persisted: true }); assert.equal(reloads, 1);
});

const notifications = '<div id="toast-region" data-notification-cursor="10"></div><span class="notification-count">3</span><span class="chat-unread-count">2</span>';
const notice = id => ({ id, titulo: '<img src=x onerror=alert(1)>', mensagem: 'Aviso de reserva', url: '/sisc-test/pages/reservas.php' });
test('notifications update counters, bound toast count, and render untrusted text and links safely', async t => {
  const p = await page(t, notifications, { fetch: async () => response({ nao_lidas: 120, mensagens_nao_lidas: 4, novas: [notice(11), notice(12), notice(13), notice(14), { ...notice(15), url: 'javascript:alert(1)' }] }) });
  const region = p.document.getElementById('toast-region');
  assert.equal(p.requests[0].url, '/sisc-test/api/notificacoes.php?after=10'); assert.equal(p.requests[0].init.headers.Accept, 'application/json');
  assert.equal(p.document.querySelector('.notification-count').textContent, '99+'); assert.equal(p.document.querySelector('.chat-unread-count').textContent, '4');
  assert.equal(region.children.length, 4); assert.equal(region.querySelector('img'), null); assert.equal(region.querySelector('strong').textContent, notice(1).titulo);
  assert.equal(region.firstElementChild.querySelector('a').pathname, '/sisc-test/pages/reservas.php'); assert.equal(region.lastElementChild.querySelector('a').getAttribute('href'), '#');
  region.querySelector('button').click(); assert.equal(region.children.length, 3);
  p.timers.filter(timer => timer.delay === 12000).forEach(timer => timer.callback()); assert.equal(region.children.length, 0);
  await p.poll(8000); assert.equal(p.requests.at(-1).url, '/sisc-test/api/notificacoes.php?after=15');
});

test('notification errors and malformed payloads preserve current state and retry on the next poll', async t => {
  const results = [response({}, false), response({ nao_lidas: 'bad', mensagens_nao_lidas: 0, novas: [] }), response({ nao_lidas: 0, mensagens_nao_lidas: 0, novas: null }), response({ nao_lidas: 0, mensagens_nao_lidas: 0, novas: [null] }), response({ nao_lidas: 0, mensagens_nao_lidas: 0, novas: [] })];
  const p = await page(t, notifications, { fetch: async () => results.shift() });
  assert.equal(p.document.querySelector('.notification-count').textContent, '3');
  for (let i = 0; i < 3; i++) { await p.poll(8000); assert.equal(p.document.querySelector('.notification-count').textContent, '3'); }
  await p.poll(8000); assert.equal(p.document.querySelector('.notification-count').hidden, true); assert.equal(p.document.querySelector('.chat-unread-count').hidden, true);
  assert.equal(p.document.getElementById('toast-region').children.length, 0); assert.equal(p.requests.at(-1).url.endsWith('after=10'), true);
});

test('notification polling prevents concurrent requests, pauses when hidden, and retries a network failure', async t => {
  let finish; const pending = new Promise(resolve => { finish = resolve; });
  const p = await page(t, notifications, { fetch: async (_url, _init, count) => { if (count === 1) return pending; if (count === 2) throw new Error('timeout'); return response({ nao_lidas: 1, mensagens_nao_lidas: 0, novas: [] }); } });
  await p.poll(8000); assert.equal(p.requests.length, 1);
  finish(response({ nao_lidas: 0, mensagens_nao_lidas: 0, novas: [] })); await settle();
  Object.defineProperty(p.document, 'hidden', { configurable: true, value: true }); await p.poll(8000); assert.equal(p.requests.length, 1);
  Object.defineProperty(p.document, 'hidden', { configurable: true, value: false }); await p.poll(8000); assert.equal(p.document.querySelector('.notification-count').hidden, true);
  await p.poll(8000); assert.equal(p.document.querySelector('.notification-count').textContent, '1'); assert.equal(p.document.querySelector('.notification-count').hidden, false);
});

const chat = '<div id="chat-messages" data-chat-id="42" data-endpoint="/sisc-test/api/chat.php"><p>Histórico existente</p></div><div id="chat-feedback"></div><form id="chat-form"><input type="hidden" name="csrf" value="form-token"><input name="mensagem" value="Olá"><button type="submit">Enviar</button></form>';
const message = overrides => ({ mensagem: '<script>alert(1)</script>', remetente_nome: 'Morador', hora: '01/10 10:00', mine: false, lida: false, ...overrides });

test('chat polling renders literal text and read receipts while preserving a reader’s scroll position', async t => {
  const p = await page(t, chat, { fetch: async () => response({ messages: [message(), message({ mine: true, lida: true }), message({ mine: true, lida: false })] }) });
  const box = p.document.getElementById('chat-messages');
  Object.defineProperties(box, { scrollHeight: { value: 1000 }, clientHeight: { value: 200 } }); box.scrollTop = 200;
  await p.poll(4000);
  assert.equal(p.requests[0].url, '/sisc-test/api/chat.php?chat_id=42'); assert.equal(box.children.length, 3); assert.equal(box.scrollTop, 200);
  assert.equal(box.querySelector('script'), null); assert.equal(box.querySelector('p').textContent, '<script>alert(1)</script>');
  assert.equal(box.querySelectorAll('.mine').length, 2); assert.match(box.children[1].querySelector('span').textContent, /Lida$/); assert.match(box.children[2].querySelector('span').textContent, /Enviada$/);
  box.scrollTop = 850; await p.poll(4000); assert.equal(box.scrollTop, 1000);
});

test('chat sends CSRF and conversation fields once, clears only a successful message, and restores focus', async t => {
  let finish; const pending = new Promise(resolve => { finish = resolve; });
  const p = await page(t, chat, { fetch: async (_url, init) => init?.method === 'POST' ? pending : response({ messages: [] }) });
  const form = p.document.getElementById('chat-form'), input = form.elements.mensagem, button = form.querySelector('button');
  assert.equal(p.submit(form).defaultPrevented, true); p.submit(form); await settle();
  assert.equal(p.requests.length, 1); assert.equal(button.disabled, true); assert.equal(input.value, 'Olá');
  assert.equal(p.requests[0].init.body.get('csrf'), 'form-token'); assert.equal(p.requests[0].init.body.get('chat_id'), '42'); assert.equal(p.requests[0].init.body.get('action'), 'send');
  assert.equal(p.requests[0].init.body.get('mensagem'), 'Olá'); assert.equal(p.requests[0].init.headers.Accept, 'application/json');
  finish(response({ ok: true })); await settle();
  assert.equal(input.value, ''); assert.equal(button.disabled, false); assert.equal(p.document.activeElement, input); assert.equal(p.document.getElementById('chat-feedback').textContent, 'Mensagem enviada.');
  assert.equal(p.requests.length, 2); assert.equal(p.document.getElementById('chat-messages').children.length, 0);
});

test('blank chat messages do not issue requests', async t => {
  const p = await page(t, chat); const form = p.document.getElementById('chat-form'); form.elements.mensagem.value = '   ';
  p.submit(form); await settle(); assert.equal(p.requests.length, 0); assert.equal(form.querySelector('button').disabled, false); assert.equal(p.document.getElementById('chat-feedback').textContent, 'Digite uma mensagem.');
});

test('chat failures keep the unsent message and re-enable the form without accepting malformed successes', async t => {
  const cases = [
    [response({ error: 'Formulário expirado.' }, false), 'Formulário expirado.'],
    [response(null, false), 'Não foi possível acessar a conversa.'],
    [response(null), 'Resposta inválida'],
    [response({}), 'Resposta inválida'],
    [{ ok: true, json: async () => { throw new SyntaxError('bad json'); } }, 'Resposta inválida'],
    [new Error('Network timeout'), 'Network timeout'],
  ];
  const p = await page(t, chat, { fetch: async () => { const current = cases[0][0]; if (current instanceof Error) throw current; return current; } });
  const form = p.document.getElementById('chat-form');
  while (cases.length) {
    p.submit(form); await settle(); assert.equal(form.elements.mensagem.value, 'Olá'); assert.equal(form.querySelector('button').disabled, false);
    assert.match(p.document.getElementById('chat-feedback').textContent, new RegExp(cases[0][1])); assert.equal(p.document.getElementById('chat-messages').textContent, 'Histórico existente'); cases.shift();
  }
  assert.equal(p.requests.length, 6);
});

test('chat polling rejects malformed history and recovers after a pending or forbidden request', async t => {
  let finish; const pending = new Promise(resolve => { finish = resolve; });
  const results = [pending, response({ messages: [null] }), response({ messages: [message({ mine: 'false' })] }), response({ messages: [] })];
  const p = await page(t, chat, { fetch: async () => results.shift() });
  const polling = p.poll(4000); await settle(); await p.poll(4000); assert.equal(p.requests.length, 1);
  finish(response({ error: 'Conversa não encontrada.' }, false)); await polling; assert.equal(p.document.getElementById('chat-messages').textContent, 'Histórico existente');
  assert.equal(p.document.getElementById('chat-feedback').textContent, 'Conversa não encontrada.');
  Object.defineProperty(p.document, 'hidden', { configurable: true, value: true }); await p.poll(4000); assert.equal(p.requests.length, 1);
  Object.defineProperty(p.document, 'hidden', { configurable: true, value: false });
  await p.poll(4000); assert.match(p.document.getElementById('chat-feedback').textContent, /Resposta inválida/); assert.equal(p.document.getElementById('chat-messages').textContent, 'Histórico existente');
  await p.poll(4000); assert.equal(p.document.getElementById('chat-messages').textContent, 'Histórico existente');
  await p.poll(4000); assert.equal(p.document.getElementById('chat-messages').children.length, 0);
});
