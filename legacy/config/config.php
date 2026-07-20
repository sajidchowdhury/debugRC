<?php
// config/config.php

// Dynamic Base URL (works with any folder name)
if (php_sapi_name() === 'cli' && empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
}
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$base_path = dirname($_SERVER['SCRIPT_NAME']);

define('BASE_URL', $protocol . '://' . $_SERVER['HTTP_HOST'] . $base_path . '/');
define('APP_NAME', 'Remote Center ERP');

// Clean base URL for user-facing links and assets (strips trailing /public if present)
$clean_base = preg_replace('#/public/?$#', '', rtrim(BASE_URL, '/')) . '/';
define('PUBLIC_URL', $clean_base);   // e.g. http://localhost/remote-center-erp/

// Local overrides first (DB credentials, FCM keys, etc.) — copy from config/local.php.example
$localConfig = __DIR__ . '/local.php';
if (is_readable($localConfig)) {
    require $localConfig;
}

if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}
if (!defined('DB_USER')) {
    define('DB_USER', 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', '');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'osudlagb_remotecenter');
}

if (!defined('FCM_SERVER_KEY')) {
    $fcmFromEnv = getenv('FCM_SERVER_KEY');
    define('FCM_SERVER_KEY', $fcmFromEnv !== false ? (string)$fcmFromEnv : '');
}

if (!defined('FCM_VAPID_KEY')) {
    $vapidFromEnv = getenv('FCM_VAPID_KEY');
    define('FCM_VAPID_KEY', $vapidFromEnv !== false ? (string)$vapidFromEnv : '');
}

$appEnv = getenv('APP_ENV');
if ($appEnv === false || $appEnv === '') {
    $appEnv = 'development';
}
define('APP_ENV', $appEnv);
define('APP_DEBUG', APP_ENV !== 'production');

if (!defined('SALES_STALE_DRAFT_DAYS')) {
    $staleDays = getenv('SALES_STALE_DRAFT_DAYS');
    define('SALES_STALE_DRAFT_DAYS', $staleDays !== false && $staleDays !== '' ? max(1, (int)$staleDays) : 14);
}

if (!defined('SALES_STALE_DRAFT_AUTO_CANCEL')) {
    $autoCancel = getenv('SALES_STALE_DRAFT_AUTO_CANCEL');
    if ($autoCancel === false || $autoCancel === '') {
        define('SALES_STALE_DRAFT_AUTO_CANCEL', false);
    } else {
        define('SALES_STALE_DRAFT_AUTO_CANCEL', filter_var($autoCancel, FILTER_VALIDATE_BOOLEAN));
    }
}

if (!defined('GL_RECONCILIATION_TOLERANCE')) {
    $reconTol = getenv('GL_RECONCILIATION_TOLERANCE');
    define('GL_RECONCILIATION_TOLERANCE', $reconTol !== false && $reconTol !== '' ? max(0.0001, (float)$reconTol) : 0.02);
}

if (!defined('PERIOD_CLOSE_ADMIN_OVERRIDE')) {
    $periodAdmin = getenv('PERIOD_CLOSE_ADMIN_OVERRIDE');
    if ($periodAdmin === false || $periodAdmin === '') {
        define('PERIOD_CLOSE_ADMIN_OVERRIDE', false);
    } else {
        define('PERIOD_CLOSE_ADMIN_OVERRIDE', filter_var($periodAdmin, FILTER_VALIDATE_BOOLEAN));
    }
}

if (!defined('RECON_ALERT_EMAIL')) {
    $reconEmail = getenv('RECON_ALERT_EMAIL');
    define('RECON_ALERT_EMAIL', $reconEmail !== false ? (string)$reconEmail : '');
}

if (!defined('TELEGRAM_BOT_TOKEN')) {
    $telegramToken = getenv('TELEGRAM_BOT_TOKEN');
    define('TELEGRAM_BOT_TOKEN', $telegramToken !== false ? trim((string)$telegramToken) : '');
}

if (!defined('TELEGRAM_ALERTS_ENABLED')) {
    $telegramEnabled = getenv('TELEGRAM_ALERTS_ENABLED');
    if ($telegramEnabled === false || $telegramEnabled === '') {
        define('TELEGRAM_ALERTS_ENABLED', true);
    } else {
        define('TELEGRAM_ALERTS_ENABLED', filter_var($telegramEnabled, FILTER_VALIDATE_BOOLEAN));
    }
}

if (!defined('SALES_DB_DRAFT_CARTS')) {
    $dbCarts = getenv('SALES_DB_DRAFT_CARTS');
    define('SALES_DB_DRAFT_CARTS', $dbCarts === '1' || $dbCarts === 'true');
}

if (!defined('APP_LOG_LEVEL')) {
    $logLevel = getenv('APP_LOG_LEVEL');
    define('APP_LOG_LEVEL', $logLevel !== false && $logLevel !== '' ? (string)$logLevel : '');
}

if (!defined('API_SUPPORTED_VERSIONS')) {
    $apiVersions = getenv('API_SUPPORTED_VERSIONS');
    define('API_SUPPORTED_VERSIONS', $apiVersions !== false ? (string)$apiVersions : '1');
}

if (!defined('AUTH_MAX_FAILED_ATTEMPTS')) {
    $authMaxFailed = getenv('AUTH_MAX_FAILED_ATTEMPTS');
    define('AUTH_MAX_FAILED_ATTEMPTS', $authMaxFailed !== false && $authMaxFailed !== '' ? max(1, (int)$authMaxFailed) : 5);
}

if (!defined('AUTH_LOCKOUT_MINUTES')) {
    $authLockMins = getenv('AUTH_LOCKOUT_MINUTES');
    define('AUTH_LOCKOUT_MINUTES', $authLockMins !== false && $authLockMins !== '' ? max(1, (int)$authLockMins) : 15);
}

if (!defined('AUTH_RESET_TOKEN_HOURS')) {
    $authResetHours = getenv('AUTH_RESET_TOKEN_HOURS');
    define('AUTH_RESET_TOKEN_HOURS', $authResetHours !== false && $authResetHours !== '' ? max(1, (int)$authResetHours) : 1);
}

if (!defined('AUTH_REMEMBER_DAYS')) {
    $rememberDays = getenv('AUTH_REMEMBER_DAYS');
    define('AUTH_REMEMBER_DAYS', $rememberDays !== false && $rememberDays !== '' ? max(1, (int)$rememberDays) : 30);
}

// Phase 0: AUTH_2FA_ISSUER removed (TOTP 2FA disabled per migration decision).

if (!defined('INVESTIGATION_QR_SECRET')) {
    $invSecret = getenv('INVESTIGATION_QR_SECRET');
    define('INVESTIGATION_QR_SECRET', $invSecret !== false ? (string)$invSecret : '');
}

if (!defined('INVESTIGATION_COMPANY_EMAIL')) {
    $invEmail = getenv('INVESTIGATION_COMPANY_EMAIL');
    define('INVESTIGATION_COMPANY_EMAIL', $invEmail !== false ? (string)$invEmail : '');
}

if (!defined('INVESTIGATION_EFFECTIVE_ROLE')) {
    $invRole = getenv('INVESTIGATION_EFFECTIVE_ROLE');
    define('INVESTIGATION_EFFECTIVE_ROLE', $invRole !== false && $invRole !== '' ? (string)$invRole : 'admin');
}

if (!defined('INVESTIGATION_OTP_MINUTES')) {
    $invOtp = getenv('INVESTIGATION_OTP_MINUTES');
    define('INVESTIGATION_OTP_MINUTES', $invOtp !== false && $invOtp !== '' ? max(5, (int)$invOtp) : 15);
}

if (!defined('INVESTIGATION_FISCAL_START_MONTH')) {
    $invFiscal = getenv('INVESTIGATION_FISCAL_START_MONTH');
    define('INVESTIGATION_FISCAL_START_MONTH', $invFiscal !== false && $invFiscal !== '' ? max(1, min(12, (int)$invFiscal)) : 7);
}

error_reporting(E_ALL);
if (APP_DEBUG) {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
?>