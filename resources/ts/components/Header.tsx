import { useState, useRef, useEffect } from 'react';
import {
  Settings as SettingsIcon, User as UserIcon, LogOut, Key, TrendingUp, RefreshCw, Menu, X, ChevronDown, Tag, Compass, Home, Bot
} from 'lucide-react';
import { getBasePath } from '../api';
import type { User } from '../types';

type PageType = 'papers' | 'journals' | 'settings' | 'trends' | 'tags' | 'research' | 'apisettings';

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
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  const userMenuRef = useRef<HTMLDivElement>(null);

  // Close user menu when clicking outside
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (userMenuRef.current && !userMenuRef.current.contains(event.target as Node)) {
        setUserMenuOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleNavigate = (page: PageType) => {
    onNavigate(page);
    setMobileMenuOpen(false);
  };

  return (
    <header className="bg-white border-b border-gray-200 sticky top-0 z-10 shadow-sm">
      <div className="w-[85%] mx-auto py-3 sm:py-4">
        <div className="flex items-center justify-between">
          {/* Logo */}
          <div className="flex items-center gap-2 sm:gap-3 cursor-pointer" onClick={() => handleNavigate('papers')}>
            <img src={`${getBasePath()}/favicon.ico`} alt="SurveyMate" className="w-7 h-7 sm:w-8 sm:h-8" />
            <h1 className="text-lg sm:text-xl font-bold text-gray-900">SurveyMate</h1>
          </div>

          {/* Desktop Actions */}
          <div className="hidden md:flex items-center gap-1 lg:gap-3">
            <button
              onClick={() => onNavigate('papers')}
              title="Home"
              className={`flex items-center gap-2 px-3 lg:px-4 py-2 border rounded-lg transition-colors ${
                currentPage === 'papers'
                  ? 'border-gray-500 bg-gray-50 text-gray-700'
                  : 'border-gray-300 hover:bg-gray-50'
              }`}
            >
              <Home className="w-4 h-4" />
              <span className="hidden lg:inline">Home</span>
            </button>

            {user.hasAnyApiKey && (
              <button
                onClick={() => onNavigate('trends')}
                title="トレンド"
                className={`flex items-center gap-2 px-3 lg:px-4 py-2 border rounded-lg transition-colors ${
                  currentPage === 'trends'
                    ? 'border-gray-500 bg-gray-50 text-gray-700'
                    : 'border-gray-300 hover:bg-gray-50'
                }`}
              >
                <TrendingUp className="w-4 h-4" />
                <span className="hidden lg:inline">トレンド</span>
              </button>
            )}

            <button
              onClick={() => onNavigate('tags')}
              title="Tagグループ"
              className={`flex items-center gap-2 px-3 lg:px-4 py-2 border rounded-lg transition-colors ${
                currentPage === 'tags'
                  ? 'border-gray-500 bg-gray-50 text-gray-700'
                  : 'border-gray-300 hover:bg-gray-50'
              }`}
            >
              <Tag className="w-4 h-4" />
              <span className="hidden lg:inline">Tagグループ</span>
            </button>

            {onManualFetch && (
              <button
                onClick={onManualFetch}
                disabled={isRefreshing}
                title={isRefreshing ? '取得中...' : 'フィード取得'}
                className="flex items-center gap-2 px-3 lg:px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50 transition-colors"
              >
                <RefreshCw className={`w-4 h-4 ${isRefreshing ? 'animate-spin' : ''}`} />
                <span className="hidden lg:inline">{isRefreshing ? '取得中...' : 'フィード取得'}</span>
              </button>
            )}

            {/* User Menu Dropdown */}
            <div className="relative" ref={userMenuRef}>
              <button
                onClick={() => setUserMenuOpen(!userMenuOpen)}
                title={user.username}
                className="flex items-center gap-1 lg:gap-2 px-2 lg:px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors"
              >
                <UserIcon className="w-4 h-4 text-gray-600" />
                <span className="hidden lg:inline text-sm font-medium text-gray-700">{user.username}</span>
                {user.isAdmin && (
                  <span className="hidden lg:inline text-xs bg-gray-100 text-gray-700 px-1.5 py-0.5 rounded">
                    管理者
                  </span>
                )}
                <ChevronDown className={`w-4 h-4 text-gray-500 transition-transform ${userMenuOpen ? 'rotate-180' : ''}`} />
              </button>

              {userMenuOpen && (
                <div className="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-20">
                  <button
                    onClick={() => {
                      onNavigate('journals');
                      setUserMenuOpen(false);
                    }}
                    className={`w-full flex items-center gap-2 px-4 py-2 text-left transition-colors ${
                      currentPage === 'journals'
                        ? 'bg-gray-50 text-gray-700'
                        : 'hover:bg-gray-50 text-gray-700'
                    }`}
                  >
                    <SettingsIcon className="w-4 h-4" />
                    論文誌管理
                  </button>
                  <button
                    onClick={() => {
                      onNavigate('research');
                      setUserMenuOpen(false);
                    }}
                    className={`w-full flex items-center gap-2 px-4 py-2 text-left transition-colors ${
                      currentPage === 'research'
                        ? 'bg-gray-50 text-gray-700'
                        : 'hover:bg-gray-50 text-gray-700'
                    }`}
                  >
                    <Compass className="w-4 h-4" />
                    調査観点設定
                  </button>
                  <button
                    onClick={() => {
                      onNavigate('apisettings');
                      setUserMenuOpen(false);
                    }}
                    className={`w-full flex items-center gap-2 px-4 py-2 text-left transition-colors ${
                      currentPage === 'apisettings'
                        ? 'bg-gray-50 text-gray-700'
                        : 'hover:bg-gray-50 text-gray-700'
                    }`}
                  >
                    <Bot className="w-4 h-4" />
                    生成AI設定
                  </button>
                  <button
                    onClick={() => {
                      onNavigate('settings');
                      setUserMenuOpen(false);
                    }}
                    className={`w-full flex items-center gap-2 px-4 py-2 text-left transition-colors ${
                      currentPage === 'settings'
                        ? 'bg-gray-50 text-gray-700'
                        : 'hover:bg-gray-50 text-gray-700'
                    }`}
                  >
                    <Key className="w-4 h-4" />
                    設定
                  </button>
                  <div className="border-t border-gray-100 my-1" />
                  <button
                    onClick={() => {
                      onLogout();
                      setUserMenuOpen(false);
                    }}
                    className="w-full flex items-center gap-2 px-4 py-2 text-left text-red-600 hover:bg-red-50 transition-colors"
                  >
                    <LogOut className="w-4 h-4" />
                    ログアウト
                  </button>
                </div>
              )}
            </div>
          </div>

          {/* Mobile Menu Button */}
          <div className="flex md:hidden items-center gap-2">
            {onManualFetch && (
              <button
                onClick={onManualFetch}
                disabled={isRefreshing}
                className="p-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50 transition-colors"
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
                onClick={() => handleNavigate('papers')}
                className={`w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
                  currentPage === 'papers'
                    ? 'bg-gray-50 text-gray-700'
                    : 'hover:bg-gray-50'
                }`}
              >
                <Home className="w-5 h-5" />
                Home
              </button>

              {user.hasAnyApiKey && (
                <button
                  onClick={() => handleNavigate('trends')}
                  className={`w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
                    currentPage === 'trends'
                      ? 'bg-gray-50 text-gray-700'
                      : 'hover:bg-gray-50'
                  }`}
                >
                  <TrendingUp className="w-5 h-5" />
                  トレンド分析
                </button>
              )}

              <button
                onClick={() => handleNavigate('tags')}
                className={`w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
                  currentPage === 'tags'
                    ? 'bg-gray-50 text-gray-700'
                    : 'hover:bg-gray-50'
                }`}
              >
                <Tag className="w-5 h-5" />
                Tagグループ
              </button>

              {onManualFetch && (
                <button
                  onClick={() => {
                    onManualFetch();
                    setMobileMenuOpen(false);
                  }}
                  disabled={isRefreshing}
                  className="w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-colors hover:bg-gray-50 disabled:opacity-50"
                >
                  <RefreshCw className={`w-5 h-5 ${isRefreshing ? 'animate-spin' : ''}`} />
                  {isRefreshing ? 'フィード取得中...' : 'フィード取得'}
                </button>
              )}

              <button
                onClick={() => handleNavigate('journals')}
                className={`w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
                  currentPage === 'journals'
                    ? 'bg-gray-50 text-gray-700'
                    : 'hover:bg-gray-50'
                }`}
              >
                <SettingsIcon className="w-5 h-5" />
                論文誌管理
              </button>

              <button
                onClick={() => handleNavigate('research')}
                className={`w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
                  currentPage === 'research'
                    ? 'bg-gray-50 text-gray-700'
                    : 'hover:bg-gray-50'
                }`}
              >
                <Compass className="w-5 h-5" />
                調査観点設定
              </button>

              <button
                onClick={() => handleNavigate('apisettings')}
                className={`w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
                  currentPage === 'apisettings'
                    ? 'bg-gray-50 text-gray-700'
                    : 'hover:bg-gray-50'
                }`}
              >
                <Bot className="w-5 h-5" />
                生成AI設定
              </button>

              <button
                onClick={() => handleNavigate('settings')}
                className={`w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-colors ${
                  currentPage === 'settings'
                    ? 'bg-gray-50 text-gray-700'
                    : 'hover:bg-gray-50'
                }`}
              >
                <Key className="w-5 h-5" />
                設定
              </button>

              <div className="border-t border-gray-200 pt-2 mt-2">
                <div className="flex items-center justify-between px-4 py-3">
                  <div className="flex items-center gap-2">
                    <UserIcon className="w-5 h-5 text-gray-600" />
                    <span className="font-medium text-gray-700">{user.username}</span>
                    {user.isAdmin && (
                      <span className="text-xs bg-gray-100 text-gray-700 px-1.5 py-0.5 rounded">
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
