import { useState, useEffect, useRef } from 'react';
import {
  TrendingUp, Calendar, FileText, Sparkles, Loader2,
  ChevronDown, ChevronUp, Clock, Target, Lightbulb,
  Tag, History, Check, X, BookOpen
} from 'lucide-react';
import api from '../api';
import type { ApiSettings, Tag as TagType, TrendSummary as TrendSummaryType, Journal } from '../types';

interface TrendStats {
  [period: string]: {
    count: number;
    dateRange: {
      from: string;
      to: string;
    };
  };
}

interface TrendSummary {
  overview: string | null;
  keyTopics: Array<{
    topic: string;
    description: string;
    paperCount: number;
  }>;
  emergingTrends: string[];
  journalInsights: Record<string, string>;
  recommendations: string[];
}

interface PeriodPaper {
  id: number;
  title: string;
  authors: string | string[];
  abstract: string | null;
  published_date: string | null;
  journal_name: string | null;
  journal_color: string;
}

type Period = 'day' | 'week' | 'month' | 'halfyear' | 'custom';

const PERIODS: { id: Period; label: string; description: string }[] = [
  { id: 'day', label: '今日', description: '本日の論文' },
  { id: 'week', label: '今週', description: '過去7日間' },
  { id: 'month', label: '今月', description: '過去30日間' },
  { id: 'halfyear', label: '半年', description: '過去6ヶ月' },
  { id: 'custom', label: 'カスタム', description: '日付を指定' },
];

const PERIOD_LABELS: Record<string, string> = {
  day: '今日',
  week: '今週',
  month: '今月',
  halfyear: '半年',
  custom: 'カスタム',
};

export default function Trends(): JSX.Element {
  const [stats, setStats] = useState<TrendStats | null>(null);
  const [selectedPeriod, setSelectedPeriod] = useState<Period>('week');
  const [papers, setPapers] = useState<PeriodPaper[]>([]);
  const [summary, setSummary] = useState<TrendSummary | null>(null);
  const [isLoadingStats, setIsLoadingStats] = useState(true);
  const [isLoadingPapers, setIsLoadingPapers] = useState(false);
  const [isGenerating, setIsGenerating] = useState(false);
  const [showPapers, setShowPapers] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // APIキー設定状態
  const [settings, setSettings] = useState<ApiSettings | null>(null);
  const hasAnyApiKey = settings?.claude_api_key_set || settings?.openai_api_key_set;

  // タグ関連
  const [tags, setTags] = useState<TagType[]>([]);
  const [selectedTagIds, setSelectedTagIds] = useState<number[]>([]);
  const [showTagSelector, setShowTagSelector] = useState(false);
  const tagSelectorRef = useRef<HTMLDivElement>(null);

  // 論文誌関連
  const [journals, setJournals] = useState<Journal[]>([]);
  const [selectedJournalIds, setSelectedJournalIds] = useState<string[]>([]);

  // カスタム日付範囲
  const [customDateFrom, setCustomDateFrom] = useState<string>('');
  const [customDateTo, setCustomDateTo] = useState<string>('');

  // 履歴関連
  const [trendHistory, setTrendHistory] = useState<TrendSummaryType[]>([]);
  const [showHistory, setShowHistory] = useState(false);
  const [isLoadingHistory, setIsLoadingHistory] = useState(false);
  const historyRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    fetchStats();
    fetchSettings();
    fetchTags();
    fetchJournals();
  }, []);

  const fetchSettings = async () => {
    try {
      const data = await api.settings.getApi();
      setSettings(data);
    } catch (err) {
      console.error('Failed to fetch settings:', err);
    }
  };

  const fetchTags = async () => {
    try {
      const data = await api.tags.list();
      setTags(data.tags || []);
    } catch (err) {
      console.error('Failed to fetch tags:', err);
    }
  };

  const fetchJournals = async () => {
    try {
      const data = await api.journals.list();
      setJournals(data.journals || []);
    } catch (err) {
      console.error('Failed to fetch journals:', err);
    }
  };

  const fetchHistory = async () => {
    try {
      setIsLoadingHistory(true);
      const data = await api.trends.history(20);
      setTrendHistory(data.summaries || []);
    } catch (err) {
      console.error('Failed to fetch history:', err);
    } finally {
      setIsLoadingHistory(false);
    }
  };

  // タグセレクタと履歴モーダルの外クリックで閉じる
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (tagSelectorRef.current && !tagSelectorRef.current.contains(event.target as Node)) {
        setShowTagSelector(false);
      }
      if (historyRef.current && !historyRef.current.contains(event.target as Node)) {
        setShowHistory(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  useEffect(() => {
    // カスタム期間の場合は両方の日付が設定されている必要がある
    if (selectedPeriod === 'custom') {
      if (customDateFrom && customDateTo) {
        fetchPapers(selectedPeriod);
        fetchSummary(selectedPeriod);
      }
    } else if (selectedPeriod) {
      fetchPapers(selectedPeriod);
      fetchSummary(selectedPeriod);
    }
  }, [selectedPeriod, selectedTagIds, selectedJournalIds, customDateFrom, customDateTo]);

  const fetchStats = async () => {
    try {
      setIsLoadingStats(true);
      const data = await api.trends.stats();
      if (data.success) {
        setStats(data.stats);
      }
    } catch (err) {
      console.error('Failed to fetch stats:', err);
    } finally {
      setIsLoadingStats(false);
    }
  };

  const fetchPapers = async (period: Period) => {
    try {
      setIsLoadingPapers(true);
      const data = await api.trends.papers(
        period,
        selectedTagIds.length > 0 ? selectedTagIds : undefined,
        selectedJournalIds.length > 0 ? selectedJournalIds : undefined,
        period === 'custom' ? customDateFrom : undefined,
        period === 'custom' ? customDateTo : undefined
      );
      if (data.success) {
        setPapers(data.papers);
      }
    } catch (err) {
      console.error('Failed to fetch papers:', err);
    } finally {
      setIsLoadingPapers(false);
    }
  };

  const fetchSummary = async (period: Period) => {
    try {
      const data = await api.trends.summary(
        period,
        selectedTagIds.length > 0 ? selectedTagIds : undefined,
        selectedJournalIds.length > 0 ? selectedJournalIds : undefined,
        period === 'custom' ? customDateFrom : undefined,
        period === 'custom' ? customDateTo : undefined
      );
      if (data.success && data.summary) {
        setSummary({
          overview: data.summary.overview || null,
          keyTopics: data.summary.keyTopics || [],
          emergingTrends: data.summary.emergingTrends || [],
          journalInsights: data.summary.journalInsights || {},
          recommendations: data.summary.recommendations || [],
        });
      } else {
        setSummary(null);
      }
    } catch (err) {
      console.error('Failed to fetch summary:', err);
      setSummary(null);
    }
  };

  const generateSummary = async () => {
    if (papers.length === 0) {
      setError('この期間に論文がありません');
      return;
    }

    // カスタム期間の場合は日付が必要
    if (selectedPeriod === 'custom' && (!customDateFrom || !customDateTo)) {
      setError('開始日と終了日を指定してください');
      return;
    }

    try {
      setIsGenerating(true);
      setError(null);
      const data = await api.trends.generate(
        selectedPeriod,
        undefined,
        selectedTagIds.length > 0 ? selectedTagIds : undefined,
        selectedJournalIds.length > 0 ? selectedJournalIds : undefined,
        selectedPeriod === 'custom' ? customDateFrom : undefined,
        selectedPeriod === 'custom' ? customDateTo : undefined
      );
      if (data.success && data.summary) {
        setSummary({
          overview: data.summary.overview || null,
          keyTopics: data.summary.keyTopics || [],
          emergingTrends: data.summary.emergingTrends || [],
          journalInsights: data.summary.journalInsights || {},
          recommendations: data.summary.recommendations || [],
        });
      } else if (data.message) {
        setError(data.message);
      }
    } catch (err) {
      console.error('Failed to generate summary:', err);
      setError('トレンド要約の生成に失敗しました');
    } finally {
      setIsGenerating(false);
    }
  };

  const toggleTag = (tagId: number) => {
    setSelectedTagIds(prev =>
      prev.includes(tagId)
        ? prev.filter(id => id !== tagId)
        : [...prev, tagId]
    );
    setSummary(null); // タグ変更時に要約をリセット
  };

  const clearTags = () => {
    setSelectedTagIds([]);
    setSummary(null);
  };

  const toggleJournal = (journalId: string) => {
    setSelectedJournalIds(prev =>
      prev.includes(journalId)
        ? prev.filter(id => id !== journalId)
        : [...prev, journalId]
    );
    setSummary(null); // 論文誌変更時に要約をリセット
  };

  const clearJournals = () => {
    setSelectedJournalIds([]);
    setSummary(null);
  };

  const openHistory = async () => {
    setShowHistory(true);
    await fetchHistory();
  };

  const loadHistorySummary = (historySummary: TrendSummaryType) => {
    setSummary({
      overview: historySummary.overview || null,
      keyTopics: historySummary.keyTopics || [],
      emergingTrends: historySummary.emergingTrends || [],
      journalInsights: historySummary.journalInsights || {},
      recommendations: historySummary.recommendations || [],
    });
    setShowHistory(false);
  };

  const currentStats = stats?.[selectedPeriod];

  return (
    <main className="w-[85%] mx-auto py-6">
        {/* Page Title */}
        <div className="mb-6">
          <h2 className="text-2xl font-bold text-gray-900">トレンド分析</h2>
          <p className="text-sm text-gray-500">期間別の論文トレンドをAIで要約</p>
        </div>

        {/* Period Selection */}
        <div className="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
          {PERIODS.map((period) => {
            const periodStats = stats?.[period.id];
            const isSelected = selectedPeriod === period.id;
            const isCustom = period.id === 'custom';
            return (
              <button
                key={period.id}
                onClick={() => setSelectedPeriod(period.id)}
                className={`p-4 rounded-xl border-2 transition-all text-left ${
                  isSelected
                    ? 'border-gray-500 bg-gray-50'
                    : 'border-gray-200 bg-white hover:border-gray-300'
                }`}
              >
                <div className="flex items-center gap-2 mb-2">
                  <Calendar className={`w-5 h-5 ${isSelected ? 'text-gray-600' : 'text-gray-400'}`} />
                  <span className={`font-semibold ${isSelected ? 'text-gray-900' : 'text-gray-900'}`}>
                    {period.label}
                  </span>
                </div>
                <p className="text-sm text-gray-500 mb-2">{period.description}</p>
                {isCustom ? (
                  <p className={`text-sm ${isSelected ? 'text-gray-600' : 'text-gray-400'}`}>
                    {customDateFrom && customDateTo
                      ? `${papers.length}件`
                      : '下で日付を選択'}
                  </p>
                ) : isLoadingStats ? (
                  <div className="h-6 bg-gray-200 rounded animate-pulse" />
                ) : (
                  <p className={`text-2xl font-bold ${isSelected ? 'text-gray-600' : 'text-gray-900'}`}>
                    {periodStats?.count ?? 0}
                    <span className="text-sm font-normal text-gray-500 ml-1">件</span>
                  </p>
                )}
              </button>
            );
          })}
        </div>

        {/* Custom Date Range Picker */}
        {selectedPeriod === 'custom' && (
          <div className="mb-6 p-4 bg-gray-50 rounded-xl border border-gray-200">
            <div className="flex items-center gap-2 mb-3">
              <Calendar className="w-4 h-4 text-gray-500" />
              <span className="text-sm font-medium text-gray-700">日付範囲を指定</span>
            </div>
            <div className="flex flex-wrap items-center gap-4">
              <div className="flex items-center gap-2">
                <label className="text-sm text-gray-600">開始日:</label>
                <input
                  type="date"
                  value={customDateFrom}
                  onChange={(e) => setCustomDateFrom(e.target.value)}
                  className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-gray-400"
                />
              </div>
              <div className="flex items-center gap-2">
                <label className="text-sm text-gray-600">終了日:</label>
                <input
                  type="date"
                  value={customDateTo}
                  onChange={(e) => setCustomDateTo(e.target.value)}
                  className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-gray-400"
                />
              </div>
            </div>
            {customDateFrom && customDateTo && (
              <p className="text-sm text-gray-500 mt-3">
                {customDateFrom} 〜 {customDateTo} の論文を対象
              </p>
            )}
          </div>
        )}

        {/* Date Range Display */}
        {selectedPeriod !== 'custom' && currentStats && (
          <div className="flex items-center gap-2 text-sm text-gray-500 mb-4">
            <Clock className="w-4 h-4" />
            <span>
              {currentStats.dateRange.from} 〜 {currentStats.dateRange.to}
            </span>
          </div>
        )}

        {/* Tag Filter */}
        {tags.length > 0 && (
          <div className="mb-6">
            <div className="flex items-center gap-2 mb-2">
              <Tag className="w-4 h-4 text-gray-500" />
              <span className="text-sm font-medium text-gray-700">タグでフィルタ</span>
              {selectedTagIds.length > 0 && (
                <button
                  onClick={clearTags}
                  className="text-xs text-gray-500 hover:text-gray-700 flex items-center gap-1"
                >
                  <X className="w-3 h-3" />
                  クリア
                </button>
              )}
            </div>
            <div className="flex flex-wrap gap-2">
              {tags.map(tag => {
                const isSelected = selectedTagIds.includes(tag.id);
                return (
                  <button
                    key={tag.id}
                    onClick={() => toggleTag(tag.id)}
                    className={`px-3 py-1.5 text-sm rounded-full border transition-all flex items-center gap-1.5 ${
                      isSelected
                        ? 'bg-gray-100 border-gray-400 text-gray-800'
                        : 'bg-white border-gray-200 text-gray-600 hover:border-gray-300'
                    }`}
                  >
                    <span
                      className="w-2.5 h-2.5 rounded-full"
                      style={{ backgroundColor: tag.color }}
                    />
                    {tag.name}
                    {isSelected && <Check className="w-3.5 h-3.5" />}
                  </button>
                );
              })}
            </div>
            {selectedTagIds.length > 0 && (
              <p className="text-xs text-gray-500 mt-2">
                選択したタグが付いた論文のみを対象にトレンド要約を生成します
              </p>
            )}
          </div>
        )}

        {/* Journal Filter */}
        {journals.length > 0 && (
          <div className="mb-6">
            <div className="flex items-center gap-2 mb-2">
              <BookOpen className="w-4 h-4 text-gray-500" />
              <span className="text-sm font-medium text-gray-700">論文誌でフィルタ</span>
              {selectedJournalIds.length > 0 && (
                <button
                  onClick={clearJournals}
                  className="text-xs text-gray-500 hover:text-gray-700 flex items-center gap-1"
                >
                  <X className="w-3 h-3" />
                  クリア
                </button>
              )}
            </div>
            <div className="flex flex-wrap gap-2">
              {journals.filter(j => j.is_active).map(journal => {
                const isSelected = selectedJournalIds.includes(journal.id);
                return (
                  <button
                    key={journal.id}
                    onClick={() => toggleJournal(journal.id)}
                    className={`px-3 py-1.5 text-sm rounded-full border transition-all flex items-center gap-1.5 ${
                      isSelected
                        ? 'bg-gray-100 border-gray-400 text-gray-800'
                        : 'bg-white border-gray-200 text-gray-600 hover:border-gray-300'
                    }`}
                  >
                    <span
                      className={`w-2.5 h-2.5 rounded-full ${journal.color}`}
                    />
                    {journal.name}
                    {isSelected && <Check className="w-3.5 h-3.5" />}
                  </button>
                );
              })}
            </div>
            {selectedJournalIds.length > 0 && (
              <p className="text-xs text-gray-500 mt-2">
                選択した論文誌の論文のみを対象にトレンド要約を生成します
              </p>
            )}
          </div>
        )}

        {/* AI Trend Summary */}
        <div className="bg-white rounded-xl border border-gray-200 shadow-sm mb-6">
          <div className="p-6 border-b border-gray-200">
            <div className="flex items-center justify-between flex-wrap gap-4">
              <div className="flex items-center gap-3">
                <div className="p-2 bg-gradient-to-br from-purple-500 to-gray-500 rounded-lg">
                  <TrendingUp className="w-5 h-5 text-white" />
                </div>
                <div>
                  <h2 className="text-lg font-semibold text-gray-900">AIトレンド要約</h2>
                  <p className="text-sm text-gray-500">
                    {selectedPeriod === 'custom' && customDateFrom && customDateTo
                      ? `${customDateFrom} 〜 ${customDateTo} の論文傾向`
                      : `${PERIODS.find(p => p.id === selectedPeriod)?.label}の論文傾向`}
                  </p>
                </div>
              </div>
              {/* ボタン類 */}
              <div className="flex items-center gap-3">
                {/* 履歴ボタン */}
                <button
                  onClick={openHistory}
                  className="flex items-center gap-2 px-3 py-2 text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
                  title="履歴を表示"
                >
                  <History className="w-4 h-4" />
                  <span className="hidden sm:inline">履歴</span>
                </button>

                {/* 生成ボタン（APIキー設定時のみ） */}
                {hasAnyApiKey && (
                  <button
                    onClick={generateSummary}
                    disabled={isGenerating || papers.length === 0}
                    className="flex items-center gap-2 px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                  >
                    {isGenerating ? (
                      <>
                        <Loader2 className="w-4 h-4 animate-spin" />
                        生成中...
                      </>
                    ) : (
                      <>
                        <Sparkles className="w-4 h-4" />
                        {summary ? '再生成' : '生成する'}
                      </>
                    )}
                  </button>
                )}
              </div>
            </div>
          </div>

          {error && (
            <div className="p-4 bg-red-50 border-b border-red-200 text-red-700">
              {error}
            </div>
          )}

          {summary ? (
            <div className="p-6 space-y-6">
              {/* Overview */}
              <div>
                <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">
                  概要
                </h3>
                <p className="text-gray-700 leading-relaxed">{summary.overview}</p>
              </div>

              {/* Key Topics */}
              {summary.keyTopics && summary.keyTopics.length > 0 && (
                <div>
                  <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
                    <Target className="w-4 h-4" />
                    主要トピック
                  </h3>
                  <div className="grid gap-3">
                    {summary.keyTopics.map((topic, index) => (
                      <div key={index} className="p-4 bg-gray-50 rounded-lg">
                        <div className="flex items-center justify-between mb-2">
                          <span className="font-semibold text-gray-900">{topic.topic}</span>
                          <span className="text-sm text-gray-600 font-medium">
                            {topic.paperCount}件
                          </span>
                        </div>
                        <p className="text-sm text-gray-600">{topic.description}</p>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Emerging Trends */}
              {summary.emergingTrends && summary.emergingTrends.length > 0 && (
                <div>
                  <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
                    <TrendingUp className="w-4 h-4" />
                    注目のトレンド
                  </h3>
                  <ul className="space-y-2">
                    {summary.emergingTrends.map((trend, index) => (
                      <li key={index} className="flex items-start gap-2">
                        <span className="w-6 h-6 bg-gray-100 text-gray-600 rounded-full flex items-center justify-center text-sm font-medium flex-shrink-0">
                          {index + 1}
                        </span>
                        <span className="text-gray-700">{trend}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {/* Journal Insights */}
              {summary.journalInsights && Object.keys(summary.journalInsights).length > 0 && (
                <div>
                  <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">
                    論文誌別傾向
                  </h3>
                  <div className="grid gap-3 md:grid-cols-2">
                    {Object.entries(summary.journalInsights).map(([journal, insight]) => (
                      <div key={journal} className="p-4 bg-blue-50 rounded-lg">
                        <span className="font-semibold text-blue-900">{journal}</span>
                        <p className="text-sm text-blue-700 mt-1">{insight}</p>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Recommendations */}
              {summary.recommendations && summary.recommendations.length > 0 && (
                <div>
                  <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
                    <Lightbulb className="w-4 h-4" />
                    研究者へのおすすめ
                  </h3>
                  <ul className="space-y-2">
                    {summary.recommendations.map((rec, index) => (
                      <li key={index} className="flex items-start gap-2 text-gray-700">
                        <span className="text-yellow-500">•</span>
                        {rec}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          ) : (
            <div className="p-12 text-center">
              <Sparkles className="w-12 h-12 text-gray-300 mx-auto mb-4" />
              {hasAnyApiKey ? (
                <>
                  <p className="text-gray-500 mb-4">
                    「生成する」ボタンを押してAIによるトレンド要約を生成してください
                  </p>
                </>
              ) : (
                <p className="text-gray-500 mb-4">
                  トレンド要約を生成するには，設定画面でAPIキーを設定してください
                </p>
              )}
            </div>
          )}
        </div>

        {/* Paper List */}
        <div className="bg-white rounded-xl border border-gray-200 shadow-sm">
          <button
            onClick={() => setShowPapers(!showPapers)}
            className="w-full p-4 flex items-center justify-between hover:bg-gray-50 transition-colors"
          >
            <div className="flex items-center gap-3">
              <FileText className="w-5 h-5 text-gray-400" />
              <span className="font-semibold text-gray-900">
                論文一覧（{papers.length}件）
              </span>
            </div>
            {showPapers ? (
              <ChevronUp className="w-5 h-5 text-gray-400" />
            ) : (
              <ChevronDown className="w-5 h-5 text-gray-400" />
            )}
          </button>

          {showPapers && (
            <div className="border-t border-gray-200">
              {isLoadingPapers ? (
                <div className="p-8 text-center">
                  <Loader2 className="w-8 h-8 text-gray-500 animate-spin mx-auto" />
                </div>
              ) : papers.length === 0 ? (
                <div className="p-8 text-center text-gray-500">
                  この期間に論文がありません
                </div>
              ) : (
                <div className="divide-y divide-gray-100 max-h-96 overflow-y-auto">
                  {papers.map((paper) => (
                    <div key={paper.id} className="p-4 hover:bg-gray-50">
                      <div className="flex items-start gap-3">
                        <div className={`w-1 self-stretch rounded-full ${paper.journal_color || 'bg-gray-500'}`} />
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2 mb-1">
                            <span className={`px-2 py-0.5 text-xs font-medium text-white rounded ${paper.journal_color || 'bg-gray-500'}`}>
                              {paper.journal_name}
                            </span>
                            {paper.published_date && (
                              <span className="text-xs text-gray-400">
                                {paper.published_date}
                              </span>
                            )}
                          </div>
                          <h4 className="font-medium text-gray-900 line-clamp-2">
                            {paper.title}
                          </h4>
                          {paper.authors && (typeof paper.authors === 'string' ? paper.authors : Object.keys(paper.authors).length > 0) && (
                            <p className="text-sm text-gray-500 mt-1 truncate">
                              {Array.isArray(paper.authors)
                                ? paper.authors.join(', ')
                                : typeof paper.authors === 'object'
                                ? Object.values(paper.authors).join(', ')
                                : paper.authors}
                            </p>
                          )}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>

        {/* History Modal */}
        {showHistory && (
          <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div
              ref={historyRef}
              className="bg-white rounded-xl shadow-xl max-w-2xl w-full max-h-[80vh] overflow-hidden flex flex-col"
            >
              <div className="p-4 border-b border-gray-200 flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <History className="w-5 h-5 text-gray-600" />
                  <h3 className="font-semibold text-gray-900">トレンド要約の履歴</h3>
                </div>
                <button
                  onClick={() => setShowHistory(false)}
                  className="p-1 text-gray-400 hover:text-gray-600 rounded"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <div className="flex-1 overflow-y-auto">
                {isLoadingHistory ? (
                  <div className="p-8 text-center">
                    <Loader2 className="w-8 h-8 text-gray-400 animate-spin mx-auto" />
                    <p className="text-gray-500 mt-2">読み込み中...</p>
                  </div>
                ) : trendHistory.length === 0 ? (
                  <div className="p-8 text-center text-gray-500">
                    履歴がありません
                  </div>
                ) : (
                  <div className="divide-y divide-gray-100">
                    {trendHistory.map((item) => (
                      <button
                        key={item.id}
                        onClick={() => loadHistorySummary(item)}
                        className="w-full p-4 text-left hover:bg-gray-50 transition-colors"
                      >
                        <div className="flex items-center justify-between mb-2">
                          <div className="flex items-center gap-2">
                            <span className="px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-700 rounded">
                              {PERIOD_LABELS[item.period] || item.period}
                            </span>
                            <span className="text-sm text-gray-500">
                              {item.paperCount}件の論文
                            </span>
                          </div>
                          <span className="text-xs text-gray-400">
                            {item.createdAt ? new Date(item.createdAt).toLocaleString('ja-JP') : ''}
                          </span>
                        </div>
                        {item.tagIds && item.tagIds.length > 0 && (
                          <div className="flex flex-wrap gap-1 mb-2">
                            {item.tagIds.map(tagId => {
                              const tag = tags.find(t => t.id === tagId);
                              if (!tag) return null;
                              return (
                                <span
                                  key={tagId}
                                  className="px-2 py-0.5 text-xs rounded-full bg-gray-50 text-gray-600 flex items-center gap-1"
                                >
                                  <span
                                    className="w-2 h-2 rounded-full"
                                    style={{ backgroundColor: tag.color }}
                                  />
                                  {tag.name}
                                </span>
                              );
                            })}
                          </div>
                        )}
                        {item.journalIds && item.journalIds.length > 0 && (
                          <div className="flex flex-wrap gap-1 mb-2">
                            {item.journalIds.map(journalId => {
                              const journal = journals.find(j => j.id === journalId);
                              if (!journal) return null;
                              return (
                                <span
                                  key={journalId}
                                  className="px-2 py-0.5 text-xs rounded-full bg-blue-50 text-blue-600 flex items-center gap-1"
                                >
                                  <BookOpen className="w-3 h-3" />
                                  {journal.name}
                                </span>
                              );
                            })}
                          </div>
                        )}
                        <p className="text-sm text-gray-700 line-clamp-2">
                          {item.overview || '概要なし'}
                        </p>
                        <div className="flex items-center gap-2 mt-2 text-xs text-gray-400">
                          <span>{item.provider}</span>
                          {item.model && <span>/ {item.model}</span>}
                        </div>
                      </button>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </div>
        )}
      </main>
  );
}
