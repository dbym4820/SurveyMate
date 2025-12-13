import { useState, useEffect } from 'react';
import {
  Plus, RefreshCw, Edit,
  ToggleLeft, ToggleRight, Loader2, FileText, Clock, Rss, Sparkles, Copy, Check, ExternalLink
} from 'lucide-react';
import api, { getBasePath } from '../api';
import JournalModal from './JournalModal';
import { RSS_URL_EXAMPLES } from '../constants';
import { useToast } from './Toast';
import type { Journal } from '../types';

export default function JournalManagement(): JSX.Element {
  const { showToast } = useToast();
  const [journals, setJournals] = useState<Journal[]>([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [editingJournal, setEditingJournal] = useState<Journal | null>(null);
  const [showInactive, setShowInactive] = useState(false);
  const [fetchingJournal, setFetchingJournal] = useState<string | null>(null);
  const [fetchingAll, setFetchingAll] = useState(false);
  const [regeneratingJournal, setRegeneratingJournal] = useState<string | null>(null);
  const [copiedFeedUrl, setCopiedFeedUrl] = useState<string | null>(null);

  // RSS配信URLを生成
  const getFeedUrl = (feedToken: string): string => {
    const basePath = getBasePath();
    return `${window.location.origin}${basePath}/rss/${feedToken}`;
  };

  // URLをクリップボードにコピー
  const copyFeedUrl = async (feedToken: string): Promise<void> => {
    const url = getFeedUrl(feedToken);
    try {
      await navigator.clipboard.writeText(url);
      setCopiedFeedUrl(feedToken);
      setTimeout(() => setCopiedFeedUrl(null), 2000);
    } catch (err) {
      console.error('Failed to copy URL:', err);
    }
  };

  // AI生成フィードを再生成
  const handleRegenerateFeed = async (journal: Journal): Promise<void> => {
    setRegeneratingJournal(journal.id);
    try {
      const result = await api.journals.regenerateFeed(journal.id);
      if (result.success) {
        const methodLabel = result.provider === 'selector' ? 'セレクタ解析' : result.provider;
        showToast(`${journal.name}: ${result.papers_count || 0}件の論文を検出（${methodLabel}）`, 'success');
        fetchJournals();
      } else {
        showToast('再生成に失敗しました: ' + (result.error || ''), 'error');
      }
    } catch (error) {
      showToast('再生成に失敗しました: ' + (error as Error).message, 'error');
    } finally {
      setRegeneratingJournal(null);
    }
  };

  useEffect(() => {
    fetchJournals();
  }, [showInactive]);

  // フィード更新イベントをリッスンしてデータを再取得
  useEffect(() => {
    const handleFeedsUpdated = () => {
      fetchJournals();
    };
    window.addEventListener('feeds-updated', handleFeedsUpdated);
    return () => {
      window.removeEventListener('feeds-updated', handleFeedsUpdated);
    };
  }, [showInactive]);

  const fetchJournals = async (): Promise<void> => {
    setLoading(true);
    try {
      const data = await api.journals.list(showInactive);
      if (data.success) {
        setJournals(data.journals);
      }
    } catch (error) {
      console.error('Failed to fetch journals:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSave = (): void => {
    setShowModal(false);
    setEditingJournal(null);
    fetchJournals();
  };

  const handleEdit = (journal: Journal): void => {
    setEditingJournal(journal);
    setShowModal(true);
  };

  const handleDelete = async (journal: Journal): Promise<void> => {
    if (!confirm(`「${journal.name}」を無効化しますか？`)) return;

    try {
      await api.journals.delete(journal.id);
      showToast('論文誌を無効化しました', 'success');
      fetchJournals();
    } catch (error) {
      showToast('無効化に失敗しました: ' + (error as Error).message, 'error');
    }
  };

  const handleActivate = async (journal: Journal): Promise<void> => {
    try {
      await api.journals.activate(journal.id);
      showToast('論文誌を有効化しました', 'success');
      fetchJournals();
    } catch (error) {
      showToast('有効化に失敗しました: ' + (error as Error).message, 'error');
    }
  };

  const handleFetchNow = async (journal: Journal): Promise<void> => {
    setFetchingJournal(journal.id);
    try {
      const data = await api.journals.fetch(journal.id);
      if (data.result.status === 'success') {
        showToast(`${journal.name}: ${data.result.new_papers || 0}件の新規論文を取得`, 'success');
        fetchJournals();
      } else {
        showToast('取得に失敗しました: ' + (data.result.error || ''), 'error');
      }
    } catch (error) {
      showToast('取得に失敗しました: ' + (error as Error).message, 'error');
    } finally {
      setFetchingJournal(null);
    }
  };

  const handleFetchAll = async (): Promise<void> => {
    setFetchingAll(true);
    try {
      const data = await api.journals.fetchAll();
      if (data.success) {
        const { total_new, error_count } = data.summary;
        if (error_count > 0) {
          showToast(`${total_new}件の新規論文を取得（${error_count}件のエラー）`, 'info');
        } else {
          showToast(`${total_new}件の新規論文を取得しました`, 'success');
        }
        fetchJournals();
      }
    } catch (error) {
      showToast('取得に失敗しました: ' + (error as Error).message, 'error');
    } finally {
      setFetchingAll(false);
    }
  };

  return (
    <main className="w-[85%] mx-auto py-6">
      {/* アクションバー */}
      <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-2 text-sm text-gray-600">
          <FileText className="w-4 h-4" />
          {journals.length}件の論文誌
        </div>
        <div className="flex items-center gap-3">
          <button
            onClick={handleFetchAll}
            disabled={fetchingAll || journals.filter(j => j.is_active !== 0 && j.is_active !== false).length === 0}
            className="flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            {fetchingAll ? (
              <Loader2 className="w-4 h-4 animate-spin" />
            ) : (
              <RefreshCw className="w-4 h-4" />
            )}
            {fetchingAll ? '取得中...' : 'すべてフェッチ'}
          </button>
          <button
            onClick={() => {
              setEditingJournal(null);
              setShowModal(true);
            }}
            className="flex items-center gap-2 px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors"
          >
            <Plus className="w-4 h-4" />
            新しい論文誌を追加
          </button>
        </div>
      </div>

      {/* フィルター */}
      <div className="mb-4 flex items-center justify-end">
          <label className="flex items-center gap-2 cursor-pointer">
            <span className="text-sm text-gray-600">無効な論文誌も表示</span>
            <button
              onClick={() => setShowInactive(!showInactive)}
              className={`w-10 h-6 rounded-full transition-colors ${
                showInactive ? 'bg-gray-600' : 'bg-gray-300'
              }`}
            >
              <div
                className={`w-4 h-4 bg-white rounded-full transition-transform m-1 ${
                  showInactive ? 'translate-x-4' : ''
                }`}
              />
            </button>
          </label>
        </div>

        {/* 論文誌リスト */}
        {loading ? (
          <div className="flex items-center justify-center py-12">
            <Loader2 className="w-8 h-8 animate-spin text-gray-600" />
          </div>
        ) : (
          <div className="grid gap-4">
            {journals.map((journal) => (
              <div
                key={journal.id}
                className={`bg-white rounded-xl shadow-sm border p-5 transition-opacity ${
                  journal.is_active === 0 || journal.is_active === false ? 'opacity-60 border-gray-300' : 'border-gray-200'
                }`}
              >
                <div className="flex items-start gap-4">
                  {/* 色バー */}
                  <div className={`w-2 self-stretch rounded-full ${journal.color}`} />

                  {/* 情報 */}
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 mb-1 flex-wrap">
                      <h3 className="font-semibold text-gray-900">{journal.name}</h3>
                      {journal.source_type === 'ai_generated' && (
                        <span className="text-xs px-2 py-0.5 bg-purple-100 text-purple-700 rounded-full flex items-center gap-1">
                          <Sparkles className="w-3 h-3" />
                          AI生成
                        </span>
                      )}
                      {(journal.is_active === 0 || journal.is_active === false) && (
                        <span className="text-xs px-2 py-0.5 bg-red-100 text-red-700 rounded-full">
                          無効
                        </span>
                      )}
                    </div>
                    <div className="flex items-center gap-4 text-xs text-gray-500 flex-wrap">
                      <span className="flex items-center gap-1">
                        <FileText className="w-3 h-3" />
                        {journal.paper_count || 0}件
                      </span>
                      {journal.last_fetched_at && (
                        <span className="flex items-center gap-1">
                          <Clock className="w-3 h-3" />
                          最終取得: {new Date(journal.last_fetched_at).toLocaleString('ja-JP')}
                        </span>
                      )}
                    </div>
                    {/* ソースURL */}
                    <div className="mt-2 text-xs text-gray-400 truncate">
                      {journal.source_type === 'ai_generated' ? (
                        <ExternalLink className="w-3 h-3 inline mr-1" />
                      ) : (
                        <Rss className="w-3 h-3 inline mr-1" />
                      )}
                      {journal.rss_url}
                    </div>
                    {/* AI生成フィードURL */}
                    {journal.source_type === 'ai_generated' && journal.generated_feed?.feed_token && (
                      <div className="mt-2 flex items-center gap-2 overflow-hidden">
                        <div className="flex-1 min-w-0 text-xs bg-gray-50 px-2 py-1 rounded font-mono overflow-hidden">
                          <Rss className="w-3 h-3 inline mr-1 text-orange-500 flex-shrink-0" />
                          <span className="truncate inline-block align-middle max-w-[calc(100%-1rem)]">
                            {getFeedUrl(journal.generated_feed.feed_token)}
                          </span>
                        </div>
                        <button
                          onClick={() => copyFeedUrl(journal.generated_feed!.feed_token)}
                          className="flex-shrink-0 p-1 text-gray-400 hover:text-gray-600 transition-colors"
                          title="URLをコピー"
                        >
                          {copiedFeedUrl === journal.generated_feed.feed_token ? (
                            <Check className="w-4 h-4 text-green-500" />
                          ) : (
                            <Copy className="w-4 h-4" />
                          )}
                        </button>
                      </div>
                    )}
                  </div>

                  {/* アクション */}
                  <div className="flex items-center gap-2">
                    {journal.is_active !== 0 && journal.is_active !== false && (
                      journal.source_type === 'ai_generated' ? (
                        <button
                          onClick={() => handleRegenerateFeed(journal)}
                          disabled={regeneratingJournal === journal.id}
                          className="p-2 text-purple-600 hover:text-purple-700 hover:bg-purple-50 rounded-lg transition-colors disabled:opacity-50"
                          title="AIで再生成"
                        >
                          {regeneratingJournal === journal.id ? (
                            <Loader2 className="w-4 h-4 animate-spin" />
                          ) : (
                            <Sparkles className="w-4 h-4" />
                          )}
                        </button>
                      ) : (
                        <button
                          onClick={() => handleFetchNow(journal)}
                          disabled={fetchingJournal === journal.id}
                          className="p-2 text-gray-600 hover:text-gray-600 hover:bg-gray-50 rounded-lg transition-colors disabled:opacity-50"
                          title="今すぐ取得"
                        >
                          {fetchingJournal === journal.id ? (
                            <Loader2 className="w-4 h-4 animate-spin" />
                          ) : (
                            <RefreshCw className="w-4 h-4" />
                          )}
                        </button>
                      )
                    )}
                    <button
                      onClick={() => handleEdit(journal)}
                      className="p-2 text-gray-600 hover:text-gray-600 hover:bg-gray-50 rounded-lg transition-colors"
                      title="編集"
                    >
                      <Edit className="w-4 h-4" />
                    </button>
                    {journal.is_active === 0 || journal.is_active === false ? (
                      <button
                        onClick={() => handleActivate(journal)}
                        className="p-2 text-gray-400 hover:text-green-600 hover:bg-green-50 rounded-lg transition-colors"
                        title="有効化"
                      >
                        <ToggleLeft className="w-4 h-4" />
                      </button>
                    ) : (
                      <button
                        onClick={() => handleDelete(journal)}
                        className="p-2 text-green-600 hover:text-gray-400 hover:bg-gray-50 rounded-lg transition-colors"
                        title="無効化"
                      >
                        <ToggleRight className="w-4 h-4" />
                      </button>
                    )}
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}

        {/* RSS URL形式ヘルプ */}
        <div className="mt-8 p-6 bg-white rounded-xl shadow-sm border border-gray-200">
          <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <Rss className="w-5 h-5 text-orange-500" />
            主要出版社のRSS URL形式
          </h2>
          <div className="space-y-3 text-sm">
            {RSS_URL_EXAMPLES.map((item) => (
              <div key={item.publisher} className="p-3 bg-gray-50 rounded-lg">
                <p className="font-medium text-gray-700 mb-1">{item.publisher}</p>
                <code className="text-xs text-gray-600 break-all">{item.format}</code>
              </div>
            ))}
          </div>
        </div>

        {/* モーダル */}
        {showModal && (
          <JournalModal
            journal={editingJournal}
            onSave={handleSave}
            onClose={() => {
              setShowModal(false);
              setEditingJournal(null);
            }}
          />
        )}
      </main>
  );
}
