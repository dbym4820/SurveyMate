import { useState, useEffect, ChangeEvent, FormEvent } from 'react';
import { ArrowLeft, Key, Eye, EyeOff, Save, Trash2, Loader2, CheckCircle, AlertCircle, Bell, BellOff } from 'lucide-react';
import api from '../api';
import type { ApiSettings } from '../types';

interface SettingsProps {
  onBack: () => void;
}

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

  // Push notification state
  const [pushSupported, setPushSupported] = useState(false);
  const [pushPermission, setPushPermission] = useState<NotificationPermission>('default');
  const [pushSubscribed, setPushSubscribed] = useState(false);
  const [pushConfigured, setPushConfigured] = useState(false);
  const [pushLoading, setPushLoading] = useState(false);

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

  // Check push notification support
  useEffect(() => {
    async function checkPushSupport(): Promise<void> {
      // Check if browser supports push notifications
      const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
      setPushSupported(supported);

      if (supported) {
        setPushPermission(Notification.permission);

        // Check server configuration and subscription status
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

  // Subscribe to push notifications
  const handlePushSubscribe = async (): Promise<void> => {
    if (!pushSupported) return;

    setPushLoading(true);
    setMessage(null);

    try {
      // Request notification permission if not granted
      if (Notification.permission === 'default') {
        const permission = await Notification.requestPermission();
        setPushPermission(permission);
        if (permission !== 'granted') {
          setMessage({ type: 'error', text: '通知の許可が必要です' });
          setPushLoading(false);
          return;
        }
      } else if (Notification.permission === 'denied') {
        setMessage({ type: 'error', text: '通知がブロックされています。ブラウザの設定から許可してください' });
        setPushLoading(false);
        return;
      }

      // Get VAPID public key from server
      const { publicKey } = await api.push.getPublicKey();

      // Get service worker registration
      const registration = await navigator.serviceWorker.ready;

      // Subscribe to push notifications
      const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKey),
      });

      // Send subscription to server
      const subscriptionJSON = subscription.toJSON();
      await api.push.subscribe(subscriptionJSON);

      setPushSubscribed(true);
      setMessage({ type: 'success', text: 'プッシュ通知を有効にしました。毎朝6時に新着論文の通知が届きます' });
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
        // Unsubscribe from browser
        await subscription.unsubscribe();

        // Notify server
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
          <div className="flex justify-end mb-8">
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

        {/* Push Notifications - Outside form */}
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
                サーバー側でプッシュ通知が設定されていません。管理者に連絡してください。
              </p>
            </div>
          ) : pushPermission === 'denied' ? (
            <div className="p-4 bg-red-50 rounded-lg border border-red-200">
              <p className="text-sm text-red-800">
                通知がブロックされています。ブラウザの設定から通知を許可してください。
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
    </div>
  );
}
