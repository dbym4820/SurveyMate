import { useState, useEffect, useCallback, ChangeEvent } from 'react';
import {
  BookOpen, RefreshCw, Settings, User as UserIcon, LogOut, Filter,
  Calendar, Sparkles, ChevronDown, ChevronUp, Loader2
} from 'lucide-react';
import api from '../api';
import PaperCard from './PaperCard';
import JournalManagement from './JournalManagement';
import { DATE_FILTERS } from '../constants';
import type { User, Paper, Journal, AIProvider, Pagination } from '../types';

interface DashboardProps {
  user: User;
  onLogout: () => void;
}

export default function Dashboard({ user, onLogout }: DashboardProps): JSX.Element {
  const [currentPage, setCurrentPage] = useState<'papers' | 'journals'>('papers');
  const [papers, setPapers] = useState<Paper[]>([]);
  const [journals, setJournals] = useState<Journal[]>([]);
  const [selectedJournals, setSelectedJournals] = useState<string[]>([]);
  const [dateFilter, setDateFilter] = useState('month');
  const [showJournalFilter, setShowJournalFilter] = useState(false);
  const [loading, setLoading] = useState(true);
  const [pagination, setPagination] = useState<Pagination>({ total: 0, limit: 0, offset: 0, hasMore: false });
  const [isRefreshing, setIsRefreshing] = useState(false);

  // AI関連
  const [aiProviders, setAiProviders] = useState<AIProvider[]>([]);
  const [selectedProvider, setSelectedProvider] = useState('claude');

  // 論文誌一覧を取得
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

  // AIプロバイダを取得
  useEffect(() => {
    async function fetchProviders(): Promise<void> {
      try {
        const data = await api.summaries.providers();
        if (data.success) {
          setAiProviders(data.providers);
          setSelectedProvider(data.current);
        }
      } catch (error) {
        console.error('Failed to fetch providers:', error);
      }
    }
    fetchProviders();
  }, []);

  // 初回読み込み
  useEffect(() => {
    fetchJournals();
  }, [fetchJournals]);

  // 日付範囲を計算
  const getDateFrom = useCallback((): string | undefined => {
    const filter = DATE_FILTERS.find((f) => f.value === dateFilter);
    if (!filter?.days) return undefined;
    const date = new Date();
    date.setDate(date.getDate() - filter.days);
    return date.toISOString().split('T')[0];
  }, [dateFilter]);

  // 論文を取得
  useEffect(() => {
    async function fetchPapers(): Promise<void> {
      if (selectedJournals.length === 0) {
        setPapers([]);
        return;
      }

      setLoading(true);
      try {
        const data = await api.papers.list({
          journals: selectedJournals,
          dateFrom: getDateFrom(),
          limit: 100,
        });
        if (data.success) {
          setPapers(data.papers);
          setPagination(data.pagination);
        }
      } catch (error) {
        console.error('Failed to fetch papers:', error);
      } finally {
        setLoading(false);
      }
    }

    if (currentPage === 'papers') {
      fetchPapers();
    }
  }, [selectedJournals, dateFilter, getDateFrom, currentPage]);

  // 論文誌選択をトグル
  const toggleJournal = (journalId: string): void => {
    setSelectedJournals((prev) =>
      prev.includes(journalId)
        ? prev.filter((id) => id !== journalId)
        : [...prev, journalId]
    );
  };

  // 手動フェッチ（管理者のみ）
  const handleManualFetch = async (): Promise<void> => {
    if (!user.isAdmin) return;
    setIsRefreshing(true);
    try {
      const data = await api.admin.runScheduler();
      if (data.success) {
        alert(`取得完了: ${data.result.newPapers}件の新規論文`);
        // 論文を再取得
        const papersData = await api.papers.list({
          journals: selectedJournals,
          dateFrom: getDateFrom(),
          limit: 100,
        });
        if (papersData.success) {
          setPapers(papersData.papers);
          setPagination(papersData.pagination);
        }
      }
    } catch (error) {
      alert('取得に失敗しました: ' + (error as Error).message);
    } finally {
      setIsRefreshing(false);
    }
  };

  // ログアウト
  const handleLogout = async (): Promise<void> => {
    try {
      await api.auth.logout();
      onLogout();
    } catch (error) {
      console.error('Logout failed:', error);
    }
  };

  // 論文誌管理画面
  if (currentPage === 'journals') {
    return (
      <JournalManagement
        onBack={() => {
          setCurrentPage('papers');
          fetchJournals();
        }}
      />
    );
  }

  return (
    <div className="min-h-screen bg-gray-50">
      {/* ヘッダー */}
      <header className="bg-white border-b border-gray-200 sticky top-0 z-10 shadow-sm">
        <div className="max-w-6xl mx-auto px-4 py-4">
          <div className="flex items-center justify-between">
            {/* ロゴ */}
            <div className="flex items-center gap-3">
              <div className="p-2 bg-indigo-100 rounded-lg">
                <BookOpen className="w-6 h-6 text-indigo-600" />
              </div>
              <div>
                <h1 className="text-xl font-bold text-gray-900">学術論文RSSリーダー</h1>
                <p className="text-sm text-gray-500">AI in Education / Cognitive Science</p>
              </div>
            </div>

            {/* アクション */}
            <div className="flex items-center gap-3">
              <button
                onClick={() => setCurrentPage('journals')}
                className="flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
              >
                <Settings className="w-4 h-4" />
                論文誌管理
              </button>

              {user.isAdmin && (
                <button
                  onClick={handleManualFetch}
                  disabled={isRefreshing}
                  className="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors"
                >
                  <RefreshCw className={`w-4 h-4 ${isRefreshing ? 'animate-spin' : ''}`} />
                  {isRefreshing ? '取得中...' : 'フィード取得'}
                </button>
              )}

              <div className="flex items-center gap-2 px-3 py-2 bg-gray-100 rounded-lg">
                <UserIcon className="w-4 h-4 text-gray-600" />
                <span className="text-sm font-medium text-gray-700">{user.username}</span>
                {user.isAdmin && (
                  <span className="text-xs bg-indigo-100 text-indigo-700 px-1.5 py-0.5 rounded">
                    管理者
                  </span>
                )}
              </div>

              <button
                onClick={handleLogout}
                className="p-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors"
                title="ログアウト"
              >
                <LogOut className="w-5 h-5" />
              </button>
            </div>
          </div>
        </div>
      </header>

      {/* メインコンテンツ */}
      <main className="max-w-6xl mx-auto px-4 py-6">
        {/* フィルターバー */}
        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6">
          <div className="flex flex-wrap gap-4 items-center justify-between">
            {/* 論文誌フィルター */}
            <div className="relative">
              <button
                onClick={() => setShowJournalFilter(!showJournalFilter)}
                className="flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
              >
                <Filter className="w-4 h-4" />
                <span>論文誌 ({selectedJournals.length}/{journals.length})</span>
                {showJournalFilter ? (
                  <ChevronUp className="w-4 h-4" />
                ) : (
                  <ChevronDown className="w-4 h-4" />
                )}
              </button>

              {showJournalFilter && (
                <div className="absolute top-full left-0 mt-2 w-96 bg-white rounded-xl shadow-xl border border-gray-200 p-4 z-20">
                  <div className="flex justify-between mb-3">
                    <button
                      onClick={() => setSelectedJournals(journals.map((j) => j.id))}
                      className="text-xs text-indigo-600 hover:underline"
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
                          className="w-4 h-4 text-indigo-600 rounded"
                        />
                        <span className={`w-3 h-3 rounded-full ${journal.color}`} />
                        <div className="flex-1 min-w-0">
                          <div className="text-sm font-medium truncate">{journal.name}</div>
                          <div className="text-xs text-gray-500">
                            {journal.category} • {journal.paper_count || 0}件
                          </div>
                        </div>
                      </label>
                    ))}
                  </div>
                </div>
              )}
            </div>

            {/* 日付フィルター */}
            <div className="flex items-center gap-2">
              <Calendar className="w-4 h-4 text-gray-500" />
              <select
                value={dateFilter}
                onChange={(e: ChangeEvent<HTMLSelectElement>) => setDateFilter(e.target.value)}
                className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              >
                {DATE_FILTERS.map((opt) => (
                  <option key={opt.value} value={opt.value}>
                    {opt.label}
                  </option>
                ))}
              </select>
            </div>

            {/* AIプロバイダ選択 */}
            {aiProviders.length > 0 && (
              <div className="flex items-center gap-2">
                <Sparkles className="w-4 h-4 text-gray-500" />
                <select
                  value={selectedProvider}
                  onChange={(e: ChangeEvent<HTMLSelectElement>) => setSelectedProvider(e.target.value)}
                  className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                >
                  {aiProviders.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name}
                    </option>
                  ))}
                </select>
              </div>
            )}

            {/* 件数表示 */}
            <div className="text-sm text-gray-600 font-medium">
              {pagination.total}件の論文
            </div>
          </div>
        </div>

        {/* 論文リスト */}
        {loading ? (
          <div className="flex items-center justify-center py-12">
            <Loader2 className="w-8 h-8 animate-spin text-indigo-600" />
          </div>
        ) : (
          <div className="space-y-4">
            {papers.map((paper) => (
              <PaperCard
                key={paper.id}
                paper={paper}
                selectedProvider={selectedProvider}
              />
            ))}
          </div>
        )}

        {/* 空状態 */}
        {!loading && papers.length === 0 && (
          <div className="text-center py-16">
            <BookOpen className="w-16 h-16 text-gray-300 mx-auto mb-4" />
            <p className="text-gray-500 text-lg">条件に一致する論文がありません</p>
            <p className="text-gray-400 text-sm mt-2">
              論文誌を選択するか、期間を変更してください
            </p>
          </div>
        )}
      </main>

      {/* フィルターのクリックアウトで閉じる */}
      {showJournalFilter && (
        <div
          className="fixed inset-0 z-10"
          onClick={() => setShowJournalFilter(false)}
        />
      )}
    </div>
  );
}
