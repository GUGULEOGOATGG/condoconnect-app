import type { Bootstrap, Message, NewReservation, NewTicket, Provider } from './types';
import { localDate, localDateTime, validateReservation, validateTicket } from './domain';

export const demoProviders: Provider[] = [
  { id: 21, nome: 'Carlos Ferreira', especialidades: 'Elétrica · Reparos gerais', bio: 'Instalações, manutenção e pequenos reparos com cuidado.', avaliacao_media: 4.9, total_avaliacoes: 24 },
  { id: 22, nome: 'Mariana Costa', especialidades: 'Hidráulica · Manutenção', bio: 'Soluções para vazamentos e instalações hidráulicas.', avaliacao_media: 4.8, total_avaliacoes: 18 },
  { id: 23, nome: 'Pedro Santos', especialidades: 'Pintura · Reparos gerais', bio: 'Acabamentos e pintura para renovar seu espaço.', avaliacao_media: 4.9, total_avaliacoes: 31 },
];
export function createDemoData(): Bootstrap {
  const today = localDate(new Date());
  const tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1); tomorrow.setHours(15, 0, 0, 0);
  const end = new Date(tomorrow); end.setHours(18);
  return {
    user: { id: 1, nome: 'Ana Martins', email: 'ana@exemplo.test', telefone: '11999990000', tipo_usuario: 'morador', condominio: 'Jardins do Parque', unidade: 'Bloco A · 204', email_verificado: 1 },
    access: { role: 'morador', manager: false, verified: true, condominio_id: 1, condominios: [], pending_verifications: 0 },
    community: { nome: 'Jardins do Parque', descricao: 'Um lugar para viver bem, juntos.', regras: 'Respeite o horário de silêncio após as 22h. Cuide dos espaços comuns e faça suas reservas com antecedência.' },
    tickets: [
      { id: 1, usuario_id: 1, prestador_id: 21, titulo: 'Lâmpada do corredor piscando', descricao: 'A luminária próxima ao elevador do segundo andar está piscando. Precisamos verificar a instalação.', categoria: 'Elétrica', prioridade: 'Média', status: 'andamento', protocolo: 'CP-EXEMPLO-001', local_problema: 'Bloco A, 2º andar', data_servico: today, criado_em: `${today} 09:30:00` },
      { id: 2, usuario_id: 1, titulo: 'Torneira da área comum', descricao: 'A torneira está pingando mesmo fechada.', categoria: 'Hidráulica', prioridade: 'Baixa', status: 'aberto', protocolo: 'CP-EXEMPLO-002', local_problema: 'Jardim, próximo à piscina', data_servico: today, criado_em: `${today} 08:15:00` },
      { id: 3, usuario_id: 1, prestador_id: 23, titulo: 'Pintura do portão', descricao: 'Retoque concluído no portão de acesso.', categoria: 'Pintura', prioridade: 'Baixa', status: 'concluido', protocolo: 'CP-EXEMPLO-003', local_problema: 'Entrada principal', data_servico: today, criado_em: `${today} 07:00:00` },
    ],
    chats: [
      { id: 1, contato_id: 21, contato_nome: 'Carlos Ferreira', contato_tipo: 'prestador', ultimo_resumo: 'Combinado! Vou verificar a luminária.', nao_lidas: 1, updated_at: `${today} 10:25:00` },
      { id: 2, contato_id: 10, contato_nome: 'Beatriz Almeida', contato_tipo: 'sindico', ultimo_resumo: 'Obrigada por avisar, Ana!', nao_lidas: 0, updated_at: `${today} 09:40:00` },
    ],
    contacts: [{ id: 10, nome: 'Beatriz Almeida', tipo_usuario: 'sindico' }, ...demoProviders.map(p => ({ id: p.id, nome: p.nome, tipo_usuario: 'prestador' as const }))],
    notices: [
      { id: 1, titulo: 'Nosso jardim vai ganhar um cuidado especial', conteudo: 'Na próxima quarta-feira, nossa equipe fará a manutenção dos jardins entre 8h e 12h. Durante esse período, evite circular nas áreas sinalizadas. Obrigada pela colaboração de todos!', autor_nome: 'Beatriz Almeida', criado_em: `${today} 08:00:00`, lido_em: null },
      { id: 2, titulo: 'Salão de festas: boas lembranças começam aqui', conteudo: 'O salão está disponível para reservas pelo aplicativo. Consulte as regras, escolha seu horário e aproveite os bons momentos com quem importa.', autor_nome: 'Beatriz Almeida', criado_em: `${today} 07:00:00`, lido_em: null },
    ],
    notifications: [{ id: 1, titulo: 'Seu chamado está em boas mãos', mensagem: 'Carlos assumiu o atendimento da luminária.', lida: 0, criado_em: `${today} 10:20:00`, chamado_id: 1 }, { id: 2, titulo: 'Nova mensagem de Carlos', mensagem: 'Combinado! Vou verificar a luminária.', lida: 0, criado_em: `${today} 10:25:00`, chat_id: 1 }],
    areas: [
      { id: 1, nome: 'Salão de festas', descricao: 'Um espaço para celebrar e reunir quem você gosta.', capacidade: 40, regras: 'Disponível até as 22h. Entregue o salão limpo e organizado.' },
      { id: 2, nome: 'Churrasqueira', descricao: 'O cenário ideal para um encontro ao ar livre.', capacidade: 15, regras: 'Não utilize caixas de som em volume alto. Recolha seus utensílios ao sair.' },
    ],
    reservations: [{ id: 1, area_id: 2, usuario_id: 1, area_nome: 'Churrasqueira', responsavel: 'Ana Martins', inicio: localDateTime(tomorrow), fim: localDateTime(end), status: 'confirmada', observacao: 'Almoço em família' }],
  };
}
export function createDemoMessages(chatId: number): Message[] {
  const today = localDate(new Date());
  return chatId === 1 ? [
    { id: 1, chat_id: 1, remetente_id: 1, remetente_nome: 'Ana Martins', mensagem: 'Oi, Carlos! A lâmpada fica ao lado do elevador do segundo andar.', created_at: `${today} 10:20:00`, lida_por_todos: 1 },
    { id: 2, chat_id: 1, remetente_id: 21, remetente_nome: 'Carlos Ferreira', mensagem: 'Combinado! Vou verificar a luminária.', created_at: `${today} 10:25:00`, lida_por_todos: 0 },
  ] : [];
}
export function addDemoTicket(data: Bootstrap, input: NewTicket): Bootstrap {
  const error = validateTicket(input); if (error) throw new Error(error);
  const id = Math.max(0, ...data.tickets.map(t => Number(t.id))) + 1;
  return { ...data, tickets: [{ ...input, id, usuario_id: data.user.id, protocolo: `CP-EXEMPLO-${String(id).padStart(3, '0')}`, status: 'aberto', criado_em: new Date().toISOString() }, ...data.tickets] };
}
export function addDemoReservation(data: Bootstrap, input: NewReservation): Bootstrap {
  const error = validateReservation(input); if (error) throw new Error(error);
  const area = data.areas.find(a => Number(a.id) === Number(input.area_id));
  if (!area) throw new Error('Espaço indisponível.');
  if (data.reservations.some(r => r.status === 'confirmada' && Number(r.area_id) === Number(input.area_id) &&
      new Date(r.inicio) < new Date(input.fim) && new Date(r.fim) > new Date(input.inicio))) throw new Error('Este horário já está reservado. Escolha outro período.');
  const id = Math.max(0, ...data.reservations.map(r => Number(r.id))) + 1;
  return { ...data, reservations: [{ ...input, id, usuario_id: data.user.id, area_nome: area.nome, responsavel: data.user.nome, status: 'confirmada' }, ...data.reservations] };
}
