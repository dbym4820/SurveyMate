import { useState, useEffect, useRef } from 'react';
import { X, Sparkles, Loader2, Trash2, ChevronDown, ChevronUp, FileText } from 'lucide-react';
import api from '../api';
import { useToast } from './Toast';
import type { Tag, TagSummary } from '../types';

interface TagSummaryModalProps {
  tag: Tag;
  onClose: () => void;
  hasAnyApiKey?: boolean;
}

export default function TagSummaryModal({ tag, onClose, hasAnyApiKey = true }: TagSummaryModalProps): JSX.Element {
  const { showToast } = useToast();
  const [summaries, setSummaries] = useState<TagSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [generating, setGenerating] = useState(false);
  const [perspectivePrompt, setPerspectivePrompt] = useState('');
  const [expandedSummaryId, setExpandedSummaryId] = useState<number | null>(null);
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  // 要約一覧を取得
  useEffect(() => {
    const fetchSummaries = async () => {
      setLoading(true);
      try {
        const data = await api.tags.getSummaries(tag.id);
        if (data.success) {
          setSummaries(data.summaries);
          // 最新の要約があれば展開
          if (data.summaries.length > 0) {
            setExpandedSummaryId(data.summaries[0].id);
          }
        }
      } catch (error) {
        console.error('Failed to fetch tag summaries:', error);
      } finally {
        setLoading(false);
      }
    };
    fetchSummaries();
  }, [tag.id]);

  // 要約を生成
  const generateSummary = async () => {
    if (!perspectivePrompt.trim()) {
      showToast('要約の観点を入力してください', 'info');
      return;
    }

    setGenerating(true);
    try {
      const data = await api.tags.generateSummary(tag.id, perspectivePrompt.trim());
      if (data.success) {
        setSummaries((prev) => [data.summary, ...prev]);
        setExpandedSummaryId(data.summary.id);
        setPerspectivePrompt('');
        showToast('要約を生成しました', 'success');
      }
    } catch (error) {
      console.error('Failed to generate tag summary:', error);
      showToast('要約の生成に失敗しました: ' + (error as Error).message, 'error');
    } finally {
      setGenerating(false);
    }
  };

  // 要約を削除
  const deleteSummary = async (summaryId: number) => {
    if (!confirm('この要約を削除しますか？')) return;

    try {
      const data = await api.tags.deleteSummary(tag.id, summaryId);
      if (data.success) {
        setSummaries((prev) => prev.filter((s) => s.id !== summaryId));
        if (expandedSummaryId === summaryId) {
          setExpandedSummaryId(null);
        }
      }
    } catch (error) {
      console.error('Failed to delete tag summary:', error);
    }
  };

  // ESCキーで閉じる
  useEffect(() => {
    const handleEsc = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };
    document.addEventListener('keydown', handleEsc);
    return () => document.removeEventListener('keydown', handleEsc);
  }, [onClose]);

  // bodyスクロール無効化
  useEffect(() => {
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = '';
    };
  }, []);

  const formatDate = (dateString: string): string => {
    const date = new Date(dateString);
    return `${date.getFullYear()}/${date.getMonth() + 1}/${date.getDate()} ${date.getHours()}:${String(date.getMinutes()).padStart(2, '0')}`;
  };

  // プロンプト例
  const promptExamples = [
    '教育工学の観点から',
    '実践的な応用可能性について',
    '研究手法の特徴と傾向',
    '今後の研究課題について',
  ];

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" onClick={onClose}>
      <div
        className="w-full max-w-3xl max-h-[90vh] bg-white rounded-xl shadow-2xl flex flex-col overflow-hidden"
        onClick={(e) => e.stopPropagation()}
      >
        {/* ヘッダー */}
        <div className="flex items-center justify-between px-4 py-3 bg-gradient-to-r from-gray-500 to-purple-500 text-white">
          <div className="flex items-center gap-3">
            <span className={`w-4 h-4 rounded-full ${tag.color}`} />
            <div>
              <h2 className="font-medium text-lg">{tag.name}</h2>
              <p className="text-xs text-white/80">タググループ要約</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 hover:bg-white/20 rounded transition-colors"
            title="閉じる (ESC)"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* コンテンツ */}
        <div className="flex-1 overflow-y-auto p-4 space-y-4">
          {/* 新規要約生成フォーム（APIキー設定時のみ） */}
          {hasAnyApiKey && (
            <div className="bg-gray-50 rounded-xl p-4 border border-gray-200">
              <h3 className="text-sm font-medium text-gray-700 mb-3 flex items-center gap-2">
                <Sparkles className="w-4 h-4 text-gray-500" />
                新しい要約を生成
              </h3>

              <div className="space-y-3">
                <div>
                  <label className="block text-xs text-gray-600 mb-1">
                    要約の観点（どのような視点で論文群を分析するか）
                  </label>
                  <textarea
                    ref={textareaRef}
                    value={perspectivePrompt}
                    onChange={(e) => setPerspectivePrompt(e.target.value)}
                    placeholder="例: 教育工学の観点から、これらの論文が示唆する実践的な応用可能性について要約してください"
                    className="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg resize-none focus:ring-2 focus:ring-gray-500 focus:border-gray-500"
                    rows={3}
                    disabled={generating}
                  />
                </div>

                {/* プロンプト例 */}
                <div className="flex flex-wrap gap-2">
                  {promptExamples.map((example) => (
                    <button
                      key={example}
                      onClick={() => setPerspectivePrompt(example)}
                      className="text-xs px-2 py-1 bg-white border border-gray-300 rounded-full hover:border-gray-400 hover:text-gray-600 transition-colors"
                      disabled={generating}
                    >
                      {example}
                    </button>
                  ))}
                </div>

                <button
                  onClick={generateSummary}
                  disabled={!perspectivePrompt.trim() || generating}
                  className="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-gradient-to-r from-gray-500 to-purple-500 text-white rounded-lg hover:from-gray-600 hover:to-purple-600 disabled:opacity-50 disabled:cursor-not-allowed font-medium transition-all"
                >
                  {generating ? (
                    <>
                      <Loader2 className="w-4 h-4 animate-spin" />
                      生成中...（最大30件の論文を分析）
                    </>
                  ) : (
                    <>
                      <Sparkles className="w-4 h-4" />
                      AIで要約を生成
                    </>
                  )}
                </button>
              </div>
            </div>
          )}

          {/* 要約一覧 */}
          <div className="space-y-3">
            <h3 className="text-sm font-medium text-gray-700">過去の要約</h3>

            {loading ? (
              <div className="flex items-center justify-center py-8">
                <Loader2 className="w-6 h-6 animate-spin text-gray-500" />
              </div>
            ) : summaries.length === 0 ? (
              <div className="text-center py-8 text-gray-500">
                <FileText className="w-12 h-12 mx-auto mb-2 text-gray-300" />
                <p className="text-sm">まだ要約がありません</p>
                {hasAnyApiKey ? (
                  <p className="text-xs text-gray-400 mt-1">上のフォームから生成してください</p>
                ) : (
                  <p className="text-xs text-gray-400 mt-1">要約を生成するにはAPIキーを設定してください</p>
                )}
              </div>
            ) : (
              summaries.map((summary) => (
                <div
                  key={summary.id}
                  className="bg-white border border-gray-200 rounded-lg overflow-hidden"
                >
                  {/* 要約ヘッダー */}
                  <button
                    onClick={() => setExpandedSummaryId(expandedSummaryId === summary.id ? null : summary.id)}
                    className="w-full flex items-center justify-between px-4 py-3 hover:bg-gray-50 transition-colors text-left"
                  >
                    <div className="flex-1 min-w-0">
                      <p className="text-sm font-medium text-gray-800 truncate">
                        {summary.perspective_prompt}
                      </p>
                      <div className="flex items-center gap-3 mt-1 text-xs text-gray-500">
                        <span>{formatDate(summary.created_at)}</span>
                        <span className="px-1.5 py-0.5 bg-gray-100 rounded">
                          {summary.ai_provider}
                        </span>
                        <span>{summary.paper_count}件の論文</span>
                      </div>
                    </div>
                    <div className="flex items-center gap-2 ml-3">
                      <button
                        onClick={(e) => {
                          e.stopPropagation();
                          deleteSummary(summary.id);
                        }}
                        className="p-1.5 text-gray-400 hover:text-red-500 transition-colors"
                        title="削除"
                      >
                        <Trash2 className="w-4 h-4" />
                      </button>
                      {expandedSummaryId === summary.id ? (
                        <ChevronUp className="w-5 h-5 text-gray-400" />
                      ) : (
                        <ChevronDown className="w-5 h-5 text-gray-400" />
                      )}
                    </div>
                  </button>

                  {/* 要約本文 */}
                  {expandedSummaryId === summary.id && (
                    <div className="px-4 pb-4 border-t border-gray-100">
                      <div className="mt-3 p-3 bg-gradient-to-r from-gray-50 to-purple-50 rounded-lg">
                        <pre className="text-sm text-gray-800 whitespace-pre-wrap font-sans leading-relaxed">
                          {summary.summary_text}
                        </pre>
                      </div>
                      {summary.tokens_used && (
                        <p className="mt-2 text-xs text-gray-400 text-right">
                          {summary.tokens_used.toLocaleString()} tokens / {summary.generation_time_ms?.toLocaleString()}ms
                        </p>
                      )}
                    </div>
                  )}
                </div>
              ))
            )}
          </div>
        </div>

        {/* フッター */}
        <div className="border-t border-gray-200 px-4 py-3 bg-gray-50">
          <p className="text-xs text-gray-500 text-center">
            ESCで閉じる
          </p>
        </div>
      </div>
    </div>
  );
}
