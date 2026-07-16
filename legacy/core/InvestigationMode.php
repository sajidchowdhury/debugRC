<?php
// core/InvestigationMode.php — global investigation window (QR on, email OTP off).

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Mail.php';

class InvestigationMode
{
    /** @var array<string, mixed>|null|false */
    private static $activeWindowCache = null;

    public static function qrToken(): string
    {
        $secret = self::qrSecret();
        if ($secret === '') {
            return '';
        }

        return hash('sha256', $secret);
    }

    public static function qrSecret(): string
    {
        return defined('INVESTIGATION_QR_SECRET') ? (string)INVESTIGATION_QR_SECRET : '';
    }

    public static function scanUrl(): string
    {
        $token = self::qrToken();
        if ($token === '') {
            return BASE_URL . 'investigation/scan';
        }

        return BASE_URL . 'investigation/scan?t=' . urlencode($token);
    }

    public static function companyEmail(): string
    {
        if (defined('INVESTIGATION_COMPANY_EMAIL')) {
            $email = trim((string)INVESTIGATION_COMPANY_EMAIL);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return '';
    }

    public static function validateQrToken(?string $token): bool
    {
        $expected = self::qrToken();
        if ($expected === '' || $token === null || $token === '') {
            return false;
        }

        return hash_equals($expected, trim($token));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getActiveWindow(): ?array
    {
        if (self::$activeWindowCache === false) {
            return null;
        }

        if (is_array(self::$activeWindowCache)) {
            return self::$activeWindowCache;
        }

        try {
            $db = new Database();
            $db->query('
                SELECT *
                FROM investigation_windows
                WHERE ended_at IS NULL
                ORDER BY id DESC
                LIMIT 1
            ');
            $row = $db->single();
            self::$activeWindowCache = $row ?: false;

            return $row ?: null;
        } catch (Throwable $e) {
            error_log('InvestigationMode::getActiveWindow failed: ' . $e->getMessage());
            self::$activeWindowCache = false;

            return null;
        }
    }

    public static function isGloballyActive(): bool
    {
        return self::getActiveWindow() !== null;
    }

    public static function isActivator(int $userId): bool
    {
        try {
            $db = new Database();
            $db->query('
                SELECT id FROM investigation_activators
                WHERE user_id = :user_id AND is_active = 1
                LIMIT 1
            ');
            $db->bind(':user_id', $userId);

            return (bool)$db->single();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return array{status: string, message: string, window_id?: int}
     */
    public static function activate(int $activatorUserId, string $notes = ''): array
    {
        self::$activeWindowCache = null;

        if (!self::isActivator($activatorUserId)) {
            return ['status' => 'error', 'message' => 'You are not authorized to activate investigation mode.'];
        }

        if (self::isGloballyActive()) {
            return ['status' => 'error', 'message' => 'Investigation mode is already active.'];
        }

        try {
            $db = new Database();
            $effectiveRole = defined('INVESTIGATION_EFFECTIVE_ROLE')
                ? (string)INVESTIGATION_EFFECTIVE_ROLE
                : Auth::ROLE_ADMIN;

            $db->query('
                INSERT INTO investigation_windows
                    (started_by_user_id, effective_role, read_only, notes)
                VALUES
                    (:user_id, :effective_role, 1, :notes)
            ');
            $db->bind(':user_id', $activatorUserId);
            $db->bind(':effective_role', $effectiveRole);
            $db->bind(':notes', mb_substr(trim($notes), 0, 255));
            $db->execute();

            self::$activeWindowCache = null;
            self::refreshAllRestrictedSessions();

            return [
                'status'    => 'success',
                'message'   => 'Investigation mode is ON. Reports are limited to the current Jul–Jun year; everything else works as usual.',
                'window_id' => (int)$db->lastInsertId(),
            ];
        } catch (Throwable $e) {
            error_log('InvestigationMode::activate failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Failed to activate investigation mode.'];
        }
    }

    /**
     * @return array{status: string, message: string}
     */
    public static function sendDeactivationOtp(int $requesterUserId): array
    {
        if (!self::isActivator($requesterUserId)) {
            return ['status' => 'error', 'message' => 'You are not authorized to request deactivation.'];
        }

        $window = self::getActiveWindow();
        if (!$window) {
            return ['status' => 'error', 'message' => 'Investigation mode is not active.'];
        }

        $email = self::companyEmail();
        if ($email === '') {
            return ['status' => 'error', 'message' => 'Company email is not configured (INVESTIGATION_COMPANY_EMAIL).'];
        }

        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = hash('sha256', $otp);
        $minutes = defined('INVESTIGATION_OTP_MINUTES') ? max(5, (int)INVESTIGATION_OTP_MINUTES) : 15;

        try {
            $db = new Database();
            $db->query('DELETE FROM investigation_deactivation_otps WHERE window_id = :window_id AND used_at IS NULL');
            $db->bind(':window_id', (int)$window['id']);
            $db->execute();

            $db->query('
                INSERT INTO investigation_deactivation_otps
                    (window_id, otp_hash, expires_at, requested_by_user_id)
                VALUES
                    (:window_id, :hash, DATE_ADD(NOW(), INTERVAL :minutes MINUTE), :user_id)
            ');
            $db->bind(':window_id', (int)$window['id']);
            $db->bind(':hash', $hash);
            $db->bind(':minutes', $minutes);
            $db->bind(':user_id', $requesterUserId);
            $db->execute();

            $subject = APP_NAME . ' — Investigation mode deactivation code';
            $body = "An authorized staff member requested to turn OFF investigation mode.\n\n"
                . "Deactivation code: {$otp}\n"
                . "Valid for {$minutes} minute(s).\n\n"
                . "If you did not expect this, do not share the code and contact management immediately.\n";

            $mailResult = Mail::sendPlain($email, $subject, $body);
            if ($mailResult['sent']) {
                return [
                    'status'  => 'success',
                    'message' => 'A deactivation code was sent to the company email. Enter it below to restore normal access.',
                    'email_delivered' => true,
                ];
            }

            Mail::logInvestigationOtp($email, $otp, $minutes);

            $showOtpOnScreen = (defined('APP_DEBUG') && APP_DEBUG)
                || (defined('INVESTIGATION_SHOW_OTP_ON_FAIL') && INVESTIGATION_SHOW_OTP_ON_FAIL);

            if ($showOtpOnScreen) {
                return [
                    'status'          => 'success',
                    'message'         => 'Email could not be sent from this server. Use the code shown below.',
                    'email_delivered' => false,
                    'dev_otp'         => $otp,
                ];
            }

            return ['status' => 'error', 'message' => 'Failed to send OTP email. Configure mail on the server or enable INVESTIGATION_SHOW_OTP_ON_FAIL in config/local.php for local testing.'];
        } catch (Throwable $e) {
            error_log('InvestigationMode::sendDeactivationOtp failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Failed to send deactivation code.'];
        }
    }

    /**
     * @return array{status: string, message: string}
     */
    public static function deactivate(string $otp, int $requesterUserId): array
    {
        if (!self::isActivator($requesterUserId)) {
            return ['status' => 'error', 'message' => 'You are not authorized to deactivate investigation mode.'];
        }

        $window = self::getActiveWindow();
        if (!$window) {
            return ['status' => 'error', 'message' => 'Investigation mode is not active.'];
        }

        $otp = preg_replace('/\s+/', '', trim($otp));
        if ($otp === '' || !ctype_digit($otp) || strlen($otp) !== 6) {
            return ['status' => 'error', 'message' => 'Enter a valid 6-digit code.'];
        }

        try {
            $db = new Database();
            $db->query('
                SELECT id FROM investigation_deactivation_otps
                WHERE window_id = :window_id
                  AND otp_hash = :hash
                  AND used_at IS NULL
                  AND expires_at > NOW()
                LIMIT 1
            ');
            $db->bind(':window_id', (int)$window['id']);
            $db->bind(':hash', hash('sha256', $otp));
            $otpRow = $db->single();

            if (!$otpRow) {
                return ['status' => 'error', 'message' => 'Invalid or expired deactivation code.'];
            }

            $db->beginTransaction();

            $db->query('UPDATE investigation_deactivation_otps SET used_at = NOW() WHERE id = :id');
            $db->bind(':id', (int)$otpRow['id']);
            $db->execute();

            $db->query('
                UPDATE investigation_windows
                SET ended_at = NOW(), ended_by_user_id = :user_id
                WHERE id = :id AND ended_at IS NULL
            ');
            $db->bind(':user_id', $requesterUserId);
            $db->bind(':id', (int)$window['id']);
            $db->execute();

            $db->commit();

            self::$activeWindowCache = null;
            self::clearAllRestrictedSessions();

            return ['status' => 'success', 'message' => 'Investigation mode is OFF. Reports use your normal date ranges again.'];
        } catch (Throwable $e) {
            if (isset($db)) {
                $db->rollback();
            }
            error_log('InvestigationMode::deactivate failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Failed to deactivate investigation mode.'];
        }
    }

    /**
     * Fiscal year start month (default July = 7).
     */
    public static function fiscalStartMonth(): int
    {
        if (defined('INVESTIGATION_FISCAL_START_MONTH')) {
            return max(1, min(12, (int)INVESTIGATION_FISCAL_START_MONTH));
        }

        return 7;
    }

    /**
     * Current Jul–Jun (or configured fiscal) window when investigation mode is ON.
     *
     * @return array{from: string, to: string, label: string}|null
     */
    public static function getReportPeriod(): ?array
    {
        if (!self::isGloballyActive()) {
            return null;
        }

        $startMonth = self::fiscalStartMonth();
        $year = (int)date('Y');
        $month = (int)date('n');

        $fyStartYear = $month >= $startMonth ? $year : $year - 1;
        $from = sprintf('%d-%02d-01', $fyStartYear, $startMonth);

        $endMonth = $startMonth - 1;
        $endYear = $fyStartYear + 1;
        if ($endMonth <= 0) {
            $endMonth = 12;
            $endYear = $fyStartYear;
        }

        $lastDay = (int)date('t', mktime(0, 0, 0, $endMonth, 1, $endYear));
        $to = sprintf('%d-%02d-%02d', $endYear, $endMonth, $lastDay);

        $today = date('Y-m-d');
        if ($to > $today) {
            $to = $today;
        }

        $label = date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));

        return [
            'from'  => $from,
            'to'    => $to,
            'label' => $label,
        ];
    }

    /**
     * @return array{from: string, to: string, clamped: bool, label: ?string}
     */
    public static function clampReportDates(string $from, string $to): array
    {
        $period = self::getReportPeriod();
        if ($period === null) {
            return [
                'from'    => $from,
                'to'      => $to,
                'clamped' => false,
                'label'   => null,
            ];
        }

        $fromClamped = max($from, $period['from']);
        $toClamped = min($to, $period['to']);
        if ($fromClamped > $toClamped) {
            $fromClamped = $period['from'];
            $toClamped = $period['to'];
        }

        return [
            'from'    => $fromClamped,
            'to'      => $toClamped,
            'clamped' => true,
            'label'   => $period['label'],
        ];
    }

    public static function clampAsOfDate(string $asOf): string
    {
        $period = self::getReportPeriod();
        if ($period === null) {
            return $asOf;
        }

        if ($asOf < $period['from']) {
            return $period['from'];
        }

        if ($asOf > $period['to']) {
            return $period['to'];
        }

        return $asOf;
    }

    /**
     * Plain-language comparison for setup screens.
     *
     * @return array<string, array<string, string>>
     */
    public static function modeComparison(): array
    {
        $period = self::getReportPeriod();
        $reportLine = $period !== null
            ? 'Reports limited to ' . $period['label']
            : 'Reports use any date range you pick';

        return [
            'normal' => [
                'Reports'         => 'Any date range',
                'Sales, users, GL' => 'Full access for your role',
            ],
            'investigation' => [
                'Reports'         => 'Jul–Jun fiscal year only (1 year window)',
                'Sales, users, GL' => 'Same as normal — nothing else changes',
            ],
        ];
    }

    /**
     * Apply or clear session flags after login.
     *
     * @param array<string, mixed> $user
     */
    public static function applySessionForUser(array $user): void
    {
        self::clearSessionFlags();
    }

    public static function syncSessionWithDatabase(): void
    {
        if (!self::isGloballyActive()) {
            self::clearSessionFlags();
        }
    }

    public static function clearSessionFlags(): void
    {
        unset(
            $_SESSION['investigation_restricted'],
            $_SESSION['investigation_window_id'],
            $_SESSION['investigation_effective_role'],
            $_SESSION['investigation_read_only']
        );
    }

    public static function isSessionRestricted(): bool
    {
        return false;
    }

    /**
     * @param int[] $branchIds
     * @return int[]
     */
    public static function applyBranchScope(array $branchIds): array
    {
        return $branchIds;
    }

    public static function isPostAllowed(string $controller, string $action): bool
    {
        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listActivators(): array
    {
        try {
            $db = new Database();
            $db->query('
                SELECT ia.*, u.username, e.name AS employee_name
                FROM investigation_activators ia
                JOIN users u ON u.id = ia.user_id
                JOIN employees e ON e.id = u.employee_id
                ORDER BY ia.id ASC
            ');

            return $db->resultSet() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return array{status: string, message: string}
     */
    public static function addActivator(int $userId, string $label = ''): array
    {
        try {
            $db = new Database();
            $db->query('
                INSERT INTO investigation_activators (user_id, label, is_active)
                VALUES (:user_id, :label, 1)
                ON DUPLICATE KEY UPDATE label = VALUES(label), is_active = 1
            ');
            $db->bind(':user_id', $userId);
            $db->bind(':label', mb_substr(trim($label), 0, 100));
            $db->execute();

            return ['status' => 'success', 'message' => 'Activator added.'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => 'Failed to add activator.'];
        }
    }

    /**
     * @return array{status: string, message: string}
     */
    public static function removeActivator(int $id): array
    {
        try {
            $db = new Database();
            $db->query('DELETE FROM investigation_activators WHERE id = :id');
            $db->bind(':id', $id);

            return $db->execute()
                ? ['status' => 'success', 'message' => 'Activator removed.']
                : ['status' => 'error', 'message' => 'Failed to remove activator.'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => 'Failed to remove activator.'];
        }
    }

    private static function refreshAllRestrictedSessions(): void
    {
        // Session flags are applied per user on next syncSessionWithDatabase() request.
    }

    private static function clearAllRestrictedSessions(): void
    {
        // Other users' sessions clear flags on their next syncSessionWithDatabase() request.
    }
}
