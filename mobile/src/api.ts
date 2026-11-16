import { normalizeServerUrl } from './domain';

export class ApiError extends Error {
  status: number;
  constructor(message: string, status: number) { super(message); this.name = 'ApiError'; this.status = status; }
}
export class ApiClient {
  baseUrl: string;
  token: string | null;
  transport: typeof fetch;
  constructor(baseUrl: string, token: string | null = null, transport: typeof fetch = fetch) {
    this.baseUrl = normalizeServerUrl(baseUrl); this.token = token; this.transport = transport;
  }
  async request<T>(action: string, data?: Record<string, unknown>, query?: Record<string, string>): Promise<T> {
    const parameters = new URLSearchParams({ action, ...query });
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await this.transport(`${this.baseUrl}/api/mobile.php?${parameters}`, {
        method: data ? 'POST' : 'GET', signal: controller.signal,
        headers: { Accept: 'application/json', ...(data ? { 'Content-Type': 'application/json' } : {}),
          ...(this.token ? { Authorization: `Bearer ${this.token}` } : {}) },
        ...(data ? { body: JSON.stringify(data) } : {}),
      });
      let payload: any;
      try { payload = await response.json(); } catch { throw new ApiError('O servidor não retornou uma resposta válida. Confira o endereço com a administração.', response.status); }
      if (!response.ok) throw new ApiError(typeof payload?.error === 'string' ? payload.error : 'Não foi possível concluir a operação.', response.status);
      if (!payload || typeof payload !== 'object' || Array.isArray(payload)) throw new ApiError('Resposta inválida do servidor.', 502);
      return payload as T;
    } catch (error) {
      if (error instanceof ApiError) throw error;
      if ((error as Error).name === 'AbortError') throw new ApiError('O servidor demorou a responder. Tente novamente.', 0);
      throw new ApiError('Não foi possível conectar. Confira sua internet e o endereço do servidor.', 0);
    } finally { clearTimeout(timeout); }
  }
}
