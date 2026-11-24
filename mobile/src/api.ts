import { normalizeServerUrl } from './domain';

/**
 * Por que uma chamada falhou, para a mensagem dizer o que fazer:
 * - unreachable: o celular não chegou ao servidor (sem internet, endereço errado, porta bloqueada, tempo esgotado);
 * - server: o servidor respondeu, mas com erro ou sem a API do app (página 404, erro 500, resposta que não é JSON);
 * - database: o servidor respondeu, mas o banco MySQL está indisponível ou desatualizado;
 * - request: o servidor recusou o pedido (senha errada, sessão expirada, dado inválido): vale a mensagem dele.
 */
export type ApiFailure = 'unreachable' | 'server' | 'database' | 'request';
export class ApiError extends Error {
  status: number; kind: ApiFailure; code: string;
  constructor(message: string, status: number, kind: ApiFailure = status === 0 ? 'unreachable' : 'request', code = '') {
    super(message); this.name = 'ApiError'; this.status = status; this.kind = kind; this.code = code;
  }
}
/** O app desiste depois disto. O PHP desiste do MySQL bem antes (DB_TIMEOUT, 5 s), então a resposta chega a tempo. */
export const REQUEST_TIMEOUT_MS = 20000;
const host = (url: string) => { try { return new URL(url).host; } catch { return url; } };

export function unreachableMessage(baseUrl: string, timedOut: boolean): string {
  return `Servidor fora do alcance: ${timedOut ? `${host(baseUrl)} não respondeu em ${REQUEST_TIMEOUT_MS / 1000} segundos` : `não foi possível abrir conexão com ${host(baseUrl)}`}. `
    + 'Confira a internet do celular (Wi-Fi ou dados móveis), se o endereço está certo e se o servidor está ligado. Toque em “Testar conexão” para ver o diagnóstico.';
}
export function databaseMessage(code: string): string {
  return code === 'database_outdated'
    ? 'Banco desatualizado: o servidor está no ar, mas faltam tabelas ou colunas no MySQL. A administração precisa rodar a migração (php backend/database/update_application.php).'
    : 'Banco indisponível: o servidor está no ar, mas não conseguiu acessar o MySQL. Avise a administração (php tools/configurar-banco.php --testar mostra a causa).';
}
export function serverErrorMessage(status: number, json: boolean): string {
  if (!json && status === 404) return 'O servidor respondeu com erro (404): a API do aplicativo não existe nesse endereço. Confira o endereço (com a subpasta, ex.: /sisc-test) e se a pasta api/ foi enviada ao servidor.';
  if (!json) return `O servidor respondeu com erro${status ? ` (${status})` : ''}, mas não com a API do aplicativo. Confira o endereço com a administração.`;
  return `O servidor respondeu com erro (${status}). Tente novamente; se continuar, avise a administração.`;
}

export class ApiClient {
  baseUrl: string;
  token: string | null;
  transport: typeof fetch;
  timeoutMs: number;
  constructor(baseUrl: string, token: string | null = null, transport: typeof fetch = fetch, timeoutMs = REQUEST_TIMEOUT_MS) {
    this.baseUrl = normalizeServerUrl(baseUrl); this.token = token; this.transport = transport; this.timeoutMs = timeoutMs;
  }
  /** Faz a chamada e devolve o JSON mesmo quando o status é de erro (usado pelo teste de conexão). */
  async raw(action: string, data?: Record<string, unknown>, query?: Record<string, string>): Promise<{ status: number; payload: any; json: boolean }> {
    const parameters = new URLSearchParams({ action, ...query });
    const controller = new AbortController();
    let timedOut = false;
    const timeout = setTimeout(() => { timedOut = true; controller.abort(); }, this.timeoutMs);
    try {
      let response: Response;
      try {
        response = await this.transport(`${this.baseUrl}/api/mobile.php?${parameters}`, {
          method: data ? 'POST' : 'GET', signal: controller.signal,
          headers: { Accept: 'application/json', ...(data ? { 'Content-Type': 'application/json' } : {}),
            ...(this.token ? { Authorization: `Bearer ${this.token}` } : {}) },
          ...(data ? { body: JSON.stringify(data) } : {}),
        });
      } catch (error) {
        throw new ApiError(unreachableMessage(this.baseUrl, timedOut || (error as Error)?.name === 'AbortError'), 0, 'unreachable', timedOut ? 'timeout' : 'network');
      }
      let payload: any = null, json = true;
      try { payload = await response.json(); } catch (error) {
        if (timedOut) throw new ApiError(unreachableMessage(this.baseUrl, true), 0, 'unreachable', 'timeout');
        json = false;
      }
      if (json && (!payload || typeof payload !== 'object' || Array.isArray(payload))) json = false;
      return { status: response.status, payload, json };
    } finally { clearTimeout(timeout); }
  }
  async request<T>(action: string, data?: Record<string, unknown>, query?: Record<string, string>): Promise<T> {
    const { status, payload, json } = await this.raw(action, data, query);
    const code = json && typeof payload.code === 'string' ? payload.code : '';
    if (code === 'database_unavailable' || code === 'database_outdated') throw new ApiError(databaseMessage(code), status, 'database', code);
    if (!json) throw new ApiError(serverErrorMessage(status, false), status || 502, 'server');
    if (status >= 500) throw new ApiError(serverErrorMessage(status, true), status, 'server', code);
    if (status < 200 || status >= 300) throw new ApiError(typeof payload.error === 'string' ? payload.error : 'Não foi possível concluir a operação.', status, 'request', code);
    return payload as T;
  }
}

export type ConnectionStep = { label: string; ok: boolean; detail: string };
export type ConnectionReport = { ok: boolean; kind: ApiFailure | null; summary: string; steps: ConnectionStep[]; elapsedMs: number };

/** "Testar conexão": chama ping (só o PHP) e health (PHP + MySQL + tabelas) e explica cada etapa. */
export async function testConnection(baseUrl: string, transport: typeof fetch = fetch, now: () => number = Date.now): Promise<ConnectionReport> {
  const started = now(), steps: ConnectionStep[] = [];
  const finish = (kind: ApiFailure | null, summary: string): ConnectionReport => ({ ok: kind === null, kind, summary, steps, elapsedMs: now() - started });
  let client: ApiClient;
  try { client = new ApiClient(baseUrl, null, transport); }
  catch (error) { steps.push({ label: 'Endereço', ok: false, detail: (error as Error).message }); return finish('request', (error as Error).message); }
  steps.push({ label: 'Endereço', ok: true, detail: client.baseUrl + (client.baseUrl.startsWith('http://') ? ' (HTTP, sem criptografia)' : '') });

  let ping;
  try { ping = await client.raw('ping'); }
  catch (error) {
    steps.push({ label: 'Servidor', ok: false, detail: (error as Error).message });
    return finish('unreachable', (error as Error).message);
  }
  if (!ping.json || ping.status !== 200 || Number(ping.payload.mobile_api) !== 1) {
    const message = serverErrorMessage(ping.status, ping.json);
    steps.push({ label: 'Servidor', ok: true, detail: `Respondeu (código ${ping.status}).` }, { label: 'API do aplicativo', ok: false, detail: message });
    return finish('server', message);
  }
  steps.push({ label: 'Servidor', ok: true, detail: `Respondeu em ${now() - started} ms.` }, { label: 'API do aplicativo', ok: true, detail: 'api/mobile.php encontrada.' });

  let health;
  try { health = await client.raw('health'); }
  catch (error) { steps.push({ label: 'Banco de dados', ok: false, detail: (error as Error).message }); return finish('unreachable', (error as Error).message); }
  if (!health.json || health.payload.database === undefined) {
    const message = 'O servidor não tem a verificação de saúde (versão antiga da API). Envie a versão atual de api/ e backend/ ao servidor.';
    steps.push({ label: 'Banco de dados', ok: false, detail: message });
    return finish('server', message);
  }
  const database = String(health.payload.database), missing: string[] = Array.isArray(health.payload.missing_tables) ? health.payload.missing_tables : [];
  if (database !== 'ok') {
    const message = database === 'not_configured' ? 'Banco indisponível: o .env do servidor não tem a configuração do MySQL. Rode php tools/configurar-banco.php no servidor.' : databaseMessage('database_unavailable');
    steps.push({ label: 'Banco de dados', ok: false, detail: message });
    return finish('database', message);
  }
  steps.push({ label: 'Banco de dados', ok: true, detail: 'O PHP conectou ao MySQL.' });
  if (health.payload.tables !== 'ok') {
    const message = `${databaseMessage('database_outdated')}${missing.length ? ` Faltam: ${missing.join(', ')}.` : ''}`;
    steps.push({ label: 'Tabelas', ok: false, detail: message });
    return finish('database', message);
  }
  steps.push({ label: 'Tabelas', ok: true, detail: 'Todas as tabelas existem.' });
  return finish(null, 'Tudo certo: o servidor, a API e o banco de dados estão funcionando.');
}
