<?php
/**
 * LINE Webhook エンドポイント（清掃者用）
 *
 * 処理するイベント:
 * - follow: 友だち追加 → 初回登録フロー開始
 * - message: メッセージ受信 → 状態に応じた処理
 * - postback: ボタン押下 → アクション実行
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/line_helpers.php';
require_once __DIR__ . '/../../includes/audit_helpers.php';

// DBから清掃者用LINE設定を取得
$lineAccount = dbSelectOne(
    "SELECT channel_secret, channel_access_token FROM line_accounts
     WHERE account_type = 'cleaner' AND is_active = 1 AND deleted_at IS NULL"
);

if (!$lineAccount || empty($lineAccount['channel_secret'])) {
    error_log('LINE Webhook: Cleaner account not configured');
    http_response_code(500);
    exit('LINE account not configured');
}

$channelSecret = $lineAccount['channel_secret'];
$channelAccessToken = $lineAccount['channel_access_token'];

// レート制限（IP単位: 60リクエスト/分）
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimit = checkRateLimit('webhook_cleaner_' . md5($clientIp), 60, 60);

if (!$rateLimit['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . $rateLimit['retry_after']);
    exit('Rate limit exceeded');
}

// 署名検証
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
$body = file_get_contents('php://input');

$hash = hash_hmac('sha256', $body, $channelSecret, true);
$expectedSignature = base64_encode($hash);

if (!hash_equals($expectedSignature, $signature)) {
    // セキュリティ: 署名の一部をログに出力しない（漏洩防止）
    error_log(sprintf(
        'LINE Webhook signature verification failed: IP=%s, Content-Length=%d',
        $clientIp,
        strlen($body)
    ));
    http_response_code(400);
    exit;
}

// リクエスト解析
$events = json_decode($body, true);

if (!$events || empty($events['events'])) {
    jsonResponse(['status' => 'ok']);
}

foreach ($events['events'] as $event) {
    try {
        // 冪等性チェック（重複イベント検出）
        $eventId = $event['webhookEventId'] ?? null;
        if ($eventId && isWebhookEventProcessed($eventId)) {
            continue; // 処理済みならスキップ
        }

        $type = $event['type'] ?? '';
        $userId = $event['source']['userId'] ?? '';
        $replyToken = $event['replyToken'] ?? '';

        if (empty($userId)) {
            continue;
        }

        switch ($type) {
            case 'follow':
                handleFollowEvent($userId, $replyToken, $channelAccessToken);
                break;

            case 'message':
                handleMessageEvent($userId, $replyToken, $event['message'] ?? [], $channelAccessToken);
                break;

            case 'postback':
                handlePostbackEvent($userId, $replyToken, $event['postback'] ?? [], $channelAccessToken);
                break;
        }

        // 処理済みとして記録
        if ($eventId) {
            markWebhookEventProcessed($eventId, $type, 'cleaner');
        }

    } catch (Exception $e) {
        error_log("LINE Webhook (cleaner) error: " . $e->getMessage());
    }
}

jsonResponse(['status' => 'ok']);

/**
 * 友だち追加イベント処理
 */
function handleFollowEvent(string $userId, string $replyToken, string $accessToken): void
{
    // 既存登録チェック
    $cleaner = dbSelectOne(
        "SELECT id, name, registration_status FROM cleaners WHERE line_user_id = ? AND deleted_at IS NULL",
        [$userId]
    );

    if ($cleaner && $cleaner['registration_status'] === 'completed') {
        // 既に登録済み
        sendLineReplyOrPush($replyToken, [
            'type' => 'text',
            'text' => "おかえりなさい、{$cleaner['name']}さん！\n\n清掃案件が届いた際はこちらでお知らせしますね。",
        ], $accessToken, $userId);
        return;
    }

    // 新規登録開始
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

    if ($cleaner) {
        // 途中の登録を更新
        dbUpdate('cleaners', [
            'registration_token' => $token,
            'registration_token_expires_at' => $expiresAt,
        ], 'id = ?', [$cleaner['id']]);
    } else {
        // 新規作成（仮登録状態）
        dbInsert('cleaners', [
            'line_user_id' => $userId,
            'name' => '',
            'is_active' => 0,
            'registration_status' => 'pending',
            'registration_token' => $token,
            'registration_token_expires_at' => $expiresAt,
        ]);
    }

    $registerUrl = APP_URL . '/register?token=' . $token;

    sendLineReplyOrPush($replyToken, [
        [
            'type' => 'text',
            'text' => "はじめまして！カクレマ清掃スタッフへようこそ。\n\n登録を完了して、清掃のお仕事を始めましょう！",
        ],
        [
            'type' => 'template',
            'altText' => '清掃スタッフ登録',
            'template' => [
                'type' => 'buttons',
                'text' => "下のボタンから登録を進めてください。",
                'actions' => [
                    [
                        'type' => 'uri',
                        'label' => '登録を始める',
                        'uri' => $registerUrl,
                    ],
                ],
            ],
        ],
    ], $accessToken, $userId);
}

/**
 * メッセージイベント処理
 */
function handleMessageEvent(string $userId, string $replyToken, array $message, string $accessToken): void
{
    $text = $message['text'] ?? '';

    // 清掃者を検索
    $cleaner = dbSelectOne(
        "SELECT id, name, registration_status, registration_token, registration_token_expires_at FROM cleaners WHERE line_user_id = ? AND deleted_at IS NULL",
        [$userId]
    );

    // 未登録の場合
    if (!$cleaner || $cleaner['registration_status'] !== 'completed') {
        // 有効期限内の既存トークンがあれば再利用（トークンスパム防止）
        $existingToken = null;
        if ($cleaner && !empty($cleaner['registration_token']) && !empty($cleaner['registration_token_expires_at'])) {
            if (strtotime($cleaner['registration_token_expires_at']) > time()) {
                $existingToken = $cleaner['registration_token'];
            }
        }

        if ($existingToken) {
            $token = $existingToken;
        } else {
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            if ($cleaner) {
                dbUpdate('cleaners', [
                    'registration_token' => $token,
                    'registration_token_expires_at' => $expiresAt,
                ], 'id = ?', [$cleaner['id']]);
            } else {
                dbInsert('cleaners', [
                    'line_user_id' => $userId,
                    'name' => '',
                    'is_active' => 0,
                    'registration_status' => 'pending',
                    'registration_token' => $token,
                    'registration_token_expires_at' => $expiresAt,
                ]);
            }
        }

        $registerUrl = APP_URL . '/register?token=' . $token;

        sendLineReplyOrPush($replyToken, [
            'type' => 'template',
            'altText' => '清掃スタッフ登録',
            'template' => [
                'type' => 'buttons',
                'text' => "まずは登録を完了してください。",
                'actions' => [
                    [
                        'type' => 'uri',
                        'label' => '登録を始める',
                        'uri' => $registerUrl,
                    ],
                ],
            ],
        ], $accessToken, $userId);
        return;
    }

    // iPassコードの確認（セキュア版：ワンタイムトークン方式）
    if (mb_strtolower($text) === 'ipass' || $text === 'コード') {
        $cleanerData = dbSelectOne(
            "SELECT id, ipass_code FROM cleaners WHERE id = ?",
            [$cleaner['id']]
        );

        if ($cleanerData && $cleanerData['ipass_code']) {
            // ワンタイムトークンを生成（5分間有効）
            $viewToken = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            // 既存の未使用トークンを無効化
            dbExecute(
                "UPDATE ipass_view_tokens SET used_at = NOW() WHERE cleaner_id = ? AND used_at IS NULL",
                [$cleanerData['id']]
            );

            // 新しいトークンを発行
            dbInsert('ipass_view_tokens', [
                'token' => $viewToken,
                'cleaner_id' => $cleanerData['id'],
                'expires_at' => $expiresAt,
            ]);

            $viewUrl = APP_URL . '/ipass/view?token=' . $viewToken;

            sendLineReplyOrPush($replyToken, [
                [
                    'type' => 'text',
                    'text' => "iPassコードを確認するには、下のボタンをタップしてください。\n\nセキュリティのため、リンクは5分間のみ有効です。",
                ],
                [
                    'type' => 'template',
                    'altText' => 'iPassコード確認',
                    'template' => [
                        'type' => 'buttons',
                        'text' => "iPassコードを表示",
                        'actions' => [
                            [
                                'type' => 'uri',
                                'label' => 'コードを表示',
                                'uri' => $viewUrl,
                            ],
                        ],
                    ],
                ],
            ], $accessToken, $userId);
        } else {
            sendLineReplyOrPush($replyToken, [
                'type' => 'text',
                'text' => "iPassコードが見つかりません。\n管理者にお問い合わせください。",
            ], $accessToken, $userId);
        }
        return;
    }

    // 清掃完了報告
    if (isCompletionKeyword($text)) {
        handleCompletionReport($userId, $replyToken, $cleaner, $accessToken);
        return;
    }

    // ヘルプ
    if ($text === 'ヘルプ' || $text === 'help' || $text === '?') {
        sendLineReplyOrPush($replyToken, [
            'type' => 'text',
            'text' => "{$cleaner['name']}さん、お疲れ様です！\n\n【使い方】\n・案件通知が届いたら、URLをタップして応募\n・「ipass」と送信でiPassコードを確認\n・「完了」と送信で清掃完了報告\n\n何かあればサポートまでご連絡ください。",
        ], $accessToken, $userId);
        return;
    }

    // デフォルト応答
    sendLineReplyOrPush($replyToken, [
        'type' => 'text',
        'text' => "{$cleaner['name']}さん、メッセージありがとうございます。\n\n清掃案件が届いた際はこちらでお知らせしますね。\n\n「ヘルプ」と送信すると使い方を確認できます。",
    ], $accessToken, $userId);
}

/**
 * ポストバックイベント処理
 */
function handlePostbackEvent(string $userId, string $replyToken, array $postback, string $accessToken): void
{
    $data = [];
    parse_str($postback['data'] ?? '', $data);
    $action = $data['action'] ?? '';

    // 清掃者を検索
    $cleaner = dbSelectOne(
        "SELECT id, name FROM cleaners WHERE line_user_id = ? AND registration_status = 'completed' AND deleted_at IS NULL",
        [$userId]
    );

    if (!$cleaner) {
        sendLineReplyOrPush($replyToken, [
            'type' => 'text',
            'text' => "登録が完了していないようです。\nまずは登録を完了してください。",
        ], $accessToken, $userId);
        return;
    }

    switch ($action) {
        case 'check_jobs':
            // 現在の募集中案件を確認
            $jobs = dbSelect(
                "SELECT j.id, sa.name as area_name, j.scheduled_at, j.base_reward
                 FROM cleaning_jobs j
                 INNER JOIN sales_areas sa ON j.sales_area_id = sa.id
                 INNER JOIN cleaner_stores cs ON j.store_id = cs.store_id
                 WHERE cs.cleaner_id = ?
                   AND j.status IN ('unassigned', 'recruiting')
                   AND j.assigned_cleaner_id IS NULL
                   AND j.scheduled_at > NOW()
                 ORDER BY j.scheduled_at
                 LIMIT 5",
                [$cleaner['id']]
            );

            if (empty($jobs)) {
                sendLineReplyOrPush($replyToken, [
                    'type' => 'text',
                    'text' => "現在、募集中の案件はありません。\n\n新しい案件が登録されたらお知らせしますね！",
                ], $accessToken, $userId);
            } else {
                $text = "【募集中の案件】\n\n";
                foreach ($jobs as $job) {
                    $date = formatDate($job['scheduled_at'], 'n/j H:i');
                    $text .= "・{$job['area_name']}\n  {$date}〜 / " . number_format($job['base_reward']) . "円\n\n";
                }
                $text .= "詳細は通知メッセージのURLからご確認ください。";
                sendLineReplyOrPush($replyToken, [
                    'type' => 'text',
                    'text' => $text,
                ], $accessToken, $userId);
            }
            break;

        case 'complete_job':
            // 清掃完了報告（postback経由）
            $jobId = (int)($data['job_id'] ?? 0);
            if ($jobId <= 0) {
                sendLineReplyOrPush($replyToken, [
                    'type' => 'text',
                    'text' => "案件の指定が不正です。",
                ], $accessToken, $userId);
                break;
            }

            // 案件情報を取得
            $job = dbSelectOne(
                "SELECT cj.*, sa.name as area_name
                 FROM cleaning_jobs cj
                 INNER JOIN sales_areas sa ON cj.sales_area_id = sa.id
                 WHERE cj.id = ?",
                [$jobId]
            );

            if (!$job) {
                sendLineReplyOrPush($replyToken, [
                    'type' => 'text',
                    'text' => "案件が見つかりません。",
                ], $accessToken, $userId);
                break;
            }

            // 完了処理
            $result = completeCleaningJob($jobId, $cleaner['id']);

            if ($result['success']) {
                $time = date('H:i', strtotime($job['scheduled_at']));
                sendLineReplyOrPush($replyToken, [
                    'type' => 'text',
                    'text' => "お疲れ様でした！\n\n📍 {$job['area_name']}\n⏰ {$time}〜\n\n清掃完了を受け付けました。\n報酬は次回の支払い日にお支払いします。",
                ], $accessToken, $userId);
            } else {
                sendLineReplyOrPush($replyToken, [
                    'type' => 'text',
                    'text' => $result['message'],
                ], $accessToken, $userId);
            }
            break;

        default:
            sendLineReplyOrPush($replyToken, [
                'type' => 'text',
                'text' => "不明な操作です。",
            ], $accessToken, $userId);
    }
}

/**
 * 清掃完了報告処理
 */
function handleCompletionReport(string $userId, string $replyToken, array $cleaner, string $accessToken): void
{
    // 当日の担当案件（assigned状態）を取得
    $jobs = getCleanerTodayJobs($cleaner['id'], 'assigned');

    if (empty($jobs)) {
        sendLineReplyOrPush($replyToken, [
            'type' => 'text',
            'text' => "現在、完了報告が可能な担当案件がありません。\n\n担当が確定している案件のみ完了報告できます。",
        ], $accessToken, $userId);
        return;
    }

    if (count($jobs) === 1) {
        // 1件のみ: 直接完了処理
        $job = $jobs[0];
        $result = completeCleaningJob($job['id'], $cleaner['id']);

        if ($result['success']) {
            sendLineReplyOrPush($replyToken, [
                'type' => 'text',
                'text' => "お疲れ様でした！\n\n📍 {$job['area_name']}\n⏰ " . date('H:i', strtotime($job['scheduled_at'])) . "〜\n\n清掃完了を受け付けました。\n報酬は次回の支払い日にお支払いします。",
            ], $accessToken, $userId);
        } else {
            sendLineReplyOrPush($replyToken, [
                'type' => 'text',
                'text' => $result['message'],
            ], $accessToken, $userId);
        }
    } else {
        // 複数件: クイックリプライで選択させる
        $quickReplyItems = [];
        foreach ($jobs as $job) {
            $time = date('H:i', strtotime($job['scheduled_at']));
            $quickReplyItems[] = [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => "{$time} {$job['area_name']}",
                    'data' => "action=complete_job&job_id={$job['id']}",
                    'displayText' => "{$time} {$job['area_name']}の完了報告",
                ],
            ];
        }

        sendLineReplyOrPush($replyToken, [
            [
                'type' => 'text',
                'text' => "本日の担当案件が" . count($jobs) . "件あります。\nどの案件の完了報告ですか？",
                'quickReply' => [
                    'items' => $quickReplyItems,
                ],
            ],
        ], $accessToken, $userId);
    }
}

