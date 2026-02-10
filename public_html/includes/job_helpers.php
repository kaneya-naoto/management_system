<?php
/**
 * 清掃案件関連ヘルパー関数
 *
 * 案件ステータス、案件生成、支払い生成、完了処理、
 * 応募トークン、当日通知、完了キーワード判定などの案件ドメイン関数群
 */

/**
 * 案件種別のラベル
 */
function jobTypeLabel(?string $type): string
{
    return match($type) {
        'regular' => '通常清掃',
        'urgent' => '急募',
        default => '-'
    };
}

/**
 * 応募状態の情報
 * @return array{class: string, label: string}
 */
function applicationStatusInfo(string $status): array
{
    return match($status) {
        'accepted' => ['class' => 'success', 'label' => '採用'],
        'rejected' => ['class' => 'danger', 'label' => '不採用'],
        default => ['class' => 'secondary', 'label' => '保留']
    };
}

/**
 * 清掃案件ステータスラベルを取得
 * @param string $status ステータス
 * @return array ['label' => 表示名, 'class' => Bootstrap色クラス]
 */
function jobStatusLabel(string $status): array
{
    return match ($status) {
        'unassigned' => ['label' => '未割当', 'class' => 'secondary'],
        'assigned' => ['label' => '確定', 'class' => 'primary'],
        'completed' => ['label' => '完了', 'class' => 'success'],
        'paid' => ['label' => '支払済', 'class' => 'info'],
        'cancelled' => ['label' => 'キャンセル', 'class' => 'danger'],
        default => ['label' => $status, 'class' => 'secondary'],
    };
}

/**
 * 清掃案件を生成（深夜跨ぎ対応、清掃終了時刻設定）
 * @param int $reservationId 予約ID
 * @param int $storeId 店舗ID
 * @param int $salesAreaId 営業区分ID
 * @param string $date 予約日 (Y-m-d)
 * @param string $startTime 予約開始時刻
 * @param string $endTime 予約終了時刻 (H:i:s)
 * @return int 生成された清掃案件ID
 */
function createCleaningJobForReservation(
    int $reservationId,
    int $storeId,
    int $salesAreaId,
    string $date,
    string $startTime,
    string $endTime
): int {
    // 清掃開始時刻 = 予約終了時刻
    // 深夜跨ぎ対応: end_time < start_time なら翌日
    $cleaningDate = $date;
    if ($endTime < $startTime) {
        $cleaningDate = date('Y-m-d', strtotime($date . ' +1 day'));
    }
    $scheduledAt = $cleaningDate . ' ' . $endTime;

    // 清掃時間を取得
    $cleaningMinutes = getCleaningDuration($salesAreaId);

    // 清掃終了予定時刻を計算
    $scheduledEndAt = date('Y-m-d H:i:s', strtotime($scheduledAt . " +{$cleaningMinutes} minutes"));

    // 店舗設定から報酬を取得（getStoreSettings経由、フォールバック: DEFAULT_CLEANING_REWARD）
    $storeSettings = getStoreSettings($storeId);
    $baseReward = $storeSettings['base_reward'];

    return dbInsert('cleaning_jobs', [
        'reservation_id' => $reservationId,
        'store_id' => $storeId,
        'sales_area_id' => $salesAreaId,
        'scheduled_at' => $scheduledAt,
        'scheduled_end_at' => $scheduledEndAt,
        'duration_minutes' => $cleaningMinutes,
        'base_reward' => $baseReward,
        'status' => 'unassigned',
        'job_type' => 'regular',
    ]);
}

/**
 * 応募用トークンを生成
 * @param int $jobId 案件ID
 * @param int|null $cleanerId 清掃者ID（固定者の場合）
 * @param string $type トークン種別（'fixed' or 'public'）
 * @param int $expiresMinutes 有効期限（分）
 * @return string 生成されたトークン
 */
function generateApplicationToken(int $jobId, ?int $cleanerId, string $type = 'public', int $expiresMinutes = 60): string
{
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresMinutes} minutes"));

    dbInsert('application_tokens', [
        'token' => $token,
        'job_id' => $jobId,
        'cleaner_id' => $cleanerId,
        'type' => $type,
        'expires_at' => $expiresAt,
    ]);

    return $token;
}

/**
 * 応募URLを生成
 * @param string $token トークン
 * @return string 完全なURL
 */
function getApplicationUrl(string $token): string
{
    return APP_URL . '/apply?token=' . $token;
}

/**
 * 案件完了時の支払いレコードを生成
 * @param int $jobId 案件ID
 * @return int|null 生成された支払いID、既に存在する場合はnull
 */
function createPaymentForJob(int $jobId): ?int
{
    dbBegin();
    try {
        // 行ロック付きで案件を取得
        $job = dbSelectOne(
            "SELECT j.*, j.assigned_cleaner_id
             FROM cleaning_jobs j
             WHERE j.id = ? AND j.assigned_cleaner_id IS NOT NULL
             FOR UPDATE",
            [$jobId]
        );

        if (!$job) {
            dbRollback();
            return null;
        }

        // 行ロック付きで既存チェック
        $existing = dbSelectOne(
            "SELECT id FROM cleaner_payments WHERE job_id = ? AND cleaner_id = ? FOR UPDATE",
            [$jobId, $job['assigned_cleaner_id']]
        );

        if ($existing) {
            dbRollback();
            return null;
        }

        $baseAmount = (int) $job['base_reward'];
        $extensionAmount = 0; // 仕様: 延長報酬は常に0
        $totalAmount = $baseAmount;

        $paymentId = dbInsert('cleaner_payments', [
            'job_id' => $jobId,
            'cleaner_id' => $job['assigned_cleaner_id'],
            'store_id' => $job['store_id'],
            'base_amount' => $baseAmount,
            'extension_amount' => $extensionAmount,
            'total_amount' => $totalAmount,
            'status' => 'pending',
        ]);

        dbCommit();
        return $paymentId;

    } catch (Exception $e) {
        dbRollback();
        error_log("createPaymentForJob error: " . $e->getMessage());
        return null;
    }
}

/**
 * 清掃完了報告のキーワード判定
 * @param string $text メッセージテキスト
 * @return bool 完了キーワードの場合true
 */
function isCompletionKeyword(string $text): bool
{
    $normalized = trim(mb_strtolower($text));
    return (bool)preg_match('/^(完了|終了|終わりました|done)$/u', $normalized);
}

/**
 * 清掃案件を完了状態に更新（冪等性確保）
 * @param int $jobId 案件ID
 * @param int $cleanerId 清掃者ID
 * @return array ['success' => bool, 'message' => string, 'job' => array|null]
 */
function completeCleaningJob(int $jobId, int $cleanerId): array
{
    dbBegin();
    try {
        // 行ロック付きで案件を取得
        $job = dbSelectOne(
            "SELECT * FROM cleaning_jobs
             WHERE id = ? AND assigned_cleaner_id = ?
             FOR UPDATE",
            [$jobId, $cleanerId]
        );

        if (!$job) {
            dbRollback();
            return [
                'success' => false,
                'message' => '担当案件が見つかりません',
                'job' => null,
            ];
        }

        if ($job['status'] === 'completed' || $job['status'] === 'paid') {
            dbRollback();
            return [
                'success' => false,
                'message' => 'この案件は既に完了報告済みです',
                'job' => $job,
            ];
        }

        if ($job['status'] !== 'assigned') {
            dbRollback();
            return [
                'success' => false,
                'message' => 'この案件は完了できる状態ではありません',
                'job' => $job,
            ];
        }

        // 早すぎる完了報告のガード（清掃開始15分前以降のみ許可）
        $scheduledAt = strtotime($job['scheduled_at']);
        $allowedFrom = $scheduledAt - (15 * 60); // 15分前から許可
        if (time() < $allowedFrom) {
            dbRollback();
            $scheduledTime = date('H:i', $scheduledAt);
            return [
                'success' => false,
                'message' => "清掃予定時刻（{$scheduledTime}）より早すぎます。開始15分前からお報告いただけます",
                'job' => $job,
            ];
        }

        // 完了状態に更新
        $completedAt = date('Y-m-d H:i:s');
        dbUpdate('cleaning_jobs', [
            'status' => 'completed',
            'completed_at' => $completedAt,
        ], 'id = ?', [$jobId]);

        // 支払いレコード生成
        createPaymentForJob($jobId);

        dbCommit();

        // 更新後のデータを取得
        $job['status'] = 'completed';
        $job['completed_at'] = $completedAt;

        return [
            'success' => true,
            'message' => 'お疲れ様でした！清掃完了を受け付けました',
            'job' => $job,
        ];

    } catch (Exception $e) {
        dbRollback();
        error_log("completeCleaningJob error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => '完了処理中にエラーが発生しました',
            'job' => null,
        ];
    }
}

/**
 * 清掃者の当日担当案件を取得
 * @param int $cleanerId 清掃者ID
 * @param string $status 取得するステータス（デフォルト: assigned）
 * @return array 案件の配列
 */
function getCleanerTodayJobs(int $cleanerId, string $status = 'assigned'): array
{
    $today = date('Y-m-d');

    return dbSelect(
        "SELECT cj.*, sa.name as area_name, s.name as store_name
         FROM cleaning_jobs cj
         INNER JOIN sales_areas sa ON cj.sales_area_id = sa.id
         INNER JOIN stores s ON cj.store_id = s.id
         WHERE cj.assigned_cleaner_id = ?
           AND cj.status = ?
           AND DATE(cj.scheduled_at) = ?
           AND cj.deleted_at IS NULL
         ORDER BY cj.scheduled_at ASC",
        [$cleanerId, $status, $today]
    );
}

/**
 * 当日予約時の即時通知を送信
 * @param int $reservationId 予約ID
 * @param int $cleaningJobId 清掃案件ID
 * @return int 送信した通知数
 */
function sendSameDayNotification(int $reservationId, int $cleaningJobId): int
{
    // 清掃案件のステータスをrecruitingに更新
    dbUpdate('cleaning_jobs', [
        'status' => 'recruiting',
    ], 'id = ? AND status = ?', [$cleaningJobId, 'unassigned']);

    // 通知送信
    return sendJobNotifications($cleaningJobId, 'normal');
}
