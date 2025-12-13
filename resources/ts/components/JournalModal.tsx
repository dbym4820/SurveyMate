import { useState, useEffect, FormEvent, ChangeEvent } from 'react';
import { Plus, Edit, X, Check, Loader2, AlertCircle, TestTube, Sparkles, Rss, Copy, ArrowRight, FileText, Home, Search, File, HelpCircle } from 'lucide-react';
import api, { getBasePath } from '../api';
import { AVAILABLE_COLORS } from '../constants';
import { useToast } from './Toast';
import type { Journal, JournalFormData, RssTestResult, PageTestResult } from '../types';

// ページ種類のラベル
const PAGE_TYPE_LABELS: Record<string, { label: string; icon: JSX.Element }> = {
  article_list: { label: '論文一覧', icon: <FileText className="w-4 h-4" /> },
  journal_home: { label: '雑誌トップ', icon: <Home className="w-4 h-4" /> },
  article_detail: { label: '個別論文', icon: <File className="w-4 h-4" /> },
  search_results: { label: '検索結果', icon: <Search className="w-4 h-4" /> },
  other: { label: 'その他', icon: <HelpCircle className="w-4 h-4" /> },
  unknown: { label: '不明', icon: <HelpCircle className="w-4 h-4" /> },
};

interface JournalModalProps {
  journal: Journal | null;
  onSave: () => void;
  onClose: () => void;
}

export default function JournalModal({ journal, onSave, onClose }: JournalModalProps): JSX.Element {
  const { showToast } = useToast();
  const isEdit = !!journal;

  const [formData, setFormData] = useState<JournalFormData>({
    name: journal?.name || '',
    rssUrl: journal?.rss_url || '',
    color: journal?.color || 'bg-blue-500',
    sourceType: journal?.source_type || 'rss',
  });

  const [testing, setTesting] = useState(false);
  const [testResult, setTestResult] = useState<RssTestResult | null>(null);
  const [pageTestResult, setPageTestResult] = useState<PageTestResult | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [hasApiKey, setHasApiKey] = useState<boolean | null>(null);
  const [copiedUrl, setCopiedUrl] = useState(false);

  // 生成されたRSS配信URLを取得
  const getGeneratedFeedUrl = (): string | null => {
    if (!journal?.generated_feed?.feed_token) return null;
    const basePath = getBasePath();
    return `${window.location.origin}${basePath}/rss/${journal.generated_feed.feed_token}`;
  };

  // URLをクリップボードにコピー
  const copyFeedUrl = async (): Promise<void> => {
    const url = getGeneratedFeedUrl();
    if (!url) return;
    try {
      await navigator.clipboard.writeText(url);
      setCopiedUrl(true);
      setTimeout(() => setCopiedUrl(false), 2000);
    } catch (err) {
      console.error('Failed to copy URL:', err);
    }
  };

  // APIキー設定状態を確認
  useEffect(() => {
    const checkApiKeys = async (): Promise<void> => {
      try {
        const settings = await api.settings.getApi();
        setHasApiKey(settings.claude_api_key_set || settings.openai_api_key_set);
      } catch {
        setHasApiKey(false);
      }
    };
    checkApiKeys();
  }, []);

  const handleChange = (field: keyof JournalFormData, value: string): void => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    setError('');
  };

  const handleTestRss = async (): Promise<void> => {
    if (!formData.rssUrl) {
      setError('RSS URLを入力してください');
      return;
    }

    setTesting(true);
    setTestResult(null);
    setPageTestResult(null);
    setError('');

    try {
      const data = await api.journals.testRss(formData.rssUrl);
      if (data.success) {
        setTestResult(data);
      } else {
        setError(data.error || 'RSSフィードの取得に失敗しました');
      }
    } catch (err) {
      setError((err as Error).message || 'RSSフィードのテストに失敗しました');
    } finally {
      setTesting(false);
    }
  };

  const handleTestPage = async (): Promise<void> => {
    if (!formData.rssUrl) {
      setError('ページURLを入力してください');
      return;
    }

    setTesting(true);
    setTestResult(null);
    setPageTestResult(null);
    setError('');

    try {
      const data = await api.journals.testPage(formData.rssUrl);
      if (data.success) {
        setPageTestResult(data);
      } else {
        setError(data.error || 'ページの解析に失敗しました');
      }
    } catch (err) {
      setError((err as Error).message || 'ページのテストに失敗しました');
    } finally {
      setTesting(false);
    }
  };

  const handleSubmit = async (e: FormEvent<HTMLFormElement>): Promise<void> => {
    e.preventDefault();

    const urlLabel = formData.sourceType === 'ai_generated' ? 'ページURL' : 'RSS URL';
    if (!formData.name || !formData.rssUrl) {
      setError(`論文誌名と${urlLabel}は必須です`);
      return;
    }

    // AI生成モードでAPIキーがない場合
    if (formData.sourceType === 'ai_generated' && !hasApiKey) {
      setError('AI生成RSSを使用するには，設定画面でAPIキーを登録してください');
      return;
    }

    setSaving(true);
    setError('');

    try {
      if (isEdit && journal) {
        await api.journals.update(journal.id, formData);
        showToast('論文誌を更新しました', 'success');
      } else {
        const result = await api.journals.create(formData);
        // 初回フェッチ結果を通知
        if (result.fetch_result) {
          const fr = result.fetch_result;
          if (fr.status === 'success') {
            const message = formData.sourceType === 'ai_generated'
              ? `論文誌を追加しました．AI解析: ${fr.new_papers || 0}件の論文を検出`
              : `論文誌を追加しました．初回取得: ${fr.new_papers || 0}件の新規論文`;
            showToast(message, 'success');
          } else {
            showToast(`論文誌を追加しましたが，初回取得に失敗しました`, 'info');
          }
        } else {
          showToast('論文誌を追加しました', 'success');
        }
      }
      onSave();
    } catch (err) {
      setError((err as Error).message || '保存に失敗しました');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        {/* ヘッダー */}
        <div className="p-6 border-b border-gray-200">
          <div className="flex items-center justify-between">
            <h2 className="text-xl font-bold text-gray-900 flex items-center gap-2">
              {isEdit ? <Edit className="w-5 h-5" /> : <Plus className="w-5 h-5" />}
              {isEdit ? '論文誌を編集' : '新しい論文誌を追加'}
            </h2>
            <button
              onClick={onClose}
              className="p-2 hover:bg-gray-100 rounded-lg transition-colors"
            >
              <X className="w-5 h-5" />
            </button>
          </div>
        </div>

        {/* フォーム */}
        <form onSubmit={handleSubmit} className="p-6 space-y-5">
          {error && (
            <div className="p-3 bg-red-50 border border-red-200 rounded-lg flex items-center gap-2 text-red-700">
              <AlertCircle className="w-4 h-4 flex-shrink-0" />
              <span className="text-sm">{error}</span>
            </div>
          )}

          {/* 論文誌名 */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              論文誌名 <span className="text-red-500">*</span>
            </label>
            <input
              type="text"
              value={formData.name}
              onChange={(e: ChangeEvent<HTMLInputElement>) => handleChange('name', e.target.value)}
              placeholder="例: International Journal of Artificial Intelligence in Education"
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500"
            />
            <p className="text-xs text-gray-500 mt-1">論文誌の正式名称（IDは自動生成されます）</p>
          </div>

          {/* ソースタイプ選択（新規追加時のみ） */}
          {!isEdit && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                フィードソース
              </label>
              <div className="flex gap-4">
                <label
                  className={`flex-1 flex items-center gap-3 p-3 border rounded-lg cursor-pointer transition-colors ${
                    formData.sourceType === 'rss'
                      ? 'border-gray-500 bg-gray-50'
                      : 'border-gray-200 hover:bg-gray-50'
                  }`}
                >
                  <input
                    type="radio"
                    name="sourceType"
                    value="rss"
                    checked={formData.sourceType === 'rss'}
                    onChange={() => {
                      handleChange('sourceType', 'rss');
                      setTestResult(null);
                      setPageTestResult(null);
                    }}
                    className="sr-only"
                  />
                  <Rss className={`w-5 h-5 ${formData.sourceType === 'rss' ? 'text-gray-600' : 'text-gray-400'}`} />
                  <div>
                    <p className="font-medium text-gray-900">RSSフィード</p>
                    <p className="text-xs text-gray-500">既存のRSSフィードから取得</p>
                  </div>
                </label>
                <label
                  className={`flex-1 flex items-center gap-3 p-3 border rounded-lg cursor-pointer transition-colors ${
                    formData.sourceType === 'ai_generated'
                      ? 'border-gray-500 bg-gray-50'
                      : 'border-gray-200 hover:bg-gray-50'
                  } ${!hasApiKey ? 'opacity-50 cursor-not-allowed' : ''}`}
                >
                  <input
                    type="radio"
                    name="sourceType"
                    value="ai_generated"
                    checked={formData.sourceType === 'ai_generated'}
                    onChange={() => {
                      if (hasApiKey) {
                        handleChange('sourceType', 'ai_generated');
                        setTestResult(null);
                        setPageTestResult(null);
                      }
                    }}
                    disabled={!hasApiKey}
                    className="sr-only"
                  />
                  <Sparkles className={`w-5 h-5 ${formData.sourceType === 'ai_generated' ? 'text-gray-600' : 'text-gray-400'}`} />
                  <div>
                    <p className="font-medium text-gray-900">AI生成</p>
                    <p className="text-xs text-gray-500">
                      {hasApiKey ? 'ページをAIで解析して生成' : 'APIキー設定が必要'}
                    </p>
                  </div>
                </label>
              </div>
              {formData.sourceType === 'ai_generated' && (
                <p className="text-xs text-amber-600 mt-2 flex items-center gap-1">
                  <AlertCircle className="w-3 h-3" />
                  AI生成RSSはページ構造によって精度が異なります．テストで確認してください．
                </p>
              )}
            </div>
          )}

          {/* RSS URL / ページURL */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              {formData.sourceType === 'ai_generated' ? 'ページURL' : 'RSS URL'} <span className="text-red-500">*</span>
            </label>
            <div className="flex gap-2">
              <input
                type="url"
                value={formData.rssUrl}
                onChange={(e: ChangeEvent<HTMLInputElement>) => handleChange('rssUrl', e.target.value)}
                placeholder={formData.sourceType === 'ai_generated' ? '論文一覧ページのURL' : 'https://...'}
                className="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500"
              />
              <button
                type="button"
                onClick={formData.sourceType === 'ai_generated' ? handleTestPage : handleTestRss}
                disabled={testing}
                className="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg flex items-center gap-2 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
              >
                {testing ? (
                  <Loader2 className="w-4 h-4 animate-spin" />
                ) : formData.sourceType === 'ai_generated' ? (
                  <Sparkles className="w-4 h-4" />
                ) : (
                  <TestTube className="w-4 h-4" />
                )}
                テスト
              </button>
            </div>
            {formData.sourceType === 'ai_generated' && (
              <p className="text-xs text-gray-500 mt-1">論文一覧が表示されているWebページのURL</p>
            )}
          </div>

          {/* RSSテスト結果 */}
          {testResult && testResult.success && (
            <div className="p-4 bg-green-50 border border-green-200 rounded-lg">
              <div className="flex items-center gap-2 text-green-700 font-medium mb-2">
                <Check className="w-4 h-4" />
                RSSフィード取得成功
              </div>
              <div className="text-sm text-green-800 space-y-1">
                <p>フィードタイトル: {testResult.feedTitle}</p>
                <p>記事数: {testResult.itemCount}件</p>
                {testResult.sampleItems && testResult.sampleItems.length > 0 && (
                  <div className="mt-2">
                    <p className="font-medium">サンプル記事:</p>
                    <ul className="list-disc list-inside text-xs mt-1 space-y-1">
                      {testResult.sampleItems.map((item, i) => (
                        <li key={i} className="truncate">{item.title}</li>
                      ))}
                    </ul>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* AIページ解析結果 */}
          {pageTestResult && pageTestResult.success && (
            <div className="p-4 bg-green-50 border border-green-200 rounded-lg">
              <div className="flex items-center gap-2 text-green-700 font-medium mb-2">
                <Sparkles className="w-4 h-4" />
                ページ構造の解析成功（{pageTestResult.provider}）
              </div>
              <div className="text-sm text-green-800 space-y-1">
                {/* ページ種類判定 */}
                {pageTestResult.page_type && (
                  <div className="flex items-center gap-2 mb-2 pb-2 border-b border-green-200">
                    {PAGE_TYPE_LABELS[pageTestResult.page_type]?.icon || PAGE_TYPE_LABELS.unknown.icon}
                    <span className="font-medium">
                      ページ種類: {PAGE_TYPE_LABELS[pageTestResult.page_type]?.label || pageTestResult.page_type}
                    </span>
                    {pageTestResult.page_type_reason && (
                      <span className="text-xs text-green-600">（{pageTestResult.page_type_reason}）</span>
                    )}
                  </div>
                )}
                {/* リダイレクト履歴 */}
                {pageTestResult.redirect_history && pageTestResult.redirect_history.length > 0 && (
                  <div className="mb-2 pb-2 border-b border-green-200">
                    <p className="text-xs font-medium text-green-700 mb-1">自動リダイレクト:</p>
                    <div className="space-y-1">
                      {pageTestResult.redirect_history.map((redirect, i) => (
                        <div key={i} className="flex items-center gap-1 text-xs text-green-600">
                          <span className="truncate max-w-[150px]" title={redirect.from}>{new URL(redirect.from).pathname}</span>
                          <ArrowRight className="w-3 h-3 flex-shrink-0" />
                          <span className="truncate max-w-[150px]" title={redirect.to}>{new URL(redirect.to).pathname}</span>
                          <span className="text-green-500">({PAGE_TYPE_LABELS[redirect.page_type]?.label || redirect.page_type})</span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
                <p>検出論文数: {pageTestResult.sample_papers?.length || 0}件</p>
                {pageTestResult.page_size && (
                  <p className="text-xs text-green-600">
                    ページサイズ: {Math.round(pageTestResult.page_size.original / 1024)}KB → {Math.round(pageTestResult.page_size.cleaned / 1024)}KB（最適化後）
                  </p>
                )}
                {pageTestResult.selectors && (
                  <div className="mt-2 text-xs text-green-600">
                    <p className="font-medium text-green-700">抽出設定:</p>
                    <p>タイトル: {pageTestResult.selectors.title || '未検出'}</p>
                    <p>URL: {pageTestResult.selectors.url || '未検出'}</p>
                    {pageTestResult.selectors.doi && (
                      <p>DOI: {pageTestResult.selectors.doi}</p>
                    )}
                  </div>
                )}
                {pageTestResult.sample_papers && pageTestResult.sample_papers.length > 0 && (
                  <div className="mt-2">
                    <p className="font-medium">検出された論文（最大5件）:</p>
                    <ul className="list-disc list-inside text-xs mt-1 space-y-1 max-h-40 overflow-y-auto">
                      {pageTestResult.sample_papers.slice(0, 5).map((paper, i) => (
                        <li key={i} className="truncate">
                          {paper.title}
                          {paper.doi && <span className="text-green-500 ml-1">[DOI: {paper.doi}]</span>}
                        </li>
                      ))}
                    </ul>
                    {pageTestResult.sample_papers.length > 5 && (
                      <p className="text-xs text-green-600 mt-1">...他 {pageTestResult.sample_papers.length - 5}件</p>
                    )}
                  </div>
                )}
              </div>
            </div>
          )}

          {/* ページ解析失敗時（リダイレクト提案あり） */}
          {pageTestResult && !pageTestResult.success && pageTestResult.article_list_url && (
            <div className="p-4 bg-amber-50 border border-amber-200 rounded-lg">
              <div className="flex items-center gap-2 text-amber-700 font-medium mb-2">
                <AlertCircle className="w-4 h-4" />
                論文一覧ページが見つかりました
              </div>
              <div className="text-sm text-amber-800 space-y-2">
                {pageTestResult.page_type && (
                  <div className="flex items-center gap-2">
                    {PAGE_TYPE_LABELS[pageTestResult.page_type]?.icon || PAGE_TYPE_LABELS.unknown.icon}
                    <span>現在のページ: {PAGE_TYPE_LABELS[pageTestResult.page_type]?.label || pageTestResult.page_type}</span>
                  </div>
                )}
                <p className="text-xs">{pageTestResult.error}</p>
                <div className="mt-2">
                  <p className="text-xs font-medium mb-1">推奨URL:</p>
                  <div className="flex items-center gap-2">
                    <code className="text-xs bg-amber-100 px-2 py-1 rounded break-all flex-1">
                      {pageTestResult.article_list_url}
                    </code>
                    <button
                      type="button"
                      onClick={() => {
                        handleChange('rssUrl', pageTestResult.article_list_url!);
                        setPageTestResult(null);
                      }}
                      className="px-3 py-1 text-xs bg-amber-600 text-white rounded hover:bg-amber-700 transition-colors flex-shrink-0"
                    >
                      このURLを使用
                    </button>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* AI生成RSS配信URL（編集時のみ，読み取り専用） */}
          {isEdit && journal?.source_type === 'ai_generated' && getGeneratedFeedUrl() && (
            <div className="overflow-hidden">
              <label className="block text-sm font-medium text-gray-700 mb-1">
                RSS配信URL
                <span className="ml-2 text-xs font-normal text-gray-500">（自動生成・変更不可）</span>
              </label>
              <div className="flex items-center gap-2">
                <div className="flex-1 min-w-0 flex items-center gap-2 px-3 py-2 bg-gray-100 border border-gray-200 rounded-lg text-sm text-gray-600 font-mono overflow-hidden">
                  <Rss className="w-4 h-4 text-orange-500 flex-shrink-0" />
                  <span className="truncate block">{getGeneratedFeedUrl()}</span>
                </div>
                <button
                  type="button"
                  onClick={copyFeedUrl}
                  className="flex-shrink-0 p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors"
                  title="URLをコピー"
                >
                  {copiedUrl ? (
                    <Check className="w-4 h-4 text-green-500" />
                  ) : (
                    <Copy className="w-4 h-4" />
                  )}
                </button>
              </div>
              <p className="text-xs text-gray-500 mt-1">
                このURLをRSSリーダーに登録してください
              </p>
            </div>
          )}

          {/* 表示色 */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              表示色
            </label>
            <div className="flex flex-wrap gap-2">
              {AVAILABLE_COLORS.map((color) => (
                <button
                  key={color.id}
                  type="button"
                  onClick={() => handleChange('color', color.id)}
                  className={`w-8 h-8 rounded-lg transition-all ${color.id} ${
                    formData.color === color.id
                      ? 'ring-2 ring-offset-2 ring-gray-500 scale-110'
                      : 'hover:scale-105'
                  }`}
                  title={color.name}
                />
              ))}
            </div>
          </div>

          {/* ボタン */}
          <div className="flex gap-3 pt-4 border-t border-gray-200">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg hover:bg-gray-50 font-medium transition-colors"
            >
              キャンセル
            </button>
            <button
              type="submit"
              disabled={saving}
              className="flex-1 px-4 py-2.5 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed font-medium flex items-center justify-center gap-2 transition-colors"
            >
              {saving ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <Check className="w-4 h-4" />
              )}
              {isEdit ? '更新' : '追加'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
