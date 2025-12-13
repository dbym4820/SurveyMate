import { useState, useEffect, useRef, useCallback, ChangeEvent } from 'react';
import {
  Save, Loader2, CheckCircle, AlertCircle, Compass, FileText,
} from 'lucide-react';
import api from '../api';
import type { ResearchPerspective } from '../types';

export default function ResearchSettings(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Research Perspective state
  const [savingPerspective, setSavingPerspective] = useState(false);
  const [researchFields, setResearchFields] = useState('');
  const [summaryPerspective, setSummaryPerspective] = useState('');
  const [readingFocus, setReadingFocus] = useState('');

  // Summary Template state
  const [savingTemplate, setSavingTemplate] = useState(false);
  const [summaryTemplate, setSummaryTemplate] = useState('');

  // Refs for auto-resize textareas
  const researchFieldsRef = useRef<HTMLTextAreaElement>(null);
  const summaryPerspectiveRef = useRef<HTMLTextAreaElement>(null);
  const readingFocusRef = useRef<HTMLTextAreaElement>(null);
  const summaryTemplateRef = useRef<HTMLTextAreaElement>(null);

  // Auto-resize textarea helper
  const autoResize = useCallback((textarea: HTMLTextAreaElement | null, minRows: number) => {
    if (!textarea) return;
    // Reset height to calculate scrollHeight correctly
    textarea.style.height = 'auto';
    // Calculate minimum height based on minRows (approx 24px per row + padding)
    const minHeight = minRows * 24 + 16;
    // Set height to max of minHeight or scrollHeight
    textarea.style.height = `${Math.max(minHeight, textarea.scrollHeight)}px`;
  }, []);

  // Fetch all settings on mount
  useEffect(() => {
    async function fetchAllSettings(): Promise<void> {
      try {
        const [perspectiveRes, templateRes] = await Promise.all([
          api.settings.getResearchPerspective(),
          api.settings.getSummaryTemplate(),
        ]);

        // Research Perspective
        const perspective: ResearchPerspective = perspectiveRes.research_perspective;
        setResearchFields(perspective.research_fields || '');
        setSummaryPerspective(perspective.summary_perspective || '');
        setReadingFocus(perspective.reading_focus || '');

        // Summary Template
        setSummaryTemplate(templateRes.summary_template || '');
      } catch (error) {
        setMessage({ type: 'error', text: '設定の取得に失敗しました' });
      } finally {
        setLoading(false);
      }
    }
    fetchAllSettings();
  }, []);

  // Auto-resize textareas after data is loaded
  useEffect(() => {
    if (!loading) {
      // Use requestAnimationFrame to ensure DOM is updated
      requestAnimationFrame(() => {
        autoResize(researchFieldsRef.current, 3);
        autoResize(summaryPerspectiveRef.current, 3);
        autoResize(readingFocusRef.current, 3);
        autoResize(summaryTemplateRef.current, 6);
      });
    }
  }, [loading, autoResize]);

  // Save Research Perspective
  const handleSavePerspective = async (): Promise<void> => {
    setSavingPerspective(true);
    setMessage(null);

    try {
      const response = await api.settings.updateResearchPerspective({
        research_fields: researchFields,
        summary_perspective: summaryPerspective,
        reading_focus: readingFocus,
      });
      setMessage({ type: 'success', text: response.message });
    } catch (error) {
      setMessage({ type: 'error', text: (error as Error).message || '保存に失敗しました' });
    } finally {
      setSavingPerspective(false);
    }
  };

  // Save Summary Template
  const handleSaveTemplate = async (): Promise<void> => {
    setSavingTemplate(true);
    setMessage(null);

    try {
      const response = await api.settings.updateSummaryTemplate({
        summary_template: summaryTemplate,
      });
      setMessage({ type: 'success', text: response.message });
    } catch (error) {
      setMessage({ type: 'error', text: (error as Error).message || '保存に失敗しました' });
    } finally {
      setSavingTemplate(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="w-8 h-8 animate-spin text-gray-600" />
      </div>
    );
  }

  return (
    <main className="w-[85%] mx-auto py-6">
      {/* Header */}
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
          <Compass className="w-6 h-6" />
          調査観点設定
        </h1>
        <p className="text-sm text-gray-500 mt-1">
          あなたの研究分野や興味に合わせた要約を生成するための設定です
        </p>
      </div>

      {/* Message */}
      {message && (
        <div
          className={`mb-6 p-4 rounded-lg flex items-center gap-2 ${
            message.type === 'success'
              ? 'bg-green-50 text-green-800 border border-green-200'
              : 'bg-red-50 text-red-800 border border-red-200'
          }`}
        >
          {message.type === 'success' ? (
            <CheckCircle className="w-5 h-5" />
          ) : (
            <AlertCircle className="w-5 h-5" />
          )}
          {message.text}
        </div>
      )}

      {/* Research Perspective Section */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <div className="flex items-center gap-2 mb-4">
          <Compass className="w-5 h-5 text-gray-600" />
          <h2 className="text-lg font-semibold text-gray-900">調査観点</h2>
        </div>
        <p className="text-sm text-gray-500 mb-4">
          AI要約生成時に，あなたの研究分野や関心に基づいた観点を反映させます
        </p>

        <div className="space-y-4">
          {/* Research Fields */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              研究分野・興味のある観点
            </label>
            <p className="text-xs text-gray-500 mb-2">
              あなたがどのような研究分野や観点に興味があるかを記述してください
            </p>
            <textarea
              ref={researchFieldsRef}
              value={researchFields}
              onChange={(e: ChangeEvent<HTMLTextAreaElement>) => {
                setResearchFields(e.target.value);
                autoResize(e.target, 3);
              }}
              placeholder="例：自然言語処理，特に大規模言語モデルの効率化と知識蒸留に関心があります．また，マルチモーダル学習や対話システムにも興味を持っています．"
              rows={3}
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 resize-y"
              maxLength={2000}
            />
            <p className="text-xs text-gray-400 mt-1 text-right">
              {researchFields.length} / 2000
            </p>
          </div>

          {/* Summary Perspective */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              要約してほしい観点
            </label>
            <p className="text-xs text-gray-500 mb-2">
              論文を要約する際に，どのような観点を重視してほしいかを記述してください
            </p>
            <textarea
              ref={summaryPerspectiveRef}
              value={summaryPerspective}
              onChange={(e: ChangeEvent<HTMLTextAreaElement>) => {
                setSummaryPerspective(e.target.value);
                autoResize(e.target, 3);
              }}
              placeholder="例：技術的な新規性や提案手法の詳細を重視してください．また，ベースライン手法との比較結果や実験設定も詳しく知りたいです．"
              rows={3}
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 resize-y"
              maxLength={2000}
            />
            <p className="text-xs text-gray-400 mt-1 text-right">
              {summaryPerspective.length} / 2000
            </p>
          </div>

          {/* Reading Focus */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              論文を読む際の着眼点
            </label>
            <p className="text-xs text-gray-500 mb-2">
              あなたが論文を読む際に，どのような観点に着目するかを記述してください
            </p>
            <textarea
              ref={readingFocusRef}
              value={readingFocus}
              onChange={(e: ChangeEvent<HTMLTextAreaElement>) => {
                setReadingFocus(e.target.value);
                autoResize(e.target, 3);
              }}
              placeholder="例：まず手法のアーキテクチャ図を確認し，次に実験結果の表を見ます．特にアブレーション研究の結果に注目し，各コンポーネントの貢献度を把握します．"
              rows={3}
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 resize-y"
              maxLength={2000}
            />
            <p className="text-xs text-gray-400 mt-1 text-right">
              {readingFocus.length} / 2000
            </p>
          </div>

          {/* Save button */}
          <div className="flex justify-end">
            <button
              type="button"
              onClick={handleSavePerspective}
              disabled={savingPerspective}
              className="flex items-center gap-2 px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              {savingPerspective ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <Save className="w-4 h-4" />
              )}
              調査観点を保存
            </button>
          </div>
        </div>
      </div>

      {/* Summary Template Section */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <div className="flex items-center gap-2 mb-4">
          <FileText className="w-5 h-5 text-gray-600" />
          <h2 className="text-lg font-semibold text-gray-900">要約テンプレート</h2>
        </div>
        <p className="text-sm text-gray-500 mb-4">
          AI要約で出力してほしい項目や形式を指定できます．空欄の場合はデフォルトの形式で要約されます
        </p>

        <div className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              要約形式の指定
            </label>
            <p className="text-xs text-gray-500 mb-2">
              どのような項目について要約してほしいかを記述してください（例：目的，手法，結果，考察など）
            </p>
            <textarea
              ref={summaryTemplateRef}
              value={summaryTemplate}
              onChange={(e: ChangeEvent<HTMLTextAreaElement>) => {
                setSummaryTemplate(e.target.value);
                autoResize(e.target, 6);
              }}
              placeholder={`例：
以下の項目について要約してください：
1. 研究の目的・背景
2. 提案手法の概要
3. 実験設定と使用データセット
4. 主要な実験結果
5. 結論と今後の課題`}
              rows={6}
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 resize-y font-mono text-sm"
              maxLength={5000}
            />
            <p className="text-xs text-gray-400 mt-1 text-right">
              {summaryTemplate.length} / 5000
            </p>
          </div>

          {/* Save button */}
          <div className="flex justify-end">
            <button
              type="button"
              onClick={handleSaveTemplate}
              disabled={savingTemplate}
              className="flex items-center gap-2 px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              {savingTemplate ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <Save className="w-4 h-4" />
              )}
              テンプレートを保存
            </button>
          </div>
        </div>
      </div>
    </main>
  );
}
