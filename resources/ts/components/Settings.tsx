import { useState, useEffect, ChangeEvent } from 'react';
import { Save, Loader2, CheckCircle, AlertCircle, Bell, BellOff, User, Settings as SettingsIcon } from 'lucide-react';
import api from '../api';
import type { Profile } from '../types';

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
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  // Profile state
  const [savingProfile, setSavingProfile] = useState(false);
  const [profile, setProfile] = useState<Profile | null>(null);
  const [editUsername, setEditUsername] = useState('');
  const [editEmail, setEditEmail] = useState('');

  // Push notification state
  const [pushSupported, setPushSupported] = useState(false);
  const [pushPermission, setPushPermission] = useState<NotificationPermission>('default');
  const [pushSubscribed, setPushSubscribed] = useState(false);
  const [pushConfigured, setPushConfigured] = useState(false);
  const [pushLoading, setPushLoading] = useState(false);

  // Fetch current profile
  useEffect(() => {
    async function fetchProfile(): Promise<void> {
      try {
        const profileData = await api.settings.getProfile();
        setProfile(profileData.profile);
        setEditUsername(profileData.profile.username);
        setEditEmail(profileData.profile.email || '');
      } catch (error) {
        setMessage({ type: 'error', text: '設定の取得に失敗しました' });
      } finally {
        setLoading(false);
      }
    }
    fetchProfile();
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
          <SettingsIcon className="w-6 h-6" />
          設定
        </h1>
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

      {/* Profile */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <div className="flex items-center gap-2 mb-4">
          <User className="w-5 h-5 text-gray-600" />
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
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500"
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
              className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500"
            />
          </div>

          {/* Save button */}
          <div className="flex justify-end">
            <button
              type="button"
              onClick={handleSaveProfile}
              disabled={savingProfile || !editUsername.trim()}
              className="flex items-center gap-2 px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
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

      {/* Push Notifications */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <div className="flex items-center gap-2 mb-4">
          <Bell className="w-5 h-5 text-gray-600" />
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
                  : 'bg-gray-600 text-white hover:bg-gray-700'
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
