import { useState, FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { LogIn, UserPlus, Loader2, AlertCircle, CheckCircle } from 'lucide-react';
import { getBasePath } from '../api';
import api from '../api';
import type { User } from '../types';

type FormMode = 'login' | 'register';

interface LoginFormProps {
  mode: FormMode;
  onLogin: (user: User) => void;
}

export default function LoginForm({ mode, onLogin }: LoginFormProps): JSX.Element {
  const navigate = useNavigate();
  const [userId, setUserId] = useState('');
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [loading, setLoading] = useState(false);

  const validateRegistration = (): string | null => {
    if (userId.length < 3 || userId.length > 50) {
      return 'ユーザーIDは3〜50文字で入力してください';
    }
    if (!/^[a-zA-Z0-9_-]+$/.test(userId)) {
      return 'ユーザーIDは英数字，アンダースコア，ハイフンのみ使用できます';
    }
    if (!username.trim()) {
      return '表示名を入力してください';
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
    const data = await api.auth.login(userId, password);
    onLogin(data.user);
  };

  const handleRegister = async (): Promise<void> => {
    const validationError = validateRegistration();
    if (validationError) {
      setError(validationError);
      return;
    }

    const data = await api.auth.register(userId, username, password, email || undefined);
    // 登録成功時，expiresAtがあれば自動ログイン完了
    if (data.expiresAt) {
      onLogin(data.user);
    } else {
      // 自動ログインされなかった場合はログインページへ
      setSuccess('登録が完了しました．ログインしてください．');
      setTimeout(() => {
        navigate('/login');
      }, 1500);
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
        setError(message || '登録に失敗しました');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-gray-100 via-purple-50 to-pink-100 px-4 py-8">
      <div className="bg-white p-6 sm:p-8 rounded-2xl shadow-xl w-full max-w-md">
        <div className="flex items-center justify-center gap-2 sm:gap-3 mb-6 sm:mb-8">
          <img
            src={`${getBasePath()}/favicon.ico`}
            alt="SurveyMate"
            className="w-10 h-10 sm:w-12 sm:h-12"
          />
          <div>
            <h1 className="text-xl sm:text-2xl font-bold text-gray-900">SurveyMate</h1>
          </div>
        </div>

        {/* モード切り替えタブ */}
        <div className="flex mb-6 bg-gray-100 rounded-lg p-1">
          <Link
            to="/login"
            className={`flex-1 py-2 px-4 rounded-md text-sm font-medium transition-colors text-center ${
              mode === 'login'
                ? 'bg-white text-gray-600 shadow-sm'
                : 'text-gray-600 hover:text-gray-900'
            }`}
          >
            ログイン
          </Link>
          <Link
            to="/register"
            className={`flex-1 py-2 px-4 rounded-md text-sm font-medium transition-colors text-center ${
              mode === 'register'
                ? 'bg-white text-gray-600 shadow-sm'
                : 'text-gray-600 hover:text-gray-900'
            }`}
          >
            新規登録
          </Link>
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

          {/* ユーザーID（ログイン用） */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              ユーザーID
            </label>
            <input
              type="text"
              name="username"
              value={userId}
              onChange={(e) => setUserId(e.target.value)}
              className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 transition-colors"
              placeholder={mode === 'register' ? '3〜50文字の英数字（ログイン時に使用）' : 'ユーザーIDを入力'}
              required
              autoComplete="username"
            />
            {mode === 'register' && (
              <p className="text-xs text-gray-500 mt-1">英数字，アンダースコア，ハイフンのみ．変更不可．</p>
            )}
          </div>

          {/* 表示名（登録時のみ） */}
          {mode === 'register' && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                表示名
              </label>
              <input
                type="text"
                value={username}
                onChange={(e) => setUsername(e.target.value)}
                className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 transition-colors"
                placeholder="画面に表示される名前"
                required
                autoComplete="name"
              />
            </div>
          )}

          {/* メールアドレス（登録時のみ） */}
          {mode === 'register' && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                メールアドレス <span className="text-gray-400">(任意)</span>
              </label>
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 transition-colors"
                placeholder="example@example.com"
                autoComplete="email"
              />
            </div>
          )}

          {/* パスワード */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              パスワード
            </label>
            <input
              type="password"
              name="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 transition-colors"
              placeholder={mode === 'register' ? '8文字以上' : 'パスワードを入力'}
              required
              autoComplete={mode === 'register' ? 'new-password' : 'current-password'}
            />
          </div>

          {/* パスワード確認（登録時のみ） */}
          {mode === 'register' && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                パスワード確認
              </label>
              <input
                type="password"
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500 transition-colors"
                placeholder="パスワードを再入力"
                required
                autoComplete="new-password"
              />
            </div>
          )}

          <button
            type="submit"
            disabled={loading}
            className="w-full py-2.5 px-4 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2 font-medium transition-colors"
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

        {mode === 'login' && (
          <p className="mt-6 text-center text-xs text-gray-500">
            アカウントをお持ちでない場合は<Link to="/register" className="text-gray-700 underline">新規登録</Link>
          </p>
        )}

        {mode === 'register' && (
          <p className="mt-6 text-center text-xs text-gray-500">
            アカウントをお持ちの場合は<Link to="/login" className="text-gray-700 underline">ログイン</Link>
          </p>
        )}

        <p className="mt-4 text-center text-[10px] text-gray-400">
          ※ このシステムは予告なく仕様変更・データのリセットを実施する場合があります
        </p>
      </div>
    </div>
  );
}
