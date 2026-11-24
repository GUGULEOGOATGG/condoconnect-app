// App web para celular (app/): mensagens de erro, botão "Testar conexão", aviso de HTTP e telas de cada nível.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { JSDOM, VirtualConsole } from 'jsdom';

const root = path.resolve(import.meta.dirname, '..');
const source = fs.readFileSync(path.join(root, 'app/app.js'), 'utf8');
const json = (body, status = 200) => ({ status, ok: status >= 200 && status < 300, json: async () => body });
const html = status => ({ status, ok: false, json: async () => { throw new SyntaxError('Unexpected token <'); } });
const wait = async (window, check, label) => {
  for (let i = 0; i < 200; i++) { if (check()) return; await new Promise(resolve => setTimeout(resolve, 5)); }
  assert.fail(`Tempo esgotado esperando: ${label}. Tela: ${window.document.body.textContent.slice(0, 400)}`);
};

function boot(t, { url = 'http://2.24.65.149:8080/sisc-test/app/', routes = {} } = {}) {
  const errors = [], virtualConsole = new VirtualConsole();
  virtualConsole.on('jsdomError', error => errors.push(error.message));
  const dom = new JSDOM('<div id="app"></div>', { url, runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole });
  const { window } = dom, calls = [];
  window.scrollTo = () => {};
  window.fetch = async (target, init) => {
    const parsed = new URL(String(target));
    const action = parsed.searchParams.get('action');
    calls.push({ action, init, url: parsed });
    assert.equal(parsed.pathname, '/sisc-test/api/mobile.php');
    const route = routes[action];
    if (!route) throw new TypeError('Failed to fetch');
    return route(init);
  };
  window.eval(source);
  t.after(() => { assert.deepEqual(errors, []); window.close(); });
  return { window, document: window.document, calls };
}
const byText = (document, selector, text) => [...document.querySelectorAll(selector)].find(node => node.textContent.trim().includes(text));
const toastText = document => document.querySelector('.toast')?.textContent || '';
const healthy = { app: 'CondoConnect', mobile_api: 1, database: 'ok', tables: 'ok', missing_tables: [], ok: true };

async function fillLogin(window, document, email = 'pessoa@example.test') {
  await wait(window, () => document.querySelector('input[type=email]'), 'tela de login');
  document.querySelector('input[type=email]').value = email;
  document.querySelector('input[type=password]').value = 'Seguro123!';
  byText(document, 'button', 'Entrar na minha conta').click();
}

test('login screen over HTTP warns about the unencrypted connection and offers the connection test', async t => {
  const { window, document } = boot(t, { routes: { ping: () => json({ app: 'CondoConnect', mobile_api: 1 }), health: () => json(healthy) } });
  await wait(window, () => byText(document, 'button', 'Testar conexão'), 'botão Testar conexão');
  assert.match(document.body.textContent, /sem criptografia \(HTTP\)/);
  byText(document, 'button', 'Testar conexão').click();
  await wait(window, () => document.querySelector('.check-summary'), 'resultado do teste');
  assert.match(document.querySelector('.check-summary').textContent, /Tudo certo/);
  assert.equal(document.querySelectorAll('.check-step.ok').length, 5);
});

test('HTTPS pages do not show the unencrypted warning', async t => {
  const { window, document } = boot(t, { url: 'https://condominio.example/sisc-test/app/' });
  await wait(window, () => byText(document, 'button', 'Testar conexão'), 'tela de login');
  assert.doesNotMatch(document.body.textContent, /sem criptografia/);
});

test('connection test names the failing step: API missing, database down or tables missing', async t => {
  for (const [routes, expected] of [
    [{}, /fora do alcance/],
    [{ ping: () => html(404) }, /404.*api\/mobile\.php/],
    [{ ping: () => json({ mobile_api: 1 }), health: () => json({ error: 'Operação inválida.' }, 405) }, /versão antiga/],
    [{ ping: () => json({ mobile_api: 1 }), health: () => json({ ...healthy, database: 'unavailable', ok: false, code: 'database_unavailable' }, 503) }, /Banco indisponível/],
    [{ ping: () => json({ mobile_api: 1 }), health: () => json({ ...healthy, tables: 'missing', missing_tables: ['reservas'], ok: false }, 503) }, /Faltam: reservas/],
  ]) {
    const { window, document } = boot(t, { routes });
    await wait(window, () => byText(document, 'button', 'Testar conexão'), 'botão');
    byText(document, 'button', 'Testar conexão').click();
    await wait(window, () => document.querySelector('.check-summary'), 'resultado');
    assert.match(document.querySelector('.check-summary').textContent, expected);
    assert.equal(document.querySelector('.check-summary').classList.contains('bad'), true);
  }
});

test('login errors tell apart an unreachable server, a server error and an unavailable database', async t => {
  for (const [login, expected] of [
    [undefined, /Servidor fora do alcance/],
    [() => html(404), /respondeu com erro \(404\)/],
    [() => json({ error: 'Falha.' }, 500), /respondeu com erro \(500\)/],
    [() => json({ error: 'O banco de dados está indisponível no momento.', code: 'database_unavailable' }, 503), /Banco indisponível/],
    [() => json({ error: 'E-mail ou senha inválidos.' }, 401), /E-mail ou senha inválidos/],
  ]) {
    const { window, document } = boot(t, { routes: login ? { login } : {} });
    await fillLogin(window, document);
    await wait(window, () => toastText(document), 'mensagem de erro');
    assert.match(toastText(document), expected);
  }
});

const accounts = {
  morador: { tabs: ['Início', 'Chamados', 'Conversas', 'Perfil'], home: 'Abrir um chamado' },
  prestador: { tabs: ['Início', 'Atendimentos', 'Conversas', 'Perfil'], home: 'Chamados disponíveis' },
  sindico: { tabs: ['Início', 'Chamados', 'Conversas', 'Perfil'], home: 'Gestão do condomínio' },
  admin: { tabs: ['Painel', 'Chamados', 'Usuários', 'Conversas', 'Perfil'], home: 'Ações administrativas' },
};
function bootstrap(role) {
  return {
    user: { id: 9, nome: `Pessoa ${role}`, email: `${role}@example.test`, tipo_usuario: role, condominio: 'Alpha', email_verificado: 1, verificado: 1 },
    tickets: [], chats: [], contacts: [], notices: [], notifications: [], areas: [], reservations: [], community: { id: 1, nome: 'Alpha' },
    access: { role, manager: role === 'sindico' || role === 'admin', verified: true, condominio_id: 1, condominios: role === 'admin' ? [{ id: 1, nome: 'Alpha' }, { id: 2, nome: 'Beta' }] : [], pending_verifications: 0 },
  };
}
test('each access level opens its own screens after login', async t => {
  for (const [role, expected] of Object.entries(accounts)) {
    const token = role.padEnd(64, '0').replace(/[^a-f0-9]/g, 'a');
    const { window, document, calls } = boot(t, { routes: {
      login: () => json({ user: bootstrap(role).user, token }),
      bootstrap: () => json(bootstrap(role)),
      admin_overview: () => json({ totals: { total_usuarios: 4 }, by_condominio: [], activity: [], pending: [] }),
    } });
    await fillLogin(window, document, `${role}@example.test`);
    await wait(window, () => document.querySelector('nav.tabs') && byText(document, 'h3,button', expected.home), `telas de ${role}`);
    assert.deepEqual([...document.querySelectorAll('nav.tabs .tab')].map(tab => tab.textContent.trim()), expected.tabs, role);
    assert.match(document.querySelector('.role-chip').textContent, { morador: /Morador/, prestador: /Prestador/, sindico: /Síndico/, admin: /Administrador/ }[role]);
    const authorized = calls.find(call => call.action === 'bootstrap');
    assert.equal(authorized.init.headers.Authorization, `Bearer ${token}`, role);
    assert.equal(window.localStorage.getItem('condoconnect.web.session.v1'), JSON.stringify({ token }));
  }
});

test('the code step of two-factor login no longer prints "false" on screen', async t => {
  const { window, document } = boot(t, { routes: { login: () => json({ requires_code: true }) } });
  await fillLogin(window, document);
  await wait(window, () => document.querySelector('input[autocomplete=one-time-code]'), 'campo do código');
  assert.doesNotMatch(document.querySelector('main.auth').textContent, /false|null|undefined/);
});
