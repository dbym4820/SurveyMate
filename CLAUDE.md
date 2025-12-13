# CLAUDE.md - SurveyMate

このファイルはClaude Codeがプロジェクトを理解するためのガイドです．

## プロジェクト概要

任意の学術論文誌のRSSフィードから論文情報を自動収集し，AI（Claude/OpenAI）で構造化要約を生成するWebアプリケーション．各ユーザーが自分の研究分野に合わせた論文誌を登録し，タグで論文を整理できる．

## 技術スタック

- **PHP 8.2+** / **Laravel 11.x** - バックエンドフレームワーク
- **TypeScript** / **React 18** - フロントエンド（Laravel Vite統合）
- **Tailwind CSS 3** - スタイリング
- **MySQL 8.0+** - データベース
- **SimplePie** - RSS取得
- **Anthropic Claude / OpenAI** - AI要約生成（各ユーザーが自身のAPIキーを設定）

## ディレクトリ構造（Laravel 11）

```
/
├── app/
│   ├── Console/Commands/      # Artisanコマンド (rss:fetch)
│   ├── Http/
│   │   ├── Controllers/       # APIコントローラー
│   │   └── Middleware/        # カスタムミドルウェア (SessionAuth)
│   ├── Models/                # Eloquentモデル (User, Paper, Journal, Tag, Summary, Session)
│   ├── Services/              # ビジネスロジック (AiSummaryService, RssFetcherService)
│   └── Providers/
├── bootstrap/
│   ├── app.php                # アプリケーション設定・ミドルウェア・ルーティング
│   └── providers.php          # サービスプロバイダー登録
├── config/
│   └── surveymate.php         # アプリケーション固有設定
├── database/migrations/
├── public/                    # Webルート (Apache DocumentRoot)
│   ├── index.php
│   ├── .htaccess
│   └── build/                 # Viteビルド出力 (gitignore)
├── resources/
│   ├── ts/                    # React/TypeScriptソース
│   │   ├── components/        # PaperList, PaperCard, Settings, JournalManagement等
│   │   ├── api.ts             # APIクライアント
│   │   ├── types.ts           # 型定義
│   │   ├── App.tsx            # ルートコンポーネント
│   │   └── main.tsx           # エントリーポイント
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

## 主要モデルとリレーション

- **User**: ユーザー（user_id: ログイン用，username: 表示名）
- **Journal**: 論文誌（user_id外部キー，ユーザーごと）
- **Paper**: 論文（journal_id外部キー）
- **Tag**: タグ（user_id外部キー，ユーザーごと）
- **paper_tag**: 論文-タグ中間テーブル（多対多）
- **Summary**: AI要約
- **Session**: セッション管理

## セットアップ

### ワンコマンドセットアップ

```bash
./setup.sh
```

実行内容：
1. Composer依存関係インストール
2. npm依存関係インストール
3. .env作成・APP_KEY生成
4. データベース作成・マイグレーション（初期管理者ユーザー自動作成）
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

`https://{your-domain}.org/surveymate/` でアクセスする場合：

```apache
Alias /surveymate /path/to/SurveyMate/public

<Directory /path/to/SurveyMate/public>
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

すべてのAPIは `/api/*` でアクセス（Apacheの場合は `/surveymate/api/*`）

### 認証
- `POST /api/auth/login` - ログイン（user_id + password）
- `POST /api/auth/register` - ユーザー登録（user_id + username + password）
- `POST /api/auth/logout` - ログアウト
- `GET /api/auth/me` - 現在のユーザー情報

### 論文・論文誌
- `GET /api/papers` - 論文一覧（journals，tags，dateFromパラメータでフィルタ）
- `GET /api/papers/{id}` - 論文詳細
- `GET /api/journals` - 論文誌一覧

### タグ
- `GET /api/tags` - タグ一覧
- `POST /api/tags` - タグ作成
- `PUT /api/tags/{id}` - タグ更新
- `DELETE /api/tags/{id}` - タグ削除
- `POST /api/papers/{paperId}/tags` - 論文にタグ追加
- `DELETE /api/papers/{paperId}/tags/{tagId}` - 論文からタグ削除

### AI要約
- `GET /api/summaries/providers` - 利用可能なAIプロバイダ
- `POST /api/summaries/generate` - 要約生成

### 設定
- `GET /api/settings/api` - API設定取得
- `PUT /api/settings/api` - API設定更新（APIキー等）

### 論文誌管理
- `POST /api/admin/journals` - 論文誌追加（初回RSS取得も実行）
- `PUT /api/admin/journals/{id}` - 論文誌更新
- `DELETE /api/admin/journals/{id}` - 論文誌削除（無効化）
- `POST /api/admin/journals/{id}/activate` - 論文誌有効化
- `GET /api/admin/journals/{id}/fetch` - RSS手動取得
- `POST /api/admin/journals/test-rss` - RSSテスト

### トレンド分析
- `GET /api/trends/stats` - 期間別統計
- `GET /api/trends/{period}/papers` - 期間別論文取得
- `POST /api/trends/{period}/generate` - トレンド要約生成

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
# アプリケーションキー（必須，php artisan key:generate で自動生成）
APP_KEY=

# データベース設定（必須）
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=surveymate
DB_USERNAME=your_user
DB_PASSWORD=your_password

# Unixソケット（MAMPの場合など）
DB_SOCKET=/Applications/MAMP/tmp/mysql/mysql.sock

# MySQLクライアントのパス（setup.sh用，任意）
MYSQL_BIN=/Applications/MAMP/Library/bin/mysql80/bin/mysql

# 初期管理者ユーザー（マイグレーション時に自動作成）
ADMIN_USER_ID=admin
ADMIN_USERNAME=管理者
ADMIN_PASSWORD=secure_password
ADMIN_EMAIL=admin@example.com

# 新規ユーザー用デフォルト論文誌（任意）
# 形式: "名前|RSS_URL;名前2|RSS_URL2" （セミコロン区切り，空白含む名前はクォートで囲む）
DEFAULT_JOURNALS=

# Web Push通知（任意）
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
```

## 注意事項

- lockファイル（composer.lock，package-lock.json）は.gitignoreで除外
- `APP_KEY` は `php artisan key:generate` で生成
- AIのAPIキーは各ユーザーが設定画面から入力（システム側で管理不要）
- 初期管理者ユーザーは.envのADMIN_*変数から自動作成
