/**
 * フロントエンド型定義
 */

export interface User {
  id: number;
  username: string;
  email?: string | null;
  isAdmin: boolean;
}

export interface Journal {
  id: string;
  name: string;
  full_name: string;
  publisher: string;
  rss_url: string;
  category: string;
  color: string;
  is_active: number | boolean;
  last_fetched_at: string | null;
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
  journal_full_name: string;
  journal_color: string;
  category: string;
  has_summary?: number;
  summaries?: Summary[];
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
  defaultModel: string;
}

export interface Pagination {
  total: number;
  limit: number;
  offset: number;
  hasMore: boolean;
}

export interface FetchResult {
  success: boolean;
  papersFetched?: number;
  newPapers?: number;
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
  id: string;
  name: string;
  fullName: string;
  publisher: string;
  rssUrl: string;
  category: string;
  color: string;
}
