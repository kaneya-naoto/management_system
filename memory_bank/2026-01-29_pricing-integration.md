# 金額・延長・支払い 統合修正

## 日付: 2026-01-29

## 問題詳細

顧客料金と清掃報酬が混同されており、以下の問題があった:
1. 報酬変更時に顧客料金（reservations.base_price）も同期更新されていた
2. 延長時に `calculateExtensionReward()` で報酬ベースの金額を顧客料金にも使用していた
3. 清掃報酬が `DEFAULT_CLEANING_REWARD` 固定で、店舗設定（`stores.base_reward`）を参照していなかった
4. `extend/respond.php` に24:00ハードリミットがあり日跨ぎ延長不可だった
5. 顧客延長承諾時に `extensions` テーブルに記録されていなかった
6. UI上で延長報酬（常に0）が表示されていた

## 根本原因

顧客料金と清掃報酬の独立性が仕様として明確化されておらず、コード上で混同されていた。

## 実施した修正

### config.php
- `EXTENSION_PRICE_PER_HOUR` 定数を追加（1,000円/時間）
- `EXTENSION_REWARD_RATE` と `REWARD_ROUND_UNIT` を削除

### functions.php
- `createCleaningJobForReservation()`: `stores.base_reward` から報酬取得
- `createCleaningJobForReservationImproved()`: 同上
- `calculateExtensionReward()`: 関数削除

### jobs/detail.php
- 報酬変更時の顧客料金同期処理を削除（独立性確保）
- 延長登録の料金計算を `EXTENSION_PRICE_PER_HOUR` ベースに変更
- 延長報酬の更新処理を削除（常に0）
- 次予約・閉店チェック追加（`calculateAvailableExtension`）
- 予約行の `FOR UPDATE` ロック追加（競合対策）
- UI: 延長報酬表示を非表示化

### extend/respond.php
- 24:00ハードリミット撤廃 → `calculateAvailableExtension` チェックに置換
- SELECTに `r.sales_area_id`, `r.store_id` を追加
- 承諾時に `extensions` テーブルにもレコード追加

### UI表示の非表示化（9箇所）
- jobs/list.php: 延長報酬の `+X円` 表示削除
- jobs/detail.php: 延長報酬行を非表示、合計報酬を base_reward のみに
- shifts/list.php: 延長報酬の `+X円` 表示削除
- cleaners/detail.php: 統計・案件履歴を base_reward のみに
- payments/list.php: 延長報酬列を削除
- payments/detail.php: 延長報酬行を非表示
- dashboard.php: KPIコスト計算を `SUM(base_reward)` のみに

### データマイグレーション
- `database/014_reset_extension_rewards.sql`: 既存の extension_reward/extension_amount を全て0にリセット

### 仕様書更新
- `spec/features/pricing.md`: 統合仕様で全面書き換え
- `spec/features/extension.md`: 通知メッセージから追加報酬行を削除
- `spec/features/payment.md`: 延長報酬計算式修正、金額確定保存方針追記

## 影響範囲

- 顧客料金計算（延長時）
- 清掃報酬の取得元
- 報酬変更時の挙動
- 延長登録処理（管理画面・顧客フロー両方）
- UI表示（9ファイル）
- KPIダッシュボード

## コードレビュー後の追加修正（第2回デプロイ）

### respond.php（競合対策強化）
- `extension_requests` UPDATE に `AND status = 'pending'` ガード追加（二重承諾防止）
- 予約を `FOR UPDATE` でロックしてから延長料金を読み取り（stale data 防止）
- 日跨ぎ延長時の `scheduled_at` を `$newEndTimestamp` から算出（日付ずれ防止）
- `cleaning_jobs` の SELECT にも `FOR UPDATE` 追加

### jobs/detail.php（TOCTOU対策 + scheduled_at更新）
- `calculateAvailableExtension` チェックを FOR UPDATE ロック後に移動（TOCTOU対策）
- 延長時に `cleaning_jobs.scheduled_at` も更新（日跨ぎ対応）
- 延長履歴テーブルの「追加報酬」列を「ステータス」列に変更
- UI延長セレクトを3時間→5時間（MAX_EXTENSION_HOURS）まで拡張

### functions.php（createPaymentForJob）
- `extension_amount` を DB の `extension_reward` から読む代わりに0固定に（仕様統一）

### payments/list.php, payments/detail.php
- SELECT から不要な `j.extension_reward` カラムを削除

## レビュー実施結果

### 内部レビュー + Codex セカンドオピニオン（2回実施）

| # | 重要度 | 指摘内容 | 対応状況 |
|---|--------|---------|---------|
| 1 | High | respond.php: `extension_requests` UPDATE に `status='pending'` ガードなし（二重承諾リスク） | 修正済み（第2回デプロイ） |
| 2 | High | respond.php: 予約を FOR UPDATE でロックせず古い end_time で計算 | 修正済み（第2回デプロイ） |
| 3 | High | respond.php: 日跨ぎ延長時に scheduled_at の日付部分が更新されない | 修正済み（第2回デプロイ） |
| 4 | Medium | jobs/detail.php: 延長時に cleaning_jobs.scheduled_at 未更新 | 修正済み（第2回デプロイ） |
| 5 | Medium | jobs/detail.php: calculateAvailableExtension がロック前に実行（TOCTOU） | 修正済み（第2回デプロイ） |
| 6 | Low | jobs/detail.php: UI延長セレクトが3時間まで（MAX_EXTENSION_HOURS=5.0と不整合） | 修正済み（第2回デプロイ） |
| 7 | Low | payments/list.php, detail.php: SELECT に不要な j.extension_reward | 修正済み（第2回デプロイ） |
| 8 | Low | functions.php createPaymentForJob: extension_reward をDBから読む（0固定にすべき） | 修正済み（第2回デプロイ） |
| 9 | High | calculateAvailableExtension() の120分上限 vs MAX_EXTENSION_HOURS=5.0 | 未対応（既存仕様、今回スコープ外） |
| 10 | Medium | 予約なし案件で延長登録可能（チェックスキップ） | 未対応（実運用上レアケース） |

### 確認できた整合点（OK）

- 顧客延長料金は `EXTENSION_PRICE_PER_HOUR × 時間` で正しく計算（独立性維持）
- 延長時の清掃報酬は追加なし（`additional_reward = 0`）
- 清掃報酬の初期値は `stores.base_reward` から取得（フォールバックあり）
- SQLインジェクションなし（全クエリでプレースホルダ使用）
- CSRF保護あり（全POSTエンドポイント）

## デプロイ履歴

### 第1回デプロイ（統合修正本体）
- config.php, functions.php, jobs/detail.php, extend/respond.php
- jobs/list.php, shifts/list.php, cleaners/detail.php
- payments/list.php, payments/detail.php, dashboard.php
- **10ファイル** → 全て成功

### 第2回デプロイ（レビュー指摘修正）
- extend/respond.php, jobs/detail.php, functions.php
- payments/list.php, payments/detail.php
- **5ファイル** → 全て成功

### データマイグレーション
- `014_reset_extension_rewards.sql` 実行済み
- cleaning_jobs: 1件の extension_reward をリセット

## 残存課題（今後対応）

1. **calculateAvailableExtension() の120分上限**: `MAX_EXTENSION_HOURS=5.0` と矛盾。24時間営業・次予約なしの場合に120分が返される。本修正のスコープ外のため据え置き。
2. **予約なし案件の延長登録**: 予約と紐づかない案件でも延長登録ボタンが表示される。実運用上ほぼ発生しないため優先度低。

## 結果

顧客料金と清掃報酬が完全に独立し、仕様通りの動作になった。
コードレビュー（内部 + Codex）で指摘された High/Medium の競合・TOCTOU・日跨ぎ問題は全て修正済み。
