import { useState, FormEvent } from 'react';
import { BookOpen, LogIn, UserPlus, Loader2, AlertCircle, CheckCircle } from 'lucide-react';
import api from '../api';
import type { User } from '../types';

interface LoginFormProps {
  onLogin: (user: User) => void;
}

type FormMode = 'login' | 'register';

export default function LoginForm({ onLogin }: LoginFormProps): JSX.Element {
  const [mode, setMode] = useState<FormMode>('login');
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [loading, setLoading] = useState(false);

  const resetForm = (): void => {
    setUsername('');
    setPassword('');
    setConfirmPassword('');
    setEmail('');
    setError('');
    setSuccess('');
  };

  const switchMode = (newMode: FormMode): void => {
    resetForm();
    setMode(newMode);
  };

  const validateRegistration = (): string | null => {
    if (username.length < 3 || username.length > 50) {
      return 'ユーザー名は3〜50文字で入力してください';
    }
    if (!/^[a-zA-Z0-9_-]+$/.test(username)) {
      return 'ユーザー名は英数字、アンダースコア、ハイフンのみ使用できます';
    }
    if (password.length < 8) {
      return 'パスワードは8文字以上で入力してください';
    }
    if (password !== confirmPassword) {
      return 'パスワードが一致しません';
    }
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      return 'メールアドレスの形式が正しくありません';
    }
    return null;
  };

  const handleLogin = async (): Promise<void> => {
    const data = await api.auth.login(username, password);
    onLogin(data.user);
  };

  const handleRegister = async (): Promise<void> => {
    const validationError = validateRegistration();
    if (validationError) {
      setError(validationError);
      return;
    }

    const data = await api.auth.register(username, password, email || undefined);
    // 登録成功時、自動ログインされていればonLoginを呼ぶ
    if (data.expiresAt) {
      onLogin(data.user);
    } else {
      // 自動ログインされなかった場合はログインフォームに戻す
      setSuccess('登録が完了しました。ログインしてください。');
      setMode('login');
      setPassword('');
      setConfirmPassword('');
    }
  };

  const handleSubmit = async (e: FormEvent<HTMLFormElement>): Promise<void> => {
    e.preventDefault();
    setError('');
    setSuccess('');
    setLoading(true);

    try {
      if (mode === 'login') {
        await handleLogin();
      } else {
        await handleRegister();
      }
    } catch (err) {
      const message = (err as Error).message;
      if (mode === 'login') {
        setError(message || 'ログインに失敗しました');
      } else {
        // エラーメッセージを日本語化
        if (message === 'Username already exists') {
          setError('このユーザー名は既に使用されています');
        } else {
          setError(message || '登録に失敗しました');
        }
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-indigo-100 via-purple-50 to-pink-100">
      <div className="bg-white p-8 rounded-2xl shadow-xl w-full max-w-md">
        <div className="flex items-center justify-center gap-3 mb-8">
          <div className="p-3 bg-indigo-100 rounded-xl">
            <BookOpen className="w-8 h-8 text-indigo-600" />
          </div>
          <div>
            <h1 className="text-2xl font-bold text-gray-900">学術論文RSSリーダー</h1>
            <p className="text-sm text-gray-500">AI in Education研究支援システム</p>
          </div>
        </div>

        {/* モード切り替えタブ */}
        <div className="flex mb-6 bg-gray-100 rounded-lg p-1">
          <button
            type="button"
            onClick={() => switchMode('login')}
            className={`flex-1 py-2 px-4 rounded-md text-sm font-medium transition-colors ${
              mode === 'login'
                ? 'bg-white text-indigo-600 shadow-sm'
                : 'text-gray-600 hover:text-gray-900'
            }`}
          >
            ログイン
          </button>
          <button
            type="button"
            onClick={() => switchMode('register')}
            className={`flex-1 py-2 px-4 rounded-md text-sm font-medium transition-colors ${
              mode === 'register'
                ? 'bg-white text-indigo-600 shadow-sm'
                : 'text-gray-600 hover:text-gray-900'
            }`}
          >
            新規登録
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          {error && (
            <div className="p-3 bg-red-50 border border-red-200 rounded-lg flex items-center gap-2 text-red-700">
              <AlertCircle className="w-4 h-4 flex-shrink-0" />
              <span className="text-sm">{error}</span>
            </div>
          )}

          {success && (
            <div className="p-3 bg-green-50 border border-green-200 rounded-lg flex items-center gap-2 text-green-700">
              <CheckCircle className="w-4 h-4 flex-shrink-0" />
              <span className="text-sm">{success}</span>
            </div>
          )}

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              ユーザー名
            </label>
            <input
              type="text"
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-colors"
              placeholder={mode === 'register' ? '3〜50文字の英数字' : 'ユーザー名を入力'}
              required
              autoComplete="username"
            />
          </div>

          {mode === 'register' && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                メールアドレス <span className="text-gray-400">(任意)</span>
              </label>
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-colors"
                placeholder="example@example.com"
                autoComplete="email"
              />
            </div>
          )}

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              パスワード
            </label>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-colors"
              placeholder={mode === 'register' ? '8文字以上' : 'パスワードを入力'}
              required
              autoComplete={mode === 'register' ? 'new-password' : 'current-password'}
            />
          </div>

          {mode === 'register' && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                パスワード確認
              </label>
              <input
                type="password"
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-colors"
                placeholder="パスワードを再入力"
                required
                autoComplete="new-password"
              />
            </div>
          )}

          <button
            type="submit"
            disabled={loading}
            className="w-full py-2.5 px-4 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2 font-medium transition-colors"
          >
            {loading ? (
              <Loader2 className="w-4 h-4 animate-spin" />
            ) : mode === 'login' ? (
              <LogIn className="w-4 h-4" />
            ) : (
              <UserPlus className="w-4 h-4" />
            )}
            {mode === 'login' ? 'ログイン' : '登録する'}
          </button>
        </form>

        <p className="mt-6 text-center text-xs text-gray-500">
          {mode === 'login'
            ? 'アカウントをお持ちでない場合は「新規登録」タブから登録できます'
            : '登録完了後、自動的にログインします'}
        </p>
      </div>
    </div>
  );
}
