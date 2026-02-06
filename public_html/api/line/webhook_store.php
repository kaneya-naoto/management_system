<?php
/**
 * 店舗用 LINE Webhook エンドポイント
 * POST /api/line/webhook/store/{store_code}
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/line_helpers.php';
require_once __DIR__ . '/../../includes/audit_helpers.php';

// POST以外は拒否
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonErrorResponse('Method Not Allowed', 405);
}

// レート制限（IP単位: 60リクエスト/分）
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimit = checkRateLimit('webhook_store_' . md5($clientIp), 60, 60);

if (!$rateLimit['allowed']) {
    header('Retry-After: ' . $rateLimit['retry_after']);
    jsonErrorResponse('Rate limit exceeded', 429);
}

// 店舗コード取得（ルーターから設定される想定、なければURIから抽出）
$storeCode = $storeCode ?? null;
if (!$storeCode) {
    $uri = $_SERVER['REQUEST_URI'];
    if (preg_match('#/api/line/webhook/store/([^/?]+)#', $uri, $matches)) {
        $storeCode = $matches[1];
    }
}

if (!$storeCode) {
    jsonErrorResponse('Store code is required', 400);
}

// 店舗情報取得
$store = dbSelectOne(
    "SELECT * FROM stores WHERE code = ? AND is_active = 1 AND deleted_at IS NULL",
    [$storeCode]
);

if (!$store) {
    jsonErrorResponse('Store not found', 404);
}

// LINE設定取得
$lineConfig = getStoreLineConfig($store['id']);

if (!$lineConfig) {
    jsonErrorResponse('LINE configuration not found for this store', 404);
}

// Channel Secret未設定の場合は拒否（署名検証ができないため）
if (empty($lineConfig['channel_secret'])) {
    error_log("LINE Webhook rejected: Channel secret not configured for store: {$storeCode}");
    jsonErrorResponse('LINE configuration incomplete', 500);
}

// リクエストボディ取得
$body = file_get_contents('php://input');

// 署名検証
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
if (empty($signature)) {
    jsonErrorResponse('Missing signature', 400);
}

if (!verifyLineSignature($body, $signature, $lineConfig['channel_secret'])) {
    error_log("LINE Webhook signature verification failed for store: {$storeCode}");
    jsonErrorResponse('Invalid signature', 401);
}

// イベント処理
$events = json_decode($body, true);
if (!isset($events['events']) || !is_array($events['events'])) {
    jsonResponse(['status' => 'ok']);
}

foreach ($events['events'] as $event) {
    try {
        // 冪等性チェック
        $eventId = $event['webhookEventId'] ?? null;
        if ($eventId && isWebhookEventProcessed($eventId)) {
            continue; // 処理済みならスキップ
        }

        $eventType = $event['type'] ?? '';
        $replyToken = $event['replyToken'] ?? null;

        switch ($eventType) {
            case 'follow':
                // 友だち追加時
                handleFollowEvent($event, $lineConfig, $store);
                break;

            case 'unfollow':
                // ブロック時
                handleUnfollowEvent($event, $lineConfig, $store);
                break;

            case 'message':
                // メッセージ受信時
                handleMessageEvent($event, $lineConfig, $store);
                break;

            case 'postback':
                // ポストバック
                handlePostbackEvent($event, $lineConfig, $store);
                break;

            default:
                // その他のイベントはログのみ
                error_log("Unhandled LINE event type: {$eventType} for store: {$storeCode}");
        }

        // 処理済みとして記録
        if ($eventId) {
            markWebhookEventProcessed($eventId, $eventType, 'store', $store['id']);
        }

    } catch (Exception $e) {
        error_log("LINE Webhook error for store {$storeCode}: " . $e->getMessage());
    }
}

jsonResponse(['status' => 'ok']);

/**
 * 友だち追加イベント処理
 */
function handleFollowEvent(array $event, array $lineConfig, array $store): void
{
    $userId = $event['source']['userId'] ?? null;
    if (!$userId) return;

    $replyToken = $event['replyToken'] ?? null;
    if (!$replyToken) return;

    // ウェルカムメッセージ送信
    $messages = [
        [
            'type' => 'text',
            'text' => $store['name'] . "の公式LINEをご登録いただきありがとうございます！\n\nこちらからご予約いただけます。"
        ]
    ];

    // LIFF IDがある場合は予約ボタンを追加
    if (!empty($lineConfig['liff_id'])) {
        $messages[] = [
            'type' => 'template',
            'altText' => 'ご予約はこちら',
            'template' => [
                'type' => 'buttons',
                'text' => 'ご予約はこちらから',
                'actions' => [
                    [
                        'type' => 'uri',
                        'label' => '予約する',
                        'uri' => 'https://liff.line.me/' . $lineConfig['liff_id']
                    ]
                ]
            ]
        ];
    }

    sendLineReplyMessage($replyToken, $messages, $lineConfig['channel_access_token']);
}

/**
 * ブロックイベント処理
 */
function handleUnfollowEvent(array $event, array $lineConfig, array $store): void
{
    $userId = $event['source']['userId'] ?? null;
    if (!$userId) return;

    // ログ記録のみ
    error_log("LINE user unfollowed store {$store['code']}: {$userId}");
}

/**
 * メッセージイベント処理
 */
function handleMessageEvent(array $event, array $lineConfig, array $store): void
{
    $userId = $event['source']['userId'] ?? null;
    $replyToken = $event['replyToken'] ?? null;
    $message = $event['message'] ?? [];

    if (!$userId || !$replyToken) return;

    $messageType = $message['type'] ?? '';
    $text = $message['text'] ?? '';

    // テキストメッセージの場合のみ処理
    if ($messageType !== 'text') {
        return;
    }

    // キーワード応答
    $response = null;

    if (mb_strpos($text, '予約') !== false) {
        if (!empty($lineConfig['liff_id'])) {
            $response = [
                [
                    'type' => 'template',
                    'altText' => 'ご予約はこちら',
                    'template' => [
                        'type' => 'buttons',
                        'text' => 'ご予約はこちらから',
                        'actions' => [
                            [
                                'type' => 'uri',
                                'label' => '予約する',
                                'uri' => 'https://liff.line.me/' . $lineConfig['liff_id']
                            ]
                        ]
                    ]
                ]
            ];
        } else {
            $response = [
                [
                    'type' => 'text',
                    'text' => "ご予約は以下のURLからお願いいたします。\n" . APP_URL . '/booking/' . $store['code']
                ]
            ];
        }
    } elseif (mb_strpos($text, '場所') !== false || mb_strpos($text, 'アクセス') !== false || mb_strpos($text, '住所') !== false) {
        $address = $store['address'] ?? '住所情報がありません';
        $response = [
            [
                'type' => 'text',
                'text' => "【" . $store['name'] . "】\n\n住所: " . $address
            ]
        ];
    } elseif (mb_strpos($text, '営業') !== false || mb_strpos($text, '時間') !== false) {
        $hours = '営業時間情報がありません';
        if (!empty($store['is_24h_open'])) {
            $hours = '24時間営業';
        } elseif (!empty($store['opening_time']) && !empty($store['closing_time'])) {
            $opening = substr($store['opening_time'], 0, 5);
            $closing = $store['closing_time'] === '00:00:00' ? '24:00' : substr($store['closing_time'], 0, 5);
            $hours = $opening . ' 〜 ' . $closing;
        }
        $response = [
            [
                'type' => 'text',
                'text' => "【" . $store['name'] . "】\n\n営業時間: " . $hours
            ]
        ];
    }

    // 応答があれば送信
    if ($response) {
        sendLineReplyMessage($replyToken, $response, $lineConfig['channel_access_token']);
    }
}

/**
 * ポストバックイベント処理
 */
function handlePostbackEvent(array $event, array $lineConfig, array $store): void
{
    $userId = $event['source']['userId'] ?? null;
    $replyToken = $event['replyToken'] ?? null;
    $data = $event['postback']['data'] ?? '';

    if (!$userId || !$replyToken || !$data) return;

    // データをパース（key=value&key2=value2形式）
    parse_str($data, $params);
    $action = $params['action'] ?? '';

    switch ($action) {
        case 'booking':
            // 予約アクション
            if (!empty($lineConfig['liff_id'])) {
                $messages = [
                    [
                        'type' => 'template',
                        'altText' => 'ご予約はこちら',
                        'template' => [
                            'type' => 'buttons',
                            'text' => 'ご予約はこちらから',
                            'actions' => [
                                [
                                    'type' => 'uri',
                                    'label' => '予約する',
                                    'uri' => 'https://liff.line.me/' . $lineConfig['liff_id']
                                ]
                            ]
                        ]
                    ]
                ];
                sendLineReplyMessage($replyToken, $messages, $lineConfig['channel_access_token']);
            }
            break;

        default:
            error_log("Unknown postback action: {$action} for store: {$store['code']}");
    }
}
