<?php
/**
 * パスワードリセット申請画面
 */

// 既にログイン済みならダッシュボードへ
if (isLoggedIn()) {
    redirect('/dashboard');
}

$success = false;
$errors = [];

// リセット申請処理
if (isPost()) {
    requireCsrf();

    $email = trim(input('email', ''));

    if (empty($email)) {
        $errors[] = 'メールアドレスを入力してください';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'メールアドレスの形式が正しくありません';
    }

    if (empty($errors)) {
        // トークン生成（ユーザーが存在しなくても同じメッセージを返す - セキュリティ対策）
        $token = generatePasswordResetToken($email);

        if ($token) {
            sendPasswordResetMail($email, $token);
        }

        // ユーザー存在有無に関わらず成功メッセージを表示（列挙攻撃対策）
        $success = true;
    }
}

$pageTitle = 'パスワードリセット';
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
                入力されたメールアドレス宛にパスワードリセットのご案内を送信しました。
                メールをご確認ください。
            </div>
            <div class="text-center">
                <a href="<?= $basePath ?>/login" class="btn btn-outline-primary">
                    ログインページへ戻る
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
                    登録済みのメールアドレスを入力してください。<br>
                    パスワードリセット用のリンクをお送りします。
                </p>

                <form method="post" action="<?= $basePath ?>/forgot-password">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= generateCsrfToken() ?>">

                    <div class="form-group">
                        <label for="email" class="form-label">メールアドレス</label>
                        <div class="input-icon-wrapper">
                            <i class="bi bi-envelope"></i>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= h(old('email')) ?>" placeholder="admin@example.com" required autofocus>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-login">
                        <i class="bi bi-envelope-arrow-up"></i>
                        リセットメールを送信
                    </button>
                </form>

                <div class="text-center mt-4">
                    <a href="<?= $basePath ?>/login" class="text-muted">
                        <i class="bi bi-arrow-left"></i> ログインページへ戻る
                    </a>
                </div>
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
<?php clearOld(); ?>
