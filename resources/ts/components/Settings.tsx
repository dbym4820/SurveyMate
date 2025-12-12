import { useState, useEffect, ChangeEvent, useMemo } from 'react';
import { Eye, EyeOff, Save, Trash2, Loader2, CheckCircle, AlertCircle, Bell, BellOff, User } from 'lucide-react';
import api from '../api';
import type { ApiSettings, AIProvider, Profile } from '../types';

// Convert base64 string to Uint8Array for applicationServerKey
function urlBase64ToUint8Array(base64String: string): ArrayBuffer {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding)
    .replace(/-/g, '+')
    .replace(/_/g, '/');

  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);

  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray.buffer;
}

export default function Settings(): JSX.Element {
  const [loading, setLoading] = useState(true);
  const [settings, setSettings] = useState<ApiSettings | null>(null);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Separate saving states for each section
  const [savingOpenai, setSavingOpenai] = useState(false);
  const [savingClaude, setSavingClaude] = useState(false);
  const [savingPreferences, setSavingPreferences] = useState(false);
  const [savingProfile, setSavingProfile] = useState(false);

  // Profile state
  const [profile, setProfile] = useState<Profile | null>(null);
  const [editUsername, setEditUsername] = useState('');
  const [editEmail, setEditEmail] = useState('');

  // Form state
  const [claudeApiKey, setClaudeApiKey] = useState('');
  const [openaiApiKey, setOpenaiApiKey] = useState('');
  const [preferredProvider, setPreferredProvider] = useState('openai');
  const [preferredOpenaiModel, setPreferredOpenaiModel] = useState('gpt-4o');
  const [preferredClaudeModel, setPreferredClaudeModel] = useState('claude-sonnet-4-20250514');
  const [showClaudeKey, setShowClaudeKey] = useState(false);
  const [showOpenaiKey, setShowOpenaiKey] = useState(false);

  // Push notification state
  const [pushSupported, setPushSupported] = useState(false);
  const [pushPermission, setPushPermission] = useState<NotificationPermission>('default');
  const [pushSubscribed, setPushSubscribed] = useState(false);
  const [pushConfigured, setPushConfigured] = useState(false);
  const [pushLoading, setPushLoading] = useState(false);

  // Get models from available_providers
  const openaiModels = useMemo(() => {
    const provider = settings?.available_providers?.find((p: AIProvider) => p.id === 'openai');
    return provider?.models || ['gpt-4o'];
  }, [settings?.available_providers]);

  const claudeModels = useMemo(() => {
    const provider = settings?.available_providers?.find((p: AIProvider) => p.id === 'claude');
    return provider?.models || ['claude-sonnet-4-20250514'];
  }, [settings?.available_providers]);

  // Fetch current settings and profile
  useEffect(() => {
    async function fetchSettings(): Promise<void> {
      try {
        const [apiData, profileData] = await Promise.all([
          api.settings.getApi(),
          api.settings.getProfile(),
        ]);
        setSettings(apiData);
        setPreferredProvider(apiData.preferred_ai_provider || 'openai');
        setPreferredOpenaiModel(apiData.preferred_openai_model || 'gpt-4o');
        setPreferredClaudeModel(apiData.preferred_claude_model || 'claude-sonnet-4-20250514');
        setProfile(profileData.profile);
        setEditUsername(profileData.profile.username);
        setEditEmail(profileData.profile.email || '');
      } catch (error) {
        setMessage({ type: 'error', text: '設定の取得に失敗しました' });
      } finally {
        setLoading(false);
      }
    }
    fetchSettings();
  }, []);

  // Check push notification support
  useEffect(() => {
    async function checkPushSupport(): Promise<void> {
      const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
      setPushSupported(supported);

      if (supported) {
        setPushPermission(Notification.permission);

        try {
          const status = await api.push.status();
          setPushConfigured(status.configured);
          setPushSubscribed(status.subscribed);
        } catch {
          // API might not be available yet
        }
      }
    }
    checkPushSupport();
  }, []);

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

      // Refresh to get masked key
      const refreshed = await api.settings.getApi();
      setSettings(refreshed);
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

      // Refresh to get masked key
      const refreshed = await api.settings.getApi();
      setSettings(refreshed);
    } catch (error) {
      setMessage({ type: 'error', text: (error as Error).message || '保存に失敗しました' });
    } finally {
      setSavingClaude(false);
    }
  };

  // Save Preferred Provider & Models
  const handleSavePreferences = async (): Promise<void> => {
    setSavingPreferences(true);
    setMessage(null);

    try {
      const result = await api.settings.updateApi({
        preferred_ai_provider: preferredProvider,
        preferred_openai_model: preferredOpenaiModel,
        preferred_claude_model: preferredClaudeModel,
      });
      updateSettingsFromResponse(result);
      setMessage({ type: 'success', text: '優先設定を保存しました' });
    } catch (error) {
      setMessage({ type: 'error', text: (error as Error).message || '保存に失敗しました' });
    } finally {
      setSavingPreferences(false);
    }
  };

  // Save Profile
  const handleSaveProfile = async (): Promise<void> => {
    if (!editUsername.trim()) {
      setMessage({ type: 'error', text: '表示名を入力してください' });
      return;
    }

    setSavingProfile(true);
    setMessage(null);

    try {
      const result = await api.settings.updateProfile({
        username: editUsername.trim(),
        email: editEmail.trim() || null,
      });
      setProfile(result.profile);
      setEditUsername(result.profile.username);
      setEditEmail(result.profile.email || '');
      setMessage({ type: 'success', text: result.message });
    } catch (error) {
      setMessage({ type: 'error', text: (error as Error).message || '保存に失敗しました' });
    } finally {
      setSavingProfile(false);
    }
  };

  // Subscribe to push notifications
  const handlePushSubscribe = async (): Promise<void> => {
    if (!pushSupported) return;

    setPushLoading(true);
    setMessage(null);

    try {
      if (Notification.permission === 'default') {
        const permission = await Notification.requestPermission();
        setPushPermission(permission);
        if (permission !== 'granted') {
          setMessage({ type: 'error', text: '通知の許可が必要です' });
          setPushLoading(false);
          return;
        }
      } else if (Notification.permission === 'denied') {
        setMessage({ type: 'error', text: '通知がブロックされています．ブラウザの設定から許可してください' });
        setPushLoading(false);
        return;
      }

      const { publicKey } = await api.push.getPublicKey();
      const registration = await navigator.serviceWorker.ready;

      const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKey),
      });

      const subscriptionJSON = subscription.toJSON();
      await api.push.subscribe(subscriptionJSON);

      setPushSubscribed(true);
      setMessage({ type: 'success', text: 'プッシュ通知を有効にしました．毎朝6時に新着論文の通知が届きます' });
    } catch (error) {
      console.error('Push subscription error:', error);
      setMessage({ type: 'error', text: (error as Error).message || 'プッシュ通知の登録に失敗しました' });
    } finally {
      setPushLoading(false);
    }
  };

  // Unsubscribe from push notifications
  const handlePushUnsubscribe = async (): Promise<void> => {
    setPushLoading(true);
    setMessage(null);

    try {
      const registration = await navigator.serviceWorker.ready;
      const subscription = await registration.pushManager.getSubscription();

      if (subscription) {
        await subscription.unsubscribe();
        await api.push.unsubscribe(subscription.endpoint);
      }

      setPushSubscribed(false);
      setMessage({ type: 'success', text: 'プッシュ通知を無効にしました' });
    } catch (error) {
      console.error('Push unsubscribe error:', error);
      setMessage({ type: 'error', text: (error as Error).message || 'プッシュ通知の解除に失敗しました' });
    } finally {
      setPushLoading(false);
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
      setMessage({ type: 'success', text: 'APIキーを削除しました' });
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
        <Loader2 className="w-8 h-8 animate-spin text-indigo-600" />
      </div>
    );
  }

  return (
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

      {/* Profile */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <div className="flex items-center gap-2 mb-4">
          <User className="w-5 h-5 text-indigo-600" />
          <h2 className="text-lg font-semibold text-gray-900">プロフィール</h2>
        </div>
        <p className="text-sm text-gray-500 mb-4">
          表示名とメールアドレスを変更できます
        </p>

        {profile && (
          <div className="mb-4 p-3 bg-gray-50 rounded-lg">
            <p className="text-sm text-gray-600">
              ログインID: <code className="font-mono">{profile.user_id}</code>
              <span className="text-gray-400 ml-2">(変更不可)</span>
            </p>
          </div>
        )}

        <div className="space-y-4">
          {/* Username */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              表示名
            </label>
            <input
              type="text"
              value={editUsername}
              onChange={(e: ChangeEvent<HTMLInputElement>) => setEditUsername(e.target.value)}
              placeholder="表示名を入力"
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>

          {/* Email */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              メールアドレス
            </label>
            <input
              type="email"
              value={editEmail}
              onChange={(e: ChangeEvent<HTMLInputElement>) => setEditEmail(e.target.value)}
              placeholder="example@example.com"
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>

          {/* Save button */}
          <div className="flex justify-end">
            <button
              type="button"
              onClick={handleSaveProfile}
              disabled={savingProfile || !editUsername.trim()}
              className="flex items-center gap-2 px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              {savingProfile ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <Save className="w-4 h-4" />
              )}
              プロフィールを保存
            </button>
          </div>
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
          <button
            type="button"
            onClick={handleSaveOpenaiKey}
            disabled={savingOpenai || !openaiApiKey}
            className="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            {savingOpenai ? (
              <Loader2 className="w-4 h-4 animate-spin" />
            ) : (
              <Save className="w-4 h-4" />
            )}
            保存
          </button>
          {settings?.openai_api_key_set && (
            <button
              type="button"
              onClick={() => handleDeleteKey('openai')}
              disabled={savingOpenai}
              className="px-4 py-2 text-red-600 border border-red-300 rounded-lg hover:bg-red-50 disabled:opacity-50"
            >
              <Trash2 className="w-4 h-4" />
            </button>
          )}
        </div>
      </div>

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
          <button
            type="button"
            onClick={handleSaveClaudeKey}
            disabled={savingClaude || !claudeApiKey}
            className="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
          >
            {savingClaude ? (
              <Loader2 className="w-4 h-4 animate-spin" />
            ) : (
              <Save className="w-4 h-4" />
            )}
            保存
          </button>
          {settings?.claude_api_key_set && (
            <button
              type="button"
              onClick={() => handleDeleteKey('claude')}
              disabled={savingClaude}
              className="px-4 py-2 text-red-600 border border-red-300 rounded-lg hover:bg-red-50 disabled:opacity-50"
            >
              <Trash2 className="w-4 h-4" />
            </button>
          )}
        </div>
      </div>

      {/* Preferred Provider & Model */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">優先プロバイダ・モデル</h2>
        <p className="text-sm text-gray-500 mb-4">
          要約生成時にデフォルトで使用するAIプロバイダとモデルを選択します
        </p>

        {/* Provider Selection */}
        <div className="mb-4">
          <label className="block text-sm font-medium text-gray-700 mb-2">
            プロバイダ
          </label>
          <select
            value={preferredProvider}
            onChange={(e: ChangeEvent<HTMLSelectElement>) => setPreferredProvider(e.target.value)}
            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
          >
            <option value="openai">OpenAI (GPT)</option>
            <option value="claude">Claude (Anthropic)</option>
          </select>
        </div>

        {/* OpenAI Model Selection */}
        <div className="mb-4">
          <label className="block text-sm font-medium text-gray-700 mb-2">
            OpenAI モデル
          </label>
          <select
            value={preferredOpenaiModel}
            onChange={(e: ChangeEvent<HTMLSelectElement>) => setPreferredOpenaiModel(e.target.value)}
            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
          >
            {openaiModels.map((modelId) => (
              <option key={modelId} value={modelId}>
                {modelId}
              </option>
            ))}
          </select>
        </div>

        {/* Claude Model Selection */}
        <div className="mb-6">
          <label className="block text-sm font-medium text-gray-700 mb-2">
            Claude モデル
          </label>
          <select
            value={preferredClaudeModel}
            onChange={(e: ChangeEvent<HTMLSelectElement>) => setPreferredClaudeModel(e.target.value)}
            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
          >
            {claudeModels.map((modelId) => (
              <option key={modelId} value={modelId}>
                {modelId}
              </option>
            ))}
          </select>
        </div>

        {/* Save button for preferences */}
        <div className="flex justify-end">
          <button
            type="button"
            onClick={handleSavePreferences}
            disabled={savingPreferences}
            className="flex items-center gap-2 px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors"
          >
            {savingPreferences ? (
              <Loader2 className="w-4 h-4 animate-spin" />
            ) : (
              <Save className="w-4 h-4" />
            )}
            設定を保存
          </button>
        </div>
      </div>

      {/* Push Notifications */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <div className="flex items-center gap-2 mb-4">
          <Bell className="w-5 h-5 text-indigo-600" />
          <h2 className="text-lg font-semibold text-gray-900">プッシュ通知</h2>
        </div>
        <p className="text-sm text-gray-500 mb-4">
          毎朝6時に新着論文のプッシュ通知を受け取ることができます
        </p>

        {!pushSupported ? (
          <div className="p-4 bg-yellow-50 rounded-lg border border-yellow-200">
            <p className="text-sm text-yellow-800">
              お使いのブラウザはプッシュ通知に対応していません
            </p>
          </div>
        ) : !pushConfigured ? (
          <div className="p-4 bg-gray-50 rounded-lg border border-gray-200">
            <p className="text-sm text-gray-600">
              サーバー側でプッシュ通知が設定されていません．管理者に連絡してください．
            </p>
          </div>
        ) : pushPermission === 'denied' ? (
          <div className="p-4 bg-red-50 rounded-lg border border-red-200">
            <p className="text-sm text-red-800">
              通知がブロックされています．ブラウザの設定から通知を許可してください．
            </p>
          </div>
        ) : (
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              {pushSubscribed ? (
                <>
                  <div className="w-10 h-10 rounded-full bg-green-100 flex items-center justify-center">
                    <Bell className="w-5 h-5 text-green-600" />
                  </div>
                  <div>
                    <p className="font-medium text-gray-900">通知ON</p>
                    <p className="text-sm text-gray-500">毎朝6時に通知が届きます</p>
                  </div>
                </>
              ) : (
                <>
                  <div className="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center">
                    <BellOff className="w-5 h-5 text-gray-400" />
                  </div>
                  <div>
                    <p className="font-medium text-gray-900">通知OFF</p>
                    <p className="text-sm text-gray-500">通知は無効です</p>
                  </div>
                </>
              )}
            </div>
            <button
              onClick={pushSubscribed ? handlePushUnsubscribe : handlePushSubscribe}
              disabled={pushLoading}
              className={`flex items-center gap-2 px-4 py-2 rounded-lg transition-colors disabled:opacity-50 ${
                pushSubscribed
                  ? 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                  : 'bg-indigo-600 text-white hover:bg-indigo-700'
              }`}
            >
              {pushLoading ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : pushSubscribed ? (
                <BellOff className="w-4 h-4" />
              ) : (
                <Bell className="w-4 h-4" />
              )}
              {pushSubscribed ? '通知を無効にする' : '通知を有効にする'}
            </button>
          </div>
        )}
      </div>
    </main>
  );
}
