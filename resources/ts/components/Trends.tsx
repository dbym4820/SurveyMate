import { useState, useEffect, ChangeEvent } from 'react';
import {
  TrendingUp, Calendar, FileText, Sparkles, Loader2,
  ChevronDown, ChevronUp, Clock, Target, Lightbulb
} from 'lucide-react';
import api, { getBasePath } from '../api';
import type { AIProvider } from '../types';

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
  overview: string;
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
  authors: string[];
  abstract: string | null;
  published_date: string | null;
  journal_name: string | null;
  journal_color: string;
}

type Period = 'day' | 'week' | 'month' | 'halfyear';

const PERIODS: { id: Period; label: string; description: string }[] = [
  { id: 'day', label: '今日', description: '本日の論文' },
  { id: 'week', label: '今週', description: '過去7日間' },
  { id: 'month', label: '今月', description: '過去30日間' },
  { id: 'halfyear', label: '半年', description: '過去6ヶ月' },
];

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

  // AI Provider
  const [aiProviders, setAiProviders] = useState<AIProvider[]>([]);
  const [selectedProvider, setSelectedProvider] = useState('claude');

  useEffect(() => {
    fetchStats();
    fetchProviders();
  }, []);

  useEffect(() => {
    if (selectedPeriod) {
      fetchPapers(selectedPeriod);
      fetchSummary(selectedPeriod);
    }
  }, [selectedPeriod]);

  const fetchProviders = async () => {
    try {
      const data = await api.summaries.providers();
      if (data.success) {
        setAiProviders(data.providers);
        setSelectedProvider(data.current);
      }
    } catch (err) {
      console.error('Failed to fetch providers:', err);
    }
  };

  const fetchStats = async () => {
    try {
      setIsLoadingStats(true);
      const response = await fetch(`${getBasePath()}/api/trends/stats`, {
        credentials: 'include',
      });
      const data = await response.json();
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
      const response = await fetch(`${getBasePath()}/api/trends/${period}/papers`, {
        credentials: 'include',
      });
      const data = await response.json();
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
      const response = await fetch(`${getBasePath()}/api/trends/${period}/summary`, {
        credentials: 'include',
      });
      const data = await response.json();
      if (data.success && data.summary) {
        setSummary(data.summary);
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

    try {
      setIsGenerating(true);
      setError(null);
      const response = await fetch(`${getBasePath()}/api/trends/${selectedPeriod}/generate`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ provider: selectedProvider }),
      });
      const data = await response.json();
      if (data.success && data.summary) {
        setSummary(data.summary);
      } else if (data.error) {
        setError(data.error);
      }
    } catch (err) {
      console.error('Failed to generate summary:', err);
      setError('トレンド要約の生成に失敗しました');
    } finally {
      setIsGenerating(false);
    }
  };

  const currentStats = stats?.[selectedPeriod];

  return (
    <main className="max-w-6xl mx-auto px-4 py-6">
        {/* Page Title */}
        <div className="mb-6">
          <h2 className="text-2xl font-bold text-gray-900">トレンド分析</h2>
          <p className="text-sm text-gray-500">期間別の論文トレンドをAIで要約</p>
        </div>

        {/* Period Selection */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
          {PERIODS.map((period) => {
            const periodStats = stats?.[period.id];
            const isSelected = selectedPeriod === period.id;
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
                {isLoadingStats ? (
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

        {/* Date Range Display */}
        {currentStats && (
          <div className="flex items-center gap-2 text-sm text-gray-500 mb-6">
            <Clock className="w-4 h-4" />
            <span>
              {currentStats.dateRange.from} 〜 {currentStats.dateRange.to}
            </span>
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
                    {PERIODS.find(p => p.id === selectedPeriod)?.label}の論文傾向
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-3">
                {/* AI Provider Selection */}
                {aiProviders.length > 0 && (
                  <div className="flex items-center gap-2">
                    <Sparkles className="w-4 h-4 text-gray-500" />
                    <select
                      value={selectedProvider}
                      onChange={(e: ChangeEvent<HTMLSelectElement>) => setSelectedProvider(e.target.value)}
                      className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-gray-500 focus:border-gray-500"
                    >
                      {aiProviders.map((p) => (
                        <option key={p.id} value={p.id}>
                          {p.name}
                        </option>
                      ))}
                    </select>
                  </div>
                )}
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
              <p className="text-gray-500 mb-4">
                「生成する」ボタンを押してAIによるトレンド要約を生成してください
              </p>
              <p className="text-sm text-gray-400">
                ※要約生成にはAPIキーの設定が必要です
              </p>
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
                        <div className={`w-1 self-stretch rounded-full ${paper.journal_color}`} />
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2 mb-1">
                            <span className={`px-2 py-0.5 text-xs font-medium text-white rounded ${paper.journal_color}`}>
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
                          {paper.authors && paper.authors.length > 0 && (
                            <p className="text-sm text-gray-500 mt-1 truncate">
                              {Array.isArray(paper.authors) ? paper.authors.join(', ') : paper.authors}
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
      </main>
  );
}
