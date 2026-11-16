import test from 'node:test';
import assert from 'node:assert/strict';
import { accessOf, localDate, localDateTime, normalizeServerUrl, ticketStats, validateNotice, validateReservation, validateTicket } from '../src/domain';
import { addDemoReservation, addDemoTicket, createDemoData } from '../src/demo';

test('server configuration requires HTTPS and preserves installation subpaths', () => {
  assert.equal(normalizeServerUrl(' https://condominio.example/sisc-test/ '), 'https://condominio.example/sisc-test');
  for (const url of ['http://condominio.example', 'ftp://example.test', 'https://user:pass@example.test', 'https://example.test?token=abc', 'https://example.test/#fragment', 'invalid']) assert.throws(() => normalizeServerUrl(url));
  // HTTP só entra em um APK de teste gerado com a opção explícita.
  assert.equal(normalizeServerUrl('http://2.24.65.149:8080/sisc-test/', true), 'http://2.24.65.149:8080/sisc-test');
  assert.throws(() => normalizeServerUrl('http://2.24.65.149:8080/sisc-test', false));
  for (const url of ['ftp://example.test', 'http://user:pass@example.test', 'http://example.test?x=1']) assert.throws(() => normalizeServerUrl(url, true));
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
test('demo ticket creation updates totals without modifying the source state', () => {
  const before = createDemoData(), result = addDemoTicket(before, ticket);
  assert.equal(before.tickets.length, 3); assert.equal(result.tickets.length, 4);
  assert.equal(result.tickets[0].status, 'aberto'); assert.notEqual(result.tickets[0].id, before.tickets[0].id);
  assert.deepEqual(ticketStats(result.tickets), { total: 4, open: 2, active: 1, done: 1 });
  assert.throws(() => addDemoTicket(before, { ...ticket, titulo: '' }));
});
test('reservation validation enforces future start, ordering and the 24-hour limit', () => {
  const now = new Date('2026-11-09T10:00:00');
  const valid = { area_id: 1, inicio: '2026-11-10T10:00', fim: '2026-11-10T12:00', observacao: '' };
  assert.equal(validateReservation(valid, now), null);
  for (const input of [{ ...valid, inicio: '2026-11-08T10:00' }, { ...valid, fim: valid.inicio }, { ...valid, fim: '2026-11-12T12:00' }, { ...valid, area_id: 0 }, { ...valid, observacao: 'x'.repeat(501) }]) assert.ok(validateReservation(input, now));
});
test('demo reservations reject overlap, allow adjacent periods and preserve the source', () => {
  const data = createDemoData(), existing = data.reservations[0];
  assert.throws(() => addDemoReservation(data, { area_id: existing.area_id, inicio: existing.inicio, fim: existing.fim, observacao: '' }), /reservado/);
  const start = new Date(existing.fim), end = new Date(start.getTime() + 3600000);
  const result = addDemoReservation(data, { area_id: existing.area_id, inicio: localDateTime(start), fim: localDateTime(end), observacao: 'Encontro' });
  assert.equal(data.reservations.length, 1); assert.equal(result.reservations.length, 2); assert.equal(result.reservations[0].responsavel, data.user.nome);
});
test('access level comes from the server and management stays hidden when the server does not report it', () => {
  const user = createDemoData().user;
  assert.deepEqual(accessOf({ user: { ...user, tipo_usuario: 'sindico', verificado: 1 } }), { role: 'sindico', manager: false, verified: true, condominio_id: 0, condominios: [], pending_verifications: 0, legacy: true });
  const access = accessOf({ user, access: { role: 'admin', manager: true, verified: true, condominio_id: '7' as unknown as number, condominios: [{ id: 7, nome: 'A' }], pending_verifications: '2' as unknown as number } });
  assert.equal(access.manager, true); assert.equal(access.condominio_id, 7); assert.equal(access.pending_verifications, 2); assert.equal(access.legacy, undefined);
  assert.equal(accessOf(createDemoData()).manager, false);
});
test('notice validation mirrors the server limits', () => {
  assert.equal(validateNotice({ titulo: 'Assembleia', conteudo: 'Dia 20, às 19h.', status: 'publicado' }), null);
  assert.ok(validateNotice({ titulo: ' ', conteudo: 'x', status: 'publicado' }));
  assert.ok(validateNotice({ titulo: 'x'.repeat(191), conteudo: 'x', status: 'rascunho' }));
  assert.ok(validateNotice({ titulo: 'x', conteudo: 'x', status: 'excluido' as 'publicado' }));
});
