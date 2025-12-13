import { useState, useEffect, ChangeEvent, useMemo } from 'react';
import {
  Save, Loader2, CheckCircle, AlertCircle, Bot, Eye, EyeOff, Trash2,
} from 'lucide-react';
import api from '../api';
import type { ApiSettings as ApiSettingsType, AIProvider } from '../types';

export default function ApiSettings(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // API Settings state
  const [settings, setSettings] = useState<ApiSettingsType | null>(null);
  const [savingOpenai, setSavingOpenai] = useState(false);
  const [savingClaude, setSavingClaude] = useState(false);
  const [savingPreferences, setSavingPreferences] = useState(false);
  const [claudeApiKey, setClaudeApiKey] = useState('');
  const [openaiApiKey, setOpenaiApiKey] = useState('');
  const [selectedProvider, setSelectedProvider] = useState('');
  const [selectedModel, setSelectedModel] = useState('');
  const [showClaudeKey, setShowClaudeKey] = useState(false);
  const [showOpenaiKey, setShowOpenaiKey] = useState(false);

  // Get available providers (only those with API keys configured)
  const availableProviders = useMemo(() => {
    const providers: { id: string; name: string }[] = [];
    if (settings?.openai_api_key_set) {
      providers.push({ id: 'openai', name: 'OpenAI (GPT)' });
    }
    if (settings?.claude_api_key_set) {
      providers.push({ id: 'claude', name: 'Claude (Anthropic)' });
    }
    return providers;
  }, [settings?.openai_api_key_set, settings?.claude_api_key_set]);

  // Get models for the selected provider
  const availableModels = useMemo(() => {
    if (!selectedProvider || !settings?.available_providers) {
      return [];
    }
    const provider = settings.available_providers.find((p: AIProvider) => p.id === selectedProvider);
    return provider?.models || [];
  }, [selectedProvider, settings?.available_providers]);

  // Fetch settings on mount
  useEffect(() => {
    async function fetchSettings(): Promise<void> {
      try {
        const apiRes = await api.settings.getApi();
        setSettings(apiRes);
        const provider = apiRes.preferred_ai_provider || '';
        setSelectedProvider(provider);
        if (provider === 'openai') {
          setSelectedModel(apiRes.preferred_openai_model || 'gpt-4o');
        } else if (provider === 'claude') {
          setSelectedModel(apiRes.preferred_claude_model || 'claude-sonnet-4-20250514');
        }
      } catch (error) {
        setMessage({ type: 'error', text: '設定の取得に失敗しました' });
      } finally {
        setLoading(false);
      }
    }
    fetchSettings();
  }, []);

  // Update selected model when provider changes
  useEffect(() => {
    if (!settings) return;
    if (selectedProvider === 'openai') {
      setSelectedModel(settings.preferred_openai_model || 'gpt-4o');
    } else if (selectedProvider === 'claude') {
      setSelectedModel(settings.preferred_claude_model || 'claude-sonnet-4-20250514');
    } else {
      setSelectedModel('');
    }
  }, [selectedProvider, settings]);

  // Helper to update settings state after API response
  const updateSettingsFromResponse = (result: {
    claude_api_key_set: boolean;
    openai_api_key_set: boolean;
    preferred_ai_provider: string;
    preferred_openai_model: string | null;
    preferred_claude_model: string | null;
    available_providers: AIProvider[];
  }) => {
    setSettings(prev => ({
      ...prev!,
      claude_api_key_set: result.claude_api_key_set,
      openai_api_key_set: result.openai_api_key_set,
      preferred_ai_provider: result.preferred_ai_provider,
      preferred_openai_model: result.preferred_openai_model,
      preferred_claude_model: result.preferred_claude_model,
      available_providers: result.available_providers,
    }));
  };

  // Save OpenAI API Key
  const handleSaveOpenaiKey = async (): Promise<void> => {
    if (!openaiApiKey) {
      setMessage({ type: 'error', text: 'OpenAI APIキーを入力してください' });
      return;
    }

    setSavingOpenai(true);
    setMessage(null);

    try {
      const result = await api.settings.updateApi({ openai_api_key: openaiApiKey });
      updateSettingsFromResponse(result);
      setOpenaiApiKey('');
      setMessage({ type: 'success', text: 'OpenAI APIキーを保存しました' });

      const refreshed = await api.settings.getApi();
      setSettings(refreshed);

      if (!selectedProvider) {
        setSelectedProvider('openai');
        setSelectedModel(refreshed.preferred_openai_model || 'gpt-4o');
      }

      // ユーザー情報を更新（トレンドボタン表示のため）
      window.dispatchEvent(new CustomEvent('user-updated'));
    } catch (error) {
      setMessage({ type: 'error', text: (error as Error).message || '保存に失敗しました' });
    } finally {
      setSavingOpenai(false);
    }
  };

  // Save Claude API Key
  const handleSaveClaudeKey = async (): Promise<void> => {
    if (!claudeApiKey) {
      setMessage({ type: 'error', text: 'Claude APIキーを入力してください' });
      return;
    }

    setSavingClaude(true);
    setMessage(null);

    try {
      const result = await api.settings.updateApi({ claude_api_key: claudeApiKey });
      updateSettingsFromResponse(result);
      setClaudeApiKey('');
      setMessage({ type: 'success', text: 'Claude APIキーを保存しました' });

      const refreshed = await api.settings.getApi();
      setSettings(refreshed);

      if (!selectedProvider) {
        setSelectedProvider('claude');
        setSelectedModel(refreshed.preferred_claude_model || 'claude-sonnet-4-20250514');
      }

      // ユーザー情報を更新（トレンドボタン表示のため）
      window.dispatchEvent(new CustomEvent('user-updated'));
    } catch (error) {
      setMessage({ type: 'error', text: (error as Error).message || '保存に失敗しました' });
    } finally {
      setSavingClaude(false);
    }
  };

  // Save Provider & Model Selection
  const handleSavePreferences = async (): Promise<void> => {
    if (!selectedProvider) {
      setMessage({ type: 'error', text: '使用するプロバイダを選択してください' });
      return;
    }

    setSavingPreferences(true);
    setMessage(null);

    try {
      const updateData: Record<string, string> = {
        preferred_ai_provider: selectedProvider,
      };

      if (selectedProvider === 'openai') {
        updateData.preferred_openai_model = selectedModel;
      } else if (selectedProvider === 'claude') {
        updateData.preferred_claude_model = selectedModel;
      }

      const result = await api.settings.updateApi(updateData);
      updateSettingsFromResponse(result);
      setMessage({ type: 'success', text: '使用プロバイダ・モデルを保存しました' });
    } catch (error) {
      setMessage({ type: 'error', text: (error as Error).message || '保存に失敗しました' });
    } finally {
      setSavingPreferences(false);
    }
  };

  // Delete API key
  const handleDeleteKey = async (provider: 'claude' | 'openai'): Promise<void> => {
    if (!confirm(`${provider === 'claude' ? 'Claude' : 'OpenAI'} APIキーを削除しますか？`)) {
      return;
    }

    if (provider === 'openai') {
      setSavingOpenai(true);
    } else {
      setSavingClaude(true);
    }

    try {
      const result = await api.settings.deleteApiKey(provider);
      setSettings({
        claude_api_key_set: result.claude_api_key_set,
        claude_api_key_masked: provider === 'claude' ? null : settings?.claude_api_key_masked || null,
        openai_api_key_set: result.openai_api_key_set,
        openai_api_key_masked: provider === 'openai' ? null : settings?.openai_api_key_masked || null,
        preferred_ai_provider: result.preferred_ai_provider,
        preferred_openai_model: result.preferred_openai_model,
        preferred_claude_model: result.preferred_claude_model,
        available_providers: result.available_providers,
      });

      if (selectedProvider === provider) {
        const otherProvider = provider === 'openai' ? 'claude' : 'openai';
        const otherAvailable = provider === 'openai' ? result.claude_api_key_set : result.openai_api_key_set;
        if (otherAvailable) {
          setSelectedProvider(otherProvider);
        } else {
          setSelectedProvider('');
          setSelectedModel('');
        }
      }

      setMessage({ type: 'success', text: 'APIキーを削除しました' });

      // ユーザー情報を更新（トレンドボタン非表示のため）
      window.dispatchEvent(new CustomEvent('user-updated'));
    } catch (error) {
      setMessage({ type: 'error', text: (error as Error).message || '削除に失敗しました' });
    } finally {
      setSavingOpenai(false);
      setSavingClaude(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="w-8 h-8 animate-spin text-gray-600" />
      </div>
    );
  }

  const hasAnyApiKey = settings?.openai_api_key_set || settings?.claude_api_key_set;

  return (
    <main className="w-[85%] mx-auto py-6">
      {/* Header */}
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
          <Bot className="w-6 h-6" />
          生成AI設定
        </h1>
        <p className="text-sm text-gray-500 mt-1">
          論文要約に使用するAPIキーとモデルを設定します
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

      {/* AI API Settings Section */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <div className="flex items-center gap-2 mb-4">
          <Bot className="w-5 h-5 text-gray-600" />
          <h2 className="text-lg font-semibold text-gray-900">APIキー設定</h2>
        </div>
        <p className="text-sm text-gray-500 mb-4">
          OpenAIまたはClaude（Anthropic）のAPIキーを設定してください
        </p>

        {/* OpenAI API Key */}
        <div className="border border-gray-200 rounded-lg p-4 mb-4">
          <div className="flex items-center justify-between mb-3">
            <div>
              <h3 className="text-sm font-semibold text-gray-900">OpenAI API Key</h3>
              <p className="text-xs text-gray-500">OpenAI APIのキーを設定します</p>
            </div>
            {settings?.openai_api_key_set && (
              <span className={`px-2 py-1 text-xs font-medium rounded ${
                settings?.openai_api_key_from_env
                  ? 'bg-blue-100 text-blue-700'
                  : 'bg-green-100 text-green-700'
              }`}>
                {settings?.openai_api_key_from_env ? '.env設定' : '設定済み'}
              </span>
            )}
          </div>

          {settings?.openai_api_key_masked && (
            <div className={`mb-3 p-2 rounded ${
              settings?.openai_api_key_from_env ? 'bg-blue-50' : 'bg-gray-50'
            }`}>
              <p className="text-xs text-gray-600">
                現在のキー: <code className="font-mono">{settings.openai_api_key_masked}</code>
                {settings?.openai_api_key_from_env && (
                  <span className="ml-2 text-blue-600">（サーバー設定から自動反映）</span>
                )}
              </p>
            </div>
          )}

          <div className="flex gap-2">
            <div className="flex-1 relative">
              <input
                type={showOpenaiKey ? 'text' : 'password'}
                value={openaiApiKey}
                onChange={(e: ChangeEvent<HTMLInputElement>) => setOpenaiApiKey(e.target.value)}
                placeholder={settings?.openai_api_key_set
                  ? (settings?.openai_api_key_from_env ? '個別キーを設定して上書き' : '新しいキーを入力して更新')
                  : 'sk-...'}
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
            <button
              type="button"
              onClick={handleSaveOpenaiKey}
              disabled={savingOpenai || !openaiApiKey}
              className="flex items-center gap-1 px-3 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors text-sm"
            >
              {savingOpenai ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
              保存
            </button>
            {settings?.openai_api_key_set && !settings?.openai_api_key_from_env && (
              <button
                type="button"
                onClick={() => handleDeleteKey('openai')}
                disabled={savingOpenai}
                className="px-3 py-2 text-red-600 border border-red-300 rounded-lg hover:bg-red-50 disabled:opacity-50"
              >
                <Trash2 className="w-4 h-4" />
              </button>
            )}
          </div>
        </div>

        {/* Claude API Key */}
        <div className="border border-gray-200 rounded-lg p-4 mb-4">
          <div className="flex items-center justify-between mb-3">
            <div>
              <h3 className="text-sm font-semibold text-gray-900">Claude API Key</h3>
              <p className="text-xs text-gray-500">Anthropic Claude APIのキーを設定します</p>
            </div>
            {settings?.claude_api_key_set && (
              <span className={`px-2 py-1 text-xs font-medium rounded ${
                settings?.claude_api_key_from_env
                  ? 'bg-blue-100 text-blue-700'
                  : 'bg-green-100 text-green-700'
              }`}>
                {settings?.claude_api_key_from_env ? '.env設定' : '設定済み'}
              </span>
            )}
          </div>

          {settings?.claude_api_key_masked && (
            <div className={`mb-3 p-2 rounded ${
              settings?.claude_api_key_from_env ? 'bg-blue-50' : 'bg-gray-50'
            }`}>
              <p className="text-xs text-gray-600">
                現在のキー: <code className="font-mono">{settings.claude_api_key_masked}</code>
                {settings?.claude_api_key_from_env && (
                  <span className="ml-2 text-blue-600">（サーバー設定から自動反映）</span>
                )}
              </p>
            </div>
          )}

          <div className="flex gap-2">
            <div className="flex-1 relative">
              <input
                type={showClaudeKey ? 'text' : 'password'}
                value={claudeApiKey}
                onChange={(e: ChangeEvent<HTMLInputElement>) => setClaudeApiKey(e.target.value)}
                placeholder={settings?.claude_api_key_set
                  ? (settings?.claude_api_key_from_env ? '個別キーを設定して上書き' : '新しいキーを入力して更新')
                  : 'sk-ant-...'}
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
            <button
              type="button"
              onClick={handleSaveClaudeKey}
              disabled={savingClaude || !claudeApiKey}
              className="flex items-center gap-1 px-3 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors text-sm"
            >
              {savingClaude ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
              保存
            </button>
            {settings?.claude_api_key_set && !settings?.claude_api_key_from_env && (
              <button
                type="button"
                onClick={() => handleDeleteKey('claude')}
                disabled={savingClaude}
                className="px-3 py-2 text-red-600 border border-red-300 rounded-lg hover:bg-red-50 disabled:opacity-50"
              >
                <Trash2 className="w-4 h-4" />
              </button>
            )}
          </div>
        </div>

        {/* Provider & Model Selection */}
        <div className="border border-gray-200 rounded-lg p-4">
          <h3 className="text-sm font-semibold text-gray-900 mb-3">使用プロバイダ・モデル</h3>
          <p className="text-xs text-gray-500 mb-3">
            要約生成時に使用するAIプロバイダとモデルを選択します
          </p>

          {!hasAnyApiKey ? (
            <div className="p-3 bg-yellow-50 rounded-lg border border-yellow-200">
              <p className="text-sm text-yellow-800">
                APIキーが設定されていません．上記でOpenAIまたはClaudeのAPIキーを設定してください．
              </p>
            </div>
          ) : (
            <>
              {/* Provider Selection */}
              <div className="mb-3">
                <label className="block text-xs font-medium text-gray-700 mb-1">
                  使用プロバイダ
                </label>
                <select
                  value={selectedProvider}
                  onChange={(e: ChangeEvent<HTMLSelectElement>) => setSelectedProvider(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 text-sm"
                >
                  <option value="">選択してください</option>
                  {availableProviders.map((provider) => (
                    <option key={provider.id} value={provider.id}>
                      {provider.name}
                    </option>
                  ))}
                </select>
              </div>

              {/* Model Selection */}
              {selectedProvider && availableModels.length > 0 && (
                <div className="mb-4">
                  <label className="block text-xs font-medium text-gray-700 mb-1">
                    使用モデル
                  </label>
                  <select
                    value={selectedModel}
                    onChange={(e: ChangeEvent<HTMLSelectElement>) => setSelectedModel(e.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 text-sm"
                  >
                    {availableModels.map((modelId) => (
                      <option key={modelId} value={modelId}>
                        {modelId}
                      </option>
                    ))}
                  </select>
                </div>
              )}

              {/* Save button */}
              <div className="flex justify-end">
                <button
                  type="button"
                  onClick={handleSavePreferences}
                  disabled={savingPreferences || !selectedProvider}
                  className="flex items-center gap-2 px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50 transition-colors text-sm"
                >
                  {savingPreferences ? (
                    <Loader2 className="w-4 h-4 animate-spin" />
                  ) : (
                    <Save className="w-4 h-4" />
                  )}
                  設定を保存
                </button>
              </div>
            </>
          )}
        </div>
      </div>
    </main>
  );
}
