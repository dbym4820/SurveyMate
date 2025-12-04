# AutoSurvey Backend - Laravel

**Version 1.0.0**

学術論文RSS集約・AI要約システムのバックエンドAPI（Laravel 12）

**Developer:** Tomoki Aburatani

## 必要要件

- PHP 8.2以上
- Composer
- MySQL 8.0以上
- Node.js（フロントエンドビルド用）

## セットアップ

### 1. 依存関係のインストール

```bash
cd backend
composer install
```

### 2. 環境設定

```bash
cp .env.example .env
# .envファイルを編集してDB接続情報とAPIキーを設定
```

必須の環境変数:
- `DB_*`: MySQLデータベース接続情報
- `CLAUDE_API_KEY` または `OPENAI_API_KEY`: AI要約生成用

### 3. アプリケーションキーの生成

```bash
php artisan key:generate
```

### 4. データベースのセットアップ

```bash
# マイグレーション実行
php artisan migrate

# 初期データ（論文誌マスタ・管理者ユーザー）を挿入
php artisan db:seed
```

### 5. 開発サーバーの起動

```bash
php artisan serve
```

APIは `http://localhost:8000/autosurvey/api` でアクセス可能です。

## CLIコマンド

### ユーザー作成

```bash
# 対話モード
php artisan user:create

# 引数指定
php artisan user:create username password --admin --email=user@example.com
```

### RSS取得

```bash
# 全ジャーナルを取得
php artisan rss:fetch

# 特定のジャーナルを取得
php artisan rss:fetch ijaied

# ジャーナル一覧を表示
php artisan rss:fetch --list
```

### スケジューラー

本番環境では、cronで毎分スケジューラーを実行:

```bash
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

デフォルトで毎日6:00（Asia/Tokyo）にRSS取得が実行されます。

## API エンドポイント

### 認証

| メソッド | エンドポイント | 説明 |
|---------|---------------|------|
| POST | `/autosurvey/api/auth/login` | ログイン |
| POST | `/autosurvey/api/auth/register` | ユーザー登録 |
| POST | `/autosurvey/api/auth/logout` | ログアウト |
| GET | `/autosurvey/api/auth/me` | 現在のユーザー情報 |

### 論文

| メソッド | エンドポイント | 説明 |
|---------|---------------|------|
| GET | `/autosurvey/api/papers` | 論文一覧（フィルタ対応） |
| GET | `/autosurvey/api/papers/:id` | 論文詳細 |
| GET | `/autosurvey/api/papers/stats` | 統計情報 |

### 論文誌

| メソッド | エンドポイント | 説明 |
|---------|---------------|------|
| GET | `/autosurvey/api/journals` | 論文誌一覧 |
| GET | `/autosurvey/api/journals/:id` | 論文誌詳細 |

### AI要約

| メソッド | エンドポイント | 説明 |
|---------|---------------|------|
| GET | `/autosurvey/api/summaries/providers` | 利用可能なAIプロバイダ |
| POST | `/autosurvey/api/summaries/generate` | 要約生成 |
| GET | `/autosurvey/api/summaries/:paperId` | 論文の要約一覧 |

### 管理者API

| メソッド | エンドポイント | 説明 |
|---------|---------------|------|
| POST | `/autosurvey/api/admin/journals` | 論文誌追加 |
| PUT | `/autosurvey/api/admin/journals/:id` | 論文誌更新 |
| DELETE | `/autosurvey/api/admin/journals/:id` | 論文誌無効化 |
| POST | `/autosurvey/api/admin/journals/test-rss` | RSSテスト |
| GET | `/autosurvey/api/admin/scheduler/status` | スケジューラー状態 |
| POST | `/autosurvey/api/admin/scheduler/run` | RSS手動取得 |
| GET | `/autosurvey/api/admin/logs` | 取得ログ |
| GET | `/autosurvey/api/admin/users` | ユーザー一覧 |

## ディレクトリ構成

```
backend/
├── app/
│   ├── Console/Commands/     # CLIコマンド
│   ├── Http/
│   │   ├── Controllers/      # APIコントローラー
│   │   └── Middleware/       # 認証ミドルウェア
│   ├── Models/               # Eloquentモデル
│   ├── Providers/            # サービスプロバイダ
│   └── Services/             # ビジネスロジック
├── config/                   # 設定ファイル
├── database/
│   ├── migrations/           # マイグレーション
│   └── seeders/              # シーダー
├── routes/
│   ├── api.php              # APIルート
│   └── console.php          # スケジュールタスク
└── .env.example             # 環境変数テンプレート
```

## 本番環境へのデプロイ

```bash
# 依存関係（本番用）
composer install --optimize-autoloader --no-dev

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

© 2024 Tomoki Aburatani
