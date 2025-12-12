import { useState, useEffect, ChangeEvent } from 'react';
import { X, Save, Loader2, CheckCircle, AlertCircle, Compass } from 'lucide-react';
import api from '../api';
import type { ResearchPerspective } from '../types';

interface ResearchPerspectiveModalProps {
  isOpen: boolean;
  onClose: () => void;
}

export default function ResearchPerspectiveModal({
  isOpen,
  onClose,
}: ResearchPerspectiveModalProps): JSX.Element | null {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  const [researchFields, setResearchFields] = useState('');
  const [summaryPerspective, setSummaryPerspective] = useState('');
  const [readingFocus, setReadingFocus] = useState('');

  // Fetch current settings when modal opens
  useEffect(() => {
    if (isOpen) {
      fetchSettings();
    }
  }, [isOpen]);

  const fetchSettings = async (): Promise<void> => {
    setLoading(true);
    setMessage(null);

    try {
      const response = await api.settings.getResearchPerspective();
      const perspective: ResearchPerspective = response.research_perspective;
      setResearchFields(perspective.research_fields || '');
      setSummaryPerspective(perspective.summary_perspective || '');
      setReadingFocus(perspective.reading_focus || '');
    } catch (error) {
      setMessage({ type: 'error', text: '設定の取得に失敗しました' });
    } finally {
      setLoading(false);
    }
  };

  const handleSave = async (): Promise<void> => {
    setSaving(true);
    setMessage(null);

    try {
      const response = await api.settings.updateResearchPerspective({
        research_fields: researchFields,
        summary_perspective: summaryPerspective,
        reading_focus: readingFocus,
      });
      setMessage({ type: 'success', text: response.message });
      // Auto close after success
      setTimeout(() => {
        onClose();
      }, 1500);
    } catch (error) {
      setMessage({ type: 'error', text: (error as Error).message || '保存に失敗しました' });
    } finally {
      setSaving(false);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200">
          <div className="flex items-center gap-2">
            <Compass className="w-5 h-5 text-gray-600" />
            <h2 className="text-lg font-semibold text-gray-900">調査観点設定</h2>
          </div>
          <button
            onClick={onClose}
            className="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Content */}
        <div className="p-6 overflow-y-auto max-h-[calc(90vh-140px)]">
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
                <CheckCircle className="w-5 h-5 flex-shrink-0" />
              ) : (
                <AlertCircle className="w-5 h-5 flex-shrink-0" />
              )}
              {message.text}
            </div>
          )}

          {loading ? (
            <div className="flex items-center justify-center py-12">
              <Loader2 className="w-8 h-8 animate-spin text-gray-600" />
            </div>
          ) : (
            <div className="space-y-6">
              <p className="text-sm text-gray-600">
                ここで設定した内容は，AI要約の生成時に参照されます．あなたの研究分野や興味に合わせた要約を生成するために活用されます．
              </p>

              {/* Research Fields */}
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  研究分野・興味のある観点
                </label>
                <p className="text-xs text-gray-500 mb-2">
                  あなたがどのような研究分野や観点に興味があるかを記述してください．
                </p>
                <textarea
                  value={researchFields}
                  onChange={(e: ChangeEvent<HTMLTextAreaElement>) => setResearchFields(e.target.value)}
                  placeholder="例：自然言語処理，特に大規模言語モデルの効率化と知識蒸留に関心があります．また，マルチモーダル学習や対話システムにも興味を持っています．"
                  rows={4}
                  className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 resize-none"
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
                  論文を要約する際に，どのような観点を重視してほしいかを記述してください．
                </p>
                <textarea
                  value={summaryPerspective}
                  onChange={(e: ChangeEvent<HTMLTextAreaElement>) => setSummaryPerspective(e.target.value)}
                  placeholder="例：技術的な新規性や提案手法の詳細を重視してください．また，ベースライン手法との比較結果や実験設定も詳しく知りたいです．"
                  rows={4}
                  className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 resize-none"
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
                  あなたが論文を読む際に，どのような観点に着目するかを記述してください．
                </p>
                <textarea
                  value={readingFocus}
                  onChange={(e: ChangeEvent<HTMLTextAreaElement>) => setReadingFocus(e.target.value)}
                  placeholder="例：まず手法のアーキテクチャ図を確認し，次に実験結果の表を見ます．特にアブレーション研究の結果に注目し，各コンポーネントの貢献度を把握します．"
                  rows={4}
                  className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 resize-none"
                  maxLength={2000}
                />
                <p className="text-xs text-gray-400 mt-1 text-right">
                  {readingFocus.length} / 2000
                </p>
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex justify-end gap-3 px-6 py-4 border-t border-gray-200 bg-gray-50">
          <button
            onClick={onClose}
            className="px-4 py-2 text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
          >
            キャンセル
          </button>
          <button
            onClick={handleSave}
            disabled={saving || loading}
            className="flex items-center gap-2 px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            {saving ? (
              <Loader2 className="w-4 h-4 animate-spin" />
            ) : (
              <Save className="w-4 h-4" />
            )}
            保存
          </button>
        </div>
      </div>
    </div>
  );
}
