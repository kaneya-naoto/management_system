# カクレマ 新基幹システム - Project Documentation

## 1. プロジェクト概要

### システム名
**カクレマ 新基幹システム**

### コンセプト
個室麻雀店「カクレマ」専用の予約・清掃業務・人員管理システム

- 予約・清掃業務・人員管理の属人化排除
- FC展開を前提とした店舗単位CMS構築
- LINEを活用した清掃募集/延長対応/急募の自動化

### 開発規模
| 項目 | 内容 |
|------|------|
| 総工数 | 13人日 |
| 想定期間 | 約2.5週間 |
| 担当 | SE 1名 |

> 詳細な開発フェーズは [spec/schedule/development_phases.md](spec/schedule/development_phases.md) を参照

---

## 2. 技術スタック

| 項目 | 選択 | 備考 |
|------|------|------|
| 言語 | PHP 8.x | フルスクラッチ（FWなし） |
| テンプレート | 素のPHP | - |
| CSS | Bootstrap 5 | - |
| DB | MySQL 8.0 | 単一DB |
| インフラ | Xserver | 共有レンタルサーバー |
| メール | XserverのSMTP | - |
| 外部連携 | LINE Messaging API | 清掃者への通知・応募管理 |

> 詳細は [spec/overview/tech_stack.md](spec/overview/tech_stack.md) を参照

---

## 3. 仕様書リファレンス

仕様の詳細は `spec/` ディレクトリに整理されています。

### システム理解（最初に読む）
| ドキュメント | 内容 |
|--------------|------|
| [spec/README.md](spec/README.md) | 仕様書インデックス |
| [spec/overview/system_overview.md](spec/overview/system_overview.md) | システム全体像・コンセプト |
| [spec/overview/organization_model.md](spec/overview/organization_model.md) | 組織階層・権限モデル |

### 機能仕様
| ドキュメント | 内容 |
|--------------|------|
| [spec/features/reservation/](spec/features/reservation/) | 予約フロー・UI |
| [spec/features/cleaning_job.md](spec/features/cleaning_job.md) | 清掃案件管理 |
| [spec/features/line/](spec/features/line/) | LINE連携 |
| [spec/features/key_management.md](spec/features/key_management.md) | 鍵番号管理 |

### DB設計
| ドキュメント | 内容 |
|--------------|------|
| [spec/db/schema.md](spec/db/schema.md) | ER図・テーブル一覧 |
| [spec/db/design_policy.md](spec/db/design_policy.md) | 設計方針 |

---

## 4. ディレクトリ構成

```
management_system/
├── project.md              # プロジェクト概要（このファイル）
├── CLAUDE.md               # AI開発ガイドライン
├── spec/                   # 仕様書
├── archive/                # 旧仕様書（アーカイブ）
│
└── public_html/            # ドキュメントルート
    ├── index.php           # エントリーポイント（ルーティング）
    ├── .htaccess           # URL書き換え
    │
    ├── pages/              # 画面（1ファイル = 1ページ）
    │   ├── login.php
    │   ├── dashboard.php   # KPIダッシュボード
    │   ├── reservations/   # 予約管理（CMS）
    │   ├── jobs/           # 清掃案件
    │   ├── cleaners/       # 清掃者管理
    │   ├── keys/           # 鍵番号管理
    │   ├── settings/       # 設定（固定者管理含む）
    │   └── booking/        # 公開予約フォーム（顧客向け）
    │       ├── index.php       # 日時選択
    │       ├── customer.php    # 顧客情報入力
    │       ├── confirm.php     # 確認画面
    │       ├── complete.php    # 予約完了処理
    │       └── thanks.php      # 完了ページ
    │
    ├── includes/           # 共通処理
    │   ├── config.php      # 設定（DB接続情報等）
    │   ├── db.php          # DB接続・クエリ
    │   ├── auth.php        # 認証・セッション・権限
    │   ├── functions.php   # 共通関数
    │   ├── header.php      # 共通ヘッダーHTML（CMS用）
    │   ├── footer.php      # 共通フッターHTML（CMS用）
    │   ├── public_header.php  # 公開ページ用ヘッダー
    │   └── public_footer.php  # 公開ページ用フッター
    │
    ├── api/                # API（外部連携）
    │   ├── line/
    │   │   └── webhook.php
    │   └── booking/
    │       └── check-availability.php  # 空き状況チェックAPI
    │
    └── assets/             # 静的ファイル
        ├── css/
        ├── js/
        └── img/
```

---

## 5. 現在の実装状況

### 完了済み
- [x] **仕様書整理（spec/ディレクトリ構造化）**
- [x] プロジェクト構造作成（public_html/配下）
- [x] 設定ファイル（includes/config.php）
- [x] index.php（エントリーポイント・ルーティング）
- [x] db.php（DB接続・クエリ関数）
- [x] auth.php（認証・セッション・権限）
- [x] functions.php（共通関数）
- [x] header.php / footer.php（共通HTML）
- [x] ログイン画面UI（pages/login.php）
- [x] ダッシュボード（pages/dashboard.php）
- [x] スケルトン画面（予約/案件/清掃者/設定）
- [x] LINE Webhook エンドポイント（api/line/webhook.php）

### 未実装（Phase順）

#### Phase 1: 基盤設計
- [x] DB設計：テーブル作成（マイグレーション） ※database/001_create_tables.sql
- [x] 初期データ作成 ※database/002_seed_data.sql
- [x] 追加カラムマイグレーション ※database/003_add_reservation_columns.sql

#### Phase 2: 認証・権限
- [ ] 実際のDB連携ログイン動作確認（DBセットアップ後）

#### Phase 3〜: 機能実装（一覧画面完了）
- [x] ダッシュボード（サマリー表示）
- [x] 予約一覧（検索・フィルタ・ページネーション）
- [x] 清掃案件一覧（ステータス別・フィルタ）
- [x] 清掃者一覧（検索・フィルタ）

#### コードレビュー対応（完了）
- [x] LIMIT/OFFSET SQLインジェクション対策（dbSelectPaginated関数）
- [x] Open Redirect脆弱性対策（redirect関数）
- [x] LINE Webhookレート制限追加
- [x] ヘルパー関数追加（buildInClause, getAccessibleStoreIds, renderPagination）
- [x] 重複コード解消

#### 2回目コードレビュー対応（完了）
- [x] 応募採用時のステータスチェック + トランザクション追加
- [x] 清掃者店舗更新のトランザクション化
- [x] 報酬上限チェック追加（100万円上限）
- [x] 入力バリデーション強化（メール形式、文字数制限）
- [x] 無駄な再取得クエリ削除
- [x] CSRFトークン1回生成に統一
- [x] ヘルパー関数追加（reservationSourceLabel, paymentStatusInfo, jobTypeLabel, applicationStatusInfo, formatTime, getDayName）

#### 詳細・編集画面（完了）
- [x] 予約詳細・編集画面（ステータス変更、顧客情報編集、キャンセル）
- [x] 清掃案件詳細・編集画面（担当者割当、応募者採用、報酬設定）
- [x] 清掃者詳細・編集画面（プロフィール編集、対応店舗設定、実績表示）

#### 3回目コードレビュー対応（Codex協調 - 完了）
- [x] 応募採用トランザクションで更新行数検証（0件ならロールバック）
- [x] 担当者割当・ステータス変更に楽観ロック追加
- [x] 募集開始に楽観ロック追加
- [x] 予約ステータス遷移ルール追加（キャンセル済み復帰不可、完了済みキャンセル不可）
- [x] 予約キャンセルに楽観ロック追加
- [x] 清掃者ステータス切替の原子的更新化
- [x] 対応店舗更新で重複ID排除追加
- [x] 休止/削除済み清掃者の採用時検証追加

#### 機能追加（完了）
- [x] 鍵番号管理（一覧・新規登録・詳細編集・有効/無効切替・使用履歴）
- [x] 予約新規登録（CMS手動登録、鍵自動割当、清掃案件自動生成）
- [x] 延長機能（延長時間登録、報酬自動計算、予約時間更新）

#### 4回目コードレビュー対応（完了）
- [x] エラーメッセージ漏洩修正（reservations/create.php）
- [x] ヘルパー関数追加（getAccessibleSalesAreas, findSalesArea, assignKeyToReservation, createCleaningJobForReservation, calculateExtensionReward）
- [x] 重複関数削除（reservations/create.php）
- [x] マジックナンバーを定数化（DEFAULT_CLEANING_REWARD, EXTENSION_REWARD_RATE, REWARD_ROUND_UNIT, MAX_EXTENSION_HOURS）
- [x] keysページでヘルパー関数使用に統一

#### 予約フォーム・KPI・固定者管理（完了）
- [x] 予約フォーム（フロント・顧客向け）
  - 4ステップウィザード（日時選択→顧客情報→確認→完了）
  - 空き状況チェックAPI（リアルタイム）
  - セッション固定化攻撃対策（session_regenerate_id）
  - レースコンディション対策（FOR UPDATE ロック）
  - サーバーサイド検証強化
- [x] KPIダッシュボード
  - 期間フィルタ（今日/今週/今月）
  - 売上/コスト/粗利益/利益率表示
  - 予約経路別集計グラフ
- [x] 固定者管理（OWNER向け設定画面）
  - 店舗別固定者一覧
  - 優先順位設定（1-99）
  - 有効/無効切替
  - 追加時の重複防止（トランザクション）

#### 5回目コードレビュー対応（完了）
- [x] 予約完了時のレースコンディション対策（FOR UPDATE）
- [x] セッション固定化対策（session_regenerate_id）
- [x] 空き状況APIの時間フォーマット検証
- [x] 顧客情報の過去日・時間形式検証追加
- [x] 電話番号フォーマット検証追加
- [x] 固定者追加の重複チェック競合対策

#### LINE連携・支払い・管理機能（完了）
- [x] LINE Webhook強化版（follow/message/postbackイベント処理）
- [x] LINE清掃者登録フロー（プロフィール登録→店舗選択→iPassコード発行）
- [x] LINE応募画面（公開・トークン認証、固定者/公募対応）
- [x] 支払い管理（一覧・詳細・一括支払い・個別支払い）
- [x] シフト管理画面（タイムライン表示、日付/店舗/ステータスフィルタ）
- [x] 操作ログ一覧（OWNER専用、期間/操作者/種別フィルタ）
- [x] 監査ログ関数（logAudit）
- [x] メール送信関数（sendMail, sendReservationConfirmMail）

#### 6回目コードレビュー対応（完了）
- [x] トークン漏洩対策（Referrer-Policy ヘッダー追加）
- [x] iPassコード生成の再帰をループに変更（スタックオーバーフロー防止）
- [x] ログ一覧のシステム（-1）フィルタバグ修正
- [x] JSONエンコードエラー対策（logAuditでJSON_THROW_ON_ERROR）
- [x] メールヘッダーインジェクション対策
- [x] ステータスフィルタのホワイトリスト追加
- [x] ipass_codeにUNIQUE制約追加

#### 設定・管理機能（完了）
- [x] 店舗設定画面（基本情報編集、営業区分管理）
- [x] ユーザー管理画面（一覧・追加・編集・削除、パスワード変更）
- [x] 清掃者新規登録（CMS手動登録、iPassコード自動生成）

#### 7回目コードレビュー対応（Codex協調 - 完了）
- [x] ユーザー更新/削除のIDOR脆弱性修正（店舗スコープ検証追加）
- [x] 清掃者登録にOWNER権限チェック追加
- [x] 営業区分更新のバリデーション強化（長さ・重複チェック、is_active修正）
- [x] 営業区分削除のトランザクション化＋FOR UPDATEロック
- [x] ユーザー削除時のuser_stores連動削除
- [x] パスワードハッシュをPASSWORD_DEFAULTに変更

#### 8回目コードレビュー対応（4エージェント並列 - 完了）
- [x] getCurrentUserId()関数追加（auth.php）
- [x] dbExecute()関数追加（db.php）
- [x] ページネーションoffsetバグ修正（logs/list.php, payments/list.php）
- [x] レート制限追加（api/booking/check-availability.php）
- [x] 重複コード削除・ヘルパー関数使用（pages/reservations/detail.php）
- [x] 署名検証失敗時のログ追加（api/line/webhook.php）

#### 次に実装予定
- [ ] 決済連携（Stripe / PAY.JP 等）
- [ ] 外部通知連携強化（メール通知自動化）

---

## 6. 開発ルール

### コーディング規約
- PSR-12準拠
- クラス名: PascalCase
- メソッド/変数: camelCase
- 定数: UPPER_SNAKE_CASE

### DB命名規則
- テーブル名: snake_case, 複数形（users, reservations）
- カラム名: snake_case（store_id, created_at）
- 外部キー: {参照先テーブル名}_id（user_id）

### セキュリティ
- SQLインジェクション: プリペアドステートメント必須
- XSS: 出力時にhtmlspecialchars()
- CSRF: トークン検証必須
- パスワード: bcrypt (cost=12)

---

## 7. 未決定事項・TODO

### 決済連携
- [ ] 決済サービス選定（Stripe / PAY.JP / Square / 他）
- [ ] 決済タイミング（予約時全額 / デポジット / 当日）

### LINE連携
- [ ] 応募画面の実装方式（LIFF vs 外部Web）
- [ ] 応募競合時の解決方法（先着 vs 抽選）

### UI/UX
- [ ] OWNERの複数店舗切り替えUI

### 非要件（v1スコープ外）
- 自動給与振込
- 外部会計ソフト連携
- 多言語対応
- オフライン運用

---

## 8. 実装上の注意

- 内部営業区分は将来「独立店舗」に昇格可能なID設計
- データ削除は原則禁止（論理削除のみ）
- FC展開を前提とした店舗データ分離

---

## 9. 本番環境情報

### サーバー情報

| 項目 | 値 |
|------|-----|
| サーバー | Xserver |
| ホスト名 | sv16722.xserver.jp |
| ドメイン | xs151334.xsrv.jp |
| テスト用パス | /kakurema/ |

### SSH接続

```bash
# WSL側の設定が必要（~/.ssh/config に追加済み）
ssh kakurema
```

**SSH設定（~/.ssh/config）:**
```
Host kakurema
  HostName sv16722.xserver.jp
  User xs151334
  Port 10022
  IdentityFile ~/.ssh/keys/kakurema.key
  ControlMaster auto
  ControlPath ~/.ssh/sockets/%r@%h-%p
  ControlPersist 600
```

**秘密鍵パスフレーズ:** `Ey9VVB494yuE`

### データベース

| 項目 | 値 |
|------|-----|
| Host | localhost |
| Database | xs151334_kakurema |
| User | xs151334_admin |
| Password | s6RTMM~cB|Dw |

**接続コマンド:**
```bash
ssh kakurema
mysql -u xs151334_admin -p xs151334_kakurema
# パスワード入力: s6RTMM~cB|Dw
```

### デプロイ

```bash
# 全ファイルデプロイ
rsync -avz --exclude='.git' --exclude='.env' \
  /mnt/c/ai/developer/management_system/public_html/ \
  kakurema:/home/xs151334/xs151334.xsrv.jp/public_html/kakurema/

# 特定ファイルのみ
rsync -avz public_html/includes/db.php \
  kakurema:/home/xs151334/xs151334.xsrv.jp/public_html/kakurema/includes/
```

### 環境設定ファイル

**サーバー側 .env（/home/xs151334/xs151334.xsrv.jp/public_html/kakurema/.env）:**
```
DB_HOST=localhost
DB_NAME=xs151334_kakurema
DB_USER=xs151334_admin
DB_PASS=s6RTMM~cB|Dw
APP_ENV=production
APP_DEBUG=false
```

### アクセスURL

| 用途 | URL |
|------|-----|
| 管理画面（テスト） | https://xs151334.xsrv.jp/kakurema/ |
| ログイン | https://xs151334.xsrv.jp/kakurema/login |
| 予約フォーム | https://xs151334.xsrv.jp/kakurema/booking |

### 管理者アカウント

| 項目 | 値 |
|------|-----|
| Email | admin@kakurema.jp |
| Password | password123 |

**その他のテストアカウント:**
| 役割 | Email | Password |
|------|-------|----------|
| HQ（本部管理者） | admin@kakurema.jp | password123 |
| OWNER（オーナー） | owner@kakurema.jp | password123 |
| STORE（スタッフ） | staff@kakurema.jp | password123 |

---

*Last Updated: 2026-01-26*
