<?php
/**
 * アプリケーション設定
 */

// .envファイルの読み込み（存在する場合）
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);

        // 空行または行頭コメントをスキップ
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // KEY=VALUE 形式をパース
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // クォートで囲まれている場合は除去
            if (strlen($value) >= 2) {
                $firstChar = $value[0];
                $lastChar = $value[strlen($value) - 1];
                if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
                    $value = substr($value, 1, -1);
                } else {
                    // クォートなしの場合、行末#コメントを除去
                    // ただし#が値に含まれる可能性があるため、スペース+#の場合のみ
                    $commentPos = strpos($value, ' #');
                    if ($commentPos !== false) {
                        $value = trim(substr($value, 0, $commentPos));
                    }
                }
            }

            // 既存の環境変数を上書きしない
            if (getenv($key) === false) {
                putenv("$key=$value");
            }
        }
    }
}

// 環境変数から取得（.envファイルまたはサーバー設定で設定）
define('DEBUG_MODE', getenv('APP_DEBUG') === 'true');

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    // 本番環境のみ: 未キャッチ例外をユーザーに見せない
    set_exception_handler(function (Throwable $e) {
        error_log('[UncaughtException] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) {
            http_response_code(500);
            // APIリクエストの場合はJSONで返す
            $isApi = isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') !== false;
            $acceptJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
            if ($isApi || $acceptJson) {
                header('Content-Type: application/json; charset=utf-8');
                while (ob_get_level() > 0) { ob_end_clean(); }
                echo json_encode(['error' => 'Internal Server Error'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            header('Content-Type: text/html; charset=UTF-8');
        }
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo 'システムエラーが発生しました。管理者にお問い合わせください。';
        exit;
    });
}

// タイムゾーン
date_default_timezone_set('Asia/Tokyo');

// HTTPS判定（ロードバランサー経由も考慮）
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

// セッション設定
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $isHttps ? '1' : '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');

// アプリケーション設定
define('APP_NAME', 'カクレマ管理システム');

// APP_URL: プロトコルが省略されている場合は https:// を自動付与
$appUrl = getenv('APP_URL') ?: 'https://example.com';
if ($appUrl !== '' && !preg_match('#^https?://#', $appUrl)) {
    $appUrl = 'https://' . $appUrl;
}
define('APP_URL', rtrim($appUrl, '/'));

// データベース設定（環境変数から取得）
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'kakurema_db');
define('DB_USER', getenv('DB_USER') ?: 'kakurema_user');

// DB_PASS: 本番環境では必須
$dbPass = getenv('DB_PASS');
if ($dbPass === false && !DEBUG_MODE) {
    http_response_code(500);
    exit('Server configuration error: DB_PASS is not set');
}
define('DB_PASS', $dbPass ?: '');
define('DB_CHARSET', 'utf8mb4');

// LINE設定: line_accounts テーブルに移行済み（店舗単位で管理）

// パスワードハッシュ設定
define('PASSWORD_COST', 12);

// セッション有効期限（秒）
define('SESSION_LIFETIME', 28800); // 8時間

// CSRF設定
define('CSRF_TOKEN_NAME', '_token');
define('CSRF_TOKEN_LIFETIME', 3600); // 1時間
define('CSRF_TOKEN_LENGTH', 64); // 16進数文字列長

// ログイン試行制限
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15分

// 清掃案件設定
define('DEFAULT_CLEANING_REWARD', 2000); // デフォルト基本報酬（円）
define('EXTENSION_PRICE_PER_HOUR', 1000); // 延長1時間あたりの顧客料金（円）
define('MAX_EXTENSION_HOURS', 5.0); // 最大延長時間（時間）
define('CLEANING_TIME_MINUTES', 60); // 清掃時間（分）- 予約間に必要な時間

// 予約設定（デフォルト値）
define('DEFAULT_HOURLY_RATE', 2500); // デフォルト時間単価（円）
define('DEFAULT_CAPACITY', 4); // デフォルト定員（名）
define('MAX_BOOKING_DURATION_HOURS', 8); // 最大予約時間
define('MIN_BOOKING_DURATION_HOURS', 1); // 最小予約時間
