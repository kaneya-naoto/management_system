<?php
/**
 * パスワードリセット画面
 */

// 既にログイン済みならダッシュボードへ
if (isLoggedIn()) {
    redirect('/dashboard');
}

$token = input('token', '');
$success = false;
$errors = [];
$tokenValid = false;
$user = null;

// トークン検証
if (!empty($token)) {
    $user = verifyPasswordResetToken($token);
    if ($user) {
        $tokenValid = true;
    }
}

// パスワードリセット処理
if (isPost() && $tokenValid) {
    requireCsrf();

    $password = input('password', '');
    $passwordConfirm = input('password_confirm', '');

    // バリデーション
    if (empty($password)) {
        $errors[] = '新しいパスワードを入力してください';
    } elseif (mb_strlen($password) < 8) {
        $errors[] = 'パスワードは8文字以上で入力してください';
    } elseif (mb_strlen($password) > 72) {
        $errors[] = 'パスワードは72文字以内で入力してください';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'パスワードが一致しません';
    }

    if (empty($errors)) {
        if (resetPassword($token, $password)) {
            $success = true;
        } else {
            $errors[] = 'パスワードのリセットに失敗しました。もう一度お試しください。';
        }
    }
}

$pageTitle = 'パスワード再設定';
$basePath = defined('BASE_PATH') ? BASE_PATH : '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - <?= h(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= $basePath ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="guest-wrapper">
    <div class="login-container">
        <div class="login-logo">
            <i class="bi bi-building"></i>
            <span><?= h(APP_NAME) ?></span>
        </div>

        <div class="login-card">
            <?php if ($success): ?>
            <div class="alert alert-success mb-4">
                <i class="bi bi-check-circle me-2"></i>
                パスワードを再設定しました。<br>
                新しいパスワードでログインしてください。
            </div>
            <div class="text-center">
                <a href="<?= $basePath ?>/login" class="btn btn-primary">
                    ログインページへ
                </a>
            </div>

            <?php elseif (!$tokenValid): ?>
            <div class="alert alert-danger mb-4">
                <i class="bi bi-exclamation-triangle me-2"></i>
                このリンクは無効または期限切れです。<br>
                パスワードリセットを再度申請してください。
            </div>
            <div class="text-center">
                <a href="<?= $basePath ?>/forgot-password" class="btn btn-primary">
                    パスワードリセット申請へ
                </a>
            </div>

            <?php else: ?>
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger mb-4">
                    <?php foreach ($errors as $error): ?>
                    <p class="mb-0"><?= h($error) ?></p>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <p class="text-muted mb-4">
                    <?= h($user['name']) ?>さん、新しいパスワードを入力してください。
                </p>

                <form method="post">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCsrfToken() ?>">
                    <input type="hidden" name="token" value="<?= h($token) ?>">

                    <div class="form-group">
                        <label for="password" class="form-label">新しいパスワード</label>
                        <div class="input-icon-wrapper">
                            <i class="bi bi-lock"></i>
                            <input type="password" class="form-control" id="password" name="password"
                                   placeholder="8文字以上" required autofocus>
                        </div>
                        <small class="text-muted">8文字以上で入力してください</small>
                    </div>

                    <div class="form-group">
                        <label for="password_confirm" class="form-label">パスワード（確認）</label>
                        <div class="input-icon-wrapper">
                            <i class="bi bi-lock-fill"></i>
                            <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                                   placeholder="もう一度入力" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-login">
                        <i class="bi bi-check-lg"></i>
                        パスワードを再設定
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <p class="login-footer">
            &copy; <?= date('Y') ?> <?= h(APP_NAME) ?>
        </p>
    </div>
</div>

<style>
.login-container {
    width: 100%;
    max-width: 380px;
    padding: var(--space-4);
}

.login-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--color-text);
    margin-bottom: var(--space-6);
}

.login-logo i {
    font-size: 1.75rem;
    color: var(--color-primary);
}

.login-card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-6);
}

.login-card .form-group {
    margin-bottom: var(--space-4);
}

.login-card .form-label {
    display: block;
    font-size: var(--text-sm);
    font-weight: 500;
    color: var(--color-text);
    margin-bottom: var(--space-2);
}

.input-icon-wrapper {
    position: relative;
}

.input-icon-wrapper i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--color-text-muted);
    font-size: 1rem;
}

.input-icon-wrapper .form-control {
    padding-left: 40px;
}

.btn-login {
    width: 100%;
    padding: var(--space-3);
    font-weight: 500;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
}

.login-footer {
    text-align: center;
    font-size: var(--text-xs);
    color: var(--color-text-muted);
    margin-top: var(--space-6);
}
</style>
</body>
</html>
