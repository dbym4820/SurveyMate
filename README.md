# SurveyMate

学術論文サーベイ支援システム（RSSによる論文集約・生成AI要約）

任意の学術論文誌のRSSフィードから論文情報を自動収集し，生成AI（OpenAI/Claude）で構造化要約を生成するWebアプリケーション．
各ユーザーが自分の研究分野に合わせた論文誌を登録し，タグで論文を整理．

## 機能

- 学術論文誌のRSSフィードから論文情報を自動収集
- Claude/OpenAI APIによる構造化AI要約生成
- 期間・論文誌・タグによるフィルタリング
- タグによる論文の整理・グループ化
- トレンド分析（期間別の研究動向要約）
- 論文誌管理（ユーザーごと）
- ユーザー別APIキー設定（システム側でのAPIキー管理不要）

## 技術スタック

- **PHP 8.2+** / **Laravel 11** - バックエンドフレームワーク
- **TypeScript** / **React 18** - フロントエンド
- **Vite 5** - フロントエンドビルドツール（Laravel統合）
- **Tailwind CSS 3** - スタイリング
- **MySQL 8.0+** - データベース
- **SimplePie** - RSS取得
- **Anthropic Claude / OpenAI** - AI要約生成（各ユーザーが自身のAPIキーを設定）

## 必要要件

- PHP 8.2以上
- Composer
- Node.js 18以上
- npm
- MySQL 8.0以上

## セットアップ

### ワンコマンドセットアップ（推奨）

```bash
./setup.sh
```

このスクリプトは以下を自動実行します：
1. PHP/Composerの依存関係インストール
2. Node.js/npmの依存関係インストール
3. 環境設定（.env作成，APP_KEY生成）
4. データベース作成・マイグレーション（初期管理者ユーザー自動作成）
5. フロントエンドビルド（Vite/TypeScript）
6. Laravel最適化

### 手動セットアップ

```bash
# PHP依存関係
composer install

# Node.js依存関係
npm install

# 環境設定
cp .env.example .env
php artisan key:generate

# データベース
php artisan migrate

# フロントエンドビルド
npm run build
```

### 環境変数の設定

`.env`ファイルを編集し，以下を設定してください：

```env
# データベース接続
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=surveymate
DB_USERNAME=your_user
DB_PASSWORD=your_password

# Unixソケット（MAMPの場合など）
DB_SOCKET=/Applications/MAMP/tmp/mysql/mysql.sock

# MySQLクライアントのパス（setup.sh用，任意）
# MAMP MySQL 8.0: /Applications/MAMP/Library/bin/mysql80/bin/mysql
MYSQL_BIN=

# 初期管理者ユーザー（マイグレーション時に自動作成）
ADMIN_USER_ID=admin
ADMIN_USERNAME=管理者
ADMIN_PASSWORD=your_secure_password
ADMIN_EMAIL=admin@example.com

# 新規ユーザー用デフォルト論文誌（任意）
# 形式: 名前|RSS_URL（複数はセミコロン区切り，空白含む名前はクォートで囲む）
# 例: DEFAULT_JOURNALS="International Journal of AIED|https://...;Journal Name|https://..."
DEFAULT_JOURNALS=
```

## 開発コマンド

```bash
# 開発サーバー起動（Vite HMR + Laravel）
php artisan serve &
npm run dev

# 本番ビルド
npm run build

# RSS取得
php artisan rss:fetch              # 全ジャーナル
php artisan rss:fetch ijaied       # 特定ジャーナル
php artisan rss:fetch --list       # ジャーナル一覧

# キャッシュクリア
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

## Apache設定

`https://your-domain.org/surveymate/` でアクセスする場合：

```apache
Alias /surveymate /path/to/SurveyMate/public

<Directory /path/to/SurveyMate/public>
    AllowOverride All
    Require all granted
</Directory>
```

パーミッション設定：
```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

## スケジューラー設定

cronで毎分スケジューラーを実行（デフォルトで毎日6:00にRSS取得）：

```bash
* * * * * cd /path/to/SurveyMate && php artisan schedule:run >> /dev/null 2>&1
```

## ディレクトリ構成

```
SurveyMate/
├── app/
│   ├── Console/Commands/      # Artisanコマンド (rss:fetch)
│   ├── Http/
│   │   ├── Controllers/       # APIコントローラー
│   │   └── Middleware/        # 認証ミドルウェア
│   ├── Models/                # Eloquentモデル (User, Paper, Journal, Tag, Summary)
│   └── Services/              # ビジネスロジック (AI要約, RSS取得)
├── config/
│   └── surveymate.php         # アプリケーション固有設定
├── database/
│   ├── migrations/            # データベースマイグレーション
│   └── seeders/               # 初期データシーダー
├── public/                    # Webルート
│   └── build/                 # Viteビルド出力
├── resources/
│   ├── ts/                    # React/TypeScriptソース
│   │   ├── components/        # Reactコンポーネント
│   │   ├── api.ts             # API通信
│   │   ├── types.ts           # 型定義
│   │   └── main.tsx           # エントリーポイント
│   └── views/
│       └── app.blade.php      # SPAテンプレート
├── routes/
│   ├── api.php                # APIルート定義
│   └── web.php                # Webルート（SPAフォールバック）
├── storage/                   # ログ・キャッシュ
├── composer.json              # PHP依存関係
├── package.json               # Node.js依存関係
├── vite.config.ts             # Vite設定
├── tailwind.config.js         # Tailwind設定
├── tsconfig.json              # TypeScript設定
└── setup.sh                   # セットアップスクリプト
```

## API エンドポイント

### 認証

| メソッド | エンドポイント | 説明 |
|---------|---------------|------|
| POST | `/api/auth/login` | ログイン |
| POST | `/api/auth/register` | ユーザー登録 |
| POST | `/api/auth/logout` | ログアウト |
| GET | `/api/auth/me` | 現在のユーザー情報 |

### 論文

| メソッド | エンドポイント | 説明 |
|---------|---------------|------|
| GET | `/api/papers` | 論文一覧（フィルタ対応） |
| GET | `/api/papers/{id}` | 論文詳細 |

### 論文誌

| メソッド | エンドポイント | 説明 |
|---------|---------------|------|
| GET | `/api/journals` | 論文誌一覧 |
| POST | `/api/admin/journals` | 論文誌追加 |
| PUT | `/api/admin/journals/{id}` | 論文誌更新 |
| DELETE | `/api/admin/journals/{id}` | 論文誌削除（無効化） |
| POST | `/api/admin/journals/{id}/activate` | 論文誌有効化 |
| POST | `/api/admin/journals/test-rss` | RSSテスト |
| GET | `/api/admin/journals/{id}/fetch` | RSS手動取得 |

### タグ

| メソッド | エンドポイント | 説明 |
|---------|---------------|------|
| GET | `/api/tags` | タグ一覧 |
| POST | `/api/tags` | タグ作成 |
| PUT | `/api/tags/{id}` | タグ更新 |
| DELETE | `/api/tags/{id}` | タグ削除 |
| POST | `/api/papers/{paperId}/tags` | 論文にタグ追加 |
| DELETE | `/api/papers/{paperId}/tags/{tagId}` | 論文からタグ削除 |

### AI要約

| メソッド | エンドポイント | 説明 |
|---------|---------------|------|
| GET | `/api/summaries/providers` | 利用可能なAIプロバイダ |
| POST | `/api/summaries/generate` | 要約生成 |

### 設定

| メソッド | エンドポイント | 説明 |
|---------|---------------|------|
| GET | `/api/settings/api` | API設定取得 |
| PUT | `/api/settings/api` | API設定更新（APIキー等） |

### トレンド分析

| メソッド | エンドポイント | 説明 |
|---------|---------------|------|
| GET | `/api/trends/stats` | 期間別統計 |
| GET | `/api/trends/{period}/papers` | 期間別論文取得 |
| POST | `/api/trends/{period}/generate` | トレンド要約生成 |

## 本番環境へのデプロイ

```bash
# 依存関係（本番用）
composer install --optimize-autoloader --no-dev
npm ci
npm run build

# キャッシュ
php artisan config:cache
php artisan route:cache
php artisan view:cache

# マイグレーション
php artisan migrate --force
```

## ライセンス

MIT

---

© 2025 Tomoki Aburatani
