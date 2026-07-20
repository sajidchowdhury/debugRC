<?php
// app/models/Reports/GeneralLedgerReport.php

require_once __DIR__ . '/../../helpers/JournalReportHelper.php';

class GeneralLedgerReport
{
    protected $db;

    public function __construct()
    {
        require_once '../core/Database.php';
        $this->db = new Database();
    }

    /**
     * @return array{ledger: ?array, from_date: string, to_date: string, opening: array, lines: array, closing: array}
     */
    public function getGeneralLedger(int $ledgerId, ?string $fromDate, ?string $toDate, ?int $branchId = null): array
    {
        $fromDate = $fromDate ?: date('Y-m-01');
        $toDate   = $toDate   ?: date('Y-m-d');

        require_once __DIR__ . '/../LedgerModel.php';
        $ledgerModel = new LedgerModel();
        $ledger = $ledgerModel->getLedgerById($ledgerId);

        if (!$ledger) {
            return [
                'ledger'     => null,
                'from_date'  => $fromDate,
                'to_date'    => $toDate,
                'branch_id'  => $branchId,
                'opening'    => ['balance' => 0, 'balance_side' => 'Dr', 'signed_balance' => 0],
                'lines'      => [],
                'closing'    => ['balance' => 0, 'balance_side' => 'Dr', 'signed_balance' => 0],
            ];
        }

        $normalBalance = $ledger['normal_balance'] ?? 'debit';

        $openingSums = $this->sumLedgerActivity($ledgerId, null, $fromDate, $branchId, true);
        $opening = JournalReportHelper::computeBalance(
            (float)$openingSums['debit'],
            (float)$openingSums['credit'],
            $normalBalance
        );

        $sql = "
            SELECT
                je.entry_date,
                je.entry_no,
                je.id AS journal_entry_id,
                je.reference_type,
                je.reference_id,
                je.description AS entry_description,
                COALESCE(je.is_reversed, 0) AS is_reversed,
                jl.debit,
                jl.credit,
                jl.description AS line_description,
                b.branch_name
            FROM journal_lines jl
            INNER JOIN journal_entries je ON je.id = jl.journal_entry_id
            LEFT JOIN branches b ON b.id = je.branch_id
            WHERE jl.ledger_id = :ledger_id
              AND je.entry_date BETWEEN :from_date AND :to_date
              AND COALESCE(je.is_reversed, 0) = 0
        ";

        if ($branchId) {
            $sql .= " AND je.branch_id = :branch_id";
        }

        $sql .= " ORDER BY je.entry_date ASC, je.id ASC, jl.id ASC";

        $this->db->query($sql);
        $this->db->bind(':ledger_id', $ledgerId);
        $this->db->bind(':from_date', $fromDate);
        $this->db->bind(':to_date', $toDate);
        if ($branchId) {
            $this->db->bind(':branch_id', $branchId);
        }

        $rawLines = $this->db->resultSet();

        $runningSigned = $opening['signed_balance'];
        $lines = [];

        foreach ($rawLines as $row) {
            $debit  = (float)$row['debit'];
            $credit = (float)$row['credit'];
            $runningSigned += ($debit - $credit);

            if ($normalBalance === 'debit') {
                $runningDisplay = $runningSigned;
                $runningSide = $runningSigned >= 0 ? 'Dr' : 'Cr';
            } else {
                $runningDisplay = -$runningSigned;
                $runningSide = $runningSigned <= 0 ? 'Cr' : 'Dr';
            }

            $refUrl = JournalReportHelper::referenceUrl($row['reference_type'] ?? null, $row['reference_id'] ?? 0);

            $lines[] = [
                'entry_date'        => $row['entry_date'],
                'entry_no'          => $row['entry_no'],
                'journal_entry_id'  => (int)$row['journal_entry_id'],
                'reference_type'    => $row['reference_type'],
                'reference_id'      => (int)($row['reference_id'] ?? 0),
                'reference_label'   => JournalReportHelper::referenceLabel($row['reference_type'] ?? null),
                'reference_url'     => $refUrl,
                'narration'         => trim((string)($row['line_description'] ?: $row['entry_description'] ?: '')),
                'debit'             => $debit,
                'credit'            => $credit,
                'running_balance'   => round(abs($runningDisplay), 2),
                'running_side'      => $runningSide,
                'branch_name'       => $row['branch_name'] ?? '',
                'is_reversed'       => !empty($row['is_reversed']),
            ];
        }

        $periodSums = $this->sumLedgerActivity($ledgerId, $fromDate, $toDate, $branchId, false);
        $closingDebit  = (float)$openingSums['debit'] + (float)$periodSums['debit'];
        $closingCredit = (float)$openingSums['credit'] + (float)$periodSums['credit'];
        $closing = JournalReportHelper::computeBalance($closingDebit, $closingCredit, $normalBalance);

        return [
            'ledger'     => $ledger,
            'from_date'  => $fromDate,
            'to_date'    => $toDate,
            'branch_id'  => $branchId,
            'opening'    => $opening,
            'lines'      => $lines,
            'closing'    => $closing,
        ];
    }

    private function sumLedgerActivity(
        int $ledgerId,
        ?string $fromDate,
        ?string $toDate,
        ?int $branchId,
        bool $beforeFrom
    ): array {
        $sql = "
            SELECT
                COALESCE(SUM(jl.debit), 0) AS debit,
                COALESCE(SUM(jl.credit), 0) AS credit
            FROM journal_lines jl
            INNER JOIN journal_entries je ON je.id = jl.journal_entry_id
            WHERE jl.ledger_id = :ledger_id
              AND COALESCE(je.is_reversed, 0) = 0
        ";

        if ($beforeFrom) {
            $sql .= " AND je.entry_date < :boundary_date";
        } else {
            $sql .= " AND je.entry_date BETWEEN :from_date AND :to_date";
        }

        if ($branchId) {
            $sql .= " AND je.branch_id = :branch_id";
        }

        $this->db->query($sql);
        $this->db->bind(':ledger_id', $ledgerId);

        if ($beforeFrom) {
            $this->db->bind(':boundary_date', $fromDate);
        } else {
            $this->db->bind(':from_date', $fromDate);
            $this->db->bind(':to_date', $toDate);
        }

        if ($branchId) {
            $this->db->bind(':branch_id', $branchId);
        }

        $row = $this->db->single() ?: [];

        return [
            'debit'  => round((float)($row['debit'] ?? 0), 2),
            'credit' => round((float)($row['credit'] ?? 0), 2),
        ];
    }

    public function exportToCsv(array $reportData): void
    {
        $ledger = $reportData['ledger'] ?? [];
        $filename = 'General_Ledger_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($ledger['ledger_code'] ?? 'ledger'));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');

        fputcsv($output, ['General Ledger Report']);
        fputcsv($output, ['Ledger: ' . ($ledger['ledger_code'] ?? '') . ' — ' . ($ledger['ledger_name'] ?? '')]);
        fputcsv($output, ['Period: ' . ($reportData['from_date'] ?? '') . ' to ' . ($reportData['to_date'] ?? '')]);
        fputcsv($output, ['Generated: ' . date('Y-m-d H:i:s')]);
        fputcsv($output, []);

        $opening = $reportData['opening'] ?? [];
        fputcsv($output, [
            'Opening balance',
            '',
            '',
            '',
            '',
            '',
            number_format((float)($opening['balance'] ?? 0), 2),
            $opening['balance_side'] ?? 'Dr',
        ]);

        fputcsv($output, ['Date', 'Entry No', 'Reference', 'Narration', 'Debit', 'Credit', 'Balance', 'Side']);

        foreach ($reportData['lines'] ?? [] as $row) {
            $ref = ($row['reference_label'] ?? '');
            if (!empty($row['reference_id'])) {
                $ref .= ' #' . $row['reference_id'];
            }

            fputcsv($output, [
                $row['entry_date'] ?? '',
                $row['entry_no'] ?? '',
                $ref,
                $row['narration'] ?? '',
                $row['debit'] > 0 ? number_format((float)$row['debit'], 2) : '',
                $row['credit'] > 0 ? number_format((float)$row['credit'], 2) : '',
                number_format((float)($row['running_balance'] ?? 0), 2),
                $row['running_side'] ?? '',
            ]);
        }

        $closing = $reportData['closing'] ?? [];
        fputcsv($output, []);
        fputcsv($output, [
            'Closing balance',
            '',
            '',
            '',
            '',
            '',
            number_format((float)($closing['balance'] ?? 0), 2),
            $closing['balance_side'] ?? 'Dr',
        ]);

        fclose($output);
        exit;
    }
}
