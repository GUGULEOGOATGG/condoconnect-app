import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { createHandler } from '../netlify/functions/api.mjs';

function fixture() {
  const stores = new Map();
  const storeFactory = ({ name }) => {
    if (!stores.has(name)) stores.set(name, new Map());
    const data = stores.get(name);
    return { get: async key => data.has(key) ? JSON.parse(data.get(key)) : null, set: async (key, value) => data.set(key, value), list: async () => ({ blobs: [...data.keys()].map(key => ({ key })) }) };
  };
  const handler = createHandler(storeFactory);
  const request = async (action, body = {}, method = 'POST', token) => {
    const response = await handler({ path: '/api/' + action, httpMethod: method, headers: token ? { authorization: 'Bearer ' + token } : {}, body: JSON.stringify(body) });
    return { status: response.statusCode, body: response.body ? JSON.parse(response.body) : null };
  };
  return { stores, request };
}
const account = { nome: 'Morador', email: 'morador@example.test', senha: 'Seguro123!', condominio: 'Alpha', cpf: '12345678900' };
const ticket = { categoria: 'Hidráulica', titulo: 'Vazamento', descricao: 'Água vazando', localProblema: 'Cozinha', prioridade: 'Média', dataServico: '2026-10-15' };

test('registration stores hashed passwords and separate expiring tokens', async () => {
  const { stores, request } = fixture();
  const result = await request('register', account);
  assert.equal(result.status, 201); assert.match(result.body.token, /^[a-f0-9]{64}$/); assert.notEqual(result.body.user.id, result.body.token);
  assert.equal(result.body.user.password, undefined); assert.equal(result.body.user.cpf, undefined);
  const stored = JSON.parse(stores.get('users').get(account.email)); assert.notEqual(stored.password, account.senha);
  assert.equal((await request('register', account)).status, 409);
  const login = await request('login.php', { email: account.email, senha: account.senha }); assert.equal(login.status, 200); assert.notEqual(login.body.token, result.body.token);
  assert.equal((await request('login', { email: account.email, senha: 'wrong' })).status, 401);
  assert.equal((await request('me', {}, 'GET', stored.id)).status, 401);
  assert.equal((await request('me', {}, 'GET', result.body.token)).status, 200);
  const hash = createHash('sha256').update(result.body.token).digest('hex');
  const session = JSON.parse(stores.get('sessions').get(hash)); session.expiresAt = Date.now() - 1; stores.get('sessions').set(hash, JSON.stringify(session));
  assert.equal((await request('me', {}, 'GET', result.body.token)).status, 401);
});

test('registration rejects privileged roles, malformed fields and weak credentials', async () => {
  const { request } = fixture();
  for (const body of [[], { ...account, nome: [] }, { ...account, email: 'invalid' }, { ...account, senha: '123' }, { ...account, tipoUsuario: 'admin' }, { ...account, tipoUsuario: 'sindico' }, {}]) assert.equal((await request('register', body)).status, 400);
  assert.equal((await request('register', {}, 'OPTIONS')).status, 204);
});

test('tickets are validated and isolated by authenticated user', async () => {
  const { request } = fixture();
  const a = (await request('register', account)).body;
  const b = (await request('register', { ...account, email: 'outro@example.test' })).body;
  assert.equal((await request('tickets', ticket)).status, 401);
  assert.equal((await request('tickets', ticket, 'POST', a.token)).status, 201);
  assert.equal((await request('tickets', {}, 'GET', a.token)).body.tickets.length, 1);
  assert.equal((await request('tickets', {}, 'GET', b.token)).body.tickets.length, 0);
  for (const body of [{}, { ...ticket, categoria: 'invalid' }, { ...ticket, prioridade: 'invalid' }, { ...ticket, dataServico: '2026-02-30' }]) assert.equal((await request('tickets', body, 'POST', a.token)).status, 400);
  const p = (await request('register', { ...account, email: 'prestador@example.test', tipoUsuario: 'prestador' })).body;
  assert.equal((await request('tickets', ticket, 'POST', p.token)).status, 403);
  assert.equal((await request('unknown', {}, 'GET', a.token)).status, 404);
});

test('a corrupted stored password is rejected without throwing', async () => {
  const { request, stores } = fixture(); await request('register', account);
  const user = JSON.parse(stores.get('users').get(account.email)); user.password = 'broken'; stores.get('users').set(account.email, JSON.stringify(user));
  assert.equal((await request('login', { email: account.email, senha: account.senha })).status, 401);
});
