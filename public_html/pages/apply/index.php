<?php
/**
 * LINE応募画面（公開）
 */
$isPublicPage = true;

// トークン取得
$token = input('token', '');

// CSRFトークン生成（公開ページ用）
if (!isset($_SESSION['apply_csrf_token'])) {
    $_SESSION['apply_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['apply_csrf_token'];

if (empty($token) || strlen($token) !== 64) {
    $error = 'このURLは無効です。最新の案件情報はLINEからご確認ください。';
    $pageTitle = '応募エラー';
    require __DIR__ . '/../../includes/public_header.php';
    ?>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6 text-center">
                <div class="alert alert-danger"><?= h($error) ?></div>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/../../includes/public_footer.php';
    exit;
}

// トークン検証
$tokenData = dbSelectOne(
    "SELECT at.*, j.id as job_id, j.scheduled_at,
            DATE(j.scheduled_at) as scheduled_date, TIME(j.scheduled_at) as scheduled_time,
            j.base_reward, j.status as job_status, j.notes as job_notes,
            j.notification_status, j.assigned_cleaner_id,
            sa.name as area_name, s.name as store_name,
            c.name as cleaner_name, c.id as token_cleaner_id
     FROM application_tokens at
     INNER JOIN cleaning_jobs j ON at.job_id = j.id
     INNER JOIN sales_areas sa ON j.sales_area_id = sa.id
     INNER JOIN stores s ON j.store_id = s.id
     LEFT JOIN cleaners c ON at.cleaner_id = c.id
     WHERE at.token = ? AND at.used_at IS NULL AND j.deleted_at IS NULL",
    [$token]
);

if (!$tokenData) {
    $error = 'このURLは無効または使用済みです。最新の案件情報はLINEからご確認ください。';
    $pageTitle = '応募エラー';
    require __DIR__ . '/../../includes/public_header.php';
    ?>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6 text-center">
                <div class="alert alert-warning"><?= h($error) ?></div>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/../../includes/public_footer.php';
    exit;
}

// 期限切れチェック
if (strtotime($tokenData['expires_at']) < time()) {
    $error = 'この案件の応募受付は終了しました。また次の案件でお待ちしています！';
    $pageTitle = '応募期限切れ';
    require __DIR__ . '/../../includes/public_header.php';
    ?>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6 text-center">
                <div class="alert alert-warning"><?= h($error) ?></div>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/../../includes/public_footer.php';
    exit;
}

// 既に担当者が決まっているかチェック
if ($tokenData['assigned_cleaner_id']) {
    $error = '残念ながら、この案件は他の方に決まりました。また次の案件でお待ちしています！';
    $pageTitle = '応募受付終了';
    require __DIR__ . '/../../includes/public_header.php';
    ?>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6 text-center">
                <div class="alert alert-info"><?= h($error) ?></div>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/../../includes/public_footer.php';
    exit;
}

$pageTitle = '清掃案件への応募';
$isFixedCleaner = $tokenData['type'] === 'fixed' && $tokenData['token_cleaner_id'];

// 応募処理
$errors = [];
$success = false;
if (isPost()) {
    // CSRF検証
    $submittedCsrf = input('csrf_token', '');
    if (!hash_equals($csrfToken, $submittedCsrf)) {
        $errors[] = '不正なリクエストです。ページを再読み込みしてください。';
    }

    $action = input('action', '');

    // 固定者の場合はトークンの清掃者IDを使用（POSTからは受け取らない）
    if ($isFixedCleaner) {
        $cleanerId = $tokenData['token_cleaner_id'];
    } else {
        // 公開応募の場合はセッションのLINEユーザーから清掃者を特定
        // POSTからのcleaner_idは信用しない
        $lineUserId = $_SESSION['line_user_id'] ?? null;
        if ($lineUserId) {
            $validatedCleaner = dbSelectOne(
                "SELECT id FROM cleaners WHERE line_user_id = ? AND is_active = 1 AND deleted_at IS NULL",
                [$lineUserId]
            );
            $cleanerId = $validatedCleaner ? (int) $validatedCleaner['id'] : 0;
        } else {
            $cleanerId = 0;
        }
    }

    if ($action === 'apply' && empty($errors)) {
        if ($cleanerId <= 0) {
            $errors[] = '清掃者情報が不正です';
        } else {
            dbBegin();
            try {
                // 案件を再取得（ロック）
                $job = dbSelectOne(
                    "SELECT id, assigned_cleaner_id, status FROM cleaning_jobs WHERE id = ? FOR UPDATE",
                    [$tokenData['job_id']]
                );

                if (!$job) {
                    throw new Exception('案件が見つかりません');
                }

                if ($job['assigned_cleaner_id']) {
                    throw new Exception('残念ながら、この案件は他の方に決まりました');
                }

                if (!in_array($job['status'], ['unassigned', 'recruiting'], true)) {
                    throw new Exception('この案件は既に処理されています');
                }

                // 応募記録（応募と同時に採用されるのでaccepted）
                dbInsert('job_applications', [
                    'job_id' => $tokenData['job_id'],
                    'cleaner_id' => $cleanerId,
                    'status' => 'accepted',
                    'applied_at' => date('Y-m-d H:i:s'),
                ]);

                // 担当者割当
                dbUpdate('cleaning_jobs', [
                    'assigned_cleaner_id' => $cleanerId,
                    'status' => 'assigned',
                    'notification_status' => 'completed',
                ], 'id = ? AND assigned_cleaner_id IS NULL', [$tokenData['job_id']]);

                // トークンを使用済みに
                dbUpdate('application_tokens', [
                    'used_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$tokenData['id']]);

                // 通知ログ更新
                if ($isFixedCleaner) {
                    dbUpdate('notification_logs', [
                        'response' => 'ok',
                        'responded_at' => date('Y-m-d H:i:s'),
                    ], 'job_id = ? AND cleaner_id = ? AND response = ?', [$tokenData['job_id'], $cleanerId, 'pending']);
                }

                dbCommit();
                $success = true;

                // 採用確定通知（LINE Push）- コミット後に実行
                try {
                    $acceptedCleaner = dbSelectOne(
                        "SELECT c.name, c.line_user_id FROM cleaners c WHERE c.id = ?",
                        [$cleanerId]
                    );
                    if ($acceptedCleaner && !empty($acceptedCleaner['line_user_id'])) {
                        $scheduledDate = formatDate($tokenData['scheduled_date'], 'n月j日');
                        $scheduledTime = formatTime($tokenData['scheduled_time']);
                        $message = "【担当確定】\n\n"
                            . "{$acceptedCleaner['name']}さん、担当が確定しました！\n\n"
                            . "📍 場所: {$tokenData['area_name']}\n"
                            . "📅 日時: {$scheduledDate} {$scheduledTime}\n"
                            . "💰 報酬: " . number_format($tokenData['base_reward']) . "円\n\n"
                            . "当日よろしくお願いします！";
                        sendLineTextPush($acceptedCleaner['line_user_id'], $message);
                    }
                } catch (Exception $e) {
                    error_log("Apply acceptance notification failed: " . $e->getMessage());
                }

            } catch (Exception $e) {
                dbRollback();
                $errors[] = $e->getMessage();
            }
        }
    } elseif ($action === 'decline' && empty($errors)) {
        // 辞退処理
        if ($isFixedCleaner) {
            dbBegin();
            try {
                // 通知ログを辞退に更新
                dbUpdate('notification_logs', [
                    'response' => 'ng',
                    'responded_at' => date('Y-m-d H:i:s'),
                ], 'job_id = ? AND cleaner_id = ? AND response = ?', [$tokenData['job_id'], $tokenData['token_cleaner_id'], 'pending']);

                // トークンを使用済みに
                dbUpdate('application_tokens', [
                    'used_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$tokenData['id']]);

                dbCommit();

                $pageTitle = '辞退完了';
                require __DIR__ . '/../../includes/public_header.php';
                ?>
                <div class="container py-5">
                    <div class="row justify-content-center">
                        <div class="col-md-6 text-center">
                            <div class="alert alert-secondary">
                                <h5>辞退を受け付けました</h5>
                                <p class="mb-0">また次の案件でお待ちしています！</p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
                require __DIR__ . '/../../includes/public_footer.php';
                exit;

            } catch (Exception $e) {
                dbRollback();
                $errors[] = $e->getMessage();
            }
        }
    }
}

// 応募成功時
if ($success) {
    $pageTitle = '応募完了';
    require __DIR__ . '/../../includes/public_header.php';
    ?>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-body text-center py-5">
                        <div class="mb-4">
                            <span class="display-1 text-success">&#10003;</span>
                        </div>
                        <h2 class="mb-4">担当が確定しました！</h2>
                        <div class="alert alert-success">
                            <table class="table table-borderless mb-0">
                                <tr>
                                    <th>場所</th>
                                    <td><?= h($tokenData['store_name']) ?> - <?= h($tokenData['area_name']) ?></td>
                                </tr>
                                <tr>
                                    <th>日時</th>
                                    <td><?= h(formatDate($tokenData['scheduled_date'], 'Y年n月j日')) ?> <?= h(formatTime($tokenData['scheduled_time'])) ?></td>
                                </tr>
                                <tr>
                                    <th>報酬</th>
                                    <td><?= number_format($tokenData['base_reward']) ?>円</td>
                                </tr>
                            </table>
                        </div>
                        <p class="text-muted">当日よろしくお願いします！</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/../../includes/public_footer.php';
    exit;
}

// 公募の場合は清掃者選択（LINE認証で取得する想定だが、今回は簡易実装）
$cleanerForPublic = null;
if (!$isFixedCleaner) {
    // 本来はLINE認証で清掃者を特定するが、今回はセッションから取得する簡易実装
    $lineUserId = $_SESSION['line_user_id'] ?? null;
    if ($lineUserId) {
        $cleanerForPublic = dbSelectOne(
            "SELECT id, name FROM cleaners WHERE line_user_id = ? AND is_active = 1 AND deleted_at IS NULL",
            [$lineUserId]
        );
    }
}

require __DIR__ . '/../../includes/public_header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                <p class="mb-0"><?= h($error) ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <?php if ($isFixedCleaner): ?>
                        <?= h($tokenData['cleaner_name']) ?>さん専用のご案内
                        <?php else: ?>
                        清掃スタッフ募集
                        <?php endif; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <table class="table table-borderless">
                        <tr>
                            <th class="text-muted" style="width: 30%">場所</th>
                            <td><?= h($tokenData['store_name']) ?> - <?= h($tokenData['area_name']) ?></td>
                        </tr>
                        <tr>
                            <th class="text-muted">日時</th>
                            <td>
                                <?= h(formatDate($tokenData['scheduled_date'], 'Y年n月j日（') . getDayName((int)date('w', strtotime($tokenData['scheduled_date']))) . '）') ?><br>
                                <?= h(formatTime($tokenData['scheduled_time'])) ?>〜
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">報酬</th>
                            <td class="h5 text-primary"><?= number_format($tokenData['base_reward']) ?>円</td>
                        </tr>
                        <?php if ($tokenData['job_notes']): ?>
                        <tr>
                            <th class="text-muted">備考</th>
                            <td><?= nl2br(h($tokenData['job_notes'])) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>

                    <?php if ($isFixedCleaner): ?>
                    <div class="alert alert-info">
                        このまま対応可能ですか？
                    </div>

                    <form method="post" class="d-flex gap-2">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="apply">
                        <button type="submit" class="btn btn-success btn-lg flex-grow-1">
                            受ける（YES）
                        </button>
                    </form>
                    <form method="post" class="mt-2">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="decline">
                        <button type="submit" class="btn btn-outline-secondary btn-lg w-100">
                            受けない（NO）
                        </button>
                    </form>

                    <?php elseif ($cleanerForPublic): ?>
                    <div class="alert alert-info">
                        <?= h($cleanerForPublic['name']) ?>さん、この案件に応募しますか？
                    </div>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="apply">
                        <button type="submit" class="btn btn-success btn-lg w-100">
                            応募する
                        </button>
                    </form>

                    <?php else: ?>
                    <div class="alert alert-warning">
                        応募するにはLINE連携が必要です。<br>
                        LINEから案件通知を受け取ってください。
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/public_footer.php'; ?>
