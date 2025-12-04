import { useState, FormEvent, ChangeEvent } from 'react';
import { Plus, Edit, X, Check, Loader2, AlertCircle, TestTube } from 'lucide-react';
import api from '../api';
import { AVAILABLE_COLORS, CATEGORIES } from '../constants';
import type { Journal, JournalFormData, RssTestResult } from '../types';

interface JournalModalProps {
  journal: Journal | null;
  onSave: () => void;
  onClose: () => void;
}

export default function JournalModal({ journal, onSave, onClose }: JournalModalProps): JSX.Element {
  const isEdit = !!journal;

  const [formData, setFormData] = useState<JournalFormData>({
    id: journal?.id || '',
    name: journal?.name || '',
    fullName: journal?.full_name || '',
    publisher: journal?.publisher || '',
    rssUrl: journal?.rss_url || '',
    category: journal?.category || 'Other',
    color: journal?.color || 'bg-blue-500',
  });

  const [testing, setTesting] = useState(false);
  const [testResult, setTestResult] = useState<RssTestResult | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

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

  const handleSubmit = async (e: FormEvent<HTMLFormElement>): Promise<void> => {
    e.preventDefault();

    if (!formData.id || !formData.name || !formData.fullName || !formData.publisher || !formData.rssUrl) {
      setError('すべての必須項目を入力してください');
      return;
    }

    setSaving(true);
    setError('');

    try {
      if (isEdit && journal) {
        await api.journals.update(journal.id, formData);
      } else {
        await api.journals.create(formData);
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

          {/* ID / 略称 */}
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                ID <span className="text-red-500">*</span>
              </label>
              <input
                type="text"
                value={formData.id}
                onChange={(e: ChangeEvent<HTMLInputElement>) =>
                  handleChange('id', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))
                }
                disabled={isEdit}
                placeholder="例: ijaied"
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
              />
              <p className="text-xs text-gray-500 mt-1">英小文字・数字・ハイフンのみ</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                略称 <span className="text-red-500">*</span>
              </label>
              <input
                type="text"
                value={formData.name}
                onChange={(e: ChangeEvent<HTMLInputElement>) => handleChange('name', e.target.value)}
                placeholder="例: IJAIED"
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              />
            </div>
          </div>

          {/* 正式名称 */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              正式名称 <span className="text-red-500">*</span>
            </label>
            <input
              type="text"
              value={formData.fullName}
              onChange={(e: ChangeEvent<HTMLInputElement>) => handleChange('fullName', e.target.value)}
              placeholder="例: International Journal of Artificial Intelligence in Education"
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>

          {/* 出版社 / カテゴリ */}
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                出版社 <span className="text-red-500">*</span>
              </label>
              <input
                type="text"
                value={formData.publisher}
                onChange={(e: ChangeEvent<HTMLInputElement>) => handleChange('publisher', e.target.value)}
                placeholder="例: Springer"
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                カテゴリ
              </label>
              <select
                value={formData.category}
                onChange={(e: ChangeEvent<HTMLSelectElement>) => handleChange('category', e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              >
                {CATEGORIES.map((cat) => (
                  <option key={cat} value={cat}>{cat}</option>
                ))}
              </select>
            </div>
          </div>

          {/* RSS URL */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              RSS URL <span className="text-red-500">*</span>
            </label>
            <div className="flex gap-2">
              <input
                type="url"
                value={formData.rssUrl}
                onChange={(e: ChangeEvent<HTMLInputElement>) => handleChange('rssUrl', e.target.value)}
                placeholder="https://..."
                className="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              />
              <button
                type="button"
                onClick={handleTestRss}
                disabled={testing}
                className="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg flex items-center gap-2 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
              >
                {testing ? (
                  <Loader2 className="w-4 h-4 animate-spin" />
                ) : (
                  <TestTube className="w-4 h-4" />
                )}
                テスト
              </button>
            </div>
          </div>

          {/* テスト結果 */}
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
                      ? 'ring-2 ring-offset-2 ring-indigo-500 scale-110'
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
              className="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed font-medium flex items-center justify-center gap-2 transition-colors"
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
