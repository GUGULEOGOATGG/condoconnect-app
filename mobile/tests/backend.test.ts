import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { createServer } from 'node:net';
import path from 'node:path';

const php = process.env.PHP_BINARY || 'php';
const available = spawnSync(php, ['-n', '-v']).status === 0;
const entry = path.resolve(import.meta.dirname, '../../api/mobile.php');
function invoke(method: string, action: string, authorization?: string) {
  const code = `$_SERVER['REQUEST_METHOD']=${JSON.stringify(method)};$_GET['action']=${JSON.stringify(action)};${authorization ? `$_SERVER['HTTP_AUTHORIZATION']=${JSON.stringify(authorization)};` : ''}register_shutdown_function(function(){echo "\\nSTATUS:".http_response_code();});require ${JSON.stringify(entry)};`;
  const process = spawnSync(php, ['-n', '-r', code], { encoding: 'utf8' });
  assert.equal(process.status, 0, process.stderr);
  const [body, status] = process.stdout.split('\nSTATUS:');
  return { body: JSON.parse(body), status: Number(status) };
}
test('native API exposes a read-only version check without database access', { skip: !available }, () => {
  assert.deepEqual(invoke('GET', 'ping'), { body: { app: 'CondoConnect', mobile_api: 1 }, status: 200 });
});
test('health check answers JSON without a database and never exposes connection details', { skip: !available }, () => {
  const health = invoke('GET', 'health');
  assert.equal(health.status, 503);
  assert.equal(health.body.ok, false);
  assert.ok(['not_configured', 'unavailable'].includes(health.body.database), health.body.database);
  assert.equal(health.body.code, 'database_unavailable');
  assert.doesNotMatch(JSON.stringify(health.body), /SQLSTATE|password|senha|DB_|127\.0\.0\.1|localhost/i);
  assert.equal(invoke('POST', 'health').status, 405);
});
test('native API rejects method confusion and unauthenticated writes before database access', { skip: !available }, () => {
  assert.equal(invoke('POST', 'ping').status, 405);
  assert.equal(invoke('GET', 'login').status, 405);
  assert.equal(invoke('GET', 'bootstrap').status, 401);
  assert.equal(invoke('POST', 'cancel_reservation').status, 401);
  assert.equal(invoke('GET', 'messages', 'Bearer invalid-token').status, 401);  // Management and administration operations follow the same rules.
  for (const action of ['save_notice', 'delete_notice', 'save_community', 'join_ticket_chat', 'verify_syndic']) {
    assert.equal(invoke('GET', action).status, 405, action);
    assert.equal(invoke('POST', action).status, 401, action);
  }
  for (const action of ['admin_overview', 'admin_users']) {
    assert.equal(invoke('POST', action).status, 405, action);
    assert.equal(invoke('GET', action).status, 401, action);
    assert.equal(invoke('GET', action, 'Bearer invalid-token').status, 401, action);
  }
});
test('native API rejects an empty registration before database access', { skip: !available }, () => {
  assert.equal(invoke('POST', 'register').status, 400);
});
test('HTTP API enforces JSON shape, role restrictions, method rules and bearer authentication', { skip: !available }, async t => {
  const listener = createServer();
  await new Promise<void>(resolve => listener.listen(0, '127.0.0.1', resolve));
  const address = listener.address();
  if (!address || typeof address === 'string') throw new Error('No test port');
  const port = address.port;
  await new Promise<void>(resolve => listener.close(() => resolve()));
  const server = spawn(php, ['-n', '-S', `127.0.0.1:${port}`, '-t', path.dirname(path.dirname(entry))], { stdio: ['ignore', 'ignore', 'pipe'] });
  t.after(() => server.kill());
  await new Promise<void>((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error('PHP test server did not start')), 10000);
    server.stderr.on('data', output => { if (String(output).includes('Development Server')) { clearTimeout(timeout); resolve(); } });
    server.on('error', error => { clearTimeout(timeout); reject(error); });
    server.on('exit', code => { clearTimeout(timeout); if (code) reject(new Error(`PHP server exit ${code}`)); });
  });
  const endpoint = `http://127.0.0.1:${port}/api/mobile.php`;
  const post = (action: string, body: string) => fetch(`${endpoint}?action=${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body });
  assert.equal((await post('register', '{"tipo_usuario":"admin"}')).status, 400);
  assert.equal((await post('register', '{"tipo_usuario":"morador","senha":"12345678","confirmar_senha":"different"}')).status, 400);
  assert.equal((await post('login', '[]')).status, 400);
  assert.equal((await post('login', '{"email":{"nested":"value"}}')).status, 400);
  assert.equal((await post('login', 'invalid-json')).status, 400);
  assert.equal((await post('login', '{"email":"","senha":""}')).status, 400);
  // Sem banco configurado, o login responde na hora com 503 e o código do banco (o app mostra "Banco indisponível").
  const started = Date.now();
  const noDatabase = await post('login', '{"email":"morador@example.test","senha":"Seguro123!"}');
  assert.equal(noDatabase.status, 503);
  assert.equal((await noDatabase.json()).code, 'database_unavailable');
  assert.ok(Date.now() - started < 10000, 'o PHP precisa responder antes do limite de 20 s do app');
  assert.equal((await post('create_ticket', '{"usuario_id":999}')).status, 401);
  assert.equal((await post('cancel_reservation', '{"id":999}')).status, 401);
  assert.equal((await fetch(`${endpoint}?action=send_message`)).status, 405);
  assert.equal((await fetch(`${endpoint}?action[]=ping`)).status, 400);
  const ping = await fetch(`${endpoint}?action=ping`);
  assert.equal(ping.headers.get('cache-control'), 'no-store, private');
  assert.deepEqual(await ping.json(), { app: 'CondoConnect', mobile_api: 1 });
});
