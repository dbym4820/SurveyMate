import { useState, useEffect, useRef, useCallback } from 'react';
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

  // グローバルキュー監視用
  const queuePollingRef = useRef<ReturnType<typeof setInterval> | null>(null);

  // ポーリングを停止
  const stopQueuePolling = useCallback(() => {
    if (queuePollingRef.current) {
      console.log('[App] キュー監視: ポーリング停止');
      clearInterval(queuePollingRef.current);
      queuePollingRef.current = null;
    }
  }, []);

  // ポーリングを開始
  const startQueuePolling = useCallback(() => {
    if (queuePollingRef.current) {
      return; // 既に開始済み
    }
    console.log('[App] キュー監視: ポーリング開始');
    queuePollingRef.current = setInterval(async () => {
      try {
        const status = await api.papers.processingStatus();
        if (status.pending_jobs > 0 || status.processing_count > 0) {
          console.log('[App] キュー監視: ジョブ処理中', {
            pending_jobs: status.pending_jobs,
            processing_count: status.processing_count,
          });
        } else {
          // ジョブがゼロになったらポーリング停止
          console.log('[App] キュー監視: ジョブ完了、ポーリング停止');
          stopQueuePolling();
        }
      } catch (error) {
        // エラーは無視
      }
    }, 10000);
  }, [stopQueuePolling]);

  // キュー処理状況を確認し、ジョブがあればポーリング開始
  const checkQueueStatus = useCallback(async () => {
    try {
      const status = await api.papers.processingStatus();
      if (status.pending_jobs > 0 || status.processing_count > 0) {
        console.log('[App] キュー監視: ジョブ検出、ポーリング開始', {
          pending_jobs: status.pending_jobs,
          processing_count: status.processing_count,
          worker_started: status.worker_started,
        });
        startQueuePolling();
      }
    } catch (error) {
      // エラーは無視（ネットワークエラーなど）
    }
  }, [startQueuePolling]);

  // コンポーネントマウント完了時に初回チェック（全ページ共通）
  useEffect(() => {
    checkQueueStatus();

    // アンマウント時にクリーンアップ
    return () => {
      stopQueuePolling();
    };
  }, [checkQueueStatus, stopQueuePolling]);

  // feeds-updatedイベントでキューをチェック（論文更新時）
  useEffect(() => {
    const handleFeedsUpdated = () => {
      console.log('[App] フィード更新検出、キューをチェック');
      checkQueueStatus();
    };
    window.addEventListener('feeds-updated', handleFeedsUpdated);
    return () => {
      window.removeEventListener('feeds-updated', handleFeedsUpdated);
    };
  }, [checkQueueStatus]);

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
