# CLAUDE.md - AutoSurvey

このファイルはClaude Codeがプロジェクトを理解するためのガイドです．

## プロジェクト概要

AIED（AI in Education），認知科学，メタ認知などの学術論文をRSSフィードから自動収集し，AI（Claude/OpenAI）で構造化要約を生成するWebアプリケーション．

## 技術スタック

- **PHP 8.2+** / **Laravel 11.x** - バックエンドフレームワーク
- **TypeScript** / **React 18** - フロントエンド（Laravel Vite統合）
- **Tailwind CSS 3** - スタイリング
- **MySQL 8.0+** - データベース
- **SimplePie** - RSS取得
- **Anthropic Claude / OpenAI** - AI要約生成

## ディレクトリ構造（Laravel 11）

```
/
├── app/
│   ├── Console/Commands/      # Artisanコマンド (user:create, rss:fetch)
│   ├── Http/
│   │   ├── Controllers/       # APIコントローラー
│   │   └── Middleware/        # カスタムミドルウェア (SessionAuth, AdminOnly)
│   ├── Models/                # Eloquentモデル
│   ├── Services/              # ビジネスロジック (AI要約, RSS取得)
│   └── Providers/
├── bootstrap/
│   ├── app.php                # アプリケーション設定・ミドルウェア・ルーティング
│   └── providers.php          # サービスプロバイダー登録
├── config/
├── database/migrations/
├── public/                    # Webルート (Apache DocumentRoot)
│   ├── index.php
│   ├── .htaccess
│   └── build/                 # Viteビルド出力 (gitignore)
├── resources/
│   ├── ts/                    # React/TypeScriptソース
│   │   ├── components/
│   │   ├── api.ts
│   │   ├── types.ts
│   │   ├── App.tsx
│   │   └── main.tsx
│   └── views/
│       └── app.blade.php      # SPAエントリーポイント
├── routes/
│   ├── api.php                # APIルート
│   ├── web.php                # SPAフォールバック
│   └── console.php            # コンソールルート・スケジューリング
├── storage/
├── composer.json              # PHP依存関係
├── package.json               # Node.js依存関係
├── vite.config.ts             # Vite設定 (Laravel統合)
├── tsconfig.json
├── tailwind.config.js
└── setup.sh                   # ワンコマンドセットアップ
```

## セットアップ

### ワンコマンドセットアップ

```bash
./setup.sh
```

実行内容：
1. Composer依存関係インストール
2. npm依存関係インストール
3. .env作成・APP_KEY生成
4. データベースマイグレーション
5. フロントエンドビルド（Vite）
6. 本番用キャッシュ生成

### 手動セットアップ

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
```

## Apache設定

`https://{your-domain}.org/autosurvey/` でアクセスする場合：

```apache
Alias /autosurvey /path/to/AutoSurvey/public

<Directory /path/to/AutoSurvey/public>
    AllowOverride All
    Require all granted
</Directory>
```

必要なモジュール:
```bash
sudo a2enmod rewrite headers deflate expires
sudo systemctl restart apache2
```

パーミッション:
```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

## 開発コマンド

```bash
# 開発サーバー（Vite + Laravel）
php artisan serve &
npm run dev

# ユーザー作成
php artisan user:create

# RSS取得
php artisan rss:fetch
php artisan rss:fetch --list

# 本番ビルド
npm run build

# キャッシュクリア
php artisan config:clear
php artisan route:clear
php artisan view:clear

# スケジュール確認
php artisan schedule:list
```

## API構造

すべてのAPIは `/api/*` でアクセス（Apacheの場合は `/autosurvey/api/*`）

### 認証
- `POST /api/auth/login`
- `POST /api/auth/register`
- `POST /api/auth/logout`
- `GET /api/auth/me`

### 論文・論文誌
- `GET /api/papers`
- `GET /api/papers/{id}`
- `GET /api/journals`

### AI要約
- `GET /api/summaries/providers`
- `POST /api/summaries/generate`

### 管理
- `POST /api/admin/journals`
- `PUT /api/admin/journals/{id}`
- `DELETE /api/admin/journals/{id}`

## コーディング規約

### PHP
- PHP 8.2+ の機能を使用可能
- Constructor property promotion 使用可
- Nullsafe演算子（?->）使用可
- Arrow functions (fn) 使用可
- Match式 使用可

### TypeScript/React
- 関数コンポーネント + Hooks
- Tailwind CSSでスタイリング
- `resources/ts/` 配下に配置

## 環境変数（.env）

```env
APP_NAME=AutoSurvey
APP_ENV=production
APP_DEBUG=false

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=autosurvey
DB_USERNAME=your_user
DB_PASSWORD=your_password

AI_PROVIDER=claude
CLAUDE_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...
```

## 注意事項

- lock ファイル（composer.lock, package-lock.json）は.gitignoreで除外
- `APP_KEY` は `php artisan key:generate` で生成
- AIのAPIキーは絶対にコミットしない
