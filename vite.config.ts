import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import path from 'path';

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/ts/main.tsx'],
      refresh: true,
    }),
    react(),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'resources/ts'),
    },
  },
  build: {
    // Use relative paths for assets
    assetsDir: 'assets',
  },
  // Ensure relative paths work in production
  base: '',
});
