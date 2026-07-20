<?php
// app/models/Reports/JournalEntriesReport.php

require_once __DIR__ . '/../../helpers/JournalReportHelper.php';

class JournalEntriesReport
{
    protected $db;

    public function __construct()
    {
        require_once '../core/Database.php';
        $this->db = new Database();
    }

    /**
     * @param array{
     *   from_date?: string,
     *   to_date?: string,
     *   reference_type?: string|null,
     *   branch_id?: int|null,
     *   reversed?: string|null,
     *   created_by?: int|null,
     *   search?: string|null
     * } $filters
     */
    public function listEntries(array $filters): array
    {
        $fromDate = $filters['from_date'] ?? date('Y-m-01');
        $toDate   = $filters['to_date']   ?? date('Y-m-d');

        $sql = "
            SELECT
                je.*,
                u.username AS created_by_name,
                b.branch_name,
                (SELECT COUNT(*) FROM journal_lines jl WHERE jl.journal_entry_id = je.id) AS line_count
            FROM journal_entries je
            LEFT JOIN users u ON u.id = je.created_by
            LEFT JOIN branches b ON b.id = je.branch_id
            WHERE je.entry_date BETWEEN :from_date AND :to_date
        ";

        if (!empty($filters['reference_type'])) {
            $sql .= " AND je.reference_type = :reference_type";
        }

        if (!empty($filters['branch_id'])) {
            $sql .= " AND je.branch_id = :branch_id";
        }

        $reversed = $filters['reversed'] ?? '';
        if ($reversed === 'yes') {
            $sql .= " AND COALESCE(je.is_reversed, 0) = 1";
        } elseif ($reversed === 'no') {
            $sql .= " AND COALESCE(je.is_reversed, 0) = 0";
        }

        if (!empty($filters['created_by'])) {
            $sql .= " AND je.created_by = :created_by";
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (
                je.entry_no LIKE :search
                OR je.description LIKE :search
                OR CAST(je.reference_id AS CHAR) LIKE :search
            )";
        }

        $sql .= " ORDER BY je.entry_date DESC, je.id DESC";

        $this->db->query($sql);
        $this->db->bind(':from_date', $fromDate);
        $this->db->bind(':to_date', $toDate);

        if (!empty($filters['reference_type'])) {
            $this->db->bind(':reference_type', $filters['reference_type']);
        }
        if (!empty($filters['branch_id'])) {
            $this->db->bind(':branch_id', (int)$filters['branch_id']);
        }
        if (!empty($filters['created_by'])) {
            $this->db->bind(':created_by', (int)$filters['created_by']);
        }
        if (!empty($filters['search'])) {
            $this->db->bind(':search', '%' . $filters['search'] . '%');
        }

        $entries = $this->db->resultSet();

        if (empty($entries)) {
            return [
                'from_date' => $fromDate,
                'to_date'   => $toDate,
                'entries'   => [],
                'total'     => 0,
            ];
        }

        $entryIds = array_column($entries, 'id');
        $linesByEntry = $this->loadLinesForEntries($entryIds);

        $rows = [];
        foreach ($entries as $entry) {
            $entryId = (int)$entry['id'];
            $refUrl = JournalReportHelper::referenceUrl($entry['reference_type'] ?? null, $entry['reference_id'] ?? 0);

            $rows[] = [
                'id'               => $entryId,
                'entry_no'         => $entry['entry_no'],
                'entry_date'       => $entry['entry_date'],
                'description'      => $entry['description'],
                'reference_type'   => $entry['reference_type'],
                'reference_id'     => (int)($entry['reference_id'] ?? 0),
                'reference_label'  => JournalReportHelper::referenceLabel($entry['reference_type'] ?? null),
                'reference_url'    => $refUrl,
                'branch_name'      => $entry['branch_name'] ?? '',
                'total_debit'      => (float)($entry['total_debit'] ?? 0),
                'total_credit'     => (float)($entry['total_credit'] ?? 0),
                'is_reversed'      => !empty($entry['is_reversed']),
                'created_by_name'  => $entry['created_by_name'] ?? '',
                'line_count'       => (int)($entry['line_count'] ?? 0),
                'lines'            => $linesByEntry[$entryId] ?? [],
            ];
        }

        return [
            'from_date' => $fromDate,
            'to_date'   => $toDate,
            'entries'   => $rows,
            'total'     => count($rows),
        ];
    }

    /** @param int[] $entryIds */
    private function loadLinesForEntries(array $entryIds): array
    {
        if (empty($entryIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));

        $this->db->query("
            SELECT jl.*, l.ledger_name, l.ledger_code
            FROM journal_lines jl
            JOIN ledgers l ON l.id = jl.ledger_id
            WHERE jl.journal_entry_id IN ($placeholders)
            ORDER BY jl.journal_entry_id ASC, jl.id ASC
        ");

        foreach ($entryIds as $i => $id) {
            $this->db->bind($i + 1, (int)$id);
        }

        $lines = $this->db->resultSet();
        $grouped = [];

        foreach ($lines as $line) {
            $jeId = (int)$line['journal_entry_id'];
            $grouped[$jeId][] = $line;
        }

        return $grouped;
    }

    public function getJournalCreators(): array
    {
        $this->db->query("
            SELECT DISTINCT u.id, u.username
            FROM journal_entries je
            JOIN users u ON u.id = je.created_by
            ORDER BY u.username ASC
        ");

        return $this->db->resultSet();
    }

    public function exportToCsv(array $reportData): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Journal_Entries_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');

        fputcsv($output, ['Journal Entries Report']);
        fputcsv($output, ['Period: ' . ($reportData['from_date'] ?? '') . ' to ' . ($reportData['to_date'] ?? '')]);
        fputcsv($output, ['Generated: ' . date('Y-m-d H:i:s')]);
        fputcsv($output, []);

        fputcsv($output, [
            'Date', 'Entry No', 'Reference', 'Branch', 'Description',
            'Debit', 'Credit', 'Reversed', 'Created By',
            'Line Ledger', 'Line Debit', 'Line Credit',
        ]);

        foreach ($reportData['entries'] ?? [] as $entry) {
            $ref = ($entry['reference_label'] ?? '');
            if (!empty($entry['reference_id'])) {
                $ref .= ' #' . $entry['reference_id'];
            }

            $lines = $entry['lines'] ?? [];
            if (empty($lines)) {
                fputcsv($output, [
                    $entry['entry_date'] ?? '',
                    $entry['entry_no'] ?? '',
                    $ref,
                    $entry['branch_name'] ?? '',
                    $entry['description'] ?? '',
                    number_format((float)($entry['total_debit'] ?? 0), 2),
                    number_format((float)($entry['total_credit'] ?? 0), 2),
                    !empty($entry['is_reversed']) ? 'Yes' : 'No',
                    $entry['created_by_name'] ?? '',
                    '', '', '',
                ]);
                continue;
            }

            $first = true;
            foreach ($lines as $line) {
                fputcsv($output, [
                    $first ? ($entry['entry_date'] ?? '') : '',
                    $first ? ($entry['entry_no'] ?? '') : '',
                    $first ? $ref : '',
                    $first ? ($entry['branch_name'] ?? '') : '',
                    $first ? ($entry['description'] ?? '') : '',
                    $first ? number_format((float)($entry['total_debit'] ?? 0), 2) : '',
                    $first ? number_format((float)($entry['total_credit'] ?? 0), 2) : '',
                    $first ? (!empty($entry['is_reversed']) ? 'Yes' : 'No') : '',
                    $first ? ($entry['created_by_name'] ?? '') : '',
                    ($line['ledger_code'] ?? '') . ' ' . ($line['ledger_name'] ?? ''),
                    (float)($line['debit'] ?? 0) > 0 ? number_format((float)$line['debit'], 2) : '',
                    (float)($line['credit'] ?? 0) > 0 ? number_format((float)$line['credit'], 2) : '',
                ]);
                $first = false;
            }
        }

        fclose($output);
        exit;
    }
}
