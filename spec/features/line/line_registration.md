# LINE 初回登録・iPassフロー

## 概要

清掃者がLINE公式アカウントに友達追加した後の初回登録フロー

---

## 初回登録フロー

### トークン方式

登録フローはセキュリティのためトークン方式を採用。LINE UserIDを直接URLパラメータに渡さず、
ワンタイムトークンを使用して登録画面にアクセスする。

### 登録ステータス管理

| ステータス | 状態 | 遷移条件 |
|-----------|------|----------|
| `pending` | 仮登録（LINE友達追加直後） | Follow Event受信時に自動作成 |
| `profile_done` | プロフィール入力完了 | 名前入力完了後 |
| `completed` | 登録完了 | 店舗選択完了後 |

### フロー詳細

```
[清掃者] LINE友達追加
    ↓
[システム] Follow Event受信
    ↓
[システム] cleanersテーブルに仮登録（registration_status='pending'）
[システム] 登録トークン発行（64文字、有効期限1時間）
    ↓
[システム] 「初めまして」メッセージ + 登録ボタン送信
           URL: /register?token={token}
    ↓
[清掃者] 登録ボタンをタップ → 外部Web画面へ
    ↓
[清掃者] プロフィール入力（名前・電話番号）・送信
    ↓
[システム] cleanersテーブル更新（registration_status='profile_done'）
    ↓
[システム] 自動リダイレクト → 店舗選択画面
           URL: /register/stores?token={token}
    ↓
[清掃者] 対応可能店舗を選択（チェックボックス、複数選択可）
    ↓
[システム] cleaner_storesに登録
[システム] iPass発行（6桁英数字）
[システム] registration_status='completed', is_active=1
[システム] 登録トークン無効化（NULLに設定）
    ↓
[システム] iPassコード + 登録完了メッセージをLINE Push送信
[システム] 完了画面を表示
```

---

## プロフィール登録

### 入力項目

| 項目 | 必須 | 説明 |
|------|------|------|
| 氏名 | ○ | フルネーム |
| 電話番号 | - | 緊急連絡先 |

### 画面仕様

- 外部Webページ（`/register?token={token}`）
- トークン方式で認証（LINE UserIDをURLパラメータに含めない）
- トークンは64文字のランダム文字列、有効期限1時間
- 入力完了後、店舗選択画面に自動リダイレクト

### 画面遷移ステップ表示

3ステップの進捗表示を実装:
1. **プロフィール**（`/register?token={token}`）
2. **店舗選択**（`/register/stores?token={token}`）
3. **完了**

### 登録処理

```php
// Webhook受信時: cleanersテーブルに仮登録
dbInsert('cleaners', [
    'line_user_id' => $userId,
    'name' => '',
    'is_active' => 0,
    'registration_status' => 'pending',
    'registration_token' => bin2hex(random_bytes(32)),      // 64文字
    'registration_token_expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
]);

// プロフィール入力完了時: ステータス更新
dbUpdate('cleaners', [
    'name' => $name,
    'phone' => $phone ?: null,
    'registration_status' => 'profile_done',
], 'id = ?', [$cleaner['id']]);
```

---

## 店舗選択

### 画面仕様

- URL: `/register/stores?token={token}`
- 同一トークンで認証（プロフィール画面と共通）
- `registration_status='pending'`の場合はプロフィール画面にリダイレクト
- 登録可能な店舗一覧をチェックボックスで表示
- 複数選択可能（1店舗以上必須）
- CSRF保護あり
- 選択完了で完了処理実行

### 登録処理

```php
dbBegin();
try {
    // 既存の店舗関連を削除して再登録
    dbExecute("DELETE FROM cleaner_stores WHERE cleaner_id = ?", [$cleaner['id']]);
    foreach ($storeIds as $storeId) {
        dbInsert('cleaner_stores', [
            'cleaner_id' => $cleaner['id'],
            'store_id' => $storeId,
        ]);
    }

    // iPassコード生成・登録完了
    $ipassCode = generateIPassCode();
    dbUpdate('cleaners', [
        'is_active' => 1,
        'registration_status' => 'completed',
        'registration_token' => null,
        'registration_token_expires_at' => null,
        'ipass_code' => $ipassCode,
        'registered_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', [$cleaner['id']]);

    dbCommit();

    // LINE Push送信（完了メッセージ + iPassコード）
    sendLineNotification($cleaner['line_user_id'], $message);
} catch (Exception $e) {
    dbRollback();
}
```

---

## iPass発行

### 仕様

| 項目 | 内容 |
|------|------|
| 形式 | 6桁の英数字 |
| 有効期限 | なし（永続） |
| 用途 | LINE以外からのログイン |

### 発行処理

```php
function generateIPass(): string {
    return strtoupper(bin2hex(random_bytes(3)));  // 例: A1B2C3
}
```

### 送信メッセージ

```
登録が完了しました！

あなたのiPass: {ipass}

このコードは大切に保管してください。
LINE以外からログインする際に使用します。
```

---

## 2回目以降のフロー

### 自動識別（Webhook経由）

```
[清掃者] LINEでメッセージ送信
    ↓
[システム] LINE UserIDでcleanersテーブルを検索
    ↓
┌─ 登録済み（completed）──→ [通常応答（ヘルプ、案件確認等）]
│
├─ 登録途中（pending/profile_done）──→ [登録トークン再発行 → 登録URL送信]
│
└─ 未登録 ──→ [仮登録 → 登録トークン発行 → 登録URL送信]
```

### メッセージコマンド（登録済み清掃者向け）

| キーワード | 処理 |
|-----------|------|
| `ipass` / `コード` | iPass確認用ワンタイムURL発行（5分間有効） |
| `完了` 等 | 清掃完了報告 |
| `ヘルプ` / `help` / `?` | 使い方案内 |
| その他 | デフォルト応答 |

### iPassログイン

LINE UserIDで識別できない場合（ブラウザからのアクセスなど）：

```
[清掃者] iPass入力
    ↓
[システム] iPassで検索
    ↓
[システム] セッション発行
    ↓
[清掃者] 応募画面へ
```

### iPass確認（セキュア版）

LINE上で「ipass」と送信した場合、コードを直接返さずワンタイムURL経由で表示:

```
[清掃者] 「ipass」とメッセージ送信
    ↓
[システム] ワンタイムトークン生成（5分間有効、ipass_view_tokensテーブル）
    ↓
[システム] 「コードを表示」ボタン付きメッセージ送信
           URL: /ipass/view?token={token}
    ↓
[清掃者] ブラウザでiPassコードを確認
```

---

## エラーハンドリング

### 無効なトークン

```
このURLは無効です。LINEから再度アクセスしてください。
```

### トークン期限切れ

```
このURLは無効または期限切れです。LINEから再度登録をお願いします。
```

（LINEでメッセージ送信すると、新しいトークン付き登録URLが再発行される）

### 登録失敗時（トランザクションエラー）

```
登録処理中にエラーが発生しました。もう一度お試しください。
```

### 重複登録防止

```php
// LINE UserIDで既存チェック（registration_statusで状態判断）
$cleaner = dbSelectOne(
    "SELECT id, name, registration_status FROM cleaners WHERE line_user_id = ? AND deleted_at IS NULL",
    [$userId]
);

if ($cleaner && $cleaner['registration_status'] === 'completed') {
    // 既に登録済み → 「おかえりなさい」メッセージ
}
// 途中の場合 → トークン再発行して登録再開
```

---

## 関連ドキュメント

- [LINE概要](line_overview.md)
- [LINE応募](line_application.md)
- [cleanersテーブル](../../db/tables/cleaners.md)

---

*Last Updated: 2026-01-29*
