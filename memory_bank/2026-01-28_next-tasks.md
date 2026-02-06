# 次にやること（TODO）

**作成日**: 2026-01-28
**関連**: 予約フロー改善実装

---

## 優先度: 高

### 1. cron設定（定点通知バッチ）
サーバーのcrontabに定点通知バッチを登録する。

```bash
# SSH接続
ssh kakurema

# crontab編集
crontab -e

# 以下を追加（毎日9:00に実行）
0 9 * * * php ~/xs151334.xsrv.jp/public_html/kakurema/cron/send_daily_notifications.php >> ~/xs151334.xsrv.jp/logs/daily_notifications.log 2>&1
```

**確認事項**:
- ログディレクトリ `~/xs151334.xsrv.jp/logs/` の作成
- 実行権限の確認

---

### 2. 実際の予約テスト（当日通知）
当日の予約を作成して、清掃者にLINE通知が届くか確認。

**手順**:
1. 管理画面で本日の予約を作成
2. 該当店舗の清掃者のLINEに通知が届くか確認
3. `notification_logs` テーブルで送信記録を確認

**確認SQL**:
```sql
SELECT * FROM notification_logs
WHERE sent_at > NOW() - INTERVAL 1 HOUR
ORDER BY sent_at DESC;
```

---

### 3. LINE完了報告テスト
清掃者LINEから完了報告して、案件ステータスが更新されるか確認。

**手順**:
1. 清掃者に案件を割り当て（`assigned_cleaner_id` 設定）
2. 清掃者LINEで「完了」と送信
3. `cleaning_jobs.status` が `completed` になるか確認
4. `cleaning_jobs.completed_at` が設定されるか確認

**確認SQL**:
```sql
SELECT id, status, completed_at, assigned_cleaner_id
FROM cleaning_jobs
WHERE assigned_cleaner_id IS NOT NULL
ORDER BY updated_at DESC LIMIT 5;
```

---

## 優先度: 中

### 4. 定点通知バッチの手動テスト
cronで自動実行される前に、手動で一度実行してみる。

```bash
ssh kakurema
php ~/xs151334.xsrv.jp/public_html/kakurema/cron/send_daily_notifications.php
```

**確認事項**:
- エラーなく実行完了するか
- 通知対象者に正しくLINE通知が送信されるか
- `daily_notification_logs` に記録が残るか

---

### 5. 予約ブロック動作確認
清掃時間考慮の予約ブロックが正しく動作するか確認。

**テストケース**:
| ケース | 既存予約 | 新規予約リクエスト | 期待結果 |
|--------|----------|-------------------|----------|
| 正常 | 10:00-13:00 | 15:00-17:00 | ✅ 予約可能 |
| ブロック | 10:00-13:00 | 13:30-15:00 | ❌ 予約不可（清掃中） |
| 境界 | 10:00-13:00 | 14:00-16:00 | ✅ 予約可能（清掃完了） |
| 深夜跨ぎ | 22:00-01:00 | 01:30-03:00 | ❌ 予約不可（清掃中） |

---

### 6. 清掃完了後の予約可能テスト
清掃完了報告後、ブロックが解除されて次の予約が入るか確認。

**手順**:
1. 13:00終了の予約を作成
2. 13:30に新規予約を試みる → 不可（清掃中）
3. 清掃者が完了報告
4. 13:30に新規予約を試みる → 可能（ブロック解除）

---

## 優先度: 低

### 7. エラー監視設定
本番運用に向けて、エラーログの監視を設定。

**監視対象**:
- `/var/log/daily_notifications.log`
- サーバーエラーログ

---

### 8. パフォーマンス確認
高負荷時の動作確認。

**確認事項**:
- 空き状況APIのレスポンス時間
- レート制限が正しく機能するか（30リクエスト/分）

---

### 9. ドキュメント整備
運用マニュアルの作成。

**内容**:
- 清掃者向け案件一覧の使い方
- LINE完了報告の手順
- トラブルシューティング

---

## 完了チェックリスト

- [ ] cron設定完了
- [ ] 当日予約通知テスト完了
- [ ] LINE完了報告テスト完了
- [ ] 定点通知バッチ手動テスト完了
- [ ] 予約ブロック動作確認完了
- [ ] 清掃完了後の予約可能テスト完了

---

*Created: 2026-01-28*
