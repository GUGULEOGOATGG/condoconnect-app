import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { accessOf, insecureHttpHosts, isInsecureUrl, localDate, localDateTime, normalizeServerUrl, ticketStats, validateNotice, validateReservation, validateTicket } from '../src/domain';
import type { User } from '../src/types';

test('server configuration requires HTTPS and preserves installation subpaths', () => {
  assert.equal(normalizeServerUrl(' https://condominio.example/sisc-test/ '), 'https://condominio.example/sisc-test');
  for (const url of ['http://condominio.example', 'ftp://example.test', 'https://user:pass@example.test', 'https://example.test?token=abc', 'https://example.test/#fragment', 'invalid']) assert.throws(() => normalizeServerUrl(url));
  // HTTP só entra em um APK de teste gerado com a opção explícita.
  assert.equal(normalizeServerUrl('http://2.24.65.149:8080/sisc-test/', true), 'http://2.24.65.149:8080/sisc-test');
  assert.throws(() => normalizeServerUrl('http://2.24.65.149:8080/sisc-test', false));
  for (const url of ['ftp://example.test', 'http://user:pass@example.test', 'http://example.test?x=1']) assert.throws(() => normalizeServerUrl(url, true));
  // Mesmo no APK de teste, HTTP só vale para o servidor liberado no network_security_config.xml.
  for (const url of ['http://example.test', 'http://2.24.65.150:8080/sisc-test', 'http://192.168.0.10/sisc']) assert.throws(() => normalizeServerUrl(url, true), /2\.24\.65\.149/);
  assert.equal(isInsecureUrl('http://2.24.65.149:8080/sisc-test'), true); assert.equal(isInsecureUrl('https://example.test'), false);
});
test('local dates preserve the date/time shown by the native picker', () => {
  const date = new Date(2026, 10, 9, 15, 7);
  assert.equal(localDate(date), '2026-11-09'); assert.equal(localDateTime(date), '2026-11-09T15:07');
});
const ticket = { titulo: 'Lâmpada do corredor', descricao: 'Está piscando.', local_problema: 'Bloco A', categoria: 'Elétrica', prioridade: 'Média', data_servico: '2026-11-09' };
test('ticket validation rejects empty fields, unsupported categories and invalid calendar dates', () => {
  assert.equal(validateTicket(ticket), null);
  for (const input of [{ ...ticket, titulo: ' ' }, { ...ticket, descricao: '' }, { ...ticket, categoria: 'Desconhecida' }, { ...ticket, prioridade: 'Qualquer' }, { ...ticket, data_servico: '2026-02-31' }, { ...ticket, titulo: 'a'.repeat(191) }]) assert.ok(validateTicket(input));
});
test('ticket stats count each status', () => {
  const base = { ...ticket, id: 1, usuario_id: 1, protocolo: 'CP-1', criado_em: '2026-11-09 10:00:00' };
  const list = [{ ...base, status: 'aberto' as const }, { ...base, id: 2, status: 'aberto' as const }, { ...base, id: 3, status: 'andamento' as const }, { ...base, id: 4, status: 'concluido' as const }];
  assert.deepEqual(ticketStats(list), { total: 4, open: 2, active: 1, done: 1 });
});
test('reservation validation enforces future start, ordering and the 24-hour limit', () => {
  const now = new Date('2026-11-09T10:00:00');
  const valid = { area_id: 1, inicio: '2026-11-10T10:00', fim: '2026-11-10T12:00', observacao: '' };
  assert.equal(validateReservation(valid, now), null);
  for (const input of [{ ...valid, inicio: '2026-11-08T10:00' }, { ...valid, fim: valid.inicio }, { ...valid, fim: '2026-11-12T12:00' }, { ...valid, area_id: 0 }, { ...valid, observacao: 'x'.repeat(501) }]) assert.ok(validateReservation(input, now));
});
test('access level comes from the server and management stays hidden when the server does not report it', () => {
  const user: User = { id: 1, nome: 'Ana', email: 'ana@exemplo.test', telefone: '11999990000', tipo_usuario: 'morador', condominio: 'Jardins', unidade: 'A 204', email_verificado: 1 };
  assert.deepEqual(accessOf({ user: { ...user, tipo_usuario: 'sindico', verificado: 1 } }), { role: 'sindico', manager: false, verified: true, condominio_id: 0, condominios: [], pending_verifications: 0, legacy: true });
  const access = accessOf({ user, access: { role: 'admin', manager: true, verified: true, condominio_id: '7' as unknown as number, condominios: [{ id: 7, nome: 'A' }], pending_verifications: '2' as unknown as number } });
  assert.equal(access.manager, true); assert.equal(access.condominio_id, 7); assert.equal(access.pending_verifications, 2); assert.equal(access.legacy, undefined);
  assert.equal(accessOf({ user, access: { role: 'morador', manager: false, verified: true, condominio_id: 1, condominios: [], pending_verifications: 0 } }).manager, false);
});
test('notice validation mirrors the server limits', () => {
  assert.equal(validateNotice({ titulo: 'Assembleia', conteudo: 'Dia 20, às 19h.', status: 'publicado' }), null);
  assert.ok(validateNotice({ titulo: ' ', conteudo: 'x', status: 'publicado' }));
  assert.ok(validateNotice({ titulo: 'x'.repeat(191), conteudo: 'x', status: 'rascunho' }));
  assert.ok(validateNotice({ titulo: 'x', conteudo: 'x', status: 'excluido' as 'publicado' }));
});
test('HTTP hosts allowed in JavaScript match the Android network security config and the APK address', () => {
  const config = new URL('../android/app/src/release/res/xml/network_security_config.xml', import.meta.url);
  // Depois da volta ao HTTPS (README), a pasta release/ é apagada e nenhum host pode ficar liberado em HTTP.
  if (!existsSync(config)) { assert.deepEqual(insecureHttpHosts, []); return; }
  const xml = readFileSync(config, 'utf8');
  const domains = [...xml.matchAll(/<domain[^>]*>([^<]+)<\/domain>/g)].map(match => match[1].trim());
  assert.deepEqual(domains, insecureHttpHosts);
  assert.match(xml, /<domain-config cleartextTrafficPermitted="true">/);
  assert.doesNotMatch(xml, /<base-config[^>]*cleartextTrafficPermitted="true"/);
  const env = readFileSync(new URL('../.env', import.meta.url), 'utf8');
  const base = /^EXPO_PUBLIC_API_BASE_URL=(.*)$/m.exec(env)?.[1].trim() || '';
  assert.equal(new URL(base).hostname, insecureHttpHosts[0]);
  const workflow = readFileSync(new URL('../../.github/workflows/android-apk.yml', import.meta.url), 'utf8');
  assert.match(workflow, new RegExp(`EXPO_PUBLIC_API_BASE_URL: ${base.replace(/[.]/g, '\\.')}\\s`));
});
