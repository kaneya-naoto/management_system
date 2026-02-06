# 金額・延長・支払い 統合仕様

## 1. 基本原則

- 顧客料金（Customer Price）と清掃報酬（Cleaner Reward）は **完全に独立**
- 一方を変更しても他方に影響しない
- それぞれ別のマスタデータから導出される

---

## 2. 顧客料金体系

| 項目 | カラム | 決定元 | 計算方法 |
|------|--------|--------|---------|
| 基本料金 | `reservations.base_price` | `sales_areas.hourly_rate` | 時間単価 × 利用時間 |
| 延長料金 | `reservations.extension_price` | `EXTENSION_PRICE_PER_HOUR`（定数） | 延長単価 × 延長時間 |
| 合計料金 | `reservations.total_price` | 計算値 | 基本料金 + 延長料金 |

---

## 3. 清掃報酬体系

| 項目 | カラム | 決定元 | 計算方法 |
|------|--------|--------|---------|
| 基本報酬 | `cleaning_jobs.base_reward` | `stores.base_reward` | 店舗ごとの固定額 |
| 延長報酬 | `cleaning_jobs.extension_reward` | なし | **常に0**（延長しても報酬不変） |

---

## 4. 定数一覧

| 定数名 | 値 | 説明 | 定義場所 |
|--------|-----|------|----------|
| `DEFAULT_HOURLY_RATE` | 2,500 | 営業区分に時間単価が未設定時のフォールバック | config.php |
| `EXTENSION_PRICE_PER_HOUR` | 1,000 | 延長1時間あたりの顧客料金 | config.php |
| `DEFAULT_CLEANING_REWARD` | 2,000 | 店舗にbase_rewardが未設定時のフォールバック | config.php |
| `MAX_EXTENSION_HOURS` | 5.0 | 最大延長時間 | config.php |

---

## 5. 処理フロー

### 予約作成時

```
顧客料金: base_price = sales_areas.hourly_rate × 利用時間
清掃報酬: base_reward = stores.base_reward（店舗マスタから取得）
```

### 延長発生時（顧客申請 → 清掃者承諾）

```
顧客料金: extension_price += EXTENSION_PRICE_PER_HOUR × 延長時間(時間)
          total_price = base_price + extension_price
清掃報酬: 変更なし
```

### 延長発生時（管理画面から登録）

```
顧客料金: extension_price += EXTENSION_PRICE_PER_HOUR × 延長時間(時間)
          total_price = base_price + extension_price
清掃報酬: 変更なし（extension_rewardは更新しない）
```

### 報酬変更時（管理画面）

```
cleaning_jobs.base_reward を更新
顧客料金は一切変更しない（独立）
```

### 案件完了時

```
cleaner_payments.base_amount = cleaning_jobs.base_reward
cleaner_payments.extension_amount = cleaning_jobs.extension_reward（通常0）
cleaner_payments.total_amount = base_amount + extension_amount
```

---

## 6. 延長の制約事項

| 項目 | 仕様 |
|------|------|
| 時間単位 | 0.5時間（30分）刻み |
| 最大延長 | 5時間（`MAX_EXTENSION_HOURS`） |
| 日跨ぎ（24:00超え） | 次予約・閉店がなければ許可 |
| 延長可能な案件 | 担当者確定済み or 完了済み |
| 延長可能時間の算出 | 次予約/閉店時間から逆算（`calculateAvailableExtension()`） |

---

## 7. 支払いフロー

```
案件完了 → createPaymentForJob() → cleaner_payments レコード生成
  ├ base_amount = cleaning_jobs.base_reward
  ├ extension_amount = cleaning_jobs.extension_reward（通常0）
  └ total_amount = base_amount + extension_amount

支払い一覧 → 管理者確認 → 個別/一括支払い処理 → status: pending → paid
```

支払いレコードの重複防止:
- `FOR UPDATE` ロックで競合防止
- `UNIQUE KEY uk_payment_job_cleaner (job_id, cleaner_id)` で1案件1清掃者

---

## 8. 仕様決定事項

| 項目 | 決定内容 |
|------|---------|
| 顧客料金と清掃報酬の関係 | 完全に独立 |
| 延長時の清掃報酬 | 変更なし（常に0） |
| 顧客延長料金の計算式 | `EXTENSION_PRICE_PER_HOUR × 延長時間` |
| 清掃報酬の取得元 | `stores.base_reward`（フォールバック: `DEFAULT_CLEANING_REWARD`） |
| 延長記録 | `extensions` テーブルに統一的に記録（顧客延長承諾時も） |
| 支払い金額の変更 | 作成済みの支払いレコードは触らない（金額確定保存） |
| 延長報酬のUI表示 | 非表示（extension_reward/extension_amountの表示削除） |
| 既存データ | extension_reward/extension_amountを全て0にリセット |
| 日跨ぎ延長 | 次予約・閉店がなければ24:00超えも許可 |

---

## 関連ドキュメント

- [支払い管理](payment.md) - 清掃者への支払い処理
- [延長対応](extension.md) - 延長申請・承諾フロー
- [予約フロー](reservation/reservation_flow.md) - 予約作成処理

---

*作成日: 2026-01-28*
*更新日: 2026-01-29（統合仕様として全面書き換え）*
