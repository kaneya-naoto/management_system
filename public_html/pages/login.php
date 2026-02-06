<?php
/**
 * ログイン画面
 */

// 既にログイン済みならダッシュボードへ
if (isLoggedIn()) {
    redirect('/dashboard');
}

// ログイン処理
if (isPost()) {
    requireCsrf();

    $email = trim(input('email', ''));
    $password = input('password', '');

    $result = login($email, $password);

    if ($result === true) {
        flashSuccess('ログインしました');
        redirect('/dashboard');
    } elseif (is_string($result)) {
        flashError($result);
        setOld(['email' => $email]);
    } else {
        flashError('メールアドレスまたはパスワードが正しくありません');
        setOld(['email' => $email]);
    }
}

$pageTitle = 'ログイン';
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
            <?php $flash = getFlash(); if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> mb-4">
                <?= h($flash['message']) ?>
            </div>
            <?php endif; ?>

            <form method="post" action="<?= $basePath ?>/login">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCsrfToken() ?>">

                <div class="form-group">
                    <label for="email" class="form-label">メールアドレス</label>
                    <div class="input-icon-wrapper">
                        <i class="bi bi-envelope"></i>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?= h(old('email')) ?>" placeholder="admin@example.com" required autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">パスワード</label>
                    <div class="input-icon-wrapper">
                        <i class="bi bi-lock"></i>
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="パスワードを入力" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-login">
                    <i class="bi bi-box-arrow-in-right"></i>
                    ログイン
                </button>
            </form>

            <div class="text-center mt-4">
                <a href="<?= $basePath ?>/forgot-password" class="text-muted">
                    パスワードを忘れた方はこちら
                </a>
            </div>
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
<?php clearOld(); ?>
