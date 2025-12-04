import { useState, useEffect } from 'react';
import { ExternalLink, Sparkles, ChevronDown, ChevronUp, Loader2 } from 'lucide-react';
import api from '../api';
import type { Paper, Summary } from '../types';

interface PaperCardProps {
  paper: Paper;
  selectedProvider: string;
}

export default function PaperCard({ paper, selectedProvider }: PaperCardProps): JSX.Element {
  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(false);
  const [expanded, setExpanded] = useState(false);
  const [abstractExpanded, setAbstractExpanded] = useState(false);

  // 既存の要約がある場合は初期化時にセット
  useEffect(() => {
    if (paper.summaries && paper.summaries.length > 0) {
      // 最新の要約を使用
      setSummary(paper.summaries[0]);
    }
  }, [paper.summaries]);

  const hasSummary = summary !== null || (paper.summaries && paper.summaries.length > 0);

  const formatDate = (dateString: string | null): string => {
    if (!dateString) return '';
    const date = new Date(dateString);
    return `${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日`;
  };

  const generateSummary = async (): Promise<void> => {
    setLoading(true);
    try {
      const data = await api.summaries.generate(paper.id, selectedProvider);
      if (data.success) {
        setSummary(data.summary);
        setExpanded(true);
      } else {
        throw new Error('Summary generation failed');
      }
    } catch (error) {
      alert('要約の生成に失敗しました: ' + (error as Error).message);
    } finally {
      setLoading(false);
    }
  };

  const authors = Array.isArray(paper.authors) ? paper.authors.join(', ') : paper.authors;

  // アブストラクトが長いかどうかを判定（約150文字以上）
  const isAbstractLong = paper.abstract && paper.abstract.length > 150;

  return (
    <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden hover:shadow-md transition-all">
      <div className="p-5">
        <div className="flex items-start gap-4">
          {/* 色バー */}
          <div className={`w-1.5 self-stretch rounded-full ${paper.journal_color}`} />

          {/* コンテンツ */}
          <div className="flex-1 min-w-0">
            {/* メタ情報 */}
            <div className="flex items-center gap-2 mb-2 flex-wrap">
              <span className={`px-2.5 py-1 text-xs font-medium text-white rounded-lg ${paper.journal_color}`}>
                {paper.journal_name}
              </span>
              <span className="text-xs text-gray-500">
                {formatDate(paper.published_date)}
              </span>
              {hasSummary && (
                <span className="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full flex items-center gap-1">
                  <Sparkles className="w-3 h-3" />
                  要約済
                </span>
              )}
            </div>

            {/* タイトル */}
            <h3 className="text-lg font-semibold text-gray-900 mb-2">
              {paper.title}
            </h3>

            {/* 著者 */}
            <p className="text-sm text-gray-600 mb-3">
              {authors}
            </p>

            {/* アブストラクト */}
            {paper.abstract && (
              <div className="mb-4">
                <p className={`text-sm text-gray-700 ${!abstractExpanded && isAbstractLong ? 'line-clamp-3' : ''}`}>
                  {paper.abstract}
                </p>
                {isAbstractLong && (
                  <button
                    onClick={() => setAbstractExpanded(!abstractExpanded)}
                    className="text-xs text-indigo-600 hover:text-indigo-800 mt-1 font-medium"
                  >
                    {abstractExpanded ? '概要を折りたたむ' : '概要を全文表示'}
                  </button>
                )}
              </div>
            )}

            {/* アクション */}
            <div className="flex items-center gap-3 flex-wrap">
              {/* AI要約ボタン：要約がない場合のみ表示 */}
              {!hasSummary && (
                <button
                  onClick={generateSummary}
                  disabled={loading}
                  className="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-indigo-500 to-purple-500 text-white text-sm rounded-lg hover:from-indigo-600 hover:to-purple-600 disabled:opacity-50 disabled:cursor-not-allowed font-medium transition-all"
                >
                  {loading ? (
                    <Loader2 className="w-4 h-4 animate-spin" />
                  ) : (
                    <Sparkles className="w-4 h-4" />
                  )}
                  {loading ? '生成中...' : 'AI要約'}
                </button>
              )}

              <a
                href={paper.url || '#'}
                target="_blank"
                rel="noopener noreferrer"
                className="flex items-center gap-1 px-3 py-2 text-sm text-gray-600 hover:text-indigo-600 transition-colors"
              >
                <ExternalLink className="w-4 h-4" />
                論文を開く
              </a>

              {/* 要約表示トグル：要約がある場合のみ表示 */}
              {hasSummary && (
                <button
                  onClick={() => setExpanded(!expanded)}
                  className="flex items-center gap-1 text-sm text-indigo-600 font-medium hover:text-indigo-800"
                >
                  {expanded ? (
                    <ChevronUp className="w-4 h-4" />
                  ) : (
                    <ChevronDown className="w-4 h-4" />
                  )}
                  {expanded ? 'AI要約を閉じる' : 'AI要約を表示'}
                </button>
              )}
            </div>

            {/* AI要約 */}
            {summary && expanded && (
              <div className="mt-4 p-5 bg-gradient-to-r from-indigo-50 to-purple-50 rounded-xl border border-indigo-100">
                <div className="flex items-center justify-between mb-4">
                  <div className="flex items-center gap-2">
                    <Sparkles className="w-5 h-5 text-indigo-600" />
                    <span className="font-medium text-indigo-900">AI要約</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-xs px-2 py-1 bg-indigo-100 rounded-full text-indigo-600">
                      {summary.ai_provider}
                    </span>
                    {summary.ai_model && (
                      <span className="text-xs text-gray-500">
                        {summary.ai_model}
                      </span>
                    )}
                  </div>
                </div>
                <div className="space-y-4 text-sm text-gray-800">
                  {summary.purpose && (
                    <div>
                      <span className="font-semibold text-indigo-700">【研究目的】</span>
                      <p className="mt-1">{summary.purpose}</p>
                    </div>
                  )}
                  {summary.methodology && (
                    <div>
                      <span className="font-semibold text-indigo-700">【手法】</span>
                      <p className="mt-1">{summary.methodology}</p>
                    </div>
                  )}
                  {summary.findings && (
                    <div>
                      <span className="font-semibold text-indigo-700">【主な発見】</span>
                      <p className="mt-1">{summary.findings}</p>
                    </div>
                  )}
                  {summary.implications && (
                    <div>
                      <span className="font-semibold text-indigo-700">【教育への示唆】</span>
                      <p className="mt-1">{summary.implications}</p>
                    </div>
                  )}
                  {!summary.purpose && summary.summary_text && (
                    <p className="whitespace-pre-wrap">{summary.summary_text}</p>
                  )}
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
