# 技術スタック

## 使用技術

| 項目 | 選択 | 備考 |
|------|------|------|
| 言語 | PHP 8.x | フルスクラッチ（FWなし） |
| テンプレート | 素のPHP | - |
| CSS | Bootstrap 5 | レスポンシブ対応 |
| DB | MySQL 8.0 | 単一DB |
| インフラ | Xserver | 共有レンタルサーバー |
| メール | XserverのSMTP | - |
| 外部連携 | LINE Messaging API | 清掃者への通知・応募管理 |

---

## コーディング規約

### PSR-12準拠

| 対象 | 命名規則 | 例 |
|------|----------|-----|
| クラス名 | PascalCase | `UserRepository`, `CleaningJobService` |
| メソッド名 | camelCase | `findById`, `createReservation` |
| 変数名 | camelCase | `$storeId`, `$cleaningJob` |
| 定数 | UPPER_SNAKE_CASE | `MAX_RETRY_COUNT`, `DEFAULT_REWARD` |

### DB命名規則

| 対象 | 命名規則 | 例 |
|------|----------|-----|
| テーブル名 | snake_case, 複数形 | `users`, `reservations`, `cleaning_jobs` |
| カラム名 | snake_case | `store_id`, `created_at`, `line_user_id` |
| 外部キー | `{参照先テーブル名}_id` | `user_id`, `store_id`, `reservation_id` |

---

## セキュリティ要件

### 必須対策

| 脅威 | 対策 |
|------|------|
| SQLインジェクション | プリペアドステートメント必須 |
| XSS | 出力時に `htmlspecialchars()` |
| CSRF | トークン検証必須 |
| パスワード漏洩 | bcrypt (cost=12) |

### LINE連携セキュリティ

| 項目 | 対策 |
|------|------|
| 署名検証 | X-Line-Signatureヘッダーの検証 |
| 冪等性 | 冪等性キーで重複処理防止 |
| トークン | ワンタイムトークンで応募URL生成 |

---

## ディレクトリ構成

```
management_system/
├── public_html/               # Webルート
│   ├── index.php              # エントリーポイント（ルーティング）
│   ├── .htaccess              # URL書き換え
│   ├── .env.example           # 環境変数テンプレート
│   │
│   ├── pages/                 # 画面（1ファイル = 1ページ）
│   │   ├── login.php          # ログイン
│   │   ├── forgot-password.php # パスワードリセット申請
│   │   ├── reset-password.php # パスワードリセット実行
│   │   ├── dashboard.php      # ダッシュボード
│   │   ├── reservations/      # 予約管理（list, create, detail, calendar, gantt）
│   │   ├── jobs/              # 清掃案件（list, detail）
│   │   ├── cleaners/          # 清掃者管理（list, create, detail）
│   │   ├── payments/          # 支払管理（list, detail）
│   │   ├── keys/              # 鍵管理（list, create, detail）
│   │   ├── shifts/            # シフト管理（list）
│   │   ├── logs/              # ログ閲覧（list）
│   │   ├── settings/          # 設定（index, store, stores, owners, users, line, line_richmenu, fixed-cleaners）
│   │   ├── booking/           # 公開予約フロー（index, customer, confirm, complete, thanks）
│   │   ├── register/          # 清掃者登録（index, stores）
│   │   ├── apply/             # 案件応募（index, list）
│   │   ├── extend/            # 延長対応（index, respond）
│   │   └── ipass/             # iPass表示（view）
│   │
│   ├── includes/              # 共通処理
│   │   ├── config.php         # 設定（DB接続情報・定数定義）
│   │   ├── db.php             # DB接続・クエリヘルパー
│   │   ├── auth.php           # 認証・認可・CSRF・セッション管理
│   │   ├── functions.php      # 共通ユーティリティ関数
│   │   ├── helpers.php        # 汎用ヘルパー関数
│   │   ├── header.php         # 管理画面 共通ヘッダーHTML
│   │   ├── footer.php         # 管理画面 共通フッターHTML
│   │   ├── public_header.php  # 公開画面 共通ヘッダーHTML
│   │   ├── public_footer.php  # 公開画面 共通フッターHTML
│   │   ├── reservation_helpers.php # 予約関連ヘルパー
│   │   ├── job_helpers.php    # 案件関連ヘルパー
│   │   ├── user_helpers.php   # ユーザー関連ヘルパー
│   │   ├── line_helper.php    # LINE API連携ヘルパー
│   │   ├── notification_helpers.php # 通知ヘルパー
│   │   └── audit_helpers.php  # 監査ログヘルパー
│   │
│   ├── api/                   # API
│   │   ├── booking/           # 予約API（check-availability）
│   │   ├── line/              # LINE連携（webhook, webhook_store, config）
│   │   ├── reservations/      # 予約データAPI（calendar-data）
│   │   ├── export/            # エクスポート（reservations, payments）
│   │   └── settings/          # 設定API（available-cleaners）
│   │
│   ├── assets/                # 静的ファイル
│   │   ├── css/
│   │   ├── js/
│   │   └── img/
│   │
│   └── uploads/               # アップロードファイル
│
├── cron/                      # Cronジョブ
│   ├── auto_urgent_recruitment.php  # 緊急募集自動処理
│   ├── process_fixed_timeout.php    # 固定者タイムアウト処理
│   └── send_daily_notifications.php # 日次通知送信
│
├── database/                  # DBマイグレーション
│   ├── 001_create_tables.sql  # 初期テーブル作成
│   ├── ...                    # 順次マイグレーション
│   └── 014_reset_extension_rewards.sql
│
├── spec/                      # 仕様書
├── memory_bank/               # 実装ログ
└── project.md                 # プロジェクト仕様
```

---

## Xserver環境の制約

| 項目 | 制約・対応 |
|------|------------|
| PHP設定 | php.iniの一部設定のみ変更可能 |
| Cronジョブ | Xserver管理画面から設定 |
| SSL | 無料SSL（Let's Encrypt）利用可能 |
| セッション | ファイルベース（デフォルト） |
| アップロード | サイズ制限あり（要確認） |

---

## 関連ドキュメント

- [システム概要](system_overview.md)
- [組織・権限モデル](organization_model.md)
- [設計方針](../db/design_policy.md)

---

*Last Updated: 2026-01-29*
