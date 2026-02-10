<?php
/**
 * 清掃案件詳細・編集
 */
$pageTitle = '清掃案件詳細';

// IDは index.php で設定済み
if (!isset($jobId) || $jobId <= 0) {
    redirect('/jobs');
}

// 案件データ取得
$job = dbSelectOne(
    "SELECT cj.*, s.name as store_name, sa.name as area_name,
     c.name as cleaner_name, c.phone as cleaner_phone, c.id as cleaner_id,
     cj.is_urgent, cj.notification_status,
     cj.fixed_notification_sent_at, cj.public_notification_sent_at
     FROM cleaning_jobs cj
     LEFT JOIN stores s ON cj.store_id = s.id
     LEFT JOIN sales_areas sa ON cj.sales_area_id = sa.id
     LEFT JOIN cleaners c ON cj.assigned_cleaner_id = c.id
     WHERE cj.id = ? AND cj.deleted_at IS NULL",
    [$jobId]
);

if (!$job) {
    flashError('案件が見つかりません');
    redirect('/jobs');
}

// 店舗アクセス権チェック
requireStoreAccess($job['store_id']);

// 関連する予約
$reservation = null;
if ($job['reservation_id']) {
    $reservation = dbSelectOne(
        "SELECT * FROM reservations WHERE id = ? AND deleted_at IS NULL",
        [$job['reservation_id']]
    );
}

// 応募者一覧
$applications = dbSelect(
    "SELECT ja.*, c.name as cleaner_name, c.phone as cleaner_phone, c.ipass_code,
     (SELECT COUNT(*) FROM cleaning_jobs cj2 WHERE cj2.assigned_cleaner_id = c.id AND cj2.status = 'completed') as completed_count
     FROM job_applications ja
     INNER JOIN cleaners c ON ja.cleaner_id = c.id
     WHERE ja.job_id = ?
     ORDER BY ja.applied_at ASC",
    [$jobId]
);

// 延長情報
$extensions = dbSelect(
    "SELECT * FROM extensions WHERE job_id = ? ORDER BY created_at ASC",
    [$jobId]
);

// 対応可能な清掃者（担当者割当用）
$inClause = buildInClause([$job['store_id']]);
$availableCleaners = dbSelect(
    "SELECT c.* FROM cleaners c
     INNER JOIN cleaner_stores cs ON c.id = cs.cleaner_id
     WHERE cs.store_id IN ({$inClause['placeholders']})
     AND c.is_active = 1 AND c.deleted_at IS NULL
     ORDER BY c.name",
    $inClause['params']
);

// 更新処理
$errors = [];
if (isPost()) {
    requireCsrf();

    $action = input('action', '');

    // ステータス変更
    if ($action === 'update_status') {
        $newStatus = input('status', '');
        $allowedStatuses = ['unassigned', 'recruiting', 'assigned', 'completed', 'paid'];

        if (!in_array($newStatus, $allowedStatuses, true)) {
            $errors[] = '不正なステータスです';
        } else {
            // 状態遷移ルール: 支払済からは変更不可
            $currentJob = dbSelectOne(
                "SELECT status FROM cleaning_jobs WHERE id = ? AND deleted_at IS NULL",
                [$jobId]
            );

            if (!$currentJob) {
                $errors[] = '案件が見つかりません';
            } elseif ($currentJob['status'] === 'paid' && $newStatus !== 'paid') {
                $errors[] = '支払済の案件はステータス変更できません';
            } else {
                // 楽観ロック: 現在のステータスを条件に含める
                $rowCount = dbUpdate(
                    'cleaning_jobs',
                    ['status' => $newStatus],
                    'id = ? AND status = ?',
                    [$jobId, $currentJob['status']]
                );

                if ($rowCount === 0) {
                    $errors[] = '他の操作と競合しました。ページを更新して再度お試しください。';
                } else {
                    // 監査ログ記録
                    logAudit(
                        'update_status',
                        'cleaning_job',
                        $jobId,
                        ['status' => $currentJob['status']],
                        ['status' => $newStatus]
                    );

                    // 完了時に支払いレコードを自動生成
                    if ($newStatus === 'completed') {
                        $paymentId = createPaymentForJob($jobId);
                        if ($paymentId) {
                            flashSuccess('ステータスを完了に更新し、支払いレコードを作成しました');
                        } else {
                            flashSuccess('ステータスを更新しました');
                        }
                    } else {
                        flashSuccess('ステータスを更新しました');
                    }
                    redirect("/jobs/{$jobId}");
                }
            }
        }
    }

    // 担当者割当
    if ($action === 'assign_cleaner') {
        $cleanerId = (int) input('cleaner_id', 0);

        if ($cleanerId <= 0) {
            $errors[] = '清掃者を選択してください';
        } else {
            // 案件が完了/支払済でないことを確認
            $currentJob = dbSelectOne(
                "SELECT status FROM cleaning_jobs WHERE id = ? AND deleted_at IS NULL",
                [$jobId]
            );

            if (!$currentJob) {
                $errors[] = '案件が見つかりません';
            } elseif (in_array($currentJob['status'], ['completed', 'paid'], true)) {
                $errors[] = '完了済みまたは支払済の案件には担当者を割り当てられません';
            } else {
                // 清掃者が存在し、この店舗に対応し、アクティブか確認
                $cleaner = dbSelectOne(
                    "SELECT c.* FROM cleaners c
                     INNER JOIN cleaner_stores cs ON c.id = cs.cleaner_id
                     WHERE c.id = ? AND cs.store_id = ? AND c.is_active = 1 AND c.deleted_at IS NULL",
                    [$cleanerId, $job['store_id']]
                );

                if (!$cleaner) {
                    $errors[] = '選択された清掃者はこの店舗に対応していないか、無効です';
                } else {
                    // 楽観ロック: 完了/支払済でない場合のみ更新
                    $rowCount = dbUpdate('cleaning_jobs', [
                        'assigned_cleaner_id' => $cleanerId,
                        'status' => 'assigned',
                    ], 'id = ? AND status NOT IN (?, ?)', [$jobId, 'completed', 'paid']);

                    if ($rowCount === 0) {
                        $errors[] = '他の操作と競合しました。ページを更新して再度お試しください。';
                    } else {
                        // 監査ログ記録
                        logAudit(
                            'assign_cleaner',
                            'cleaning_job',
                            $jobId,
                            ['assigned_cleaner_id' => null, 'status' => $currentJob['status']],
                            ['assigned_cleaner_id' => $cleanerId, 'status' => 'assigned']
                        );

                        flashSuccess('担当者を割り当てました');
                        redirect("/jobs/{$jobId}");
                    }
                }
            }
        }
    }

    // 応募者を採用
    if ($action === 'accept_application') {
        $applicationId = (int) input('application_id', 0);

        $application = dbSelectOne(
            "SELECT * FROM job_applications WHERE id = ? AND job_id = ?",
            [$applicationId, $jobId]
        );

        if (!$application) {
            $errors[] = '応募が見つかりません';
        } else {
            // 案件が募集中であることを確認（レースコンディション対策）
            $currentJob = dbSelectOne(
                "SELECT status, assigned_cleaner_id FROM cleaning_jobs WHERE id = ? AND deleted_at IS NULL",
                [$jobId]
            );

            if (!$currentJob) {
                $errors[] = '案件が見つかりません';
            } elseif ($currentJob['status'] !== 'recruiting') {
                $errors[] = 'この案件は募集中ではありません（現在: ' . statusLabel($currentJob['status'], 'job') . '）';
            } elseif ($currentJob['assigned_cleaner_id']) {
                $errors[] = 'すでに担当者が割り当てられています';
            } else {
                // 応募者が有効か確認
                $applicantCleaner = dbSelectOne(
                    "SELECT id FROM cleaners WHERE id = ? AND is_active = 1 AND deleted_at IS NULL",
                    [$application['cleaner_id']]
                );

                if (!$applicantCleaner) {
                    $errors[] = 'この応募者は現在無効な清掃者です';
                } else {
                    // トランザクションで一括処理
                    dbBegin();
                    try {
                        // WHERE条件にステータスも追加（楽観的ロック）
                        $jobUpdated = dbUpdate('cleaning_jobs', [
                            'assigned_cleaner_id' => $application['cleaner_id'],
                            'status' => 'assigned',
                        ], 'id = ? AND status = ?', [$jobId, 'recruiting']);

                        // 更新行数が0なら競合発生
                        if ($jobUpdated === 0) {
                            throw new RuntimeException('Job status changed by another operation');
                        }

                        dbUpdate('job_applications', ['status' => 'accepted'], 'id = ?', [$applicationId]);

                        // 他の応募を却下
                        dbUpdate('job_applications',
                            ['status' => 'rejected'],
                            'job_id = ? AND id != ? AND status = ?',
                            [$jobId, $applicationId, 'applied']
                        );

                        // 監査ログ記録
                        logAudit(
                            'assign_cleaner',
                            'cleaning_job',
                            $jobId,
                            ['assigned_cleaner_id' => null, 'status' => 'recruiting'],
                            ['assigned_cleaner_id' => $application['cleaner_id'], 'status' => 'assigned']
                        );

                        dbCommit();

                        // 採用確定通知（LINE Push）- コミット後に実行
                        try {
                            $acceptedCleaner = dbSelectOne(
                                "SELECT c.name, c.line_user_id
                                 FROM cleaners c WHERE c.id = ?",
                                [$application['cleaner_id']]
                            );
                            if ($acceptedCleaner && !empty($acceptedCleaner['line_user_id'])) {
                                $jobInfo = dbSelectOne(
                                    "SELECT j.scheduled_at, j.base_reward, sa.name as area_name
                                     FROM cleaning_jobs j
                                     INNER JOIN sales_areas sa ON j.sales_area_id = sa.id
                                     WHERE j.id = ?",
                                    [$jobId]
                                );
                                if ($jobInfo) {
                                    $scheduledDate = formatDate($jobInfo['scheduled_at'], 'n月j日');
                                    $scheduledTime = formatDate($jobInfo['scheduled_at'], 'H:i');
                                    $message = "【採用確定】\n\n"
                                        . "{$acceptedCleaner['name']}さん、清掃案件の担当が確定しました！\n\n"
                                        . "📍 場所: {$jobInfo['area_name']}\n"
                                        . "📅 日時: {$scheduledDate} {$scheduledTime}\n"
                                        . "💰 報酬: " . number_format($jobInfo['base_reward']) . "円\n\n"
                                        . "当日よろしくお願いします！";
                                    sendLineTextPush($acceptedCleaner['line_user_id'], $message);
                                }
                            }
                        } catch (Exception $e) {
                            error_log("Acceptance notification failed for job {$jobId}: " . $e->getMessage());
                        }

                        flashSuccess('応募者を採用しました');
                        redirect("/jobs/{$jobId}");
                    } catch (Exception $e) {
                        dbRollback();
                        error_log("Accept application failed for job {$jobId}: " . $e->getMessage());
                        $errors[] = '採用処理に失敗しました。他の操作と競合した可能性があります。';
                    }
                }
            }
        }
    }

    // 募集開始（固定者優先通知）
    if ($action === 'start_recruiting') {
        // 楽観ロック: 未割当の場合のみ募集開始可能
        $rowCount = dbUpdate(
            'cleaning_jobs',
            ['status' => 'recruiting'],
            'id = ? AND status = ?',
            [$jobId, 'unassigned']
        );

        if ($rowCount === 0) {
            $errors[] = '募集を開始できませんでした。ステータスが変更された可能性があります。';
        } else {
            // 固定者がいれば優先通知、なければ公募
            $fixedCount = dbSelectOne(
                "SELECT COUNT(*) as cnt FROM fixed_cleaners
                 WHERE store_id = ? AND is_active = 1 AND deleted_at IS NULL",
                [$job['store_id']]
            );

            $notificationType = ($fixedCount['cnt'] > 0) ? 'fixed' : 'normal';
            $sentCount = sendJobNotifications($jobId, $notificationType);

            // 監査ログ記録
            logAudit(
                'start_recruiting',
                'cleaning_job',
                $jobId,
                ['status' => 'unassigned'],
                ['status' => 'recruiting']
            );

            if ($sentCount > 0) {
                flashSuccess("募集を開始しました（{$sentCount}名に通知送信）");
            } else {
                flashSuccess('募集を開始しました（通知対象者なし）');
            }
            redirect("/jobs/{$jobId}");
        }
    }

    // 急募通知送信
    if ($action === 'send_urgent') {
        // 最新状態を取得して検証
        $currentJob = dbSelectOne(
            "SELECT status, assigned_cleaner_id FROM cleaning_jobs WHERE id = ? AND deleted_at IS NULL",
            [$jobId]
        );

        if (!$currentJob) {
            $errors[] = '案件が見つかりません';
        } elseif (!in_array($currentJob['status'], ['unassigned', 'recruiting'], true)) {
            $errors[] = '急募通知は未割当または募集中の案件のみ送信できます（現在: ' . statusLabel($currentJob['status'], 'job') . '）';
        } elseif ($currentJob['assigned_cleaner_id']) {
            $errors[] = '既に担当者が割り当てられています';
        } else {
            // 楽観ロック: 現在のステータスを条件に含める
            $rowCount = dbUpdate('cleaning_jobs', [
                'is_urgent' => 1,
                'status' => 'recruiting',
            ], 'id = ? AND status IN (?, ?) AND assigned_cleaner_id IS NULL', [$jobId, 'unassigned', 'recruiting']);

            if ($rowCount === 0) {
                $errors[] = '他の操作と競合しました。ページを更新して再度お試しください。';
            } else {
                $sentCount = sendJobNotifications($jobId, 'urgent');

                if ($sentCount > 0) {
                    flashSuccess("【急募】通知を送信しました（{$sentCount}名）");
                } else {
                    flashSuccess('急募フラグを設定しました（通知対象者なし）');
                }
                redirect("/jobs/{$jobId}");
            }
        }
    }

    // 公募開始（固定者スキップ）
    if ($action === 'start_public') {
        // 最新状態を取得して検証
        $currentJob = dbSelectOne(
            "SELECT status, assigned_cleaner_id FROM cleaning_jobs WHERE id = ? AND deleted_at IS NULL",
            [$jobId]
        );

        if (!$currentJob) {
            $errors[] = '案件が見つかりません';
        } elseif (!in_array($currentJob['status'], ['unassigned', 'recruiting'], true)) {
            $errors[] = '公募は未割当または募集中の案件のみ可能です（現在: ' . statusLabel($currentJob['status'], 'job') . '）';
        } elseif ($currentJob['assigned_cleaner_id']) {
            $errors[] = '既に担当者が割り当てられています';
        } else {
            // 楽観ロック: 現在のステータスを条件に含める
            $rowCount = dbUpdate('cleaning_jobs', [
                'status' => 'recruiting',
            ], 'id = ? AND status IN (?, ?) AND assigned_cleaner_id IS NULL', [$jobId, 'unassigned', 'recruiting']);

            if ($rowCount === 0) {
                $errors[] = '他の操作と競合しました。ページを更新して再度お試しください。';
            } else {
                $sentCount = sendJobNotifications($jobId, 'normal');

                if ($sentCount > 0) {
                    flashSuccess("公募通知を送信しました（{$sentCount}名）");
                } else {
                    flashSuccess('募集を開始しました（通知対象者なし）');
                }
                redirect("/jobs/{$jobId}");
            }
        }
    }

    // 報酬更新
    if ($action === 'update_reward') {
        $baseReward = (int) input('base_reward', 0);
        $maxReward = 1000000; // 上限100万円

        // 支払済み・キャンセル済みの案件は報酬変更不可
        $currentJob = dbSelectOne(
            "SELECT status FROM cleaning_jobs WHERE id = ? AND deleted_at IS NULL",
            [$jobId]
        );
        if ($currentJob && in_array($currentJob['status'], ['paid', 'cancelled'], true)) {
            $errors[] = '支払済みまたはキャンセル済みの案件は報酬を変更できません';
        } elseif ($baseReward < 0) {
            $errors[] = '報酬は0円以上で指定してください';
        } elseif ($baseReward > $maxReward) {
            $errors[] = '報酬は' . number_format($maxReward) . '円以下で指定してください';
        } else {
            // 案件の報酬を更新（顧客料金は変更しない: 独立）
            // 楽観ロック: 支払済み・キャンセル済みでないことをWHERE条件で保証
            $rowCount = dbUpdate('cleaning_jobs', ['base_reward' => $baseReward], 'id = ? AND status NOT IN (?, ?)', [$jobId, 'paid', 'cancelled']);

            if ($rowCount === 0) {
                $errors[] = '他の操作と競合しました。ページを更新して再度お試しください。';
            } else {
                flashSuccess('報酬を更新しました');
                redirect("/jobs/{$jobId}");
            }
        }
    }

    // 延長登録
    if ($action === 'add_extension') {
        $rawExtensionHours = input('extension_hours', '');
        $storeSettings = getStoreSettings((int) $job['store_id']);
        $maxExtension = $storeSettings['max_extension_hours'];

        // 型チェック: 数値かどうか検証
        if (!is_numeric($rawExtensionHours)) {
            $errors[] = '延長時間は数値で入力してください';
        } else {
            $extensionHours = (float) $rawExtensionHours;

            // 許可された値のみ受け付ける（0.5刻み、店舗設定のmax_extension_hoursまで）
            $allowedValues = [];
            for ($v = 0.5; $v <= $maxExtension; $v += 0.5) {
                $allowedValues[] = $v;
            }
            if (!in_array($extensionHours, $allowedValues, true)) {
                $errors[] = "延長時間は0.5〜{$maxExtension}時間の範囲で0.5時間単位で選択してください";
            } elseif ($extensionHours > $maxExtension) {
                $errors[] = "延長時間は最大{$maxExtension}時間です";
            } elseif (!in_array($job['status'], ['assigned', 'completed'], true)) {
                $errors[] = '担当者確定済みまたは完了済みの案件のみ延長できます';
            }
        }

        if (empty($errors)) {
            // 顧客延長料金を計算（店舗設定のextension_price_per_hour ベース）
            $ratePerHour = $storeSettings['extension_price_per_hour'];
            $additionalPrice = (int) ($ratePerHour * $extensionHours);

            dbBegin();
            try {
                // 予約をFOR UPDATEでロックして最新値を取得（競合対策）
                if ($reservation) {
                    $reservation = dbSelectOne(
                        "SELECT * FROM reservations WHERE id = ? FOR UPDATE",
                        [$reservation['id']]
                    );
                }

                // ロック取得後に延長可能時間を再検証（TOCTOU対策）
                if ($reservation) {
                    $extensionInfo = calculateAvailableExtension(
                        (int) $reservation['sales_area_id'],
                        (int) $reservation['store_id'],
                        $reservation['start_time'],
                        $reservation['end_time'],
                        $reservation['reservation_date']
                    );
                    $requestedMinutes = (int) ($extensionHours * 60);
                    if ($extensionInfo['available_minutes'] < $requestedMinutes) {
                        throw new Exception("延長可能時間（{$extensionInfo['available_minutes']}分）を超えています");
                    }
                }

                // 延長記録（additional_rewardは0: 清掃報酬追加なし）
                dbInsert('extensions', [
                    'job_id' => $jobId,
                    'extension_hours' => $extensionHours,
                    'additional_reward' => 0,
                    'requested_at' => date('Y-m-d H:i:s'),
                    'approved_at' => date('Y-m-d H:i:s'),
                    'status' => 'approved',
                ]);

                // 清掃報酬は更新しない（仕様: 延長しても報酬不変）

                // 予約の終了時間・顧客延長料金を更新
                if ($reservation) {
                    // 日跨ぎ対応: end_time < start_time の場合は翌日として計算
                    $oldEndTimestamp = strtotime($reservation['reservation_date'] . ' ' . $reservation['end_time']);
                    if ($reservation['end_time'] < $reservation['start_time']) {
                        $oldEndTimestamp = strtotime('+1 day', $oldEndTimestamp);
                    }
                    $newEndTimestamp = $oldEndTimestamp + ($extensionHours * 3600);
                    $newEndTime = date('H:i:s', $newEndTimestamp);

                    $currentExtPrice = (int) ($reservation['extension_price'] ?? 0);
                    $newExtPrice = $currentExtPrice + $additionalPrice;

                    dbUpdate('reservations', [
                        'end_time' => $newEndTime,
                        'extension_price' => $newExtPrice,
                        'total_price' => (int) $reservation['base_price'] + $newExtPrice,
                    ], 'id = ?', [$reservation['id']]);

                    // 清掃案件のscheduled_atも更新（日跨ぎ対応）
                    dbUpdate('cleaning_jobs', [
                        'scheduled_at' => date('Y-m-d H:i:s', $newEndTimestamp),
                    ], 'id = ?', [$jobId]);
                }

                dbCommit();
                flashSuccess("延長を登録しました（+{$extensionHours}時間、料金 " . number_format($additionalPrice) . "円）");
                redirect("/jobs/{$jobId}");
            } catch (Exception $e) {
                dbRollback();
                error_log("Extension add failed for job {$jobId}: " . $e->getMessage());
                $errors[] = '延長の登録に失敗しました';
            }
        }
    }
}

// CSRFトークンを1回だけ生成
$csrfToken = generateCsrfToken();

require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="<?= url('/jobs') ?>" class="btn btn-outline-secondary btn-sm mb-2">← 案件一覧へ戻る</a>
        <h1 class="h3 mb-0">清掃案件 #<?= $job['id'] ?></h1>
    </div>
    <div>
        <span class="badge bg-<?= statusClass($job['status']) ?> fs-6">
            <?= statusLabel($job['status'], 'job') ?>
        </span>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?= h($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row">
    <!-- 案件情報 -->
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">案件情報</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th class="text-muted" style="width: 40%">予定日時</th>
                                <td><?= h(formatDateTime($job['scheduled_at'])) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">店舗</th>
                                <td><?= h($job['store_name']) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">営業区分</th>
                                <td><?= h($job['area_name'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">種別</th>
                                <td>
                                    <?= jobTypeLabel($job['job_type']) ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th class="text-muted" style="width: 40%">報酬</th>
                                <td><strong><?= formatMoney($job['base_reward']) ?></strong></td>
                            </tr>
                            <tr>
                                <th class="text-muted">備考</th>
                                <td><?= h($job['notes'] ?? '-') ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 担当者情報 -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">担当者</h5>
                <?php if ($job['status'] !== 'completed' && $job['status'] !== 'paid'): ?>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#assignModal">
                    担当者を変更
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($job['cleaner_name']): ?>
                    <div class="d-flex align-items-center">
                        <div class="me-3">
                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                <?= h(mb_substr($job['cleaner_name'], 0, 1)) ?>
                            </div>
                        </div>
                        <div>
                            <h6 class="mb-0"><?= h($job['cleaner_name']) ?></h6>
                            <small class="text-muted"><?= h($job['cleaner_phone'] ?? '-') ?></small>
                        </div>
                        <div class="ms-auto">
                            <a href="<?= url('/cleaners/' . $job['cleaner_id']) ?>" class="btn btn-outline-primary btn-sm">詳細</a>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">担当者未割当</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- 応募者一覧 -->
        <?php if (!empty($applications)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">応募者一覧 (<?= count($applications) ?>人)</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>清掃者</th>
                                <th>実績</th>
                                <th>応募日時</th>
                                <th>ステータス</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($applications as $app): ?>
                            <tr>
                                <td>
                                    <strong><?= h($app['cleaner_name']) ?></strong>
                                    <small class="text-muted d-block"><?= h($app['cleaner_phone'] ?? '-') ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?= $app['completed_count'] ?>件完了</span>
                                </td>
                                <td><?= h(formatDateTime($app['applied_at'])) ?></td>
                                <td>
                                    <?php $appStatus = applicationStatusInfo($app['status']); ?>
                                    <span class="badge bg-<?= $appStatus['class'] ?>"><?= $appStatus['label'] ?></span>
                                </td>
                                <td>
                                    <?php if ($app['status'] === 'applied' && $job['status'] === 'recruiting'): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="action" value="accept_application">
                                        <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-success">採用</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 延長履歴 -->
        <?php if (!empty($extensions)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">延長履歴</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>延長時間</th>
                                <th>ステータス</th>
                                <th>登録日時</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($extensions as $ext): ?>
                            <tr>
                                <td><?= $ext['extension_hours'] ?>時間</td>
                                <td>
                                    <?php
                                    $extStatusLabel = match($ext['status']) {
                                        'approved' => '承認済',
                                        'pending' => '保留',
                                        'rejected' => '却下',
                                        default => $ext['status']
                                    };
                                    ?>
                                    <span class="badge bg-<?= $ext['status'] === 'approved' ? 'success' : ($ext['status'] === 'pending' ? 'warning' : 'danger') ?>">
                                        <?= $extStatusLabel ?>
                                    </span>
                                </td>
                                <td><?= h(formatDateTime($ext['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 関連予約 -->
        <?php if ($reservation): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">関連予約</h5>
            </div>
            <div class="card-body">
                <p class="mb-2">
                    <strong><?= h($reservation['customer_name']) ?></strong>
                    <span class="badge bg-<?= statusClass($reservation['status']) ?> ms-2">
                        <?= statusLabel($reservation['status'], 'reservation') ?>
                    </span>
                </p>
                <p class="text-muted mb-2">
                    <?= h(formatDate($reservation['reservation_date'])) ?>
                    <?= h(substr($reservation['start_time'], 0, 5)) ?> - <?= h(substr($reservation['end_time'], 0, 5)) ?>
                </p>
                <a href="<?= url('/reservations/' . $reservation['id']) ?>" class="btn btn-outline-primary btn-sm">予約詳細を見る</a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- サイドバー -->
    <div class="col-lg-4">
        <!-- ステータス変更 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">ステータス変更</h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="update_status">
                    <div class="mb-3">
                        <select name="status" class="form-select">
                            <option value="unassigned" <?= $job['status'] === 'unassigned' ? 'selected' : '' ?>>未割当</option>
                            <option value="recruiting" <?= $job['status'] === 'recruiting' ? 'selected' : '' ?>>募集中</option>
                            <option value="assigned" <?= $job['status'] === 'assigned' ? 'selected' : '' ?>>確定</option>
                            <option value="completed" <?= $job['status'] === 'completed' ? 'selected' : '' ?>>完了</option>
                            <option value="paid" <?= $job['status'] === 'paid' ? 'selected' : '' ?>>支払済</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">変更を保存</button>
                </form>
            </div>
        </div>

        <!-- 募集・通知 -->
        <?php if ($job['status'] === 'unassigned' || $job['status'] === 'recruiting'): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">LINE通知</h5>
            </div>
            <div class="card-body">
                <?php if ($job['is_urgent']): ?>
                <div class="alert alert-danger py-2 mb-3">
                    <strong>【急募】</strong> この案件は急募に設定されています
                </div>
                <?php endif; ?>

                <?php if ($job['status'] === 'unassigned'): ?>
                <p class="text-muted small mb-3">固定者がいる場合は優先通知、いない場合は公募通知を送信します。</p>
                <form method="post" class="mb-2">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="start_recruiting">
                    <button type="submit" class="btn btn-warning w-100">募集を開始（固定者優先）</button>
                </form>
                <form method="post" class="mb-2">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="start_public">
                    <button type="submit" class="btn btn-outline-warning w-100">公募で開始（固定者スキップ）</button>
                </form>
                <?php endif; ?>

                <?php if (!$job['is_urgent'] && !$job['assigned_cleaner_id']): ?>
                <hr>
                <p class="text-muted small mb-2">担当者が決まらない場合は急募通知を送信できます。</p>
                <form method="post">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="send_urgent">
                    <button type="submit" class="btn btn-danger w-100"
                            onclick="return confirm('【急募】通知を全清掃者に送信しますか？')">
                        【急募】通知を送信
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($job['notification_status'] && $job['notification_status'] !== 'pending'): ?>
                <div class="mt-3 small text-muted">
                    <strong>通知状態:</strong>
                    <?php
                    echo match($job['notification_status']) {
                        'fixed_waiting' => '固定者応答待ち',
                        'public_recruiting' => '公募中',
                        'completed' => '完了',
                        default => $job['notification_status']
                    };
                    ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- 報酬設定 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">報酬設定</h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="update_reward">
                    <div class="mb-3">
                        <label class="form-label">基本報酬（円）</label>
                        <input type="number" name="base_reward" class="form-control"
                               value="<?= $job['base_reward'] ?>" min="0" step="100">
                    </div>
                    <button type="submit" class="btn btn-outline-primary w-100">報酬を更新</button>
                </form>
            </div>
        </div>

        <!-- 延長登録 -->
        <?php if (in_array($job['status'], ['assigned', 'completed'], true)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">延長登録</h5>
            </div>
            <div class="card-body">
                <?php
                $detailStoreSettings = getStoreSettings((int) $job['store_id']);
                $detailMaxExtHours = $detailStoreSettings['max_extension_hours'];
                $detailExtPrice = $detailStoreSettings['extension_price_per_hour'];
                ?>
                <p class="text-muted small mb-3">
                    延長料金: <?= formatMoney($detailExtPrice) ?>/時間（顧客請求）
                </p>
                <form method="post">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="add_extension">
                    <div class="mb-3">
                        <label class="form-label">延長時間</label>
                        <select name="extension_hours" class="form-select">
                            <?php for ($h = 0.5; $h <= $detailMaxExtHours; $h += 0.5): ?>
                            <?php
                                $wholeH = (int) floor($h);
                                $remainM = (int) (($h - $wholeH) * 60);
                                if ($remainM === 0) {
                                    $optLabel = $wholeH . '時間';
                                } elseif ($wholeH === 0) {
                                    $optLabel = $remainM . '分';
                                } else {
                                    $optLabel = $wholeH . '時間' . $remainM . '分';
                                }
                            ?>
                            <option value="<?= $h ?>" <?= $h == 1.0 ? 'selected' : '' ?>><?= $optLabel ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-info w-100">延長を追加</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 担当者割当モーダル -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="assign_cleaner">
                <div class="modal-header">
                    <h5 class="modal-title">担当者割当</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">清掃者を選択</label>
                        <select name="cleaner_id" class="form-select" required>
                            <option value="">-- 選択してください --</option>
                            <?php foreach ($availableCleaners as $cleaner): ?>
                            <option value="<?= $cleaner['id'] ?>" <?= $job['cleaner_id'] == $cleaner['id'] ? 'selected' : '' ?>>
                                <?= h($cleaner['name']) ?>
                                <?php if ($cleaner['phone']): ?>
                                (<?= h($cleaner['phone']) ?>)
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                    <button type="submit" class="btn btn-primary">割当</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
