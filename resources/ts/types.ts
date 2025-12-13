/**
 * フロントエンド型定義
 */

export interface User {
  id: number;
  userId: string;      // ログイン用ID
  username: string;    // 表示名
  email?: string | null;
  isAdmin: boolean;
  initialSetupCompleted?: boolean;  // 初期設定完了フラグ
  hasAnyApiKey?: boolean;           // APIキー設定済みフラグ
}

export interface GeneratedFeed {
  id: number;
  feed_token: string;
  source_url: string;
  ai_provider: string | null;
  ai_model: string | null;
  generation_status: 'pending' | 'success' | 'error';
  error_message: string | null;
  last_generated_at: string | null;
}

export interface Journal {
  id: string;
  name: string;
  rss_url: string;
  color: string;
  is_active: number | boolean;
  last_fetched_at: string | null;
  paper_count?: number;
  source_type?: 'rss' | 'ai_generated';
  generated_feed?: GeneratedFeed | null;
}

export interface Tag {
  id: number;
  name: string;
  color: string;
  description?: string | null;
  paper_count?: number;
}

export interface Paper {
  id: number;
  journal_id: string;
  title: string;
  authors: string | string[];
  abstract: string | null;
  url: string | null;
  doi: string | null;
  published_date: string | null;
  fetched_at: string;
  journal_name: string;
  journal_color: string;
  has_summary?: number;
  has_full_text?: boolean;
  full_text_source?: string | null;
  summaries?: Summary[];
  tags?: Tag[];
}

export interface Summary {
  id: number;
  paper_id: number;
  ai_provider: string;
  ai_model: string;
  summary_text: string;
  purpose: string | null;
  methodology: string | null;
  findings: string | null;
  implications: string | null;
  tokens_used: number | null;
  generation_time_ms: number | null;
  created_at: string;
}

export interface AIProvider {
  id: string;
  name: string;
  models: string[];
  default_model: string;
  user_key?: boolean;
}

export interface Pagination {
  total: number;
  limit: number;
  offset: number;
  hasMore: boolean;
}

export interface FetchResult {
  status: 'success' | 'error';
  papers_fetched?: number;
  new_papers?: number;
  execution_time_ms?: number;
  error?: string;
}

export interface RssTestResult {
  success: boolean;
  feedTitle?: string;
  itemCount?: number;
  sampleItems?: Array<{
    title: string;
    pubDate?: string;
    author?: string;
  }>;
  error?: string;
}

export interface ColorOption {
  id: string;
  name: string;
  hex: string;
}

export interface DateFilter {
  value: string;
  label: string;
  days: number | null;
}

export interface RssUrlExample {
  publisher: string;
  format: string;
  example: string;
}

// API Response types
export interface ApiResponse<T = unknown> {
  success: boolean;
  error?: string;
  data?: T;
}

export interface LoginResponse {
  success: boolean;
  user: User;
  expiresAt: string;
}

export interface RegisterResponse {
  success: boolean;
  user: User;
  expiresAt?: string;
}

export interface AuthMeResponse {
  authenticated: boolean;
  user: User;
}

export interface JournalsResponse {
  success: boolean;
  journals: Journal[];
}

export interface PapersResponse {
  success: boolean;
  papers: Paper[];
  pagination: Pagination;
}

export interface ProvidersResponse {
  success: boolean;
  providers: AIProvider[];
  current: string;
}

export interface GenerateSummaryResponse {
  success: boolean;
  summary: Summary;
}

// Form data types
export interface JournalFormData {
  name: string;
  rssUrl: string;
  color: string;
  sourceType?: 'rss' | 'ai_generated';
}

// AI RSS生成関連
export interface PageTestResult {
  success: boolean;
  selectors?: {
    paper_container?: string;
    title?: string;
    title_attr?: string;
    url?: string;
    url_attr?: string;
    authors?: string | null;
    authors_attr?: string;
    abstract?: string | null;
    date?: string | null;
    date_format?: string;
  };
  sample_papers?: Array<{
    title: string;
    url: string;
  }>;
  provider?: string;
  page_size?: {
    original: number;
    cleaned: number;
  };
  error?: string;
}

export interface RegenerateFeedResult {
  success: boolean;
  message?: string;
  papers_count?: number;
  feed_token?: string;
  provider?: string;
  error?: string;
}

// API Settings types
export interface ApiSettings {
  claude_api_key_set: boolean;
  claude_api_key_masked: string | null;
  openai_api_key_set: boolean;
  openai_api_key_masked: string | null;
  preferred_ai_provider: string;
  preferred_openai_model: string | null;
  preferred_claude_model: string | null;
  available_providers: AIProvider[];
}

export interface ApiSettingsResponse {
  success: boolean;
  message?: string;
  updated?: Record<string, string>;
  claude_api_key_set: boolean;
  openai_api_key_set: boolean;
  preferred_ai_provider: string;
  preferred_openai_model: string | null;
  preferred_claude_model: string | null;
  available_providers: AIProvider[];
}

// Tag API Response types
export interface TagsResponse {
  success: boolean;
  tags: Tag[];
}

export interface TagResponse {
  success: boolean;
  tag: Tag;
  message?: string;
}

export interface TagPapersResponse {
  success: boolean;
  tag: Tag;
  papers: Paper[];
  latest_summary: TagSummary | null;
}

// Profile types
export interface Profile {
  user_id: string;
  username: string;
  email: string | null;
}

export interface ProfileResponse {
  success: boolean;
  profile: Profile;
}

export interface ProfileUpdateResponse {
  success: boolean;
  message: string;
  updated: Record<string, string | null>;
  profile: Profile;
}

// Summary Chat types
export interface ChatMessage {
  id: number;
  role: 'user' | 'assistant';
  content: string;
  ai_provider?: string | null;
  ai_model?: string | null;
  tokens_used?: number | null;
  created_at: string;
}

export interface ChatMessagesResponse {
  success: boolean;
  messages: ChatMessage[];
}

export interface ChatSendResponse {
  success: boolean;
  user_message: ChatMessage;
  ai_message: ChatMessage;
}

// Tag Summary types
export interface TagSummary {
  id: number;
  perspective_prompt: string;
  summary_text: string;
  ai_provider: string;
  ai_model: string | null;
  paper_count: number;
  tokens_used: number | null;
  generation_time_ms: number | null;
  created_at: string;
}

export interface TagSummariesResponse {
  success: boolean;
  tag: Tag;
  summaries: TagSummary[];
}

export interface TagSummaryResponse {
  success: boolean;
  summary: TagSummary;
}

// Research Perspective types（調査観点設定）
export interface ResearchPerspective {
  research_fields: string;     // 研究分野や興味のある観点
  summary_perspective: string; // 要約してほしい観点
  reading_focus: string;       // 論文を読む際に着目する観点
}

export interface ResearchPerspectiveResponse {
  success: boolean;
  research_perspective: ResearchPerspective;
}

export interface ResearchPerspectiveUpdateResponse {
  success: boolean;
  message: string;
  research_perspective: ResearchPerspective;
}

// Summary Template types（要約テンプレート設定）
export interface SummaryTemplateResponse {
  success: boolean;
  summary_template: string;
}

export interface SummaryTemplateUpdateResponse {
  success: boolean;
  message: string;
  summary_template: string;
}
