# 乖離修正 完了レポート

## 実施日: 2026-01-29

## 概要

実装コードとspec文書間の乖離を3カテゴリ（A: spec更新、B: 実装修正、C: ユーザー判断）に分類し、Phase 1〜4で修正を実施。

---

## Phase 1: セキュリティ修正（完了）

### B-01: 清掃者用Webhookに冪等性チェック追加
- **ファイル**: `public_html/api/line/webhook.php`
- **修正内容**: `line_helper.php`のrequireを追加し、イベントループにtry/catch + `isWebhookEventProcessed()`/`markWebhookEventProcessed()`を追加
- **影響**: 二重処理リスクの解消

### B-02: 店舗用Webhookにレート制限追加
- **ファイル**: `public_html/api/line/webhook_store.php`
- **修正内容**: APCu優先・DBフォールバックのレート制限（60リクエスト/分/IP）を追加
- **影響**: DoSリスクの軽減

---

## Phase 2: UX改善（完了）

### B-04 + B-14: 延長確認通知 + 利用者通知
- **ファイル**: `public_html/pages/extend/respond.php`
- **修正内容**: 延長承諾/拒否後にメール通知を送信する処理を追加（try/catch内、トランザクション外）
- **影響**: 延長結果が顧客にメール通知される

### B-05: 採用確定通知（LINE Push）
- **ファイル**: `public_html/pages/jobs/detail.php`, `public_html/pages/apply/index.php`
- **修正内容**: 応募採用確定後にLINE Push通知を清掃者に送信
- **影響**: 採用された清掃者がLINEで即座に通知を受け取れる

### B-13: 急募自動発動cronスクリプト
- **ファイル**: `cron/auto_urgent_recruitment.php`（新規）
- **修正内容**: 清掃予定2時間前で未割当の案件を自動的に急募化し、全清掃者に通知
- **実行間隔**: 10分（cron設定例付き）、flock二重実行防止

---

## Phase 3: spec文書一括更新（完了）

6つのサブエージェントで並列実行し、以下のspecファイルを更新:

### サブエージェント1: schema.md
- A-02: ユーザーステータスENUM追加
- A-26〜A-30: notification_logs/email_logs/audit_logs構造変更、login_attempts変更、新テーブル追加
- A-32: cleaner_payments ステータス pending/paid に更新
- C-07反映

### サブエージェント2: organization_model.md + tech_stack.md
- A-01: auth.php統合設計を反映
- A-31: ディレクトリ構成の大幅拡張
- C-01: 3階層ロールのみ（権限細分化なし）
- C-02: MVP版決済と明記

### サブエージェント3: 予約関連spec
- A-03: LIFF対応の顧客自動予約
- A-04: 4ステップウィザード・店舗コードURL
- A-05: 清掃時間の動的取得（sales_areas.cleaning_duration_minutes）
- C-03: CMS登録でメール・電話任意

### サブエージェント4: 清掃・支払spec
- A-06: 延長報酬=0（追加報酬なし）
- A-07: cleaner_paymentsにstore_id, paid_by追加
- A-08: 案件一覧に応募数・店舗名カラム
- A-09: CSVエクスポート・平均報酬サマリー
- A-10, A-24: タイムアウトベース固定者通知
- A-25: 固定者操作（優先順位変更/有効無効/削除）
- A-34: 通知履歴は現在ステータスのみ

### サブエージェント5: LINE関連spec
- A-11: hash_equals()使用
- A-12: 応募URL有効期限（固定者30分/公募120分）
- A-13: トークン方式の登録フロー
- A-14: 登録ステータス段階管理
- A-15: プレーンテキスト通知
- A-16: webhook_eventsにaccount_type, store_id
- A-17: 応募方式確定（外部Web + 先着 + 即時通知）

### サブエージェント6: 延長・鍵・設計ポリシーspec
- A-18: 延長URL形式を /extend/room/{room_code} に修正
- A-19: 予約番号入力不要（自動特定）
- A-20: 深夜跨ぎ3パターン対応
- A-21: PHP側日時取得（MySQL不一致防止）
- A-22: 鍵選択はID昇順
- A-23: 割当履歴の「操作者」カラム削除
- A-33: response_token認証方式
- C-05: CASCADE DELETE方針（マスタ系禁止/ログ系許容）
- C-06: deleted_atは必要テーブルのみ

---

## Phase 4: カテゴリC決定事項（完了）

### C-07: 支払いステータスENUM変更
- **ファイル**: `database/015_update_payment_status_enum.sql`（新規）
- **修正内容**: 3ステップマイグレーション（unpaid→pending）
  1. ENUMにpending追加
  2. unpaidデータをpendingに更新
  3. ENUMからunpaid削除

---

## スコープ外（未実施）

- B-03: LINE Secret/Token暗号化 → 別フェーズ
- B-06〜B-12: 低優先度の管理効率改善
- B-15〜B-17: 低優先度の通知・DB制約
- 鍵ガント表示 → 不要

---

## 修正ファイル一覧

### 実装修正
| ファイル | 種別 | 関連修正 |
|---------|------|---------|
| `public_html/api/line/webhook.php` | 修正 | B-01 |
| `public_html/api/line/webhook_store.php` | 修正 | B-02 |
| `public_html/pages/extend/respond.php` | 修正 | B-04, B-14 |
| `public_html/pages/jobs/detail.php` | 修正 | B-05 |
| `public_html/pages/apply/index.php` | 修正 | B-05 |
| `cron/auto_urgent_recruitment.php` | 新規 | B-13 |
| `database/015_update_payment_status_enum.sql` | 新規 | C-07 |

### spec更新
| ファイル | 関連修正 |
|---------|---------|
| `spec/db/schema.md` | A-02, A-26〜A-30, A-32 |
| `spec/db/design_policy.md` | C-05, C-06 |
| `spec/overview/organization_model.md` | A-01, C-01 |
| `spec/overview/tech_stack.md` | A-31 |
| `spec/overview/system_overview.md` | C-02 |
| `spec/features/reservation/reservation_flow.md` | A-03, A-04 |
| `spec/features/reservation/reservation_ui.md` | C-03 |
| `spec/features/reservation/reservation_block.md` | A-05 |
| `spec/features/cleaning_job.md` | A-06, A-08, A-34 |
| `spec/features/payment.md` | A-07, A-09, A-32 |
| `spec/features/fixed_cleaner.md` | A-10, A-24, A-25 |
| `spec/features/extension.md` | A-18, A-19, A-20, A-33 |
| `spec/features/extension_room_url.md` | A-21 |
| `spec/features/key_management.md` | A-22, A-23 |
| `spec/features/line/line_overview.md` | A-11, A-12, A-17 |
| `spec/features/line/line_registration.md` | A-13, A-14 |
| `spec/features/line/line_application.md` | A-17 |
| `spec/features/line/cleaner_line_extension.md` | A-15, A-16 |
| `spec/features/line/store_line.md` | Webhook仕様更新 |

---

*Created: 2026-01-29*
