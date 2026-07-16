<?php
// app/services/Accounting/AccountingPeriodService.php — Phase 6B soft period close

require_once __DIR__ . '/../../../core/Database.php';
require_once __DIR__ . '/../../../core/Auth.php';
require_once __DIR__ . '/../../helpers/Helper.php';

class AccountingPeriodService
{
    private Database $db;

    /** @var array<int, string|null> */
    private static array $closedCache = [];

    /** @var bool|null */
    private static ?bool $tableExists = null;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? new Database();
    }

    public static function canBypassPeriodLock(): bool
    {
        if (Auth::isSuperadmin()) {
            return true;
        }
        if (Auth::isAdmin() && defined('PERIOD_CLOSE_ADMIN_OVERRIDE') && PERIOD_CLOSE_ADMIN_OVERRIDE) {
            return true;
        }
        return false;
    }

    public static function tableExists(?Database $db = null): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }
        try {
            $db = $db ?? new Database();
            $db->query("SHOW TABLES LIKE 'accounting_periods'");
            self::$tableExists = (bool)$db->single();
        } catch (Throwable $e) {
            self::$tableExists = false;
        }
        return self::$tableExists;
    }

    /**
     * @return string|null Y-m-d closed through date, or null if branch is open
     */
    public function getClosedThroughDate(int $branchId): ?string
    {
        if ($branchId <= 0 || !self::tableExists($this->db)) {
            return null;
        }
        if (array_key_exists($branchId, self::$closedCache)) {
            return self::$closedCache[$branchId];
        }

        $this->db->query('
            SELECT closed_through_date
            FROM accounting_periods
            WHERE branch_id = :bid
            LIMIT 1
        ');
        $this->db->bind(':bid', $branchId);
        $row = $this->db->single();
        $closed = !empty($row['closed_through_date']) ? (string)$row['closed_through_date'] : null;
        self::$closedCache[$branchId] = $closed;

        return $closed;
    }

    /**
     * First date allowed for new postings (day after close), or null if unrestricted.
     */
    public function earliestOpenDate(int $branchId): ?string
    {
        $closed = $this->getClosedThroughDate($branchId);
        if ($closed === null) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $closed);
        if (!$dt) {
            return null;
        }
        return $dt->modify('+1 day')->format('Y-m-d');
    }

    /**
     * Validate a posting/document date against branch period close.
     * Returns null when allowed, or an error message string.
     */
    public static function validatePostingDate(string $entryDate, ?int $branchId = null): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
            return 'Valid entry date is required';
        }

        if (self::canBypassPeriodLock()) {
            return null;
        }

        $branchId = ($branchId !== null && $branchId > 0) ? $branchId : Helper::sessionBranchId();
        $service = new self();
        $closed = $service->getClosedThroughDate($branchId);

        if ($closed === null) {
            return null;
        }

        if ($entryDate <= $closed) {
            $openFrom = $service->earliestOpenDate($branchId) ?? $closed;
            return 'Accounting period is closed through '
                . date('d M Y', strtotime($closed))
                . '. Earliest allowed date: '
                . date('d M Y', strtotime($openFrom))
                . '.';
        }

        return null;
    }

    /**
     * Banner payload for accounting module views.
     *
     * @return array<string, mixed>
     */
    public function bannerForBranch(int $branchId): array
    {
        $closed = $this->getClosedThroughDate($branchId);
        $canBypass = self::canBypassPeriodLock();

        return [
            'branch_id'          => $branchId,
            'closed_through'     => $closed,
            'earliest_open_date' => $closed ? $this->earliestOpenDate($branchId) : null,
            'can_bypass'         => $canBypass,
            'is_locked'          => $closed !== null && !$canBypass,
            'manage_url'         => BASE_URL . 'AccountingPeriod/index',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBranchPeriods(): array
    {
        if (!self::tableExists($this->db)) {
            return [];
        }

        $this->db->query('
            SELECT
                b.id AS branch_id,
                b.branch_name,
                ap.closed_through_date,
                ap.closed_at,
                ap.notes,
                u.username AS closed_by_name
            FROM branches b
            LEFT JOIN accounting_periods ap ON ap.branch_id = b.id
            LEFT JOIN users u ON u.id = ap.closed_by
            WHERE COALESCE(b.is_active, 1) = 1
            ORDER BY b.branch_name ASC
        ');

        return $this->db->resultSet() ?: [];
    }

    public function closePeriod(int $branchId, string $closedThroughDate, string $notes = ''): array
    {
        if (!Auth::isAdmin()) {
            return ['status' => 'error', 'message' => 'Admin access required'];
        }
        if ($branchId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $closedThroughDate)) {
            return ['status' => 'error', 'message' => 'Valid branch and close date are required'];
        }
        if (!self::tableExists($this->db)) {
            return ['status' => 'error', 'message' => 'Run migration 045 (accounting_periods) first'];
        }

        require_once __DIR__ . '/YearEndChecklistService.php';
        $gate = (new YearEndChecklistService($this->db))->validateBeforeClose($branchId, $closedThroughDate);
        if (!$gate['allowed']) {
            return [
                'status'    => 'error',
                'message'   => $gate['message'],
                'checklist' => $gate['checklist'] ?? null,
            ];
        }

        $this->db->query('SELECT id FROM branches WHERE id = :id AND COALESCE(is_active, 1) = 1 LIMIT 1');
        $this->db->bind(':id', $branchId);
        if (!$this->db->single()) {
            return ['status' => 'error', 'message' => 'Branch not found'];
        }

        $notes = trim($notes);
        $userId = (int)($_SESSION['user_id'] ?? 0) ?: null;

        $this->db->query('
            INSERT INTO accounting_periods (branch_id, closed_through_date, closed_by, notes)
            VALUES (:bid, :closed, :uid, :notes)
            ON DUPLICATE KEY UPDATE
                closed_through_date = VALUES(closed_through_date),
                closed_by = VALUES(closed_by),
                closed_at = CURRENT_TIMESTAMP,
                notes = VALUES(notes)
        ');
        $this->db->bind(':bid', $branchId);
        $this->db->bind(':closed', $closedThroughDate);
        $this->db->bind(':uid', $userId);
        $this->db->bind(':notes', $notes !== '' ? $notes : null);
        $this->db->execute();

        unset(self::$closedCache[$branchId]);

        return [
            'status'              => 'success',
            'message'             => 'Period closed through ' . $closedThroughDate,
            'branch_id'           => $branchId,
            'closed_through_date' => $closedThroughDate,
        ];
    }

    public function reopenPeriod(int $branchId): array
    {
        if (!Auth::isSuperadmin()) {
            return ['status' => 'error', 'message' => 'Superadmin access required to reopen a period'];
        }
        if ($branchId <= 0 || !self::tableExists($this->db)) {
            return ['status' => 'error', 'message' => 'Invalid request'];
        }

        $this->db->query('DELETE FROM accounting_periods WHERE branch_id = :bid');
        $this->db->bind(':bid', $branchId);
        $this->db->execute();

        unset(self::$closedCache[$branchId]);

        return [
            'status'    => 'success',
            'message'   => 'Period lock removed for branch',
            'branch_id' => $branchId,
        ];
    }
}
