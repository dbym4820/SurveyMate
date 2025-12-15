import { useState, useEffect, useCallback, useRef, ChangeEvent } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  Filter, Calendar, ChevronDown, ChevronUp, Loader2, FileText, Tag, X, BookOpen
} from 'lucide-react';
import api from '../api';
import PaperCard from './PaperCard';
import TagSummaryModal from './TagSummaryModal';
import Toast, { ToastType } from './Toast';
import { DATE_FILTERS } from '../constants';
import type { Paper, Journal, Pagination, Tag as TagType, ApiSettings } from '../types';

interface ToastState {
  show: boolean;
  message: string;
  type: ToastType;
}

export default function PaperList(): JSX.Element {
  const [searchParams, setSearchParams] = useSearchParams();
  const [papers, setPapers] = useState<Paper[]>([]);
  const [journals, setJournals] = useState<Journal[]>([]);
  const [selectedJournals, setSelectedJournals] = useState<string[]>([]);
  const [dateFilter, setDateFilter] = useState('all');
  const [showJournalFilter, setShowJournalFilter] = useState(false);
  const [loading, setLoading] = useState(true);
  const [pagination, setPagination] = useState<Pagination>({ total: 0, limit: 0, offset: 0, hasMore: false });

  // タグ関連
  const [allTags, setAllTags] = useState<TagType[]>([]);
  const [selectedTags, setSelectedTags] = useState<number[]>([]);
  const [showTagFilter, setShowTagFilter] = useState(false);
  const [tagSummaryTag, setTagSummaryTag] = useState<TagType | null>(null);

  // APIキー設定状態
  const [settings, setSettings] = useState<ApiSettings | null>(null);

  // Toast notification
  const [toast, setToast] = useState<ToastState>({ show: false, message: '', type: 'info' });

  const hideToast = () => {
    setToast((prev) => ({ ...prev, show: false }));
  };

  // Fetch journals
  const fetchJournals = useCallback(async (): Promise<void> => {
    try {
      const data = await api.journals.list();
      if (data.success) {
        setJournals(data.journals);
        if (selectedJournals.length === 0) {
          setSelectedJournals(data.journals.map((j) => j.id));
        }
      }
    } catch (error) {
      console.error('Failed to fetch journals:', error);
    }
  }, [selectedJournals.length]);

  // Fetch tags
  const fetchTags = useCallback(async (): Promise<void> => {
    try {
      const data = await api.tags.list();
      if (data.success) {
        setAllTags(data.tags);
      }
    } catch (error) {
      console.error('Failed to fetch tags:', error);
    }
  }, []);

  // Fetch settings (API key status)
  const fetchSettings = useCallback(async (): Promise<void> => {
    try {
      const data = await api.settings.getApi();
      setSettings(data);
    } catch (error) {
      console.error('Failed to fetch settings:', error);
    }
  }, []);

  // APIキー設定有無
  const hasAnyApiKey = settings?.claude_api_key_set || settings?.openai_api_key_set;

  // Initial load
  useEffect(() => {
    fetchJournals();
    fetchTags();
    fetchSettings();
  }, [fetchJournals, fetchTags, fetchSettings]);

  // URLパラメータからタグ要約モーダルを開く
  useEffect(() => {
    const tagSummaryId = searchParams.get('tagSummary');
    if (tagSummaryId && allTags.length > 0) {
      const tagId = parseInt(tagSummaryId, 10);
      const tag = allTags.find((t) => t.id === tagId);
      if (tag) {
        setTagSummaryTag(tag);
      }
    }
  }, [searchParams, allTags]);

  // タグ要約モーダルを開く（URLも更新）
  const openTagSummary = (tag: TagType): void => {
    setTagSummaryTag(tag);
    setSearchParams({ tagSummary: tag.id.toString() });
  };

  // タグ要約モーダルを閉じる（URLからパラメータを削除）
  const closeTagSummary = (): void => {
    setTagSummaryTag(null);
    searchParams.delete('tagSummary');
    setSearchParams(searchParams);
  };

  // Toggle tag selection
  const toggleTag = (tagId: number): void => {
    setSelectedTags((prev) =>
      prev.includes(tagId)
        ? prev.filter((id) => id !== tagId)
        : [...prev, tagId]
    );
  };

  // Clear tag filter
  const clearTagFilter = (): void => {
    setSelectedTags([]);
  };

  // Calculate date range
  const getDateFrom = useCallback((): string | undefined => {
    const filter = DATE_FILTERS.find((f) => f.value === dateFilter);
    if (!filter?.days) return undefined;
    const date = new Date();
    date.setDate(date.getDate() - filter.days);
    return date.toISOString().split('T')[0];
  }, [dateFilter]);

  // Fetch papers
  const fetchPapers = useCallback(async (): Promise<Paper[]> => {
    if (selectedJournals.length === 0) {
      setPapers([]);
      setLoading(false);
      return [];
    }

    setLoading(true);
    try {
      const data = await api.papers.list({
        journals: selectedJournals,
        tags: selectedTags.length > 0 ? selectedTags : undefined,
        dateFrom: getDateFrom(),
        limit: 100,
      });
      if (data.success) {
        setPapers(data.papers);
        setPagination(data.pagination);
        return data.papers;
      }
      return [];
    } catch (error) {
      console.error('Failed to fetch papers:', error);
      return [];
    } finally {
      setLoading(false);
    }
  }, [selectedJournals, selectedTags, getDateFrom]);

  // PDF処理中の論文があれば5秒ごとにワーカー起動確認
  const pollingIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const serverProcessingCountRef = useRef<number>(0);

  // ポーリングを開始
  const startPolling = useCallback(() => {
    if (pollingIntervalRef.current) {
      return; // 既に開始済み
    }
    console.log('[PaperList] PDF処理状況ポーリングを開始');
    pollingIntervalRef.current = setInterval(() => {
      checkProcessingStatusInternal();
    }, 5000);
  }, []);

  // ポーリングを停止
  const stopPolling = useCallback(() => {
    if (pollingIntervalRef.current) {
      console.log('[PaperList] PDF処理状況ポーリングを停止');
      clearInterval(pollingIntervalRef.current);
      pollingIntervalRef.current = null;
    }
  }, []);

  // 処理状況確認とワーカー起動（個別の論文状態を更新）
  const checkProcessingStatusInternal = async () => {
    try {
      const status = await api.papers.processingStatus();
      console.log('[PaperList] 処理状況:', status);

      // サーバー側の処理中カウントを保存
      serverProcessingCountRef.current = status.processing_count;

      // 各論文のpdf_statusを個別に更新
      if (status.paper_statuses && status.paper_statuses.length > 0) {
        setPapers((prevPapers) => {
          let hasChanges = false;
          const updatedPapers = prevPapers.map((paper) => {
            const statusInfo = status.paper_statuses.find((s) => s.id === paper.id);
            if (statusInfo) {
              // 状態が変わった場合のみ更新
              if (
                paper.pdf_status !== statusInfo.pdf_status ||
                paper.has_local_pdf !== statusInfo.has_local_pdf
              ) {
                hasChanges = true;
                console.log(`[PaperList] 論文 ${paper.id} の状態更新: ${paper.pdf_status} → ${statusInfo.pdf_status}`);
                return {
                  ...paper,
                  pdf_status: statusInfo.pdf_status as Paper['pdf_status'],
                  has_local_pdf: statusInfo.has_local_pdf,
                };
              }
            }
            return paper;
          });
          // 変更がなければ同じ配列を返す（再レンダリング防止）
          return hasChanges ? updatedPapers : prevPapers;
        });
      }

      // サーバー側で処理中の論文がなくなり、ジョブもない場合はポーリング停止
      if (status.processing_count === 0 && status.pending_jobs === 0 && pollingIntervalRef.current) {
        console.log('[PaperList] サーバー側で全処理完了、ポーリング停止');
        clearInterval(pollingIntervalRef.current);
        pollingIntervalRef.current = null;
      }

      return status;
    } catch (error) {
      console.error('[PaperList] 処理状況確認エラー:', error);
      return null;
    }
  };

  // 画面表示時（論文取得後）にキューにジョブがあればワーカー起動・ポーリング開始
  useEffect(() => {
    const loadAndCheckProcessing = async () => {
      const loadedPapers = await fetchPapers();

      // 画面表示時に必ず処理状況をチェック（キューにジョブがあればワーカー起動）
      const status = await checkProcessingStatusInternal();

      // ローカルの論文にPDF処理中のものがあるか、サーバー側でジョブがある場合はポーリング開始
      const hasLocalProcessing = loadedPapers.some(
        (p) => p.pdf_status === 'pending' || p.pdf_status === 'processing'
      );
      const hasServerProcessing = status && (status.processing_count > 0 || status.pending_jobs > 0);

      if (hasLocalProcessing || hasServerProcessing) {
        console.log('[PaperList] 画面表示: 処理中のジョブを検出、ポーリング開始', {
          localProcessing: hasLocalProcessing,
          serverProcessing: hasServerProcessing,
          pendingJobs: status?.pending_jobs,
        });
        startPolling();
      }
    };
    loadAndCheckProcessing();
  }, [selectedJournals, selectedTags, getDateFrom]);

  // コンポーネントのアンマウント時にポーリングを停止
  useEffect(() => {
    return () => {
      stopPolling();
    };
  }, [stopPolling]);

  // フィード更新イベントをリッスンしてデータを再取得
  useEffect(() => {
    const handleFeedsUpdated = async () => {
      fetchJournals();
      await fetchPapers();
      // フィード更新後は処理状況をチェックしてポーリング開始
      const status = await checkProcessingStatusInternal();
      if (status && (status.processing_count > 0 || status.pending_jobs > 0)) {
        startPolling();
      }
    };
    window.addEventListener('feeds-updated', handleFeedsUpdated);
    return () => {
      window.removeEventListener('feeds-updated', handleFeedsUpdated);
    };
  }, [fetchJournals, fetchPapers]);

  // Toggle journal selection
  const toggleJournal = (journalId: string): void => {
    setSelectedJournals((prev) =>
      prev.includes(journalId)
        ? prev.filter((id) => id !== journalId)
        : [...prev, journalId]
    );
  };

  return (
    <>
      <main className="w-[85%] mx-auto py-4 sm:py-6">
        {/* Filter bar */}
        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-3 sm:p-4 mb-4 sm:mb-6">
          <div className="flex flex-col sm:flex-row sm:flex-wrap gap-3 sm:gap-4 sm:items-center sm:justify-between">
            {/* Top row: journal filter and count (mobile) */}
            <div className="flex items-center justify-between sm:justify-start gap-3">
              {/* Journal filter */}
              <div className="relative">
                <button
                  onClick={() => setShowJournalFilter(!showJournalFilter)}
                  className="flex items-center gap-2 px-3 sm:px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors text-sm"
                >
                  <Filter className="w-4 h-4" />
                  <span className="hidden xs:inline">論文誌</span> ({selectedJournals.length}/{journals.length})
                  {showJournalFilter ? (
                    <ChevronUp className="w-4 h-4" />
                  ) : (
                    <ChevronDown className="w-4 h-4" />
                  )}
                </button>

                {showJournalFilter && (
                  <div className="absolute top-full left-0 mt-2 w-[calc(100vw-2rem)] sm:w-96 max-w-96 bg-white rounded-xl shadow-xl border border-gray-200 p-3 sm:p-4 z-20">
                    <div className="flex justify-between mb-3">
                      <button
                        onClick={() => setSelectedJournals(journals.map((j) => j.id))}
                        className="text-xs text-gray-600 hover:underline"
                      >
                        すべて選択
                      </button>
                      <button
                        onClick={() => setSelectedJournals([])}
                        className="text-xs text-gray-500 hover:underline"
                      >
                        すべて解除
                      </button>
                    </div>
                    <div className="space-y-1 max-h-64 overflow-y-auto">
                      {journals.map((journal) => (
                        <label
                          key={journal.id}
                          className="flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50 cursor-pointer"
                        >
                          <input
                            type="checkbox"
                            checked={selectedJournals.includes(journal.id)}
                            onChange={() => toggleJournal(journal.id)}
                            className="w-4 h-4 text-gray-600 rounded"
                          />
                          <span className={`w-3 h-3 rounded-full flex-shrink-0 ${journal.color || 'bg-gray-500'}`} />
                          <div className="flex-1 min-w-0">
                            <div className="text-sm font-medium truncate">{journal.name}</div>
                            <div className="text-xs text-gray-500">
                              {journal.paper_count || 0}件
                            </div>
                          </div>
                        </label>
                      ))}
                    </div>
                  </div>
                )}
              </div>

              {/* Tag filter */}
              {allTags.length > 0 && (
                <div className="relative">
                  <button
                    onClick={() => setShowTagFilter(!showTagFilter)}
                    className={`flex items-center gap-2 px-3 sm:px-4 py-2 border rounded-lg hover:bg-gray-50 transition-colors text-sm ${
                      selectedTags.length > 0 ? 'border-gray-500 bg-gray-50 text-gray-700' : 'border-gray-300'
                    }`}
                  >
                    <Tag className="w-4 h-4" />
                    <span className="hidden xs:inline">Tag</span>
                    {selectedTags.length > 0 && (
                      <span className="bg-gray-600 text-white text-xs px-1.5 py-0.5 rounded-full">
                        {selectedTags.length}
                      </span>
                    )}
                    {showTagFilter ? (
                      <ChevronUp className="w-4 h-4" />
                    ) : (
                      <ChevronDown className="w-4 h-4" />
                    )}
                  </button>

                  {showTagFilter && (
                    <div className="absolute top-full left-0 mt-2 w-[calc(100vw-2rem)] sm:w-72 max-w-72 bg-white rounded-xl shadow-xl border border-gray-200 p-3 sm:p-4 z-20">
                      <div className="flex justify-between mb-3">
                        <span className="text-sm font-medium text-gray-700">Tagでフィルター</span>
                        {selectedTags.length > 0 && (
                          <button
                            onClick={clearTagFilter}
                            className="text-xs text-gray-500 hover:text-red-500 flex items-center gap-1"
                          >
                            <X className="w-3 h-3" />
                            解除
                          </button>
                        )}
                      </div>
                      <div className="space-y-1 max-h-64 overflow-y-auto">
                        {allTags.map((tag) => (
                          <div
                            key={tag.id}
                            className="flex items-center gap-2 p-2 rounded-lg hover:bg-gray-50 group"
                          >
                            <label className="flex items-center gap-3 flex-1 min-w-0 cursor-pointer">
                              <input
                                type="checkbox"
                                checked={selectedTags.includes(tag.id)}
                                onChange={() => toggleTag(tag.id)}
                                className="w-4 h-4 text-gray-600 rounded"
                              />
                              <span className={`w-3 h-3 rounded-full flex-shrink-0 ${tag.color}`} />
                              <div className="flex-1 min-w-0">
                                <div className="text-sm font-medium truncate">{tag.name}</div>
                                <div className="text-xs text-gray-500">
                                  {tag.paper_count || 0}件
                                </div>
                              </div>
                            </label>
                            {/* タグ要約ボタン（APIキー設定時のみ） */}
                            {hasAnyApiKey && (tag.paper_count ?? 0) > 0 && (
                              <button
                                onClick={(e) => {
                                  e.stopPropagation();
                                  openTagSummary(tag);
                                  setShowTagFilter(false);
                                }}
                                className="p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-50 rounded opacity-0 group-hover:opacity-100 transition-all"
                                title="タグの論文を要約"
                              >
                                <BookOpen className="w-4 h-4" />
                              </button>
                            )}
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              )}

              {/* Count display (mobile) */}
              <div className="text-sm text-gray-600 font-medium sm:hidden">
                {pagination.total}件
              </div>
            </div>

            {/* Bottom row: date/AI selection (mobile: horizontal) */}
            <div className="flex items-center gap-2 sm:gap-4 flex-wrap">
              {/* Date filter */}
              <div className="flex items-center gap-1.5 sm:gap-2">
                <Calendar className="w-4 h-4 text-gray-500" />
                <select
                  value={dateFilter}
                  onChange={(e: ChangeEvent<HTMLSelectElement>) => setDateFilter(e.target.value)}
                  className="px-2 sm:px-3 py-1.5 sm:py-2 border border-gray-300 rounded-lg text-xs sm:text-sm focus:ring-2 focus:ring-gray-500 focus:border-gray-500"
                >
                  {DATE_FILTERS.map((opt) => (
                    <option key={opt.value} value={opt.value}>
                      {opt.label}
                    </option>
                  ))}
                </select>
              </div>

              {/* Count display (desktop) */}
              <div className="hidden sm:block text-sm text-gray-600 font-medium">
                {pagination.total}件の論文
              </div>
            </div>
          </div>
        </div>

        {/* Paper list */}
        {loading ? (
          <div className="flex items-center justify-center py-12">
            <Loader2 className="w-8 h-8 animate-spin text-gray-600" />
          </div>
        ) : (
          <div className="space-y-4">
            {papers.map((paper) => (
              <PaperCard
                key={paper.id}
                paper={paper}
                onTagsChange={fetchTags}
                hasAnyApiKey={hasAnyApiKey}
              />
            ))}
          </div>
        )}

        {/* Empty state */}
        {!loading && papers.length === 0 && (
          <div className="text-center py-16">
            <FileText className="w-16 h-16 text-gray-300 mx-auto mb-4" />
            <p className="text-gray-500 text-lg">条件に一致する論文がありません</p>
            <p className="text-gray-400 text-sm mt-2">
              論文誌を選択するか，期間を変更してください
            </p>
          </div>
        )}
      </main>

      {/* Click outside to close filter */}
      {(showJournalFilter || showTagFilter) && (
        <div
          className="fixed inset-0 z-10"
          onClick={() => {
            setShowJournalFilter(false);
            setShowTagFilter(false);
          }}
        />
      )}

      {/* Toast notification */}
      {toast.show && (
        <Toast
          message={toast.message}
          type={toast.type}
          onClose={hideToast}
        />
      )}

      {/* Tag Summary Modal */}
      {tagSummaryTag && (
        <TagSummaryModal
          tag={tagSummaryTag}
          onClose={closeTagSummary}
          hasAnyApiKey={hasAnyApiKey}
        />
      )}
    </>
  );
}
