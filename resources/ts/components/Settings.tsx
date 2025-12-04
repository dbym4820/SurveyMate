import { useState, useEffect, ChangeEvent, FormEvent } from 'react';
import { ArrowLeft, Key, Eye, EyeOff, Save, Trash2, Loader2, CheckCircle, AlertCircle } from 'lucide-react';
import api from '../api';
import type { ApiSettings } from '../types';

interface SettingsProps {
  onBack: () => void;
}

export default function Settings({ onBack }: SettingsProps): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [settings, setSettings] = useState<ApiSettings | null>(null);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Form state
  const [claudeApiKey, setClaudeApiKey] = useState('');
  const [openaiApiKey, setOpenaiApiKey] = useState('');
  const [preferredProvider, setPreferredProvider] = useState('claude');
  const [showClaudeKey, setShowClaudeKey] = useState(false);
  const [showOpenaiKey, setShowOpenaiKey] = useState(false);

  // Fetch current settings
  useEffect(() => {
    async function fetchSettings(): Promise<void> {
      try {
        const data = await api.settings.getApi();
        setSettings(data);
        setPreferredProvider(data.preferred_ai_provider || 'claude');
      } catch (error) {
        setMessage({ type: 'error', text: '設定の取得に失敗しました' });
      } finally {
        setLoading(false);
      }
    }
    fetchSettings();
  }, []);

  // Save settings
  const handleSubmit = async (e: FormEvent): Promise<void> => {
    e.preventDefault();
    setSaving(true);
    setMessage(null);

    try {
      const updateData: Record<string, string | null> = {
        preferred_ai_provider: preferredProvider,
      };

      if (claudeApiKey) {
        updateData.claude_api_key = claudeApiKey;
      }
      if (openaiApiKey) {
        updateData.openai_api_key = openaiApiKey;
      }

      const result = await api.settings.updateApi(updateData);

      setSettings({
        claude_api_key_set: result.claude_api_key_set,
        claude_api_key_masked: settings?.claude_api_key_masked || null,
        openai_api_key_set: result.openai_api_key_set,
        openai_api_key_masked: settings?.openai_api_key_masked || null,
        preferred_ai_provider: result.preferred_ai_provider,
        available_providers: result.available_providers,
      });

      // Clear input fields after successful save
      setClaudeApiKey('');
      setOpenaiApiKey('');
      setMessage({ type: 'success', text: '設定を保存しました' });

      // Refresh settings to get new masked keys
      const refreshed = await api.settings.getApi();
      setSettings(refreshed);
    } catch (error) {
      setMessage({ type: 'error', text: (error as Error).message || '保存に失敗しました' });
    } finally {
      setSaving(false);
    }
  };

  // Delete API key
  const handleDeleteKey = async (provider: 'claude' | 'openai'): Promise<void> => {
    if (!confirm(`${provider === 'claude' ? 'Claude' : 'OpenAI'} APIキーを削除しますか？`)) {
      return;
    }

    setSaving(true);
    try {
      const result = await api.settings.deleteApiKey(provider);
      setSettings({
        claude_api_key_set: result.claude_api_key_set,
        claude_api_key_masked: provider === 'claude' ? null : settings?.claude_api_key_masked || null,
        openai_api_key_set: result.openai_api_key_set,
        openai_api_key_masked: provider === 'openai' ? null : settings?.openai_api_key_masked || null,
        preferred_ai_provider: result.preferred_ai_provider,
        available_providers: result.available_providers,
      });
      setMessage({ type: 'success', text: 'APIキーを削除しました' });
    } catch (error) {
      setMessage({ type: 'error', text: (error as Error).message || '削除に失敗しました' });
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <Loader2 className="w-8 h-8 animate-spin text-indigo-600" />
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Header */}
      <header className="bg-white border-b border-gray-200 sticky top-0 z-10 shadow-sm">
        <div className="max-w-4xl mx-auto px-4 py-4">
          <div className="flex items-center gap-4">
            <button
              onClick={onBack}
              className="p-2 hover:bg-gray-100 rounded-lg transition-colors"
            >
              <ArrowLeft className="w-5 h-5" />
            </button>
            <div className="flex items-center gap-2">
              <Key className="w-5 h-5 text-indigo-600" />
              <h1 className="text-xl font-bold text-gray-900">API設定</h1>
            </div>
          </div>
        </div>
      </header>

      {/* Main content */}
      <main className="max-w-4xl mx-auto px-4 py-6">
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

        <form onSubmit={handleSubmit}>
          {/* Claude API Key */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <div className="flex items-center justify-between mb-4">
              <div>
                <h2 className="text-lg font-semibold text-gray-900">Claude API Key</h2>
                <p className="text-sm text-gray-500">
                  Anthropic Claude APIのキーを設定します
                </p>
              </div>
              {settings?.claude_api_key_set && (
                <span className="px-2 py-1 text-xs font-medium bg-green-100 text-green-700 rounded">
                  設定済み
                </span>
              )}
            </div>

            {settings?.claude_api_key_masked && (
              <div className="mb-4 p-3 bg-gray-50 rounded-lg">
                <p className="text-sm text-gray-600">
                  現在のキー: <code className="font-mono">{settings.claude_api_key_masked}</code>
                </p>
              </div>
            )}

            <div className="flex gap-2">
              <div className="flex-1 relative">
                <input
                  type={showClaudeKey ? 'text' : 'password'}
                  value={claudeApiKey}
                  onChange={(e: ChangeEvent<HTMLInputElement>) => setClaudeApiKey(e.target.value)}
                  placeholder={settings?.claude_api_key_set ? '新しいキーを入力して更新' : 'sk-ant-...'}
                  className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 font-mono text-sm"
                />
                <button
                  type="button"
                  onClick={() => setShowClaudeKey(!showClaudeKey)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                >
                  {showClaudeKey ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
              {settings?.claude_api_key_set && (
                <button
                  type="button"
                  onClick={() => handleDeleteKey('claude')}
                  disabled={saving}
                  className="px-4 py-2 text-red-600 border border-red-300 rounded-lg hover:bg-red-50 disabled:opacity-50"
                >
                  <Trash2 className="w-4 h-4" />
                </button>
              )}
            </div>
          </div>

          {/* OpenAI API Key */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <div className="flex items-center justify-between mb-4">
              <div>
                <h2 className="text-lg font-semibold text-gray-900">OpenAI API Key</h2>
                <p className="text-sm text-gray-500">
                  OpenAI APIのキーを設定します
                </p>
              </div>
              {settings?.openai_api_key_set && (
                <span className="px-2 py-1 text-xs font-medium bg-green-100 text-green-700 rounded">
                  設定済み
                </span>
              )}
            </div>

            {settings?.openai_api_key_masked && (
              <div className="mb-4 p-3 bg-gray-50 rounded-lg">
                <p className="text-sm text-gray-600">
                  現在のキー: <code className="font-mono">{settings.openai_api_key_masked}</code>
                </p>
              </div>
            )}

            <div className="flex gap-2">
              <div className="flex-1 relative">
                <input
                  type={showOpenaiKey ? 'text' : 'password'}
                  value={openaiApiKey}
                  onChange={(e: ChangeEvent<HTMLInputElement>) => setOpenaiApiKey(e.target.value)}
                  placeholder={settings?.openai_api_key_set ? '新しいキーを入力して更新' : 'sk-...'}
                  className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 font-mono text-sm"
                />
                <button
                  type="button"
                  onClick={() => setShowOpenaiKey(!showOpenaiKey)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                >
                  {showOpenaiKey ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
              {settings?.openai_api_key_set && (
                <button
                  type="button"
                  onClick={() => handleDeleteKey('openai')}
                  disabled={saving}
                  className="px-4 py-2 text-red-600 border border-red-300 rounded-lg hover:bg-red-50 disabled:opacity-50"
                >
                  <Trash2 className="w-4 h-4" />
                </button>
              )}
            </div>
          </div>

          {/* Preferred Provider */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">優先プロバイダ</h2>
            <p className="text-sm text-gray-500 mb-4">
              要約生成時にデフォルトで使用するAIプロバイダを選択します
            </p>
            <select
              value={preferredProvider}
              onChange={(e: ChangeEvent<HTMLSelectElement>) => setPreferredProvider(e.target.value)}
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            >
              <option value="claude">Claude (Anthropic)</option>
              <option value="openai">OpenAI (GPT)</option>
            </select>
          </div>

          {/* Submit button */}
          <div className="flex justify-end">
            <button
              type="submit"
              disabled={saving}
              className="flex items-center gap-2 px-6 py-3 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors"
            >
              {saving ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <Save className="w-4 h-4" />
              )}
              設定を保存
            </button>
          </div>
        </form>
      </main>
    </div>
  );
}
