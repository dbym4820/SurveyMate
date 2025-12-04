import {
  Settings as SettingsIcon, User as UserIcon, LogOut, Key, TrendingUp, RefreshCw
} from 'lucide-react';
import { getBasePath } from '../api';
import type { User } from '../types';

type PageType = 'papers' | 'journals' | 'settings' | 'trends';

interface HeaderProps {
  user: User;
  currentPage: PageType;
  onNavigate: (page: PageType) => void;
  onLogout: () => void;
  isRefreshing?: boolean;
  onManualFetch?: () => void;
}

export default function Header({
  user,
  currentPage,
  onNavigate,
  onLogout,
  isRefreshing = false,
  onManualFetch,
}: HeaderProps): JSX.Element {
  return (
    <header className="bg-white border-b border-gray-200 sticky top-0 z-10 shadow-sm">
      <div className="max-w-6xl mx-auto px-4 py-4">
        <div className="flex items-center justify-between">
          {/* Logo */}
          <div className="flex items-center gap-3 cursor-pointer" onClick={() => onNavigate('papers')}>
            <img src={`${getBasePath()}/favicon.ico`} alt="AutoSurvey" className="w-8 h-8" />
            <div>
              <h1 className="text-xl font-bold text-gray-900">AutoSurvey</h1>
            </div>
          </div>

          {/* Actions */}
          <div className="flex items-center gap-3">
            <button
              onClick={() => onNavigate('trends')}
              className={`flex items-center gap-2 px-4 py-2 border rounded-lg transition-colors ${
                currentPage === 'trends'
                  ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                  : 'border-gray-300 hover:bg-gray-50'
              }`}
            >
              <TrendingUp className="w-4 h-4" />
              トレンド
            </button>

            <button
              onClick={() => onNavigate('settings')}
              className={`flex items-center gap-2 px-4 py-2 border rounded-lg transition-colors ${
                currentPage === 'settings'
                  ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                  : 'border-gray-300 hover:bg-gray-50'
              }`}
            >
              <Key className="w-4 h-4" />
              API設定
            </button>

            <button
              onClick={() => onNavigate('journals')}
              className={`flex items-center gap-2 px-4 py-2 border rounded-lg transition-colors ${
                currentPage === 'journals'
                  ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                  : 'border-gray-300 hover:bg-gray-50'
              }`}
            >
              <SettingsIcon className="w-4 h-4" />
              論文誌管理
            </button>

            {user.isAdmin && onManualFetch && (
              <button
                onClick={onManualFetch}
                disabled={isRefreshing}
                className="flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors"
              >
                <RefreshCw className={`w-4 h-4 ${isRefreshing ? 'animate-spin' : ''}`} />
                {isRefreshing ? '取得中...' : 'フィード取得'}
              </button>
            )}

            <div className="flex items-center gap-2 px-3 py-2 bg-gray-100 rounded-lg">
              <UserIcon className="w-4 h-4 text-gray-600" />
              <span className="text-sm font-medium text-gray-700">{user.username}</span>
              {user.isAdmin && (
                <span className="text-xs bg-indigo-100 text-indigo-700 px-1.5 py-0.5 rounded">
                  管理者
                </span>
              )}
            </div>

            <button
              onClick={onLogout}
              className="p-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors"
              title="ログアウト"
            >
              <LogOut className="w-5 h-5" />
            </button>
          </div>
        </div>
      </div>
    </header>
  );
}
