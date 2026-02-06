<?php
/**
 * 共通関数（後方互換ラッパー）
 *
 * 全ての関数は以下の分割ファイルに移動済み。
 * このファイルは既存のrequire_once互換性のために残しています。
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/reservation_helpers.php';
require_once __DIR__ . '/job_helpers.php';
require_once __DIR__ . '/line_helpers.php';      // notification_helpers.php が sendLineTextPush() に依存
require_once __DIR__ . '/notification_helpers.php';
require_once __DIR__ . '/audit_helpers.php';
require_once __DIR__ . '/user_helpers.php';
