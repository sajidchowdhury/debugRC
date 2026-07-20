<?php
// app/models/JournalEntryModel.php

require_once __DIR__ . '/../../core/Database.php';

class JournalEntryModel {

    protected $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?? new Database();
    }

    public function setDatabase(Database $db): void
    {
        $this->db = $db;
    }

    /**
     * Create a complete journal entry with lines (double-entry)
     */
    public function createEntry(array $header, array $lines): array
    {
        // Basic validation
        if (empty($lines)) {
            return ['status' => 'error', 'message' => 'Journal lines are required'];
        }

        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($lines as $line) {
            $totalDebit += (float)($line['debit'] ?? 0);
            $totalCredit += (float)($line['credit'] ?? 0);
        }

        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            return ['status' => 'error', 'message' => 'Debits and Credits must be equal'];
        }

        require_once __DIR__ . '/../services/Accounting/AccountingPeriodService.php';
        $periodError = AccountingPeriodService::validatePostingDate(
            (string)($header['entry_date'] ?? date('Y-m-d')),
            isset($header['branch_id']) ? (int)$header['branch_id'] : null
        );
        if ($periodError !== null) {
            return ['status' => 'error', 'message' => $periodError];
        }

        // Transaction management is handled by the caller (OtherExpenseModel, OtherIncomeModel, etc.).
        // We no longer call beginTransaction() here to avoid nested transaction errors.

        try {
            // Generate Entry Number
            $entryNo = $this->generateEntryNo();

            // Insert Journal Entry
            $this->db->query("
                INSERT INTO journal_entries 
                (entry_no, entry_date, description, reference_type, reference_id, 
                 branch_id, total_debit, total_credit, created_by)
                VALUES 
                (:entry_no, :entry_date, :description, :reference_type, :reference_id,
                 :branch_id, :total_debit, :total_credit, :created_by)
            ");

            $this->db->bind(':entry_no', $entryNo);
            $this->db->bind(':entry_date', $header['entry_date'] ?? date('Y-m-d'));
            $this->db->bind(':description', $header['description'] ?? '');
            $this->db->bind(':reference_type', $header['reference_type'] ?? null);
            $this->db->bind(':reference_id', $header['reference_id'] ?? null);
            $this->db->bind(':branch_id', $header['branch_id'] ?? ($_SESSION['branch_id'] ?? 1));
            $this->db->bind(':total_debit', $totalDebit);
            $this->db->bind(':total_credit', $totalCredit);
            $this->db->bind(':created_by', $_SESSION['user_id'] ?? 1);

            $this->db->execute();
            $journalEntryId = $this->db->lastInsertId();

            if (empty($journalEntryId) || (int)$journalEntryId <= 0) {
                // Fallback: retrieve by the entry_no we just generated (unique key guarantees it)
                $this->db->query("SELECT id FROM journal_entries WHERE entry_no = :eno LIMIT 1");
                $this->db->bind(':eno', $entryNo);
                $row = $this->db->single();
                $journalEntryId = $row['id'] ?? null;
            }

            if (empty($journalEntryId) || (int)$journalEntryId <= 0) {
                throw new \Exception('Journal header inserted (entry_no=' . $entryNo . ') but lastInsertId() and lookup both returned no id. Verify journal_entries.id has AUTO_INCREMENT attribute.');
            }

            // Insert Journal Lines
            foreach ($lines as $line) {
                $this->db->query("
                    INSERT INTO journal_lines 
                    (journal_entry_id, ledger_id, debit, credit, description, entity_type, entity_id)
                    VALUES 
                    (:journal_entry_id, :ledger_id, :debit, :credit, :description, :entity_type, :entity_id)
                ");

                $this->db->bind(':journal_entry_id', $journalEntryId);
                $this->db->bind(':ledger_id', $line['ledger_id']);
                $this->db->bind(':debit', $line['debit'] ?? 0);
                $this->db->bind(':credit', $line['credit'] ?? 0);
                $this->db->bind(':description', $line['description'] ?? null);
                $this->db->bind(':entity_type', $line['entity_type'] ?? null);
                $this->db->bind(':entity_id', $line['entity_id'] ?? null);

                $this->db->execute();
            }

            return [
                'status' => 'success',
                'message' => 'Journal entry created successfully',
                'journal_entry_id' => $journalEntryId,
                'entry_no' => $entryNo
            ];

        } catch (Exception $e) {
            // Let the outer transaction (in OtherExpenseModel etc.) handle rollback
            throw $e;   // Re-throw so caller can catch and rollback
        }
    }

    /**
     * Get a journal entry with all its lines
     */
    public function getEntryWithLines($journalEntryId)
    {
        $this->db->query("
            SELECT je.*, u.username as created_by_name
            FROM journal_entries je
            LEFT JOIN users u ON u.id = je.created_by
            WHERE je.id = :id
        ");
        $this->db->bind(':id', $journalEntryId);
        $entry = $this->db->single();

        if (!$entry) return null;

        $this->db->query("
            SELECT jl.*, l.ledger_name, l.ledger_code
            FROM journal_lines jl
            JOIN ledgers l ON l.id = jl.ledger_id
            WHERE jl.journal_entry_id = :id
            ORDER BY jl.id ASC
        ");
        $this->db->bind(':id', $journalEntryId);
        $entry['lines'] = $this->db->resultSet();

        return $entry;
    }

    /**
     * Get recent journal entries
     */
    public function getRecentEntries($limit = 50)
    {
        $this->db->query("
            SELECT je.*, u.username as created_by_name
            FROM journal_entries je
            LEFT JOIN users u ON u.id = je.created_by
            ORDER BY je.entry_date DESC, je.id DESC
            LIMIT :limit
        ");
        $this->db->bind(':limit', $limit);
        return $this->db->resultSet();
    }

    private function generateEntryNo(): string
    {
        $this->db->query("SELECT COUNT(*) as total FROM journal_entries");
        $row = $this->db->single();
        $next = str_pad(($row['total'] ?? 0) + 1, 6, '0', STR_PAD_LEFT);
        return "JE-" . date('Y') . "-" . $next;
    }

    /**
     * Find the journal entry id for a given reference (e.g. other_expense)
     */
    public function findJournalEntryByReference($referenceType, $referenceId): ?int
    {
        $this->db->query("
            SELECT id FROM journal_entries 
            WHERE reference_type = :type AND reference_id = :ref_id 
            LIMIT 1
        ");
        $this->db->bind(':type', $referenceType);
        $this->db->bind(':ref_id', $referenceId);
        $row = $this->db->single();
        return $row['id'] ?? null;
    }

    /**
     * Latest non-reversed journal for a document reference (e.g. sales_invoice_adjustment).
     */
    public function findActiveJournalEntryByReference(string $referenceType, int $referenceId): ?int
    {
        $this->db->query("
            SELECT id FROM journal_entries
            WHERE reference_type = :type AND reference_id = :ref_id
              AND COALESCE(is_reversed, 0) = 0
            ORDER BY id DESC
            LIMIT 1
        ");
        $this->db->bind(':type', $referenceType);
        $this->db->bind(':ref_id', $referenceId);
        $row = $this->db->single();
        return isset($row['id']) ? (int)$row['id'] : null;
    }

    /**
     * Create a reversing journal entry for an existing one.
     * Swaps all debits and credits.
     */
    public function createReversingEntry($originalJournalEntryId, $reason = ''): array
    {
        // Get original entry + lines
        $original = $this->getEntryWithLines($originalJournalEntryId);
        if (!$original) {
            return ['status' => 'error', 'message' => 'Original journal entry not found'];
        }

        if (!empty($original['is_reversed'])) {
            return [
                'status'           => 'success',
                'message'          => 'Journal already reversed',
                'journal_entry_id' => null,
            ];
        }

        $reversingLines = [];
        foreach ($original['lines'] as $line) {
            $reversingLines[] = [
                'ledger_id'   => $line['ledger_id'],
                'debit'       => $line['credit'],   // Swap
                'credit'      => $line['debit'],    // Swap
                'description' => 'Reversal: ' . ($line['description'] ?? ''),
                'entity_type' => $line['entity_type'],
                'entity_id'   => $line['entity_id'],
            ];
        }

        $header = [
            'entry_date'     => date('Y-m-d'),
            'description'    => 'Reversal of ' . $original['entry_no'] . ' - ' . $reason,
            'reference_type' => 'reversal',
            'reference_id'   => $originalJournalEntryId,
            'branch_id'      => $original['branch_id'],
        ];

        $result = $this->createEntry($header, $reversingLines);

        if ($result['status'] === 'success') {
            $this->db->query('
                UPDATE journal_entries
                SET reversal_of_entry_id = :original_id
                WHERE id = :new_id
            ');
            $this->db->bind(':original_id', $originalJournalEntryId);
            $this->db->bind(':new_id', $result['journal_entry_id']);
            $this->db->execute();

            $this->db->query('UPDATE journal_entries SET is_reversed = 1 WHERE id = :id');
            $this->db->bind(':id', $originalJournalEntryId);
            $this->db->execute();
        }

        return $result;
    }
}