<?php
// app/services/Accounting/AccountingDashboardService.php — Phase 8A accounting home dashboard

require_once __DIR__ . '/../../../core/Database.php';
require_once __DIR__ . '/../../helpers/Helper.php';
require_once __DIR__ . '/../../helpers/JournalReportHelper.php';
require_once __DIR__ . '/../../models/Reports/TrialBalanceReport.php';
require_once __DIR__ . '/ReconciliationService.php';
require_once __DIR__ . '/AccountingPeriodService.php';

class AccountingDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function build(?int $branchId = null): array
    {
        $branchId = ($branchId !== null && $branchId > 0) ? $branchId : Helper::sessionBranchId();
        $fromDate = date('Y-m-01');
        $toDate = date('Y-m-d');
        $base = defined('BASE_URL') ? BASE_URL : '/';

        $tbReport = (new TrialBalanceReport())->getTrialBalance($fromDate, $toDate);
        $reconReport = (new ReconciliationService())->runFullReport($branchId, $fromDate, $toDate);
        $periodBanner = (new AccountingPeriodService())->bannerForBranch($branchId);

        $reconOverall = ReconciliationService::overallStatus($reconReport);

        return [
            'branch_id'   => $branchId,
            'branch_name' => $reconReport['branch_name'] ?? 'Branch',
            'from_date'   => $fromDate,
            'to_date'     => $toDate,
            'ran_at'      => $reconReport['ran_at'] ?? date('Y-m-d H:i:s'),
            'trial_balance' => [
                'is_balanced'  => !empty($tbReport['is_balanced']),
                'difference'   => (float)($tbReport['difference'] ?? 0),
                'grand_debit'  => (float)($tbReport['grand_debit'] ?? 0),
                'grand_credit' => (float)($tbReport['grand_credit'] ?? 0),
                'status'       => !empty($tbReport['is_balanced']) ? 'ok' : 'fail',
                'url'          => $base . 'Report/TrialBalance?search=1&from_date=' . $fromDate . '&to_date=' . $toDate,
            ],
            'reconciliation' => [
                'has_issues'     => !empty($reconReport['has_issues']),
                'overall_status' => $reconOverall,
                'overall_label'  => ReconciliationService::sectionStatusLabel($reconOverall),
                'sections'       => ReconciliationService::sectionSummaries($reconReport),
                'issues'         => $reconReport['issues'] ?? [],
                'url'            => $base . 'Reconciliation/index?from=' . $fromDate . '&to=' . $toDate,
            ],
            'period' => array_merge($periodBanner, [
                'status' => empty($periodBanner['closed_through']) ? 'ok' : 'warn',
                'label'  => empty($periodBanner['closed_through'])
                    ? 'Open for posting'
                    : 'Closed through ' . date('d M Y', strtotime((string)$periodBanner['closed_through'])),
            ]),
            'recent_journals' => $this->fetchRecentJournals($branchId, 20),
            'reports_url'     => $base . 'Report/index#cat-finance',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRecentJournals(int $branchId, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $db = new Database();

        $sql = '
            SELECT
                je.id,
                je.entry_no,
                je.entry_date,
                je.reference_type,
                je.reference_id,
                je.description,
                je.total_debit,
                je.total_credit,
                je.is_reversed,
                je.branch_id,
                u.username AS created_by_name,
                b.branch_name
            FROM journal_entries je
            LEFT JOIN users u ON u.id = je.created_by
            LEFT JOIN branches b ON b.id = je.branch_id
            WHERE COALESCE(je.is_reversed, 0) = 0
        ';

        if ($branchId > 0) {
            $sql .= ' AND je.branch_id = :branch_id';
        }

        $sql .= ' ORDER BY je.entry_date DESC, je.id DESC LIMIT :limit';

        $db->query($sql);
        if ($branchId > 0) {
            $db->bind(':branch_id', $branchId);
        }
        $db->bind(':limit', $limit);

        $rows = $db->resultSet() ?: [];
        $base = defined('BASE_URL') ? BASE_URL : '/';

        foreach ($rows as &$row) {
            $refType = (string)($row['reference_type'] ?? '');
            $refId = (int)($row['reference_id'] ?? 0);
            $row['reference_label'] = JournalReportHelper::referenceLabel($refType);
            $row['source_url'] = JournalReportHelper::referenceUrl($refType, $refId);
            $row['journal_url'] = $base . 'Report/JournalEntries?search=1&from_date='
                . date('Y-m-01') . '&to_date=' . date('Y-m-d');
        }
        unset($row);

        return $rows;
    }
}
