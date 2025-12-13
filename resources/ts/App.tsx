import { useState, useEffect } from 'react';
import { BrowserRouter, Routes, Route, Navigate, useNavigate, useLocation } from 'react-router-dom';
import { Loader2 } from 'lucide-react';
import api, { getBasePath } from './api';
import LoginForm from './components/LoginForm';
import Header from './components/Header';
import PaperList from './components/PaperList';
import JournalManagement from './components/JournalManagement';
import TagManagement from './components/TagManagement';
import Settings from './components/Settings';
import ResearchSettings from './components/ResearchSettings';
import ApiSettings from './components/ApiSettings';
import Trends from './components/Trends';
import InitialSetup from './components/InitialSetup';
import { ToastProvider } from './components/Toast';
import type { User } from './types';

type PageType = 'papers' | 'journals' | 'settings' | 'trends' | 'tags' | 'research' | 'apisettings';

// ルートとページタイプのマッピング
const routeToPage: Record<string, PageType> = {
  '/': 'papers',
  '/papers': 'papers',
  '/journals': 'journals',
  '/tags': 'tags',
  '/settings': 'settings',
  '/settings/research': 'research',
  '/settings/api': 'apisettings',
  '/trends': 'trends',
};

const pageToRoute: Record<PageType, string> = {
  papers: '/papers',
  journals: '/journals',
  tags: '/tags',
  settings: '/settings',
  research: '/settings/research',
  apisettings: '/settings/api',
  trends: '/trends',
};

// 認証が必要なルート
function ProtectedRoute({
  user,
  children,
}: {
  user: User | null;
  children: React.ReactNode;
}): JSX.Element {
  if (!user) {
    return <Navigate to="/login" replace />;
  }
  return <>{children}</>;
}

// ログイン済みの場合リダイレクト
function PublicRoute({
  user,
  children,
}: {
  user: User | null;
  children: React.ReactNode;
}): JSX.Element {
  if (user) {
    return <Navigate to="/papers" replace />;
  }
  return <>{children}</>;
}

// メインレイアウト（ヘッダー付き）
function MainLayout({ user, onLogout }: { user: User; onLogout: () => void }): JSX.Element {
  const navigate = useNavigate();
  const location = useLocation();
  const [isRefreshing, setIsRefreshing] = useState(false);

  // 現在のページを取得
  const currentPage = routeToPage[location.pathname] || 'papers';

  // ナビゲーション
  const handleNavigate = (page: PageType) => {
    navigate(pageToRoute[page]);
  };

  // ログアウト
  const handleLogout = async (): Promise<void> => {
    try {
      await api.auth.logout();
      onLogout();
      navigate('/login');
    } catch (error) {
      console.error('Logout failed:', error);
    }
  };

  // 手動フェッチ
  const handleManualFetch = async (): Promise<void> => {
    setIsRefreshing(true);
    try {
      await api.admin.runScheduler();
      // フィード取得完了を通知（PaperListなどで再描画）
      window.dispatchEvent(new CustomEvent('feeds-updated'));
    } catch (error) {
      console.error('Manual fetch failed:', error);
    } finally {
      setIsRefreshing(false);
    }
  };

  return (
    <div className="min-h-screen bg-gray-50">
      <Header
        user={user}
        currentPage={currentPage}
        onNavigate={handleNavigate}
        onLogout={handleLogout}
        isRefreshing={isRefreshing}
        onManualFetch={handleManualFetch}
      />
      <Routes>
        <Route path="/" element={<Navigate to="/papers" replace />} />
        <Route path="/papers" element={<PaperList />} />
        <Route path="/journals" element={<JournalManagement />} />
        <Route path="/tags" element={<TagManagement />} />
        <Route path="/tags/:tagId" element={<TagManagement />} />
        <Route path="/settings" element={<Settings />} />
        <Route path="/settings/research" element={<ResearchSettings />} />
        <Route path="/settings/api" element={<ApiSettings />} />
        <Route path="/trends" element={<Trends />} />
        <Route path="*" element={<Navigate to="/papers" replace />} />
      </Routes>
    </div>
  );
}

// アプリケーションルート
function AppRoutes(): JSX.Element {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  // ユーザー情報を再取得する関数
  const refreshUser = async (): Promise<void> => {
    try {
      const data = await api.auth.me();
      if (data.authenticated) {
        setUser(data.user);
      }
    } catch {
      console.log('Failed to refresh user');
    }
  };

  // 認証状態をチェック
  useEffect(() => {
    async function checkAuth(): Promise<void> {
      try {
        const data = await api.auth.me();
        if (data.authenticated) {
          setUser(data.user);
        }
      } catch {
        // 未認証の場合はエラーになるが，正常
        console.log('Not authenticated');
      } finally {
        setLoading(false);
      }
    }
    checkAuth();
  }, []);

  // user-updated イベントをリッスンしてユーザー情報を再取得
  useEffect(() => {
    const handleUserUpdated = () => {
      refreshUser();
    };
    window.addEventListener('user-updated', handleUserUpdated);
    return () => {
      window.removeEventListener('user-updated', handleUserUpdated);
    };
  }, []);

  // 初期設定完了時のコールバック
  const handleInitialSetupComplete = () => {
    setUser(prev => prev ? { ...prev, initialSetupCompleted: true } : null);
  };

  // ローディング中
  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-100">
        <Loader2 className="w-8 h-8 animate-spin text-gray-600" />
      </div>
    );
  }

  return (
    <Routes>
      <Route
        path="/login"
        element={
          <PublicRoute user={user}>
            <LoginForm mode="login" onLogin={setUser} />
          </PublicRoute>
        }
      />
      <Route
        path="/register"
        element={
          <PublicRoute user={user}>
            <LoginForm mode="register" onLogin={setUser} />
          </PublicRoute>
        }
      />
      {/* 初期設定ページ */}
      <Route
        path="/initial-setup"
        element={
          <ProtectedRoute user={user}>
            {user && !user.initialSetupCompleted ? (
              <InitialSetup onComplete={handleInitialSetupComplete} />
            ) : (
              <Navigate to="/papers" replace />
            )}
          </ProtectedRoute>
        }
      />
      <Route
        path="/*"
        element={
          <ProtectedRoute user={user}>
            {/* 初期設定未完了の場合はリダイレクト */}
            {user && !user.initialSetupCompleted ? (
              <Navigate to="/initial-setup" replace />
            ) : (
              <MainLayout user={user!} onLogout={() => setUser(null)} />
            )}
          </ProtectedRoute>
        }
      />
    </Routes>
  );
}

export default function App(): JSX.Element {
  const basePath = getBasePath();

  return (
    <ToastProvider>
      <BrowserRouter basename={basePath}>
        <AppRoutes />
      </BrowserRouter>
    </ToastProvider>
  );
}
