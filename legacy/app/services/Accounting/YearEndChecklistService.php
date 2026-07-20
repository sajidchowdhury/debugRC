<?php
// app/services/Accounting/YearEndChecklistService.php — Phase 6C pre-close checklist

require_once __DIR__ . '/../../../core/Database.php';
require_once __DIR__ . '/ReconciliationService.php';
require_once __DIR__ . '/../../models/Reports/TrialBalanceReport.php';
require_once __DIR__ . '/../../helpers/Helper.php';

class YearEndChecklistService
{
    private Database $db;
    private int $backupMaxAgeDays;

    public function __construct(?Database $db = null, int $backupMaxAgeDays = 14)
    {
        $this->db = $db ?? new Database();
        $this->backupMaxAgeDays = max(1, $backupMaxAgeDays);
    }

    /**
     * Resolve fiscal period from calendar year or explicit dates.
     *
     * @return array{from: string, to: string, year: int}
     */
    public function resolvePeriod(?int $year = null, ?string $fromDate = null, ?string $toDate = null): array
    {
        if ($fromDate && $toDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            return [
                'from' => $fromDate,
                'to'   => $toDate,
                'year' => (int)substr($fromDate, 0, 4),
            ];
        }

        if ($toDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            $year = $year ?: (int)substr($toDate, 0, 4);
            return [
                'from' => sprintf('%04d-01-01', $year),
                'to'   => $toDate,
                'year' => $year,
            ];
        }

        $year = $year ?: (int)date('Y');
        return [
            'from' => sprintf('%04d-01-01', $year),
            'to'   => sprintf('%04d-12-31', $year),
            'year' => $year,
        ];
    }

    /**
     * Full pre-close checklist for UI / JSON API.
     *
     * @return array<string, mixed>
     */
    public function runChecklist(?int $branchId, ?int $year = null, ?string $fromDate = null, ?string $toDate = null): array
    {
        $period = $this->resolvePeriod($year, $fromDate, $toDate);
        $from = $period['from'];
        $to = $period['to'];

        $passBranch = ($branchId !== null && $branchId > 0) ? $branchId : null;

        $tbReport = (new TrialBalanceReport())->getTrialBalance($from, $to, null, false);
        $reconReport = (new ReconciliationService())->runFullReport($passBranch ?? 0, $from, $to);
        $backup = $this->findRecentBackup();

        $tbBalanced = !empty($tbReport['is_balanced']);
        $reconGreen = empty($reconReport['has_issues']);
        $backupOk = $backup !== null;

        $items = [
            $this->makeItem(
                'tb_balanced',
                'auto',
                'Trial balance balanced',
                'Period debits must equal period credits for ' . $from . ' → ' . $to . '.',
                $tbBalanced ? 'pass' : 'fail',
                $tbBalanced
                    ? 'Period Dr ' . number_format((float)$tbReport['grand_debit'], 2) . ' = Cr ' . number_format((float)$tbReport['grand_credit'], 2)
                    : 'Difference ' . number_format((float)($tbReport['difference'] ?? 0), 2),
                false,
                'Report/TrialBalance?search=1&from_date=' . urlencode($from) . '&to_date=' . urlencode($to)
            ),
            $this->makeItem(
                'recon_green',
                'auto',
                'GL reconciliation within tolerance',
                'AR, AP, inventory, COGS and related sub-ledgers vs GL control accounts.',
                $reconGreen ? 'pass' : 'fail',
                $reconGreen
                    ? 'All sections within tolerance'
                    : implode('; ', array_slice($reconReport['issues'] ?? [], 0, 3)),
                false,
                'Reconciliation/index?from=' . urlencode($from) . '&to=' . urlencode($to)
                    . ($passBranch ? '&branch_id=' . $passBranch : '')
            ),
            $this->makeItem(
                'backup_taken',
                'auto',
                'Accounting backup on file',
                'Recent dump from backup_accounting_core.php (within ' . $this->backupMaxAgeDays . ' days).',
                $backupOk ? 'pass' : 'warn',
                $backupOk
                    ? ($backup['filename'] ?? '') . ' · ' . ($backup['modified_at'] ?? '')
                    : 'No recent backup found in /backups — run backup script before final close',
                false,
                null
            ),
            $this->makeItem(
                'export_ready',
                'reference',
                'Year-end archive exports',
                'Download Trial Balance and GL line archive CSV for the selected period (links below).',
                'info',
                null,
                true,
                null
            ),
        ];

        $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0, 'total' => 0];
        foreach ($items as $item) {
            $summary[$item['status']]++;
            $summary['total']++;
        }

        $blocking = array_values(array_filter($items, static fn(array $i): bool => $i['status'] === 'fail'));

        return [
            'year'               => $period['year'],
            'from_date'          => $from,
            'to_date'            => $to,
            'branch_id'          => $passBranch,
            'branch_name'        => $this->resolveBranchName($passBranch),
            'ran_at'             => date('Y-m-d H:i:s'),
            'sections'           => [
                [
                    'id'    => 'year_end',
                    'title' => 'Year-end pre-close',
                    'icon'  => 'fa-calendar-check',
                    'items' => $items,
                ],
            ],
            'summary'            => $summary,
            'trial_balance'      => $tbReport,
            'reconciliation'     => $reconReport,
            'backup'             => $backup,
            'blocking_failures'  => $blocking,
            'can_close_period'   => $blocking === [],
            'close_blocked_reason' => $blocking === []
                ? null
                : 'Resolve failing checks before period close (reconciliation and trial balance must pass).',
        ];
    }

    /**
     * Server gate for AccountingPeriodService::closePeriod.
     *
     * @return array{allowed: bool, message: string, checklist?: array}
     */
    public function validateBeforeClose(int $branchId, string $closedThroughDate): array
    {
        $year = (int)substr($closedThroughDate, 0, 4);
        $checklist = $this->runChecklist($branchId, $year, null, $closedThroughDate);

        $failIds = array_map(static fn(array $i) => $i['id'], $checklist['blocking_failures'] ?? []);

        if (in_array('recon_green', $failIds, true)) {
            return [
                'allowed'   => false,
                'message'   => 'Period close blocked: GL reconciliation has issues. Fix data or run Reconciliation hub.',
                'checklist' => $checklist,
            ];
        }

        if (in_array('tb_balanced', $failIds, true)) {
            return [
                'allowed'   => false,
                'message'   => 'Period close blocked: Trial Balance is out of balance for the year.',
                'checklist' => $checklist,
            ];
        }

        return ['allowed' => true, 'message' => 'OK', 'checklist' => $checklist];
    }

    /**
     * @return array{filename: string, modified_at: string, path: string, age_days: int}|null
     */
    public function findRecentBackup(?string $backupsDir = null): ?array
    {
        $root = dirname(__DIR__, 3);
        $dir = $backupsDir ?? ($root . '/backups');
        if (!is_dir($dir)) {
            return null;
        }

        $cutoff = time() - ($this->backupMaxAgeDays * 86400);
        $best = null;

        foreach (glob($dir . '/accounting_core_*.sql') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $mtime = filemtime($path);
            if ($mtime === false || $mtime < $cutoff) {
                continue;
            }
            if ($best === null || $mtime > $best['mtime']) {
                $best = [
                    'path'        => $path,
                    'filename'    => basename($path),
                    'modified_at' => date('Y-m-d H:i:s', $mtime),
                    'mtime'       => $mtime,
                    'age_days'    => (int)floor((time() - $mtime) / 86400),
                ];
            }
        }

        if ($best === null) {
            return null;
        }
        unset($best['mtime']);
        return $best;
    }

    public function exportTrialBalanceCsv(string $fromDate, string $toDate): void
    {
        $report = (new TrialBalanceReport())->getTrialBalance($fromDate, $toDate, null, false);
        (new TrialBalanceReport())->exportToCsv($report, 'Year_End_Trial_Balance');
    }

    /**
     * GL archive — all journal lines in period (company or branch scoped).
     */
    public function exportGlArchiveCsv(string $fromDate, string $toDate, ?int $branchId = null): void
    {
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND je.branch_id = ' . (int)$branchId;
        }

        $this->db->query("
            SELECT
                je.entry_date,
                je.entry_no,
                je.id AS journal_entry_id,
                je.reference_type,
                je.reference_id,
                je.description AS entry_description,
                l.ledger_code,
                l.ledger_name,
                jl.debit,
                jl.credit,
                jl.description AS line_description,
                b.branch_name
            FROM journal_lines jl
            INNER JOIN journal_entries je ON je.id = jl.journal_entry_id
            INNER JOIN ledgers l ON l.id = jl.ledger_id
            LEFT JOIN branches b ON b.id = je.branch_id
            WHERE je.entry_date BETWEEN :from_date AND :to_date
              AND COALESCE(je.is_reversed, 0) = 0
              {$branchSql}
            ORDER BY je.entry_date ASC, je.id ASC, jl.id ASC
        ");
        $this->db->bind(':from_date', $fromDate);
        $this->db->bind(':to_date', $toDate);
        $rows = $this->db->resultSet() ?: [];

        $suffix = $branchId ? '_branch_' . $branchId : '';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="GL_Archive_' . $fromDate . '_to_' . $toDate . $suffix . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['GL Archive — journal lines']);
        fputcsv($out, ['Period', $fromDate . ' to ' . $toDate]);
        fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, ['Date', 'Entry No', 'JE ID', 'Reference', 'Ref ID', 'Ledger Code', 'Ledger', 'Debit', 'Credit', 'Line note', 'Entry narration', 'Branch']);

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['entry_date'] ?? '',
                $row['entry_no'] ?? '',
                $row['journal_entry_id'] ?? '',
                $row['reference_type'] ?? '',
                $row['reference_id'] ?? '',
                $row['ledger_code'] ?? '',
                $row['ledger_name'] ?? '',
                (float)($row['debit'] ?? 0) > 0 ? number_format((float)$row['debit'], 2, '.', '') : '',
                (float)($row['credit'] ?? 0) > 0 ? number_format((float)$row['credit'], 2, '.', '') : '',
                $row['line_description'] ?? '',
                $row['entry_description'] ?? '',
                $row['branch_name'] ?? '',
            ]);
        }

        fclose($out);
        exit;
    }

    private function resolveBranchName(?int $branchId): string
    {
        if ($branchId === null || $branchId <= 0) {
            return 'All branches';
        }
        $this->db->query('SELECT branch_name FROM branches WHERE id = :id LIMIT 1');
        $this->db->bind(':id', $branchId);
        $row = $this->db->single();
        return $row['branch_name'] ?? ('Branch #' . $branchId);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeItem(
        string $id,
        string $kind,
        string $title,
        string $expected,
        string $status,
        ?string $actual,
        bool $referenceOnly,
        ?string $route
    ): array {
        return [
            'id'             => $id,
            'kind'           => $kind,
            'title'          => $title,
            'expected'       => $expected,
            'status'         => $status,
            'actual'         => $actual,
            'reference_only' => $referenceOnly,
            'route'          => $route,
        ];
    }
}
