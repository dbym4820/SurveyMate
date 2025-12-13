import { useState, useEffect, ChangeEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Loader2, CheckCircle, AlertCircle, Bot, Eye, EyeOff,
  Compass, FileText, Rocket, Info, User,
} from 'lucide-react';
import api, { getBasePath } from '../api';

interface InitialSetupProps {
  onComplete: () => void;
}

export default function InitialSetup({ onComplete }: InitialSetupProps): JSX.Element {
  const navigate = useNavigate();
  const [saving, setSaving] = useState(false);
  const [loadingDefaults, setLoadingDefaults] = useState(true);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Research Fields state (required)
  const [researchFields, setResearchFields] = useState('');

  // Research Perspective state (optional)
  const [summaryPerspective, setSummaryPerspective] = useState('');
  const [readingFocus, setReadingFocus] = useState('');

  // Summary Template state
  const [summaryTemplate, setSummaryTemplate] = useState('');

  // API Settings state
  const [claudeApiKey, setClaudeApiKey] = useState('');
  const [openaiApiKey, setOpenaiApiKey] = useState('');
  const [showClaudeKey, setShowClaudeKey] = useState(false);
  const [showOpenaiKey, setShowOpenaiKey] = useState(false);

  // Track if defaults exist
  const [hasDefaults, setHasDefaults] = useState(false);

  // Calculate rows based on text content
  const calculateRows = (text: string, minRows: number = 2, maxRows: number = 15): number => {
    if (!text) return minRows;
    const lines = text.split('\n');
    const wrappedLines = lines.reduce((count, line) => {
      return count + Math.max(1, Math.ceil(line.length / 60));
    }, 0);
    return Math.max(minRows, Math.min(wrappedLines + 1, maxRows));
  };

  // Load default values on mount
  useEffect(() => {
    const loadDefaults = async () => {
      try {
        const [perspectiveRes, templateRes] = await Promise.all([
          api.settings.getResearchPerspective(),
          api.settings.getSummaryTemplate(),
        ]);

        const perspective = perspectiveRes.research_perspective;
        const template = templateRes.summary_template;

        // Set defaults if they exist (research_fields is intentionally left empty for user input)
        if (perspective.summary_perspective) setSummaryPerspective(perspective.summary_perspective);
        if (perspective.reading_focus) setReadingFocus(perspective.reading_focus);
        if (template) setSummaryTemplate(template);

        // Check if any defaults exist (excluding research_fields)
        const defaultsExist = !!(
          perspective.summary_perspective ||
          perspective.reading_focus ||
          template
        );
        setHasDefaults(defaultsExist);
      } catch (error) {
        console.error('Failed to load defaults:', error);
      } finally {
        setLoadingDefaults(false);
      }
    };

    loadDefaults();
  }, []);

  // Handle save
  const handleSave = async (): Promise<void> => {
    // Validate required field
    if (!researchFields.trim()) {
      setMessage({ type: 'error', text: '研究分野を入力してください' });
      return;
    }

    setSaving(true);
    setMessage(null);

    try {
      await api.settings.completeInitialSetup({
        claude_api_key: claudeApiKey || undefined,
        openai_api_key: openaiApiKey || undefined,
        research_fields: researchFields,
        summary_perspective: summaryPerspective || undefined,
        reading_focus: readingFocus || undefined,
        summary_template: summaryTemplate || undefined,
      });

      setMessage({ type: 'success', text: '初期設定が完了しました' });

      // Wait a moment to show success message
      setTimeout(() => {
        onComplete();
        navigate('/papers');
      }, 1000);
    } catch (error) {
      setMessage({ type: 'error', text: (error as Error).message || '保存に失敗しました' });
      setSaving(false);
    }
  };

  if (loadingDefaults) {
    return (
      <div className="min-h-screen bg-gradient-to-br from-gray-100 via-purple-50 to-pink-100 flex items-center justify-center">
        <Loader2 className="w-8 h-8 animate-spin text-gray-600" />
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-gray-100 via-purple-50 to-pink-100 py-8 px-4">
      <div className="w-[85%] max-w-4xl mx-auto">
        {/* Header */}
        <div className="text-center mb-6">
          <div className="flex items-center justify-center gap-3 mb-4">
            <img src={`${getBasePath()}/favicon.ico`} alt="SurveyMate" className="w-12 h-12" />
            <h1 className="text-2xl font-bold text-gray-900">SurveyMate</h1>
          </div>
          <h2 className="text-xl font-semibold text-gray-800 mb-2 flex items-center justify-center gap-2">
            <Rocket className="w-5 h-5" />
            初期設定
          </h2>
          <p className="text-gray-600">
            AI要約機能を使用するための設定を行います
          </p>
        </div>

        {/* Info banner */}
        <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 flex items-start gap-3">
          <Info className="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" />
          <div className="text-sm text-blue-800">
            <p className="font-medium mb-1">これらの情報は後からいつでも編集できます</p>
            <p className="text-blue-700">
              研究分野以外の項目は任意です．「設定」ページからいつでも変更できますので，まずはデフォルト値のままお試しください．
            </p>
          </div>
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

        {/* Research Fields Section (Required) */}
        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
          <div className="flex items-center gap-2 mb-4">
            <User className="w-5 h-5 text-gray-600" />
            <h2 className="text-lg font-semibold text-gray-900">研究分野</h2>
            <span className="text-xs text-red-600 bg-red-50 px-2 py-0.5 rounded border border-red-200">必須</span>
          </div>
          <p className="text-sm text-gray-500 mb-4">
            あなたの研究分野や興味のある領域を入力してください．AI要約があなたの専門に合わせた内容になります．
          </p>

          <textarea
            value={researchFields}
            onChange={(e: ChangeEvent<HTMLTextAreaElement>) => setResearchFields(e.target.value)}
            placeholder="例：自然言語処理，特に大規模言語モデルの効率化と知識蒸留に関心があります．"
            rows={calculateRows(researchFields, 3)}
            className={`w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 resize-y min-h-[80px] text-sm ${
              !researchFields.trim() ? 'border-red-300 bg-red-50/30' : 'border-gray-300'
            }`}
            maxLength={2000}
          />
          {!researchFields.trim() && (
            <p className="text-xs text-red-500 mt-1">この項目は必須です</p>
          )}
        </div>

        {/* Research Perspective Section (Optional) */}
        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
          <div className="flex items-center gap-2 mb-4">
            <Compass className="w-5 h-5 text-gray-600" />
            <h2 className="text-lg font-semibold text-gray-900">調査観点</h2>
            <span className="text-xs text-gray-500 bg-gray-100 px-2 py-0.5 rounded">任意</span>
          </div>
          <p className="text-sm text-gray-500 mb-2">
            要約の観点や論文を読む際の着眼点を設定できます
          </p>
          {hasDefaults && (
            <p className="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded px-3 py-2 mb-4">
              デフォルト値が設定されています．問題なければそのままご利用ください．
            </p>
          )}

          <div className="space-y-4">
            {/* Summary Perspective */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                要約してほしい観点
              </label>
              <textarea
                value={summaryPerspective}
                onChange={(e: ChangeEvent<HTMLTextAreaElement>) => setSummaryPerspective(e.target.value)}
                placeholder="例：技術的な新規性や提案手法の詳細を重視してください．"
                rows={calculateRows(summaryPerspective, 2)}
                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 resize-y min-h-[60px] text-sm"
                maxLength={2000}
              />
            </div>

            {/* Reading Focus */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                論文を読む際の着眼点
              </label>
              <textarea
                value={readingFocus}
                onChange={(e: ChangeEvent<HTMLTextAreaElement>) => setReadingFocus(e.target.value)}
                placeholder="例：まず手法のアーキテクチャを確認し，次に実験結果の表を見ます．"
                rows={calculateRows(readingFocus, 2)}
                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 resize-y min-h-[60px] text-sm"
                maxLength={2000}
              />
            </div>
          </div>
        </div>

        {/* Summary Template Section */}
        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
          <div className="flex items-center gap-2 mb-4">
            <FileText className="w-5 h-5 text-gray-600" />
            <h2 className="text-lg font-semibold text-gray-900">要約テンプレート</h2>
            <span className="text-xs text-gray-500 bg-gray-100 px-2 py-0.5 rounded">任意</span>
          </div>
          <p className="text-sm text-gray-500 mb-2">
            AI要約で出力してほしい項目や形式を指定できます
          </p>
          {hasDefaults && summaryTemplate && (
            <p className="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded px-3 py-2 mb-4">
              デフォルト値が設定されています．問題なければそのままご利用ください．
            </p>
          )}

          <div>
            <textarea
              value={summaryTemplate}
              onChange={(e: ChangeEvent<HTMLTextAreaElement>) => setSummaryTemplate(e.target.value)}
              placeholder={`例：
以下の項目について要約してください：
1. 研究の目的・背景
2. 提案手法の概要
3. 主要な実験結果
4. 結論と今後の課題`}
              rows={calculateRows(summaryTemplate, 5, 30)}
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 resize-y min-h-[120px] font-mono text-sm"
              maxLength={5000}
            />
          </div>
        </div>

        {/* AI API Settings Section */}
        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
          <div className="flex items-center gap-2 mb-4">
            <Bot className="w-5 h-5 text-gray-600" />
            <h2 className="text-lg font-semibold text-gray-900">生成AI API設定</h2>
            <span className="text-xs text-gray-500 bg-gray-100 px-2 py-0.5 rounded">任意</span>
          </div>
          <p className="text-sm text-gray-500 mb-2">
            論文要約に使用するAPIキーを設定します（どちらか一方でOK）
          </p>
          <p className="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded px-3 py-2 mb-4">
            この項目は必須ではありません．後から「設定」ページでいつでも追加・変更できます．
          </p>

          {/* OpenAI API Key */}
          <div className="border border-gray-200 rounded-lg p-4 mb-4">
            <div className="flex items-center justify-between mb-3">
              <div>
                <h3 className="text-sm font-semibold text-gray-900">OpenAI API Key</h3>
                <p className="text-xs text-gray-500">OpenAI APIのキーを設定します</p>
              </div>
              {openaiApiKey && (
                <span className="px-2 py-1 text-xs font-medium bg-green-100 text-green-700 rounded">
                  入力済み
                </span>
              )}
            </div>

            <div className="relative">
              <input
                type={showOpenaiKey ? 'text' : 'password'}
                value={openaiApiKey}
                onChange={(e: ChangeEvent<HTMLInputElement>) => setOpenaiApiKey(e.target.value)}
                placeholder="sk-..."
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 font-mono text-sm"
              />
              <button
                type="button"
                onClick={() => setShowOpenaiKey(!showOpenaiKey)}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
              >
                {showOpenaiKey ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
              </button>
            </div>
          </div>

          {/* Claude API Key */}
          <div className="border border-gray-200 rounded-lg p-4">
            <div className="flex items-center justify-between mb-3">
              <div>
                <h3 className="text-sm font-semibold text-gray-900">Claude API Key</h3>
                <p className="text-xs text-gray-500">Anthropic Claude APIのキーを設定します</p>
              </div>
              {claudeApiKey && (
                <span className="px-2 py-1 text-xs font-medium bg-green-100 text-green-700 rounded">
                  入力済み
                </span>
              )}
            </div>

            <div className="relative">
              <input
                type={showClaudeKey ? 'text' : 'password'}
                value={claudeApiKey}
                onChange={(e: ChangeEvent<HTMLInputElement>) => setClaudeApiKey(e.target.value)}
                placeholder="sk-ant-..."
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 font-mono text-sm"
              />
              <button
                type="button"
                onClick={() => setShowClaudeKey(!showClaudeKey)}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
              >
                {showClaudeKey ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
              </button>
            </div>
          </div>
        </div>

        {/* Action Button */}
        <div className="flex justify-center">
          <button
            type="button"
            onClick={handleSave}
            disabled={saving || !researchFields.trim()}
            className="flex items-center gap-2 px-8 py-3 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Rocket className="w-4 h-4" />}
            始める
          </button>
        </div>
      </div>
    </div>
  );
}
