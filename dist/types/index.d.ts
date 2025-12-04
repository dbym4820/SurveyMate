/**
 * 型定義
 */
import type { Request as ExpressRequest, Response, NextFunction } from 'express';
import type { RowDataPacket, ResultSetHeader } from 'mysql2';
export interface User extends RowDataPacket {
    id: number;
    username: string;
    password_hash: string;
    email: string | null;
    is_admin: boolean;
    is_active: boolean;
    last_login_at: Date | null;
    created_at: Date;
}
export interface Session extends RowDataPacket {
    id: string;
    user_id: number;
    expires_at: Date;
    created_at: Date;
    username?: string;
    is_admin?: boolean;
}
export interface Journal extends RowDataPacket {
    id: string;
    name: string;
    full_name: string;
    publisher: string;
    rss_url: string;
    category: string;
    color: string;
    is_active: boolean;
    last_fetched_at: Date | null;
    created_at: Date;
    paper_count?: number;
}
export interface Paper extends RowDataPacket {
    id: number;
    journal_id: string;
    title: string;
    authors: string | string[];
    abstract: string | null;
    url: string | null;
    doi: string | null;
    published_date: string | null;
    external_id: string | null;
    fetched_at: Date;
    updated_at: Date;
    journal_name?: string;
    journal_full_name?: string;
    journal_color?: string;
    category?: string;
    has_summary?: number;
}
export interface Summary extends RowDataPacket {
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
    created_at: Date;
}
export interface FetchLog extends RowDataPacket {
    id: number;
    journal_id: string;
    status: 'success' | 'error';
    papers_fetched: number;
    new_papers: number;
    error_message: string | null;
    execution_time_ms: number | null;
    created_at: Date;
    journal_name?: string;
}
export interface AuthenticatedUser {
    userId: number;
    username: string;
    isAdmin: boolean;
}
export interface Request extends ExpressRequest {
    user?: AuthenticatedUser;
}
export { Response, NextFunction };
export interface ApiResponse<T = unknown> {
    success: boolean;
    error?: string;
    data?: T;
}
export interface LoginResponse {
    success: boolean;
    user: {
        id: number;
        username: string;
        email: string | null;
        isAdmin: boolean;
    };
    expiresAt: Date;
}
export interface PapersListParams {
    journalIds?: string[];
    dateFrom?: string;
    dateTo?: string;
    search?: string;
    limit?: number;
    offset?: number;
}
export interface Pagination {
    total: number;
    limit: number;
    offset: number;
    hasMore: boolean;
}
export interface SummaryOptions {
    provider?: string;
    model?: string;
}
export interface SummaryResult {
    ai_provider: string;
    ai_model: string;
    summary_text: string;
    purpose: string | null;
    methodology: string | null;
    findings: string | null;
    implications: string | null;
    tokens_used: number | null;
    generation_time_ms: number;
}
export interface AIProvider {
    id: string;
    name: string;
    models: string[];
    defaultModel: string;
}
export interface FetchResult {
    success: boolean;
    papersFetched?: number;
    newPapers?: number;
    error?: string;
}
export interface FetchAllResult {
    skipped?: boolean;
    total: number;
    success: number;
    failed: number;
    newPapers: number;
    details: Array<{
        journalId: string;
        journalName: string;
        success: boolean;
        papersFetched?: number;
        newPapers?: number;
        error?: string;
    }>;
}
export interface SchedulerStatus {
    isRunning: boolean;
    isScheduled: boolean;
    schedule: string;
    lastRunTime: Date | null;
    nextRunTime: Date | null;
}
export interface PaperInput {
    journal_id: string;
    title: string;
    authors: string[];
    abstract: string | null;
    url: string | null;
    doi: string | null;
    published_date: string | null;
    external_id: string | null;
}
export interface SummaryInput {
    paper_id: number;
    ai_provider: string;
    ai_model: string;
    summary_text: string;
    purpose?: string | null;
    methodology?: string | null;
    findings?: string | null;
    implications?: string | null;
    tokens_used?: number | null;
    generation_time_ms?: number | null;
}
export interface FetchLogInput {
    journal_id: string;
    status: 'success' | 'error';
    papers_fetched?: number;
    new_papers?: number;
    error_message?: string | null;
    execution_time_ms?: number | null;
}
export { ResultSetHeader };
//# sourceMappingURL=index.d.ts.map