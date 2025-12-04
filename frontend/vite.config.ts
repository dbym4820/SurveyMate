import { defineConfig, Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import { spawn, ChildProcess } from 'child_process';

// サブディレクトリパス（末尾スラッシュ必須）
const BASE_PATH = '/autosurvey/';

// バックエンドサーバーを起動するプラグイン
function backendPlugin(): Plugin {
  let backendProcess: ChildProcess | null = null;

  return {
    name: 'backend-server',
    configureServer() {
      // バックエンドサーバーを起動
      backendProcess = spawn('npx', ['tsx', '../src/server.ts'], {
        cwd: import.meta.dirname,
        stdio: 'inherit',
        shell: true,
        env: { ...process.env, NODE_ENV: 'development' },
      });

      backendProcess.on('error', (err) => {
        console.error('Backend server error:', err);
      });

      // Vite終了時にバックエンドも終了
      process.on('exit', () => {
        if (backendProcess) {
          backendProcess.kill();
        }
      });

      process.on('SIGINT', () => {
        if (backendProcess) {
          backendProcess.kill();
        }
        process.exit();
      });
    },
    closeBundle() {
      if (backendProcess) {
        backendProcess.kill();
      }
    },
  };
}

export default defineConfig(({ command }) => ({
  plugins: [
    react(),
    // 開発時のみバックエンドを起動
    command === 'serve' ? backendPlugin() : null,
  ].filter(Boolean) as Plugin[],
  base: BASE_PATH,
  server: {
    port: 5173,
    proxy: {
      '/autosurvey/api': {
        target: 'http://localhost:3001',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/autosurvey/, ''),
      },
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
  },
}));
