# Codex レビュー結果

## 概要

OpenAI Codex による仕様・設計レビュー（2026-01-23実施）

---

## レビュー対象

- DB設計（テーブル定義、制約）
- 開発スケジュール
- セキュリティ設計
- 認可モデル

---

## 指摘事項

### 重要度: 高（対応済み）

| 指摘事項 | 詳細 | 対応状況 |
|----------|------|----------|
| user_stores テーブルのPK設計が無効 | COALESCE使用のPKは機能しない | ✅ AUTO_INCREMENT PKに変更 |
| ソフトデリート用ユニーク制約が未実装 | 論理削除時にemail重複が発生 | ✅ active_flag 生成列追加 |
| 12日間スケジュールは現実的でない | 実際には2〜3倍必要 | ⚠️ 要検討 |

### 重要度: 中（対応済み）

| 指摘事項 | 詳細 | 対応状況 |
|----------|------|----------|
| cleaner_payments の重複防止 | 1案件に複数支払いが可能 | ✅ UNIQUE KEY追加 |
| key_assignments の期間チェック | 開始 < 終了の検証なし | ✅ CHECK制約追加 |
| 複数テーブルで deleted_at 欠落 | 一部テーブルで論理削除対応漏れ | ✅ 設計方針で対応済み確認 |

---

## 実装時考慮事項

### 3階層認可

```
role × scope の組み合わせで判定
```

| role | scope | 判定 |
|------|-------|------|
| HQ | 全て | アクセス可 |
| OWNER | owner_id一致 | アクセス可 |
| STORE | store_id一致 | アクセス可 |

### LINE連携セキュリティ

| 項目 | 推奨対策 |
|------|----------|
| 署名検証 | X-Line-Signatureヘッダーの検証必須 |
| 冪等性 | webhookEventIdで重複処理防止 |
| トークン | 応募URLにワンタイムトークン使用 |

### 鍵割当の競合防止

```sql
-- SELECT FOR UPDATE + トランザクション
START TRANSACTION;
SELECT * FROM keys WHERE ... FOR UPDATE;
INSERT INTO key_assignments (...);
COMMIT;
```

---

## 未決定事項（追加）

レビューにより新たに特定された未決定事項：

| 項目 | 選択肢 |
|------|--------|
| OWNERの複数店舗切り替えUI | ドロップダウン / タブ / 別画面 |
| LINE連携の実装方式 | LIFF vs 外部Web |
| 応募競合時の解決方法 | 先着 vs 抽選 |

---

## スケジュールに関する指摘

### 原文

> 12日間のスケジュールは現実的ではない可能性があります。
> 特にLINE連携、決済Webhook、認可システムは実装・テストに時間がかかります。
> 実際には2〜3倍の期間を見込むことを推奨します。

### 対応方針

1. 優先度の高い機能から段階的にリリース
2. LINE連携は十分なテスト期間を確保
3. バッファを含めた現実的なスケジュールを再検討

---

## 改善後のDB設計

### user_stores テーブル

```sql
-- Before（問題あり）
PRIMARY KEY (COALESCE(owner_id, 0), store_id)

-- After（修正済み）
id BIGINT NOT NULL AUTO_INCREMENT,
PRIMARY KEY (id),
UNIQUE KEY uk_user_store (user_id, store_id)
```

### users テーブル（ソフトデリート対応）

```sql
-- active_flag 生成列を追加
ALTER TABLE users ADD COLUMN active_flag TINYINT
  GENERATED ALWAYS AS (IF(deleted_at IS NULL, 1, NULL)) STORED;

-- ユニーク制約（削除済みは除外）
CREATE UNIQUE INDEX uk_users_email_active ON users(email, active_flag);
```

### cleaner_payments テーブル

```sql
-- 重複防止の制約追加
UNIQUE KEY uk_payment_job_cleaner (job_id, cleaner_id)
```

---

## 関連ドキュメント

- [DB設計方針](../db/design_policy.md)
- [開発フェーズ](../schedule/development_phases.md)
- [LINE連携概要](../features/line/line_overview.md)

---

*Last Updated: 2026-01-26*
