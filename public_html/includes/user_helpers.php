<?php
/**
 * ユーザー関連ヘルパー関数
 *
 * iPassコード生成、パスワードリセットトークン管理、
 * パスワードリセット処理などのユーザードメイン関数群
 */

/**
 * iPassコードを生成
 * @return string 6桁の英数字コード
 * @throws RuntimeException 100回試行しても重複が解消されない場合
 */
function generateIPassCode(): string
{
    $maxAttempts = 100;

    for ($i = 0; $i < $maxAttempts; $i++) {
        $code = strtoupper(bin2hex(random_bytes(3)));

        // 重複チェック
        $existing = dbSelectOne("SELECT id FROM cleaners WHERE ipass_code = ?", [$code]);
        if (!$existing) {
            return $code;
        }
    }

    // 到達した場合は異常事態
    throw new RuntimeException("Failed to generate unique iPass code after {$maxAttempts} attempts");
}

/**
 * パスワードリセットトークンを生成
 * @param string $email ユーザーのメールアドレス
 * @return string|null 生成されたトークン、ユーザーが見つからない場合はnull
 */
function generatePasswordResetToken(string $email): ?string
{
    // ユーザー検証
    $user = dbSelectOne(
        "SELECT id, email, name FROM users WHERE email = ? AND status = 'active' AND deleted_at IS NULL",
        [$email]
    );

    if (!$user) {
        return null;
    }

    // 既存の未使用トークンを無効化（1ユーザー1トークンポリシー）
    dbExecute(
        "UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL",
        [$user['id']]
    );

    // 新しいトークンを生成（平文はユーザーに返す）
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // ハッシュ化してDBに保存（セキュリティ強化）
    $tokenHash = hash('sha256', $token);

    dbInsert('password_reset_tokens', [
        'user_id' => $user['id'],
        'token' => $tokenHash,
        'expires_at' => $expiresAt,
    ]);

    return $token;
}

/**
 * パスワードリセットトークンを検証
 * @param string $token トークン（平文）
 * @return array|null ユーザー情報（id, email, name）、無効な場合はnull
 */
function verifyPasswordResetToken(string $token): ?array
{
    // トークン長の検証（64文字 = 32バイトのhex）
    if (strlen($token) !== 64) {
        return null;
    }

    // 入力トークンをハッシュ化して検索
    $tokenHash = hash('sha256', $token);

    $tokenData = dbSelectOne(
        "SELECT prt.*, u.id as user_id, u.email, u.name
         FROM password_reset_tokens prt
         INNER JOIN users u ON prt.user_id = u.id
         WHERE prt.token = ?
           AND prt.used_at IS NULL
           AND prt.expires_at > NOW()
           AND u.status = 'active'
           AND u.deleted_at IS NULL",
        [$tokenHash]
    );

    if (!$tokenData) {
        return null;
    }

    return [
        'id' => $tokenData['user_id'],
        'email' => $tokenData['email'],
        'name' => $tokenData['name'],
        'token_id' => $tokenData['id'],
    ];
}

/**
 * パスワードをリセット
 * @param string $token リセットトークン
 * @param string $newPassword 新しいパスワード
 * @return bool 成功したらtrue
 */
function resetPassword(string $token, string $newPassword): bool
{
    $tokenData = verifyPasswordResetToken($token);

    if (!$tokenData) {
        return false;
    }

    dbBegin();
    try {
        // パスワード更新
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        dbUpdate('users', [
            'password' => $hashedPassword,
            'password_changed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$tokenData['id']]);

        // トークンを使用済みに
        dbUpdate('password_reset_tokens', [
            'used_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$tokenData['token_id']]);

        dbCommit();
        return true;

    } catch (Exception $e) {
        dbRollback();
        error_log("Password reset failed: " . $e->getMessage());
        return false;
    }
}
