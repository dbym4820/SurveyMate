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
  ApiSettings,
  ApiSettingsResponse,
  TagsResponse,
  TagResponse,
  TagPapersResponse,
  TagSummariesResponse,
  TagSummaryResponse,
  ProfileResponse,
  ProfileUpdateResponse,
  ChatMessagesResponse,
  ChatSendResponse,
  ResearchPerspectiveResponse,
  ResearchPerspectiveUpdateResponse,
} from './types';

// ベースパス（現在のURLから自動検出）
export const getBasePath = (): string => {
  const pathParts = window.location.pathname.split('/').filter(Boolean);
  const firstPart = pathParts[0] || '';
  const isSubdir = firstPart && !firstPart.includes('.') && firstPart !== 'api';
  return isSubdir ? `/${firstPart}` : '';
};

// APIベースパス
const getApiBase = (): string => {
  return `${getBasePath()}/api`;
};
const API_BASE = getApiBase();

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
      'Accept': 'application/json',
      ...options.headers,
    },
    ...options,
  };

  if (config.body && typeof config.body === 'object' && !(config.body instanceof FormData)) {
    config.body = JSON.stringify(config.body);
  }

  const response = await fetch(url, config);
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new ApiError(
      (data as { error?: string }).error || 'APIエラーが発生しました',
      response.status,
      data
    );
  }

  // Add success flag for compatibility
  return { success: true, ...data } as T;
}

export const api = {
  // 認証
  auth: {
    login: (userId: string, password: string): Promise<LoginResponse> =>
      request('/auth/login', {
        method: 'POST',
        body: JSON.stringify({ user_id: userId, password }),
      }),
    register: (userId: string, username: string, password: string, email?: string): Promise<RegisterResponse> =>
      request('/auth/register', {
        method: 'POST',
        body: JSON.stringify({ user_id: userId, username, password, email }),
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
    create: (data: JournalFormData): Promise<{ success: boolean; journal: Journal; fetch_result?: FetchResult }> =>
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
      tags?: number[];
      dateFrom?: string;
      dateTo?: string;
      search?: string;
      limit?: number;
      offset?: number;
    } = {}): Promise<PapersResponse> => {
      const query = new URLSearchParams();
      if (params.journals) query.set('journals', params.journals.join(','));
      if (params.tags && params.tags.length > 0) query.set('tags', params.tags.join(','));
      if (params.dateFrom) query.set('dateFrom', params.dateFrom);
      if (params.dateTo) query.set('dateTo', params.dateTo);
      if (params.search) query.set('search', params.search);
      if (params.limit) query.set('limit', params.limit.toString());
      if (params.offset) query.set('offset', params.offset.toString());
      return request(`/papers?${query}`);
    },
    getFullText: (paperId: number): Promise<{
      success: boolean;
      paper_id: number;
      title: string;
      full_text: string;
      full_text_source: string | null;
      full_text_fetched_at: string | null;
    }> => request(`/papers/${paperId}/full-text`),
  },

  // タグ
  tags: {
    list: (): Promise<TagsResponse> =>
      request('/tags'),
    create: (name: string, color?: string, description?: string): Promise<TagResponse> =>
      request('/tags', { method: 'POST', body: JSON.stringify({ name, color, description }) }),
    update: (id: number, data: { name?: string; color?: string; description?: string }): Promise<TagResponse> =>
      request(`/tags/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
    delete: (id: number): Promise<{ success: boolean; message: string }> =>
      request(`/tags/${id}`, { method: 'DELETE' }),
    papers: (tagId: number): Promise<TagPapersResponse> =>
      request(`/tags/${tagId}/papers`),
    addToPaper: (paperId: number, data: { tag_id?: number; tag_name?: string; color?: string; description?: string }): Promise<TagResponse> =>
      request(`/papers/${paperId}/tags`, { method: 'POST', body: JSON.stringify(data) }),
    removeFromPaper: (paperId: number, tagId: number): Promise<{ success: boolean; message: string }> =>
      request(`/papers/${paperId}/tags/${tagId}`, { method: 'DELETE' }),
    // タグ要約
    getSummaries: (tagId: number): Promise<TagSummariesResponse> =>
      request(`/tags/${tagId}/summaries`),
    generateSummary: (tagId: number, perspectivePrompt: string, provider?: string): Promise<TagSummaryResponse> =>
      request(`/tags/${tagId}/summaries`, { method: 'POST', body: JSON.stringify({ perspective_prompt: perspectivePrompt, provider }) }),
    deleteSummary: (tagId: number, summaryId: number): Promise<{ success: boolean; message: string }> =>
      request(`/tags/${tagId}/summaries/${summaryId}`, { method: 'DELETE' }),
  },

  // AI要約
  summaries: {
    providers: (): Promise<ProvidersResponse> =>
      request('/summaries/providers'),
    generate: (paperId: number, provider?: string): Promise<GenerateSummaryResponse> =>
      request('/summaries/generate', { method: 'POST', body: JSON.stringify({ paperId, provider }) }),
    // チャット（要約についての会話）
    chat: {
      getMessages: (summaryId: number): Promise<ChatMessagesResponse> =>
        request(`/summaries/${summaryId}/chat`),
      send: (summaryId: number, message: string): Promise<ChatSendResponse> =>
        request(`/summaries/${summaryId}/chat`, { method: 'POST', body: JSON.stringify({ message }) }),
      clear: (summaryId: number): Promise<{ success: boolean; message: string }> =>
        request(`/summaries/${summaryId}/chat`, { method: 'DELETE' }),
    },
  },

  // 管理者
  admin: {
    runScheduler: (): Promise<{ success: boolean; result: FetchResult }> =>
      request('/admin/scheduler/run', { method: 'POST' }),
    getLogs: (limit = 50): Promise<{ success: boolean; logs: unknown[] }> =>
      request(`/admin/logs?limit=${limit}`),
  },

  // 設定
  settings: {
    getApi: (): Promise<ApiSettings & { success: boolean }> =>
      request('/settings/api'),
    updateApi: (data: {
      claude_api_key?: string | null;
      openai_api_key?: string | null;
      preferred_ai_provider?: string;
      preferred_openai_model?: string;
      preferred_claude_model?: string;
    }): Promise<ApiSettingsResponse> =>
      request('/settings/api', { method: 'PUT', body: JSON.stringify(data) }),
    deleteApiKey: (provider: 'claude' | 'openai'): Promise<ApiSettingsResponse> =>
      request(`/settings/api/${provider}`, { method: 'DELETE' }),
    getProfile: (): Promise<ProfileResponse> =>
      request('/settings/profile'),
    updateProfile: (data: { username?: string; email?: string | null }): Promise<ProfileUpdateResponse> =>
      request('/settings/profile', { method: 'PUT', body: JSON.stringify(data) }),
    // 調査観点設定
    getResearchPerspective: (): Promise<ResearchPerspectiveResponse> =>
      request('/settings/research-perspective'),
    updateResearchPerspective: (data: {
      research_fields?: string;
      summary_perspective?: string;
      reading_focus?: string;
    }): Promise<ResearchPerspectiveUpdateResponse> =>
      request('/settings/research-perspective', { method: 'PUT', body: JSON.stringify(data) }),
  },

  // プッシュ通知
  push: {
    getPublicKey: (): Promise<{ success: boolean; publicKey: string }> =>
      request('/push/public-key'),
    subscribe: (subscription: PushSubscriptionJSON): Promise<{ success: boolean; message: string; subscription_id: number }> =>
      request('/push/subscribe', { method: 'POST', body: JSON.stringify(subscription) }),
    unsubscribe: (endpoint: string): Promise<{ success: boolean; message: string }> =>
      request('/push/unsubscribe', { method: 'POST', body: JSON.stringify({ endpoint }) }),
    status: (): Promise<{ success: boolean; configured: boolean; subscribed: boolean; subscription_count: number }> =>
      request('/push/status'),
  },
};

export default api;
