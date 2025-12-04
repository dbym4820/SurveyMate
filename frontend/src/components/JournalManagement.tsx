import { useState, useEffect } from 'react';
import {
  Settings, Plus, Database, ChevronLeft, RefreshCw, Edit, Trash2,
  ToggleRight, Loader2, Globe, FileText, Clock, Rss
} from 'lucide-react';
import api from '../api';
import JournalModal from './JournalModal';
import { RSS_URL_EXAMPLES } from '../constants';
import type { Journal } from '../types';

interface JournalManagementProps {
  onBack: () => void;
}

export default function JournalManagement({ onBack }: JournalManagementProps): JSX.Element {
  const [journals, setJournals] = useState<Journal[]>([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [editingJournal, setEditingJournal] = useState<Journal | null>(null);
  const [showInactive, setShowInactive] = useState(false);
  const [fetchingJournal, setFetchingJournal] = useState<string | null>(null);

  useEffect(() => {
    fetchJournals();
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
      fetchJournals();
    } catch (error) {
      alert('無効化に失敗しました: ' + (error as Error).message);
    }
  };

  const handleActivate = async (journal: Journal): Promise<void> => {
    try {
      await api.journals.activate(journal.id);
      fetchJournals();
    } catch (error) {
      alert('有効化に失敗しました: ' + (error as Error).message);
    }
  };

  const handleFetchNow = async (journal: Journal): Promise<void> => {
    setFetchingJournal(journal.id);
    try {
      const data = await api.journals.fetch(journal.id);
      if (data.success) {
        alert(`${journal.name}: ${data.result.newPapers || 0}件の新規論文を取得しました`);
        fetchJournals();
      } else {
        alert('取得に失敗しました: ' + (data.result.error || ''));
      }
    } catch (error) {
      alert('取得に失敗しました: ' + (error as Error).message);
    } finally {
      setFetchingJournal(null);
    }
  };

  return (
    <div className="min-h-screen bg-gray-50">
      {/* ヘッダー */}
      <header className="bg-white border-b border-gray-200 sticky top-0 z-10 shadow-sm">
        <div className="max-w-6xl mx-auto px-4 py-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              <button
                onClick={onBack}
                className="p-2 hover:bg-gray-100 rounded-lg transition-colors"
              >
                <ChevronLeft className="w-5 h-5" />
              </button>
              <div className="p-2 bg-indigo-100 rounded-lg">
                <Settings className="w-6 h-6 text-indigo-600" />
              </div>
              <div>
                <h1 className="text-xl font-bold text-gray-900">論文誌管理</h1>
                <p className="text-sm text-gray-500">RSSフィードの追加・編集・削除</p>
              </div>
            </div>
            <button
              onClick={() => {
                setEditingJournal(null);
                setShowModal(true);
              }}
              className="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors"
            >
              <Plus className="w-4 h-4" />
              新しい論文誌を追加
            </button>
          </div>
        </div>
      </header>

      {/* メインコンテンツ */}
      <main className="max-w-6xl mx-auto px-4 py-6">
        {/* フィルター */}
        <div className="mb-6 flex items-center justify-between">
          <div className="flex items-center gap-2 text-sm text-gray-600">
            <Database className="w-4 h-4" />
            {journals.length}件の論文誌
          </div>
          <label className="flex items-center gap-2 cursor-pointer">
            <span className="text-sm text-gray-600">無効な論文誌も表示</span>
            <button
              onClick={() => setShowInactive(!showInactive)}
              className={`w-10 h-6 rounded-full transition-colors ${
                showInactive ? 'bg-indigo-600' : 'bg-gray-300'
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
            <Loader2 className="w-8 h-8 animate-spin text-indigo-600" />
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
                      <span className="text-xs px-2 py-0.5 bg-gray-100 rounded-full text-gray-600">
                        {journal.category}
                      </span>
                      {(journal.is_active === 0 || journal.is_active === false) && (
                        <span className="text-xs px-2 py-0.5 bg-red-100 text-red-700 rounded-full">
                          無効
                        </span>
                      )}
                    </div>
                    <p className="text-sm text-gray-600 mb-1">{journal.full_name}</p>
                    <div className="flex items-center gap-4 text-xs text-gray-500 flex-wrap">
                      <span className="flex items-center gap-1">
                        <Globe className="w-3 h-3" />
                        {journal.publisher}
                      </span>
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
                    <div className="mt-2 text-xs text-gray-400 truncate">
                      <Rss className="w-3 h-3 inline mr-1" />
                      {journal.rss_url}
                    </div>
                  </div>

                  {/* アクション */}
                  <div className="flex items-center gap-2">
                    {journal.is_active !== 0 && journal.is_active !== false && (
                      <button
                        onClick={() => handleFetchNow(journal)}
                        disabled={fetchingJournal === journal.id}
                        className="p-2 text-gray-600 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors disabled:opacity-50"
                        title="今すぐ取得"
                      >
                        {fetchingJournal === journal.id ? (
                          <Loader2 className="w-4 h-4 animate-spin" />
                        ) : (
                          <RefreshCw className="w-4 h-4" />
                        )}
                      </button>
                    )}
                    <button
                      onClick={() => handleEdit(journal)}
                      className="p-2 text-gray-600 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
                      title="編集"
                    >
                      <Edit className="w-4 h-4" />
                    </button>
                    {journal.is_active === 0 || journal.is_active === false ? (
                      <button
                        onClick={() => handleActivate(journal)}
                        className="p-2 text-gray-600 hover:text-green-600 hover:bg-green-50 rounded-lg transition-colors"
                        title="有効化"
                      >
                        <ToggleRight className="w-4 h-4" />
                      </button>
                    ) : (
                      <button
                        onClick={() => handleDelete(journal)}
                        className="p-2 text-gray-600 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                        title="無効化"
                      >
                        <Trash2 className="w-4 h-4" />
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
      </main>

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
    </div>
  );
}
