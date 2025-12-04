import type {
  LoginResponse,
  RegisterResponse,
  AuthMeResponse,
  JournalsResponse,
  PapersResponse,
  ProvidersResponse,
  GenerateSummaryResponse,
  RssTestResult,
  FetchResult,
  JournalFormData,
  Journal,
} from './types';

// ベースパス（Viteのimport.meta.envから取得、またはデフォルト）
const BASE_PATH = import.meta.env.BASE_URL || '/autosurvey/';
const API_BASE = `${BASE_PATH}api`.replace(/\/+/g, '/').replace(/\/$/, '');

export class ApiError extends Error {
  status: number;
  data: unknown;

  constructor(message: string, status: number, data?: unknown) {
    super(message);
    this.status = status;
    this.data = data;
  }
}

async function request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
  const url = `${API_BASE}${endpoint}`;
  const config: RequestInit = {
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      ...options.headers,
    },
    ...options,
  };

  if (config.body && typeof config.body === 'object' && !(config.body instanceof FormData)) {
    config.body = JSON.stringify(config.body);
  }

  const response = await fetch(url, config);
  const data = await response.json().catch(() => ({})) as T;

  if (!response.ok) {
    throw new ApiError(
      (data as { error?: string }).error || 'APIエラーが発生しました',
      response.status,
      data
    );
  }

  return data;
}

export const api = {
  // 認証
  auth: {
    login: (username: string, password: string): Promise<LoginResponse> =>
      request('/auth/login', {
        method: 'POST',
        body: JSON.stringify({ username, password }),
      }),
    register: (username: string, password: string, email?: string): Promise<RegisterResponse> =>
      request('/auth/register', {
        method: 'POST',
        body: JSON.stringify({ username, password, email }),
      }),
    logout: (): Promise<{ success: boolean }> =>
      request('/auth/logout', { method: 'POST' }),
    me: (): Promise<AuthMeResponse> =>
      request('/auth/me'),
  },

  // 論文誌
  journals: {
    list: (all = false): Promise<JournalsResponse> =>
      request(`/journals${all ? '?all=true' : ''}`),
    create: (data: JournalFormData): Promise<{ success: boolean; journal: Journal }> =>
      request('/admin/journals', { method: 'POST', body: JSON.stringify(data) }),
    update: (id: string, data: Partial<JournalFormData>): Promise<{ success: boolean; journal: Journal }> =>
      request(`/admin/journals/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
    delete: (id: string, permanent = false): Promise<{ success: boolean; message: string }> =>
      request(`/admin/journals/${id}${permanent ? '?permanent=true' : ''}`, { method: 'DELETE' }),
    activate: (id: string): Promise<{ success: boolean; message: string }> =>
      request(`/admin/journals/${id}/activate`, { method: 'POST' }),
    testRss: (rssUrl: string): Promise<RssTestResult> =>
      request('/admin/journals/test-rss', { method: 'POST', body: JSON.stringify({ rssUrl }) }),
    fetch: (id: string): Promise<{ success: boolean; result: FetchResult }> =>
      request(`/admin/journals/${id}/fetch`),
  },

  // 論文
  papers: {
    list: (params: {
      journals?: string[];
      dateFrom?: string;
      dateTo?: string;
      search?: string;
      limit?: number;
      offset?: number;
    } = {}): Promise<PapersResponse> => {
      const query = new URLSearchParams();
      if (params.journals) query.set('journals', params.journals.join(','));
      if (params.dateFrom) query.set('dateFrom', params.dateFrom);
      if (params.dateTo) query.set('dateTo', params.dateTo);
      if (params.search) query.set('search', params.search);
      if (params.limit) query.set('limit', params.limit.toString());
      if (params.offset) query.set('offset', params.offset.toString());
      return request(`/papers?${query}`);
    },
  },

  // AI要約
  summaries: {
    providers: (): Promise<ProvidersResponse> =>
      request('/summaries/providers'),
    generate: (paperId: number, provider?: string): Promise<GenerateSummaryResponse> =>
      request('/summaries/generate', { method: 'POST', body: JSON.stringify({ paperId, provider }) }),
  },

  // 管理者
  admin: {
    runScheduler: (): Promise<{ success: boolean; result: FetchResult }> =>
      request('/admin/scheduler/run', { method: 'POST' }),
    getLogs: (limit = 50): Promise<{ success: boolean; logs: unknown[] }> =>
      request(`/admin/logs?limit=${limit}`),
  },
};

export default api;
