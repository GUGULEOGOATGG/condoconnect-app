import type { Access, Bootstrap, NewReservation, NewTicket, NoticeInput, NoticeStatus, Ticket, TicketStatus } from './types';

export const categories = ['Elétrica', 'Hidráulica', 'Pintura', 'Limpeza', 'Elevadores', 'Jardinagem', 'Segurança', 'Reparos gerais'];
export const priorities = ['Baixa', 'Média', 'Alta', 'Urgente'];
export const statusLabels: Record<TicketStatus, string> = { aberto: 'Aberto', andamento: 'Em andamento', concluido: 'Concluído', cancelado: 'Cancelado' };
export const roleLabels = { morador: 'Morador', prestador: 'Prestador', sindico: 'Síndico', admin: 'Administrador' };
export const roleSummaries = {
  morador: 'Abre chamados, faz reservas e acompanha os comunicados.', prestador: 'Aceita chamados e conduz os atendimentos.',
  sindico: 'Gerencia comunicados, reservas, chamados e as informações do condomínio.', admin: 'Acompanha todos os condomínios, usuários e verificações.',
};
export const noticeStatusLabels: Record<NoticeStatus, string> = { publicado: 'Publicado', rascunho: 'Rascunho', arquivado: 'Arquivado' };
/** Servidores com a API anterior não enviam `access`: nesse caso as telas de gestão ficam ocultas. */
export function accessOf(data: Pick<Bootstrap, 'user' | 'access'>): Access {
  if (data.access) return { ...data.access, manager: Boolean(data.access.manager), condominios: data.access.condominios || [], pending_verifications: Number(data.access.pending_verifications) || 0, condominio_id: Number(data.access.condominio_id) || 0 };
  return { role: data.user.tipo_usuario, manager: false, verified: Number(data.user.verificado) === 1, condominio_id: 0, condominios: [], pending_verifications: 0, legacy: true };
}
export function validateNotice(input: NoticeInput): string | null {
  if (!input.titulo.trim() || !input.conteudo.trim()) return 'Informe o título e o conteúdo.';
  if (input.titulo.trim().length > 190 || input.conteudo.trim().length > 15000) return 'Revise o tamanho do título e do conteúdo.';
  if (!(input.status in noticeStatusLabels)) return 'Selecione uma situação válida.';
  return null;
}
export function localDate(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}
export function localDateTime(date: Date): string {
  return `${localDate(date)}T${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}
export function displayDate(value: string, time = false): string {
  const date = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return 'Data indisponível';
  return date.toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', ...(time ? { hour: '2-digit', minute: '2-digit' } : {}) });
}
export function ticketStats(tickets: Ticket[]) {
  return { total: tickets.length, open: tickets.filter(t => t.status === 'aberto').length,
    active: tickets.filter(t => t.status === 'andamento').length, done: tickets.filter(t => t.status === 'concluido').length };
}
export function validateTicket(input: NewTicket): string | null {
  if (!input.titulo.trim() || !input.descricao.trim() || !input.local_problema.trim()) return 'Preencha o título, a descrição e o local.';
  if (input.titulo.trim().length > 190 || input.descricao.trim().length > 10000 || input.local_problema.trim().length > 255) return 'Revise o tamanho dos textos do chamado.';
  if (!categories.includes(input.categoria) || !priorities.includes(input.prioridade)) return 'Selecione categoria e prioridade válidas.';
  const date = new Date(`${input.data_servico}T12:00:00`);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(input.data_servico) || Number.isNaN(date.getTime()) || localDate(date) !== input.data_servico) return 'Selecione uma data válida.';
  return null;
}
export function validateReservation(input: NewReservation, now = new Date()): string | null {
  const start = new Date(input.inicio), end = new Date(input.fim);
  if (!input.area_id || Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return 'Selecione um espaço e um período válido.';
  if (start <= now || end <= start || end.getTime() - start.getTime() > 86400000) return 'Escolha um período futuro de até 24 horas, com término após o início.';
  if (input.observacao.length > 500) return 'A observação pode ter até 500 caracteres.';
  return null;
}
/** HTTP só é aceito em um APK de teste gerado com EXPO_PUBLIC_ALLOW_HTTP=1 (veja mobile/.env). */
export const allowsInsecureHttp = process.env.EXPO_PUBLIC_ALLOW_HTTP === '1';
/** Únicos servidores liberados em HTTP. Igual a android/app/src/release/res/xml/network_security_config.xml. */
export const insecureHttpHosts = ['2.24.65.149'];
export function isInsecureUrl(url: string): boolean { return /^http:\/\//i.test(url.trim()); }
export function normalizeServerUrl(input: string, allowHttp = allowsInsecureHttp): string {
  let url: URL;
  try { url = new URL(input.trim()); } catch { throw new Error('Informe o endereço HTTPS do seu site.'); }
  if (allowHttp && url.protocol === 'http:' && !insecureHttpHosts.includes(url.hostname)) {
    throw new Error(`Neste APK de teste, HTTP só é liberado para ${insecureHttpHosts.join(', ')}. Para outro servidor, use HTTPS.`);
  }
  if ((url.protocol !== 'https:' && !(allowHttp && url.protocol === 'http:')) || !url.hostname || url.username || url.password || url.search || url.hash) {
    throw new Error('Use um endereço HTTPS, sem senha, parâmetros ou fragmentos.');
  }
  return url.toString().replace(/\/+$/, '');
}
