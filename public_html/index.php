<?php
/**
 * エントリーポイント（シンプルルーティング）
 */

// 共通ファイル読み込み
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// セッション開始
startSession();

// ベースパス取得（サブディレクトリ対応）
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$basePath = ($scriptDir === '/' || $scriptDir === '\\') ? '' : $scriptDir;

// リクエストパス取得（ベースパスを除去）
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($basePath && strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}
$path = rtrim($path, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

// ベースパスを定数化（テンプレートで使用）
define('BASE_PATH', $basePath);

// ルーティング
switch ($path) {
    // 認証
    case '/':
    case '/login':
        require __DIR__ . '/pages/login.php';
        break;

    case '/forgot-password':
        require __DIR__ . '/pages/forgot-password.php';
        break;

    case '/reset-password':
        require __DIR__ . '/pages/reset-password.php';
        break;

    case '/logout':
        // POSTメソッドとCSRF検証必須
        if ($method !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }
        requireCsrf();
        logout();
        redirect('/login');
        break;

    case '/switch-store':
        // 店舗切り替え（POSTメソッドとCSRF検証必須）
        if ($method !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }
        requireLogin();
        requireCsrf();

        // 店舗ID取得（空文字は「全店舗」）
        $storeId = isset($_POST['store_id']) && $_POST['store_id'] !== ''
            ? (int)$_POST['store_id']
            : null;

        // セッションに保存
        if (!setSelectedStore($storeId)) {
            setFlash('error', 'この店舗へのアクセス権限がありません');
        }

        // リダイレクト先を安全に取得（Open Redirect対策）
        $referer = $_POST['redirect'] ?? $_SERVER['HTTP_REFERER'] ?? '/dashboard';
        $parsedUrl = parse_url($referer);
        $redirectPath = '/dashboard'; // デフォルト値

        // パス部分のみを抽出し、安全性を検証
        if (isset($parsedUrl['path'])) {
            $path = $parsedUrl['path'];

            // ベースパスを除去（サブディレクトリ対応）
            if ($basePath && strpos($path, $basePath) === 0) {
                $path = substr($path, strlen($basePath));
            }

            // 相対パスであることを確認（/ で始まり // ではない = 外部URLではない）
            if (strpos($path, '/') === 0 && strpos($path, '//') !== 0) {
                $redirectPath = $path;

                // クエリパラメータを処理（store_idを除去）
                if (!empty($parsedUrl['query'])) {
                    parse_str($parsedUrl['query'], $queryParams);
                    unset($queryParams['store_id']);
                    if (!empty($queryParams)) {
                        $redirectPath .= '?' . http_build_query($queryParams);
                    }
                }
            }
        }

        redirect($redirectPath);
        break;

    // ダッシュボード
    case '/dashboard':
        requireLogin();
        require __DIR__ . '/pages/dashboard.php';
        break;

    // 予約管理
    case '/reservations':
        requireLogin();
        require __DIR__ . '/pages/reservations/list.php';
        break;

    case '/reservations/new':
        requireLogin();
        require __DIR__ . '/pages/reservations/create.php';
        break;

    case '/reservations/calendar':
        requireLogin();
        require __DIR__ . '/pages/reservations/calendar.php';
        break;

    case '/reservations/gantt':
        requireLogin();
        require __DIR__ . '/pages/reservations/gantt.php';
        break;

    // 清掃案件
    case '/jobs':
        requireLogin();
        require __DIR__ . '/pages/jobs/list.php';
        break;

    // 清掃者管理
    case '/cleaners':
        requireLogin();
        require __DIR__ . '/pages/cleaners/list.php';
        break;

    // 鍵番号管理
    case '/keys':
        requireLogin();
        require __DIR__ . '/pages/keys/list.php';
        break;

    case '/keys/new':
        requireLogin();
        require __DIR__ . '/pages/keys/create.php';
        break;

    // 設定
    case '/settings':
        requireLogin();
        requireRole('OWNER');
        require __DIR__ . '/pages/settings/index.php';
        break;

    // API: LINE Webhook（清掃者用）
    case '/api/line/webhook':
        require __DIR__ . '/api/line/webhook.php';
        break;

    // API: LINE Config（LIFF用）
    case '/api/line/config':
        require __DIR__ . '/api/line/config.php';
        break;

    // API: 予約空き状況チェック
    case '/api/booking/check-availability':
        require __DIR__ . '/api/booking/check-availability.php';
        break;

    // API: CSVエクスポート
    case '/api/export/payments':
        requireLogin();
        require __DIR__ . '/api/export/payments.php';
        break;

    case '/api/export/reservations':
        requireLogin();
        require __DIR__ . '/api/export/reservations.php';
        break;

    case '/api/reservations/calendar-data':
        requireLogin();
        require __DIR__ . '/api/reservations/calendar-data.php';
        break;

    // API: 固定者追加用 - 利用可能な清掃者取得
    case '/api/settings/available-cleaners':
        requireLogin();
        requireRole('OWNER');
        require __DIR__ . '/api/settings/available-cleaners.php';
        break;

    // 公開予約フォーム（認証不要）
    case '/booking':
        require __DIR__ . '/pages/booking/index.php';
        break;

    case '/booking/customer':
        require __DIR__ . '/pages/booking/customer.php';
        break;

    case '/booking/confirm':
        require __DIR__ . '/pages/booking/confirm.php';
        break;

    case '/booking/complete':
        require __DIR__ . '/pages/booking/complete.php';
        break;

    case '/booking/thanks':
        require __DIR__ . '/pages/booking/thanks.php';
        break;

    // 固定者管理
    case '/settings/fixed-cleaners':
        requireLogin();
        requireRole('OWNER');
        require __DIR__ . '/pages/settings/fixed-cleaners.php';
        break;

    // 店舗設定
    case '/settings/store':
        requireLogin();
        requireRole('OWNER');
        require __DIR__ . '/pages/settings/store.php';
        break;

    // ユーザー管理
    case '/settings/users':
        requireLogin();
        requireRole('OWNER');
        require __DIR__ . '/pages/settings/users.php';
        break;

    // LINE連携設定
    case '/settings/line':
        requireLogin();
        requireRole('OWNER');
        require __DIR__ . '/pages/settings/line.php';
        break;

    case '/settings/line/richmenu':
        requireLogin();
        requireRole('OWNER');
        require __DIR__ . '/pages/settings/line_richmenu.php';
        break;

    // オーナー管理（HQ専用）
    case '/settings/owners':
        requireLogin();
        requireRole('HQ');
        require __DIR__ . '/pages/settings/owners.php';
        break;

    // 店舗管理（HQ専用）
    case '/settings/stores':
        requireLogin();
        requireRole('HQ');
        require __DIR__ . '/pages/settings/stores.php';
        break;

    // 清掃者新規登録（OWNER以上）
    case '/cleaners/new':
        requireLogin();
        requireRole('OWNER');
        require __DIR__ . '/pages/cleaners/create.php';
        break;

    // 支払い管理
    case '/payments':
        requireLogin();
        require __DIR__ . '/pages/payments/list.php';
        break;

    // iPassコード閲覧（公開・ワンタイムトークン認証）
    case '/ipass/view':
        require __DIR__ . '/pages/ipass/view.php';
        break;

    // LINE応募画面（公開）
    case '/apply':
        require __DIR__ . '/pages/apply/index.php';
        break;

    // 清掃者向け案件一覧（公開・トークン/セッション認証）
    case '/apply/list':
        require __DIR__ . '/pages/apply/list.php';
        break;

    // 延長申請（公開）
    case '/extend':
        require __DIR__ . '/pages/extend/index.php';
        break;

    case '/extend/respond':
        require __DIR__ . '/pages/extend/respond.php';
        break;

    // 延長申請（部屋コード方式）はdefaultセクションで処理

    // LINE清掃者登録（公開）
    case '/register':
        require __DIR__ . '/pages/register/index.php';
        break;

    case '/register/stores':
        require __DIR__ . '/pages/register/stores.php';
        break;

    // シフト管理
    case '/shifts':
        requireLogin();
        require __DIR__ . '/pages/shifts/list.php';
        break;

    // 操作ログ
    case '/logs':
        requireLogin();
        requireRole('OWNER');
        require __DIR__ . '/pages/logs/list.php';
        break;

    // 動的ルート（パラメータ付き）
    default:
        // 店舗コード付き予約フォーム: /booking/{STORE_CODE}
        if (preg_match('#^/booking/([A-Za-z0-9_-]+)$#', $path, $matches)) {
            // customer, confirm, complete, thanks は除外
            $segment = strtolower($matches[1]);
            if (!in_array($segment, ['customer', 'confirm', 'complete', 'thanks'])) {
                $storeCode = strtoupper($matches[1]);
                require __DIR__ . '/pages/booking/index.php';
                break;
            }
        }

        // 店舗コード付き各ステップ: /booking/{STORE_CODE}/{step}
        if (preg_match('#^/booking/([A-Za-z0-9_-]+)/(customer|confirm|complete|thanks)$#', $path, $matches)) {
            $storeCode = strtoupper($matches[1]);
            $step = $matches[2];
            require __DIR__ . '/pages/booking/' . $step . '.php';
            break;
        }

        // 予約詳細: /reservations/{id}
        if (preg_match('#^/reservations/(\d+)$#', $path, $matches)) {
            requireLogin();
            $reservationId = (int) $matches[1];
            require __DIR__ . '/pages/reservations/detail.php';
            break;
        }

        // 清掃案件詳細: /jobs/{id}
        if (preg_match('#^/jobs/(\d+)$#', $path, $matches)) {
            requireLogin();
            $jobId = (int) $matches[1];
            require __DIR__ . '/pages/jobs/detail.php';
            break;
        }

        // 清掃者詳細: /cleaners/{id}
        if (preg_match('#^/cleaners/(\d+)$#', $path, $matches)) {
            requireLogin();
            $cleanerId = (int) $matches[1];
            require __DIR__ . '/pages/cleaners/detail.php';
            break;
        }

        // 店舗用LINE Webhook: /api/line/webhook/store/{store_code}
        if (preg_match('#^/api/line/webhook/store/([A-Za-z0-9_-]+)$#', $path, $matches)) {
            $storeCode = strtoupper($matches[1]);
            require __DIR__ . '/api/line/webhook_store.php';
            break;
        }

        // 鍵詳細: /keys/{id}
        if (preg_match('#^/keys/(\d+)$#', $path, $matches)) {
            requireLogin();
            $keyId = (int) $matches[1];
            require __DIR__ . '/pages/keys/detail.php';
            break;
        }

        // 支払い詳細: /payments/{id}
        if (preg_match('#^/payments/(\d+)$#', $path, $matches)) {
            requireLogin();
            $paymentId = (int) $matches[1];
            require __DIR__ . '/pages/payments/detail.php';
            break;
        }

        // 延長申請（部屋コード方式）: /extend/room/{room_code}
        // セキュリティ: 8-32文字の長さ制限でDoS攻撃を防止
        if (preg_match('#^/extend/room/([a-z0-9]{8,32})$#i', $path, $matches)) {
            $roomCode = strtolower($matches[1]);
            require __DIR__ . '/pages/extend/index.php';
            break;
        }

        // 404
        http_response_code(404);
        $pageTitle = 'ページが見つかりません';
        require __DIR__ . '/includes/header.php';
        echo '<div class="text-center py-5">';
        echo '<h1 class="display-4">404</h1>';
        echo '<p class="lead">ページが見つかりませんでした</p>';
        echo '<a href="' . url('/dashboard') . '" class="btn btn-primary">ダッシュボードへ</a>';
        echo '</div>';
        require __DIR__ . '/includes/footer.php';
        break;
}
