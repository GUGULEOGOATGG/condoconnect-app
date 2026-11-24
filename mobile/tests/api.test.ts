import test from 'node:test';
import assert from 'node:assert/strict';
import { ApiClient, ApiError, testConnection } from '../src/api';

test('authenticated requests preserve subpaths and send the token only in Authorization', async () => {
  let calledUrl = '', calledInit: RequestInit | undefined;
  const transport = (async (url: string, init: RequestInit) => { calledUrl = url; calledInit = init; return new Response(JSON.stringify({ ok: true })); }) as typeof fetch;
  const client = new ApiClient('https://example.test/sisc/', 'a'.repeat(64), transport);
  assert.deepEqual(await client.request('send_message', { chat_id: 12, mensagem: 'Olá' }), { ok: true });
  assert.equal(calledUrl, 'https://example.test/sisc/api/mobile.php?action=send_message');
  assert.equal(calledInit?.method, 'POST');
  assert.equal((calledInit?.headers as Record<string,string>).Authorization, `Bearer ${'a'.repeat(64)}`);
  assert.equal(calledInit?.body, JSON.stringify({ chat_id: 12, mensagem: 'Olá' }));
  assert.equal(calledUrl.includes('a'.repeat(64)), false);
});
test('public requests omit Authorization and safely encode search terms', async () => {
  const client = new ApiClient('https://example.test', null, (async (url, init) => {
    assert.equal(new URL(String(url)).searchParams.get('q'), 'Elétrica & reparos');
    assert.equal(init?.method, 'GET'); assert.equal((init?.headers as any).Authorization, undefined);
    return new Response('{"providers":[]}');
  }) as typeof fetch);
  assert.deepEqual(await client.request('providers', undefined, { q: 'Elétrica & reparos' }), { providers: [] });
});
test('authentication failures retain status and the server’s actionable message', async () => {
  const client = new ApiClient('https://example.test', null, (async () => new Response('{"error":"Sua sessão expirou."}', { status: 401 })) as typeof fetch);
  await assert.rejects(client.request('bootstrap'), error => error instanceof ApiError && error.status === 401 && error.message === 'Sua sessão expirou.');
});
test('HTML, malformed payloads and network errors become readable errors', async () => {
  for (const payload of ['<html>404</html>', 'null', '[]']) {
    const client = new ApiClient('https://example.test', null, (async () => new Response(payload)) as typeof fetch);
    await assert.rejects(client.request('ping'), ApiError);
  }
  const client = new ApiClient('https://example.test', null, (async () => { throw new TypeError('Network request failed'); }) as typeof fetch);
  await assert.rejects(client.request('ping'), error => error instanceof ApiError && error.status === 0);
});
test('each failure kind gets its own message: unreachable, server error and database unavailable', async () => {
  const offline = new ApiClient('https://example.test', null, (async () => { throw new TypeError('Network request failed'); }) as typeof fetch);
  await assert.rejects(offline.request('login', {}), (e: ApiError) => e.kind === 'unreachable' && e.code === 'network' && /fora do alcance/.test(e.message));
  const html404 = new ApiClient('https://example.test', null, (async () => new Response('<html>404</html>', { status: 404 })) as typeof fetch);
  await assert.rejects(html404.request('login', {}), (e: ApiError) => e.kind === 'server' && e.status === 404 && /api\//.test(e.message));
  const crash = new ApiClient('https://example.test', null, (async () => new Response('{"error":"x"}', { status: 500 })) as typeof fetch);
  await assert.rejects(crash.request('login', {}), (e: ApiError) => e.kind === 'server' && e.status === 500 && /respondeu com erro/.test(e.message));
  const database = new ApiClient('https://example.test', null, (async () => new Response('{"error":"O banco de dados está indisponível.","code":"database_unavailable"}', { status: 503 })) as typeof fetch);
  await assert.rejects(database.request('login', {}), (e: ApiError) => e.kind === 'database' && e.status === 503 && /Banco indisponível/.test(e.message));
  const outdated = new ApiClient('https://example.test', null, (async () => new Response('{"error":"x","code":"database_outdated"}', { status: 503 })) as typeof fetch);
  await assert.rejects(outdated.request('bootstrap'), (e: ApiError) => e.kind === 'database' && /migração/.test(e.message));
  const refused = new ApiClient('https://example.test', null, (async () => new Response('{"error":"E-mail ou senha inválidos."}', { status: 401 })) as typeof fetch);
  await assert.rejects(refused.request('login', {}), (e: ApiError) => e.kind === 'request' && e.message === 'E-mail ou senha inválidos.');
});
test('a server that never answers is reported as out of reach after the timeout', async () => {
  const hanging = (async (_url: string, init: RequestInit) => new Promise((_resolve, reject) => {
    init.signal?.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
  })) as typeof fetch;
  const client = new ApiClient('https://example.test', null, hanging, 30);
  await assert.rejects(client.request('login', {}), (e: ApiError) => e.kind === 'unreachable' && e.code === 'timeout' && /não respondeu/.test(e.message));
});
function fakeServer(routes: Record<string, () => Response>): typeof fetch {
  return (async (url: string) => {
    const route = routes[new URL(String(url)).searchParams.get('action') || ''];
    if (!route) throw new TypeError('Network request failed');
    return route();
  }) as typeof fetch;
}
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
test('connection test stops at the failing step and says why', async () => {
  const healthy = { app: 'CondoConnect', mobile_api: 1, database: 'ok', tables: 'ok', missing_tables: [], ok: true };
  const ping = () => json({ app: 'CondoConnect', mobile_api: 1 });
  const ok = await testConnection('https://example.test/sisc', fakeServer({ ping, health: () => json(healthy) }));
  assert.equal(ok.ok, true); assert.equal(ok.kind, null); assert.equal(ok.steps.every(step => step.ok), true); assert.equal(ok.steps.length, 5);

  const offline = await testConnection('https://example.test', fakeServer({}));
  assert.equal(offline.kind, 'unreachable'); assert.equal(offline.steps.at(-1)?.label, 'Servidor');

  const missingApi = await testConnection('https://example.test', fakeServer({ ping: () => new Response('<h1>Not Found</h1>', { status: 404 }) }));
  assert.equal(missingApi.kind, 'server'); assert.match(missingApi.summary, /404/);

  const oldApi = await testConnection('https://example.test', fakeServer({ ping, health: () => json({ error: 'Operação inválida.' }, 405) }));
  assert.equal(oldApi.kind, 'server'); assert.match(oldApi.summary, /versão antiga/);

  const noDatabase = await testConnection('https://example.test', fakeServer({ ping, health: () => json({ ...healthy, database: 'unavailable', tables: 'unknown', ok: false }, 503) }));
  assert.equal(noDatabase.kind, 'database'); assert.match(noDatabase.summary, /Banco indisponível/);

  const noTables = await testConnection('https://example.test', fakeServer({ ping, health: () => json({ ...healthy, tables: 'missing', missing_tables: ['reservas'], ok: false }, 503) }));
  assert.equal(noTables.kind, 'database'); assert.match(noTables.summary, /reservas/);

  const badUrl = await testConnection('ftp://example.test', fakeServer({}));
  assert.equal(badUrl.ok, false); assert.equal(badUrl.steps.length, 1);
});
