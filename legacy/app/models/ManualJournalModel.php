<?php
// app/models/ManualJournalModel.php — Phase 6A manual GL journals

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/JournalEntryModel.php';
require_once __DIR__ . '/../helpers/Helper.php';

class ManualJournalModel
{
    private Database $db;
    private JournalEntryModel $journalModel;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? new Database();
        $this->journalModel = new JournalEntryModel($this->db);
    }

    public function canOverrideBranch(): bool
    {
        return (new Helper($this->db))->canOverrideBranch();
    }

    public function resolveBranchId(?int $requestedBranchId): int
    {
        if ($this->canOverrideBranch() && $requestedBranchId > 0) {
            return $requestedBranchId;
        }
        return Helper::sessionBranchId() ?: 1;
    }

    public function userCanAccess(array $row): bool
    {
        if ($this->canOverrideBranch()) {
            return true;
        }
        $sessionBranch = Helper::sessionBranchId();
        return $sessionBranch <= 0 || (int)($row['branch_id'] ?? 0) === $sessionBranch;
    }

    public function canUserReverse(array $row): bool
    {
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, ['admin', 'accountant'], true)) {
            return false;
        }
        if (!$this->userCanAccess($row)) {
            return false;
        }
        return empty($row['is_reversed']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listManualJournals(array $filters = []): array
    {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($filters['from_date'] ?? ''))
            ? $filters['from_date']
            : date('Y-m-01');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($filters['to_date'] ?? ''))
            ? $filters['to_date']
            : date('Y-m-d');

        $sql = "
            SELECT
                mj.id,
                mj.internal_note,
                mj.attachment_filename,
                mj.branch_id,
                mj.created_at,
                je.id AS journal_entry_id,
                je.entry_no,
                je.entry_date,
                je.description,
                je.total_debit,
                je.total_credit,
                je.is_reversed,
                je.reference_type,
                je.reference_id,
                b.branch_name,
                u.username AS created_by_name,
                (SELECT COUNT(*) FROM journal_lines jl WHERE jl.journal_entry_id = je.id) AS line_count
            FROM manual_journals mj
            INNER JOIN journal_entries je ON je.id = mj.journal_entry_id
            LEFT JOIN branches b ON b.id = mj.branch_id
            LEFT JOIN users u ON u.id = mj.created_by
            WHERE je.entry_date BETWEEN :from_date AND :to_date
        ";

        $branchId = isset($filters['branch_id']) && (int)$filters['branch_id'] > 0
            ? (int)$filters['branch_id']
            : null;

        if (!$this->canOverrideBranch()) {
            $branchId = Helper::sessionBranchId() ?: null;
        }

        if ($branchId) {
            $sql .= ' AND mj.branch_id = :branch_id';
        }

        if (!empty($filters['reversed_only'])) {
            $sql .= ' AND COALESCE(je.is_reversed, 0) = 1';
        } elseif (!empty($filters['active_only'])) {
            $sql .= ' AND COALESCE(je.is_reversed, 0) = 0';
        }

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $sql .= ' AND (je.entry_no LIKE :search OR je.description LIKE :search OR mj.internal_note LIKE :search)';
        }

        $sql .= ' ORDER BY je.entry_date DESC, mj.id DESC LIMIT 500';

        $this->db->query($sql);
        $this->db->bind(':from_date', $from);
        $this->db->bind(':to_date', $to);
        if ($branchId) {
            $this->db->bind(':branch_id', $branchId);
        }
        if ($search !== '') {
            $this->db->bind(':search', '%' . $search . '%');
        }

        return $this->db->resultSet() ?: [];
    }

    public function getIndexStats(?int $branchId = null): array
    {
        if (!$this->canOverrideBranch()) {
            $branchId = Helper::sessionBranchId() ?: null;
        }

        $branchSql = $branchId ? ' AND mj.branch_id = ' . (int)$branchId : '';

        $this->db->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN COALESCE(je.is_reversed, 0) = 0 THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN COALESCE(je.is_reversed, 0) = 1 THEN 1 ELSE 0 END) AS reversed_count,
                SUM(CASE WHEN je.entry_date = CURDATE() AND COALESCE(je.is_reversed, 0) = 0 THEN 1 ELSE 0 END) AS today_count
            FROM manual_journals mj
            INNER JOIN journal_entries je ON je.id = mj.journal_entry_id
            WHERE 1=1 {$branchSql}
        ");
        $row = $this->db->single() ?: [];

        return [
            'total'    => (int)($row['total'] ?? 0),
            'active'   => (int)($row['active_count'] ?? 0),
            'reversed' => (int)($row['reversed_count'] ?? 0),
            'today'    => (int)($row['today_count'] ?? 0),
        ];
    }

    public function getById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $this->db->query("
            SELECT
                mj.*,
                je.entry_no,
                je.entry_date,
                je.description,
                je.total_debit,
                je.total_credit,
                je.is_reversed,
                je.reference_type,
                je.reference_id,
                je.reversal_of_entry_id,
                b.branch_name,
                u.username AS created_by_name
            FROM manual_journals mj
            INNER JOIN journal_entries je ON je.id = mj.journal_entry_id
            LEFT JOIN branches b ON b.id = mj.branch_id
            LEFT JOIN users u ON u.id = mj.created_by
            WHERE mj.id = :id
            LIMIT 1
        ");
        $this->db->bind(':id', $id);
        $row = $this->db->single();

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed>|null $uploadedFile $_FILES['attachment'] or null
     */
    public function createManualJournal(array $data, array $lines, ?array $uploadedFile = null): array
    {
        $entryDate = trim((string)($data['entry_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
            return ['status' => 'error', 'message' => 'Valid entry date is required'];
        }

        $description = trim((string)($data['description'] ?? ''));
        if ($description === '') {
            return ['status' => 'error', 'message' => 'Description / narration is required'];
        }

        $branchId = $this->resolveBranchId((int)($data['branch_id'] ?? 0));

        require_once __DIR__ . '/../services/Accounting/AccountingPeriodService.php';
        $periodError = AccountingPeriodService::validatePostingDate($entryDate, $branchId);
        if ($periodError !== null) {
            return ['status' => 'error', 'message' => $periodError];
        }

        $lineValidation = $this->normalizeLines($lines);
        if ($lineValidation['status'] !== 'success') {
            return $lineValidation;
        }
        $normalizedLines = $lineValidation['lines'];

        $attachment = null;
        if ($uploadedFile && !empty($uploadedFile['tmp_name'])) {
            $attachmentResult = $this->storeAttachment($uploadedFile);
            if ($attachmentResult['status'] !== 'success') {
                return $attachmentResult;
            }
            $attachment = $attachmentResult;
        }

        $this->db->beginTransaction();

        try {
            $header = [
                'entry_date'     => $entryDate,
                'description'    => $description,
                'reference_type' => 'manual',
                'reference_id'   => null,
                'branch_id'      => $branchId,
            ];

            $jeResult = $this->journalModel->createEntry($header, $normalizedLines);
            if (($jeResult['status'] ?? '') !== 'success') {
                $this->db->rollback();
                return $jeResult;
            }

            $journalEntryId = (int)($jeResult['journal_entry_id'] ?? 0);
            if ($journalEntryId <= 0) {
                $this->db->rollback();
                return ['status' => 'error', 'message' => 'Journal entry was not created'];
            }

            $this->db->query("
                INSERT INTO manual_journals
                (journal_entry_id, internal_note, attachment_filename, attachment_path, branch_id, created_by)
                VALUES
                (:journal_entry_id, :internal_note, :attachment_filename, :attachment_path, :branch_id, :created_by)
            ");
            $this->db->bind(':journal_entry_id', $journalEntryId);
            $this->db->bind(':internal_note', trim((string)($data['internal_note'] ?? '')) ?: null);
            $this->db->bind(':attachment_filename', $attachment['filename'] ?? null);
            $this->db->bind(':attachment_path', $attachment['path'] ?? null);
            $this->db->bind(':branch_id', $branchId);
            $this->db->bind(':created_by', (int)($_SESSION['user_id'] ?? 0) ?: null);
            $this->db->execute();

            $manualId = (int)$this->db->lastInsertId();
            if ($manualId <= 0) {
                $this->db->query('SELECT id FROM manual_journals WHERE journal_entry_id = :je LIMIT 1');
                $this->db->bind(':je', $journalEntryId);
                $manualId = (int)($this->db->single()['id'] ?? 0);
            }

            $this->db->query('UPDATE journal_entries SET reference_id = :ref WHERE id = :id');
            $this->db->bind(':ref', $manualId);
            $this->db->bind(':id', $journalEntryId);
            $this->db->execute();

            $this->logPosting($journalEntryId, 'posted', 'Manual journal #' . $manualId);

            $this->db->commit();

            return [
                'status'           => 'success',
                'message'          => 'Manual journal posted successfully',
                'manual_journal_id'=> $manualId,
                'journal_entry_id' => $journalEntryId,
                'entry_no'         => $jeResult['entry_no'] ?? '',
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($attachment && !empty($attachment['path'])) {
                @unlink($attachment['absolute_path'] ?? '');
            }
            error_log('ManualJournalModel::createManualJournal: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Could not post manual journal. Please try again.'];
        }
    }

    public function reverseManualJournal(int $manualId, string $reason = ''): array
    {
        $row = $this->getById($manualId);
        if (!$row || !$this->userCanAccess($row)) {
            return ['status' => 'error', 'message' => 'Manual journal not found'];
        }
        if (!$this->canUserReverse($row)) {
            return ['status' => 'error', 'message' => 'This journal cannot be reversed'];
        }

        $reason = trim($reason);
        if ($reason === '') {
            return ['status' => 'error', 'message' => 'Reversal reason is required'];
        }

        $journalEntryId = (int)($row['journal_entry_id'] ?? 0);
        if ($journalEntryId <= 0) {
            return ['status' => 'error', 'message' => 'Linked journal entry missing'];
        }

        $this->db->beginTransaction();

        try {
            $result = $this->journalModel->createReversingEntry($journalEntryId, $reason);
            if (($result['status'] ?? '') !== 'success') {
                $this->db->rollback();
                return $result;
            }

            if (!empty($result['journal_entry_id'])) {
                $this->logPosting((int)$result['journal_entry_id'], 'reversed', $reason);
            }

            $this->db->commit();

            return [
                'status'           => 'success',
                'message'          => 'Manual journal reversed',
                'manual_journal_id'=> $manualId,
                'journal_entry_id' => $journalEntryId,
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('ManualJournalModel::reverseManualJournal: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Could not reverse manual journal'];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array{status: string, message?: string, lines?: array<int, array<string, mixed>>}
     */
    public function normalizeLines(array $lines): array
    {
        $normalized = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($lines as $line) {
            $ledgerId = (int)($line['ledger_id'] ?? 0);
            $debit = round(max(0, (float)($line['debit'] ?? 0)), 2);
            $credit = round(max(0, (float)($line['credit'] ?? 0)), 2);

            if ($ledgerId <= 0) {
                continue;
            }
            if ($debit <= 0 && $credit <= 0) {
                continue;
            }
            if ($debit > 0 && $credit > 0) {
                return ['status' => 'error', 'message' => 'Each line must be either debit or credit, not both'];
            }

            if (!$this->ledgerExists($ledgerId)) {
                return ['status' => 'error', 'message' => 'Invalid ledger selected on one or more lines'];
            }

            $normalized[] = [
                'ledger_id'   => $ledgerId,
                'debit'       => $debit,
                'credit'      => $credit,
                'description' => trim((string)($line['description'] ?? '')) ?: null,
            ];
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        if (count($normalized) < 2) {
            return ['status' => 'error', 'message' => 'At least two lines with amounts are required'];
        }

        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            return [
                'status'  => 'error',
                'message' => 'Debits (' . number_format($totalDebit, 2) . ') must equal credits (' . number_format($totalCredit, 2) . ')',
            ];
        }

        return ['status' => 'success', 'lines' => $normalized];
    }

    private function ledgerExists(int $ledgerId): bool
    {
        $this->db->query('SELECT id FROM ledgers WHERE id = :id AND is_active = 1 LIMIT 1');
        $this->db->bind(':id', $ledgerId);
        return (bool)$this->db->single();
    }

    /**
     * @param array<string, mixed> $file
     * @return array{status: string, message?: string, filename?: string, path?: string, absolute_path?: string}
     */
    private function storeAttachment(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['status' => 'error', 'message' => 'Attachment upload failed'];
        }

        $maxBytes = 5 * 1024 * 1024;
        if ((int)($file['size'] ?? 0) > $maxBytes) {
            return ['status' => 'error', 'message' => 'Attachment must be 5 MB or smaller'];
        }

        $original = basename((string)($file['name'] ?? 'attachment'));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'];
        if (!in_array($ext, $allowed, true)) {
            return ['status' => 'error', 'message' => 'Attachment type not allowed'];
        }

        $dir = dirname(__DIR__, 2) . '/public/uploads/manual_journals';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['status' => 'error', 'message' => 'Could not create upload directory'];
        }

        $stored = 'MJ-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $absolute = $dir . '/' . $stored;
        if (!move_uploaded_file($file['tmp_name'], $absolute)) {
            return ['status' => 'error', 'message' => 'Could not save attachment'];
        }

        return [
            'status'        => 'success',
            'filename'      => $original,
            'path'          => 'uploads/manual_journals/' . $stored,
            'absolute_path' => $absolute,
        ];
    }

    private function logPosting(int $journalEntryId, string $action, string $remarks): void
    {
        try {
            $this->db->query("SHOW TABLES LIKE 'journal_posting_logs'");
            if (!$this->db->single()) {
                return;
            }
            $this->db->query("
                INSERT INTO journal_posting_logs (journal_entry_id, action, performed_by, remarks)
                VALUES (:je, :action, :user, :remarks)
            ");
            $this->db->bind(':je', $journalEntryId);
            $this->db->bind(':action', $action);
            $this->db->bind(':user', (int)($_SESSION['user_id'] ?? 0) ?: null);
            $this->db->bind(':remarks', $remarks);
            $this->db->execute();
        } catch (Throwable $e) {
            error_log('ManualJournalModel::logPosting: ' . $e->getMessage());
        }
    }
}
