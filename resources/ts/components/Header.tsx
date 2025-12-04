import { useState } from 'react';
import {
  Settings as SettingsIcon, User as UserIcon, LogOut, Key, TrendingUp, RefreshCw, Menu, X
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
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  const handleNavigate = (page: PageType) => {
    onNavigate(page);
    setMobileMenuOpen(false);
  };

  return (
    <header className="bg-white border-b border-gray-200 sticky top-0 z-10 shadow-sm">
      <div className="max-w-6xl mx-auto px-4 py-3 sm:py-4">
        <div className="flex items-center justify-between">
          {/* Logo */}
          <div className="flex items-center gap-2 sm:gap-3 cursor-pointer" onClick={() => handleNavigate('papers')}>
            <img src={`${getBasePath()}/favicon.ico`} alt="AutoSurvey" className="w-7 h-7 sm:w-8 sm:h-8" />
            <h1 className="text-lg sm:text-xl font-bold text-gray-900">AutoSurvey</h1>
          </div>

          {/* Desktop Actions */}
          <div className="hidden md:flex items-center gap-3">
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

          {/* Mobile Menu Button */}
          <div className="flex md:hidden items-center gap-2">
            {user.isAdmin && onManualFetch && (
              <button
                onClick={onManualFetch}
                disabled={isRefreshing}
                className="p-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors"
              >
                <RefreshCw className={`w-5 h-5 ${isRefreshing ? 'animate-spin' : ''}`} />
              </button>
            )}
            <button
              onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
              className="p-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors"
            >
              {mobileMenuOpen ? <X className="w-6 h-6" /> : <Menu className="w-6 h-6" />}
            </button>
          </div>
        </div>

        {/* Mobile Menu */}
        {mobileMenuOpen && (
          <div className="md:hidden mt-4 pt-4 border-t border-gray-200">
            <div className="space-y-2">
              <button
                onClick={() => handleNavigate('trends')}
                className={`w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
                  currentPage === 'trends'
                    ? 'bg-indigo-50 text-indigo-700'
                    : 'hover:bg-gray-50'
                }`}
              >
                <TrendingUp className="w-5 h-5" />
                トレンド分析
              </button>

              <button
                onClick={() => handleNavigate('settings')}
                className={`w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
                  currentPage === 'settings'
                    ? 'bg-indigo-50 text-indigo-700'
                    : 'hover:bg-gray-50'
                }`}
              >
                <Key className="w-5 h-5" />
                API設定
              </button>

              <button
                onClick={() => handleNavigate('journals')}
                className={`w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
                  currentPage === 'journals'
                    ? 'bg-indigo-50 text-indigo-700'
                    : 'hover:bg-gray-50'
                }`}
              >
                <SettingsIcon className="w-5 h-5" />
                論文誌管理
              </button>

              <div className="border-t border-gray-200 pt-2 mt-2">
                <div className="flex items-center justify-between px-4 py-3">
                  <div className="flex items-center gap-2">
                    <UserIcon className="w-5 h-5 text-gray-600" />
                    <span className="font-medium text-gray-700">{user.username}</span>
                    {user.isAdmin && (
                      <span className="text-xs bg-indigo-100 text-indigo-700 px-1.5 py-0.5 rounded">
                        管理者
                      </span>
                    )}
                  </div>
                  <button
                    onClick={onLogout}
                    className="flex items-center gap-2 px-3 py-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                  >
                    <LogOut className="w-5 h-5" />
                    ログアウト
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}
      </div>
    </header>
  );
}
