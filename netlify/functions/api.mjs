import { getStore } from '@netlify/blobs';
import { randomBytes, scryptSync, timingSafeEqual, createHash } from 'node:crypto';

export const createHandler = (storeFactory = getStore) => {
const users = () => storeFactory({ name: 'users', consistency: 'strong' });
const tickets = () => storeFactory({ name: 'tickets', consistency: 'strong' });
const sessions = () => storeFactory({ name: 'sessions', consistency: 'strong' });
const tokenHash = token => createHash('sha256').update(token).digest('hex');
const issueToken = async user => { const token = randomBytes(32).toString('hex'); await sessions().set(tokenHash(token), JSON.stringify({ email: user.email, expiresAt: Date.now() + 7 * 86400000 })); return token; };
const json = (status, body) => ({ statusCode: status, headers: { 'Content-Type': 'application/json; charset=utf-8' }, body: JSON.stringify(body) });
const id = () => randomBytes(16).toString('hex');
const passwordHash = password => { const salt = randomBytes(16).toString('hex'); return `${salt}:${scryptSync(password, salt, 64).toString('hex')}`; };
const passwordValid = (password, stored) => { if (typeof stored !== 'string' || !/^[a-f0-9]{32}:[a-f0-9]{128}$/.test(stored)) return false; const [salt, expected] = stored.split(':'); const actual = scryptSync(password, salt, 64).toString('hex'); return timingSafeEqual(Buffer.from(expected, 'hex'), Buffer.from(actual, 'hex')); };
const publicUser = ({ password, cpf, ...user }) => user;
const readBody = async event => { try { return JSON.parse(event.body || '{}'); } catch { return null; } };

return async (event) => {
  if (event.httpMethod === 'OPTIONS') return { statusCode: 204, headers: { Allow: 'GET, POST, OPTIONS' }, body: '' };
  const action = event.path.split('/').pop().replace(/\.php$/, '');
  const body = await readBody(event);
  if (!body || typeof body !== 'object' || Array.isArray(body)) return json(400, { error: 'JSON inválido.' });

  if (Object.values(body).some(value => typeof value !== 'string')) return json(400, { error: 'Campos inválidos.' });

  if (action === 'register' && event.httpMethod === 'POST') {
    const { nome, email, senha, telefone = '', cpf = '', tipoUsuario = 'morador', condominio = '' } = body;
    if (!nome || !email || !senha || !condominio) return json(400, { error: 'Preencha os campos obrigatórios.' });
    if (!['morador', 'prestador'].includes(tipoUsuario)) return json(400, { error: 'Perfil inválido.' });
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return json(400, { error: 'E-mail inválido.' });
    if (senha.length < 8) return json(400, { error: 'A senha deve ter pelo menos 8 caracteres.' });
    const key = email.trim().toLowerCase();
    if (await users().get(key, { type: 'json' })) return json(409, { error: 'Este e-mail já está cadastrado.' });
    const user = { id: id(), nome: nome.trim(), email: key, telefone: telefone.trim(), cpf: cpf.trim(), tipoUsuario, condominio: condominio.trim(), password: passwordHash(senha), criadoEm: new Date().toISOString() };
    await users().set(key, JSON.stringify(user));
    return json(201, { user: publicUser(user), token: await issueToken(user) });
  }

  if (action === 'login' && event.httpMethod === 'POST') {
    const user = await users().get(String(body.email || '').trim().toLowerCase(), { type: 'json' });
    if (!user || !passwordValid(String(body.senha || ''), user.password)) return json(401, { error: 'E-mail ou senha inválidos.' });
    return json(200, { user: publicUser(user), token: await issueToken(user) });
  }

  const token = (event.headers.authorization || event.headers.Authorization || '').replace(/^Bearer\s+/i, '');
  if (!/^[a-f0-9]{64}$/.test(token)) return json(401, { error: 'Sessão inválida. Entre novamente.' });
  const session = await sessions().get(tokenHash(token), { type: 'json' });
  const user = session && session.expiresAt > Date.now() ? await users().get(session.email, { type: 'json' }) : null;
  if (!user) return json(401, { error: 'Sessão inválida. Entre novamente.' });
  if (action === 'me' && event.httpMethod === 'GET') return json(200, { user: publicUser(user) });

  if (action === 'tickets' && event.httpMethod === 'GET') {
    const result = await tickets().list(); const data = [];
    for (const item of result.blobs) { const ticket = await tickets().get(item.key, { type: 'json' }); if (ticket?.usuarioId === user.id) data.push(ticket); }
    return json(200, { tickets: data.sort((a, b) => b.criadoEm.localeCompare(a.criadoEm)) });
  }
  if (action === 'tickets' && event.httpMethod === 'POST') {
    const { categoria, titulo, descricao, localProblema, prioridade, dataServico } = body;
    if (![categoria, titulo, descricao, localProblema, prioridade, dataServico].every(Boolean)) return json(400, { error: 'Preencha todos os campos.' });
    if (user.tipoUsuario !== 'morador') return json(403, { error: 'Somente moradores podem abrir chamados.' });
    if (!['Elétrica','Hidráulica','Pintura','Limpeza','Elevadores','Jardinagem','Segurança','Reparos gerais'].includes(categoria) || !['Baixa','Média','Alta','Urgente'].includes(prioridade) || !/^\d{4}-\d{2}-\d{2}$/.test(dataServico) || Number.isNaN(Date.parse(dataServico)) || new Date(dataServico).toISOString().slice(0,10) !== dataServico) return json(400, { error: 'Dados do chamado inválidos.' });
    const ticket = { id: id(), protocolo: `CP-${Date.now().toString().slice(-8)}`, usuarioId: user.id, categoria, titulo, descricao, localProblema, prioridade, dataServico, status: 'aberto', criadoEm: new Date().toISOString() };
    await tickets().set(ticket.id, JSON.stringify(ticket));
    return json(201, { ticket });
  }
  return json(404, { error: 'Rota não encontrada.' });
};

};
export default createHandler();
