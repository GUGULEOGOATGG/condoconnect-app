export type Role = 'morador' | 'prestador' | 'sindico' | 'admin';
export type User = {
  id: number; nome: string; email: string; telefone: string; tipo_usuario: Role;
  condominio: string; unidade: string; email_verificado: number; verificado?: number; certificado_status?: string;
};
/** Nível de acesso informado pelo servidor. O app só o usa para escolher telas: cada operação é conferida no PHP. */
export type Access = { role: Role; manager: boolean; verified: boolean; condominio_id: number; condominios: { id: number; nome: string }[]; pending_verifications: number; legacy?: boolean };
export type TicketStatus = 'aberto' | 'andamento' | 'concluido' | 'cancelado';
export type Ticket = {
  id: number; usuario_id: number; prestador_id?: number | null; titulo: string;
  descricao: string; categoria: string; prioridade: string; status: TicketStatus;
  protocolo: string; local_problema: string; data_servico: string; criado_em: string;
};
export type NewTicket = Pick<Ticket, 'titulo' | 'descricao' | 'categoria' | 'prioridade' | 'local_problema' | 'data_servico'>;
export type Chat = { id: number; contato_id: number; contato_nome: string; contato_tipo: Role; ultimo_resumo: string; nao_lidas: number; updated_at: string };
export type Contact = { id: number; nome: string; tipo_usuario: Role };
export type Message = { id: number; chat_id: number; remetente_id: number; remetente_nome: string; mensagem: string; created_at: string; lida_por_todos: number };
export type NoticeStatus = 'publicado' | 'rascunho' | 'arquivado';
export type Notice = { id: number; titulo: string; conteudo: string; autor_nome: string; criado_em: string; lido_em: string | null; status?: NoticeStatus };
export type NoticeInput = { id?: number; titulo: string; conteudo: string; status: NoticeStatus };
export type CommunityInput = { descricao: string; regras: string; contato: string };
export type PendingSyndic = { id: number; nome: string; email: string; telefone: string; condominio: string; unidade: string; certificado_status: string; criado_em: string; tem_certificado: number };
export type AdminUser = { id: number; nome: string; email: string; tipo_usuario: Role; status: string; certificado_status: string; verificado: number; email_verificado: number; criado_em: string };
export type AdminOverview = {
  totals: Record<'total_usuarios' | 'moradores' | 'profissionais' | 'sindicos' | 'condominios' | 'total_chamados' | 'chamados_ativos' | 'chamados_abertos' | 'chamados_concluidos' | 'reservas' | 'comunicados', number>;
  by_condominio: { id: number; nome: string; usuarios: number; chamados: number }[];
  activity: { id: number; chamado_id: number; observacao: string | null; status_novo: string; usuario_nome: string | null; titulo: string; criado_em: string }[];
  pending: PendingSyndic[];
};
export type Notification = { id: number; titulo: string; mensagem: string; lida: number; criado_em: string; chamado_id?: number | null; chat_id?: number | null; tipo?: string };
export type Area = { id: number; nome: string; descricao: string; capacidade: number; regras: string };
export type Reservation = { id: number; area_id: number; usuario_id: number; area_nome: string; responsavel: string; inicio: string; fim: string; status: string; observacao: string };
export type NewReservation = Pick<Reservation, 'area_id' | 'inicio' | 'fim' | 'observacao'>;
export type Provider = { id: number; nome: string; especialidades: string; bio: string; avaliacao_media: number; total_avaliacoes: number };
export type Bootstrap = {
  user: User; tickets: Ticket[]; chats: Chat[]; contacts: Contact[]; notices: Notice[];
  notifications: Notification[]; areas: Area[]; reservations: Reservation[];
  community: { nome: string; descricao?: string; regras?: string; contato?: string; endereco?: string; cidade?: string; estado?: string } | null;
  access?: Access;
};
export type Registration = { nome: string; email: string; cpf: string; telefone: string; senha: string; confirmar_senha: string; tipo_usuario: 'morador' | 'prestador'; condominio: string; unidade: string };
export type LoginResult = { requires_code?: boolean; user?: User; token?: string; verification_sent?: boolean };
