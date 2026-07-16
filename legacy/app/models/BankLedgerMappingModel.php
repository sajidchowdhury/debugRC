<?php
// app/models/BankLedgerMappingModel.php — Phase 5 per-bank GL account

require_once __DIR__ . '/../../core/Database.php';

class BankLedgerMappingModel
{
    protected Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? new Database();
    }

    public function tableExists(): bool
    {
        try {
            $this->db->query("SHOW TABLES LIKE 'bank_ledger_mappings'");
            return (bool)$this->db->single();
        } catch (Throwable $e) {
            return false;
        }
    }

    public function getLedgerIdForBank(int $bankId): ?int
    {
        if ($bankId <= 0 || !$this->tableExists()) {
            return null;
        }

        $this->db->query("
            SELECT ledger_id FROM bank_ledger_mappings
            WHERE bank_id = :bid
            LIMIT 1
        ");
        $this->db->bind(':bid', $bankId);
        $row = $this->db->single();

        return isset($row['ledger_id']) ? (int)$row['ledger_id'] : null;
    }

    public function saveMapping(int $bankId, int $ledgerId): bool
    {
        if ($bankId <= 0 || $ledgerId <= 0 || !$this->tableExists()) {
            return false;
        }

        $this->db->query("
            INSERT INTO bank_ledger_mappings (bank_id, ledger_id)
            VALUES (:bid, :lid)
            ON DUPLICATE KEY UPDATE ledger_id = VALUES(ledger_id)
        ");
        $this->db->bind(':bid', $bankId);
        $this->db->bind(':lid', $ledgerId);

        return $this->db->execute();
    }
}