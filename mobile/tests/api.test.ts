import test from 'node:test';
import assert from 'node:assert/strict';
import { ApiClient, ApiError } from '../src/api';

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
