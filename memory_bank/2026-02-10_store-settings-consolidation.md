# 店舗設定の統合・拡充

**日付**: 2026-02-10
**コミット**: e6480bf (develop)

## 概要

config.phpにハードコードされていた定数（延長料金・最大延長時間・清掃時間）を店舗ごとにDB設定可能にし、
DBカラムはあるが管理画面がなかった設定項目（時間単価・最小/最大利用時間・予約受付日数）のUIを追加した。

## 問題

1. **DBカラムはあるが管理画面なし**: `default_hourly_rate`, `max_duration_hours`, `min_duration_hours`, `booking_days_ahead`, `cleaning_duration_minutes`
2. **config.phpにハードコード**: `EXTENSION_PRICE_PER_HOUR`(1000), `MAX_EXTENSION_HOURS`(5.0), `CLEANING_TIME_MINUTES`(60) — 店舗ごとに変更不可
3. **設定ページが分散**: 店舗基本情報しかなく、予約・清掃関連の設定UIがなかった

## 実施した修正

### DBマイグレーション (`database/017_add_store_settings.sql`)
- `stores` テーブルに3カラム追加:
  - `extension_price_per_hour` INT NOT NULL DEFAULT 1000
  - `max_extension_hours` DECIMAL(3,1) NOT NULL DEFAULT 5.0
  - `cleaning_time_minutes` INT NOT NULL DEFAULT 60
- `sales_areas.cleaning_duration_minutes` を nullable化 (NULL = 店舗デフォルト使用)

### ヘルパー関数 (`includes/helpers.php`)
- `getStoreSettings(int $storeId): array` 追加
- 8項目を返却、static $cache でリクエスト内キャッシュ
- config.php定数をフォールバック値として使用

### 3段階フォールバック
```
清掃時間: 営業区分(nullable) > 店舗設定 > config.php定数
延長時間: 店舗設定 > config.php定数
報酬:     店舗設定 > config.php定数
```

### 店舗設定ページ (`pages/settings/store.php`)
- include分割: 7テンプレートファイルに分離（`store/_*.php`）
- 新規カード追加: 「予約設定」「清掃・延長設定」
- POST処理に7項目のバリデーション・保存を追加
- 単一`<form>`で全カードを包む設計（部分送信によるデフォルト値上書き防止）

### 定数参照箇所の書き換え (12ファイル)

| ファイル | 変更内容 |
|---------|---------|
| `includes/config.php` | コメントに「店舗設定が優先。フォールバック用」追記 |
| `pages/booking/index.php` | JS内ハードコード → 店舗設定連動 + タイムゾーン修正 |
| `pages/booking/customer.php` | 利用時間バリデーション → getStoreSettings() |
| `pages/booking/confirm.php` | MIN/MAX定数 → getStoreSettings() |
| `api/booking/check-availability.php` | 利用時間バリデーション → getStoreSettings() |
| `pages/jobs/detail.php` | 延長定数 → getStoreSettings() |
| `pages/extend/index.php` | 延長選択肢 → max_extension_hours連動 |
| `pages/extend/respond.php` | 延長料金 → getStoreSettings() |
| `includes/reservation_helpers.php` | getCleaningDuration() 3段階化、延長選択肢動的生成 |
| `includes/job_helpers.php` | createCleaningJobForReservation() → getStoreSettings() |

## Codexレビューで修正した問題

1. **マイグレーションの既存データ処理**: `UPDATE SET NULL WHERE = 60` を削除（意図して60分に設定した区分を壊す恐れ）
2. **タイムゾーン問題**: `toISOString()` → ローカル日付フォーマットに修正（UTC変換で日付がずれる問題）
3. **ステップ検証追加**: max_extension_hours (0.5h刻み)、cleaning_time_minutes (15分刻み) のサーバーサイドバリデーション

## チーム構成

| エージェント | 担当 |
|-------------|------|
| agent-db-settings | DB + ヘルパー + store.php拡充 |
| agent-booking | 予約フロー全般 (booking/*, check-availability) |
| agent-extension | 延長・清掃関連 (extend/*, jobs/detail, reservation_helpers, job_helpers) |

ファイル競合は完全分離で防止。

## 影響範囲

- 店舗設定ページ (`/settings/store`)
- 公開予約フォーム (`/booking`)
- 顧客延長申請 (`/extend`)
- 管理画面延長登録 (`/jobs/{id}`)
- 清掃案件生成 (createCleaningJobForReservation)
- 清掃時間取得 (getCleaningDuration)

## ファイル一覧

### 新規 (8ファイル)
- `database/017_add_store_settings.sql`
- `pages/settings/store/_basic_info.php`
- `pages/settings/store/_business_hours.php`
- `pages/settings/store/_booking_settings.php`
- `pages/settings/store/_cleaning_settings.php`
- `pages/settings/store/_sales_areas.php`
- `pages/settings/store/_modals.php`
- `pages/settings/store/_scripts.php`

### 変更 (12ファイル)
- `pages/settings/store.php`
- `includes/helpers.php`
- `includes/config.php`
- `pages/booking/index.php`
- `pages/booking/customer.php`
- `pages/booking/confirm.php`
- `api/booking/check-availability.php`
- `pages/jobs/detail.php`
- `pages/extend/index.php`
- `pages/extend/respond.php`
- `includes/reservation_helpers.php`
- `includes/job_helpers.php`
