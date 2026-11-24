import React, { createContext, useContext, useEffect, useRef, useState } from 'react';
import * as SecureStore from 'expo-secure-store';
import { ApiClient, ApiError, testConnection } from './api';
import type { ConnectionReport } from './api';
import type { AdminOverview, AdminUser, Bootstrap, CommunityInput, LoginResult, Message, NewReservation, NewTicket, NoticeInput, Provider, Registration, User } from './types';
import { normalizeServerUrl } from './domain';

type AppContextValue = {
  data: Bootstrap | null; mode: 'live' | null; serverUrl: string; starting: boolean;
  refreshing: boolean; error: string; clearError: () => void;
  configure: (url: string) => Promise<void>; checkConnection: (url?: string) => Promise<ConnectionReport>; login: (email: string, password: string, code?: string) => Promise<boolean>;
  register: (values: Registration) => Promise<void>; forgotPassword: (email: string) => Promise<string>;
  logout: () => Promise<void>; refresh: () => Promise<void>;
  createTicket: (input: NewTicket) => Promise<void>; changeTicket: (id: number, status: string) => Promise<void>;
  acceptTicket: (id: number) => Promise<number>; providers: (query: string) => Promise<Provider[]>;
  openChat: (contact: number) => Promise<number>; messages: (id: number) => Promise<Message[]>;
  sendMessage: (id: number, message: string) => Promise<void>; reserve: (input: NewReservation) => Promise<void>;
  cancelReservation: (id: number) => Promise<void>; readNotice: (id: number) => Promise<void>;
  readNotification: (id: number) => Promise<void>; saveProfile: (nome: string, telefone: string) => Promise<void>;
  resendVerification: () => Promise<string>;
  // Gestão (síndico responsável e administração) e administração da plataforma.
  selectCondominio: (id: number) => Promise<void>; saveNotice: (input: NoticeInput) => Promise<void>; deleteNotice: (id: number) => Promise<void>;
  saveCommunity: (input: CommunityInput) => Promise<void>; joinTicketChat: (ticket: number) => Promise<number>;
  adminOverview: () => Promise<AdminOverview>; adminUsers: (query: string) => Promise<AdminUser[]>; verifySyndic: (user: number, status: 'aprovado' | 'rejeitado') => Promise<void>;
};
const Context = createContext<AppContextValue | null>(null);
const SESSION_KEY = 'condoconnect.session.v1', SERVER_KEY = 'condoconnect.server.v1';
export function AppProvider({ children }: { children: React.ReactNode }) {
  const [data, setData] = useState<Bootstrap | null>(null);
  const [mode, setMode] = useState<'live' | null>(null);
  const [serverUrl, setServerUrl] = useState('');
  const [starting, setStarting] = useState(true), [refreshing, setRefreshing] = useState(false), [error, setError] = useState('');
  const client = useRef<ApiClient | null>(null), generation = useRef(0);
  const refreshInFlight = useRef<Promise<void> | null>(null);
  // Condomínio escolhido pela administração; 0 usa o da própria conta.
  const condominio = useRef(0);
  const bootstrap = (connection: ApiClient) => connection.request<Bootstrap>('bootstrap', undefined, condominio.current ? { condominio_id: String(condominio.current) } : undefined);
  const liveOnly = () => { if (mode !== 'live') throw new Error('Disponível apenas com uma conta real conectada ao servidor.'); };

  useEffect(() => {
    (async () => {
      try {
        const configured = await SecureStore.getItemAsync(SERVER_KEY);
        const defaultUrl = process.env.EXPO_PUBLIC_API_BASE_URL || '';
        setServerUrl(configured || defaultUrl);
        const stored = await SecureStore.getItemAsync(SESSION_KEY);
        if (stored) {
          const session = JSON.parse(stored);
          if (typeof session.server !== 'string' || !/^[a-f0-9]{64}$/.test(session.token)) throw new Error('Sessão local inválida.');
          const restored = new ApiClient(session.server, session.token);
          const payload = await bootstrap(restored);
          client.current = restored; setServerUrl(session.server); setData(payload); setMode('live');
        }
      } catch (failure) {
        if (failure instanceof ApiError && failure.status !== 401) setError(failure.message);
        else await SecureStore.deleteItemAsync(SESSION_KEY).catch(() => {});
      } finally { setStarting(false); }
    })();
  }, []);
  const api = () => {
    if (!client.current) throw new Error('Configure o servidor e entre na sua conta.');
    return client.current;
  };
  const publicApi = () => {
    if (!serverUrl) throw new Error('Informe o endereço do servidor em “Conectar ao meu condomínio”.');
    return new ApiClient(serverUrl);
  };
  const establish = async (result: LoginResult, connection: ApiClient, attempt: number) => {
    if (!result.token || !result.user) throw new Error('Resposta de autenticação inválida.');
    connection.token = result.token; condominio.current = 0;
    const payload = await bootstrap(connection);
    if (generation.current !== attempt) return;
    await SecureStore.setItemAsync(SESSION_KEY, JSON.stringify({ server: connection.baseUrl, token: result.token }));
    generation.current++; client.current = connection;
    setData(payload); setMode('live'); setError('');
  };
  const refresh = async () => {
    if (mode !== 'live') return;
    if (refreshInFlight.current) return refreshInFlight.current;
    const currentGeneration = generation.current;
    const job = (async () => {
      setRefreshing(true);
      try {
        const payload = await bootstrap(api());
        if (generation.current === currentGeneration) { setData(payload); setError(''); }
      } catch (failure) {
        if (generation.current !== currentGeneration) return;
        if (failure instanceof ApiError && failure.status === 401) {
          await SecureStore.deleteItemAsync(SESSION_KEY);
          generation.current++; client.current = null; setData(null); setMode(null);
        }
        setError((failure as Error).message);
      } finally { setRefreshing(false); refreshInFlight.current = null; }
    })();
    refreshInFlight.current = job;
    return job;
  };
  const mutate = async <T,>(action: string, input: Record<string, unknown>): Promise<T> => {
    const result = await api().request<T>(action, input);
    await refresh();
    return result;
  };
  const value: AppContextValue = {
    data, mode, serverUrl, starting, refreshing, error, clearError: () => setError(''), refresh,
    configure: async input => {
      const url = normalizeServerUrl(input), connection = new ApiClient(url);
      const result = await connection.request<{ mobile_api: number }>('ping');
      if (result.mobile_api !== 1) throw new Error('Esta instalação ainda não tem a API do aplicativo.');
      await SecureStore.setItemAsync(SERVER_KEY, url); setServerUrl(url);
    },
    checkConnection: async url => {
      const target = (url ?? serverUrl).trim();
      if (!target) throw new Error('Informe o endereço do servidor em “Conectar ao meu condomínio”.');
      return testConnection(target);
    },
    login: async (email, senha, codigo = '') => {
      const attempt = generation.current;
      const connection = publicApi();
      const result = await connection.request<LoginResult>('login', { email, senha, codigo });
      if (result.requires_code) return false;
      await establish(result, connection, attempt); return true;
    },
    register: async values => {
      const attempt = generation.current;
      const connection = publicApi();
      const result = await connection.request<LoginResult>('register', { ...values });
      await establish(result, connection, attempt);
      if (!result.verification_sent) setError('Sua conta foi criada, mas o e-mail de confirmação não foi enviado. Toque em reenviar confirmação.');
    },
    forgotPassword: async email => (await publicApi().request<{ message: string }>('forgot_password', { email })).message,
    logout: async () => {
      const connection = client.current;
      // Always clear this device even if a connection is unavailable.
      generation.current++; client.current = null; condominio.current = 0; setData(null); setMode(null); setError('');
      await SecureStore.deleteItemAsync(SESSION_KEY);
      if (connection) await connection.request('logout', {}).catch(() => {});
    },
    createTicket: async input => {
      await mutate('create_ticket', { ...input });
    },
    changeTicket: async (id, status) => {
      await mutate('ticket_status', { id, status });
    },
    acceptTicket: async id => (await mutate<{ chat_id: number }>('accept_ticket', { id })).chat_id,
    providers: async q => (await api().request<{ providers: Provider[] }>('providers', undefined, { q })).providers,
    openChat: async contact => (await mutate<{ chat_id: number }>('open_chat', { usuario_id: contact })).chat_id,
    messages: async id => (await api().request<{ messages: Message[] }>('messages', undefined, { chat_id: String(id) })).messages,
    sendMessage: async (id, mensagem) => {
      if (!mensagem.trim() || mensagem.length > 4000) throw new Error('Escreva uma mensagem de até 4.000 caracteres.');
      await api().request('send_message', { chat_id: id, mensagem });
    },
    reserve: async input => {
      await mutate('reserve', { ...input });
    },
    cancelReservation: async id => {
      await mutate('cancel_reservation', { id });
    },
    readNotice: async id => {
      await mutate('read_notice', { id });
    },
    readNotification: async id => {
      await mutate('read_notification', { id });
    },
    saveProfile: async (nome, telefone) => {
      if (!nome.trim() || !/^\d{10,11}$/.test(telefone.replace(/\D/g, ''))) throw new Error('Informe seu nome e um telefone com DDD.');
      await mutate('profile', { nome: nome.trim(), telefone });
    },
    resendVerification: async () => (await api().request<{ message: string }>('resend_verification', {})).message,
    selectCondominio: async id => {
      liveOnly(); const previous = condominio.current; condominio.current = id;
      try { const payload = await bootstrap(api()); setData(payload); setError(''); }
      catch (failure) { condominio.current = previous; throw failure; }
    },
    saveNotice: async input => { liveOnly(); await mutate('save_notice', { id: input.id || 0, titulo: input.titulo.trim(), conteudo: input.conteudo.trim(), status: input.status, condominio_id: condominio.current }); },
    deleteNotice: async id => { liveOnly(); await mutate('delete_notice', { id }); },
    saveCommunity: async input => { liveOnly(); await mutate('save_community', { descricao: input.descricao.trim(), regras: input.regras.trim(), contato: input.contato.trim(), condominio_id: condominio.current }); },
    joinTicketChat: async ticket => { liveOnly(); return (await mutate<{ chat_id: number }>('join_ticket_chat', { id: ticket })).chat_id; },
    adminOverview: async () => { liveOnly(); return api().request<AdminOverview>('admin_overview'); },
    adminUsers: async query => { liveOnly(); return (await api().request<{ users: AdminUser[] }>('admin_users', undefined, { q: query })).users; },
    verifySyndic: async (user, status) => { liveOnly(); await mutate('verify_syndic', { user_id: user, status }); },
  };
  return <Context.Provider value={value}>{children}</Context.Provider>;
}
export function useApp() {
  const context = useContext(Context); if (!context) throw new Error('AppProvider ausente.'); return context;
}
