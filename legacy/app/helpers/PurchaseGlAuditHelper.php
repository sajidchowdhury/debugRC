<?php
// app/helpers/PurchaseGlAuditHelper.php — load linked journal entries for purchase GL audit surfaces (Phase 5B).

require_once __DIR__ . '/../models/JournalEntryModel.php';
require_once __DIR__ . '/../../core/Database.php';

class PurchaseGlAuditHelper
{
    public static function entryWithLines(?int $journalEntryId): ?array
    {
        $journalEntryId = (int)($journalEntryId ?? 0);
        if ($journalEntryId <= 0) {
            return null;
        }

        return (new JournalEntryModel())->getEntryWithLines($journalEntryId);
    }

    public static function reversingEntry(?int $originalJournalEntryId): ?array
    {
        $originalJournalEntryId = (int)($originalJournalEntryId ?? 0);
        if ($originalJournalEntryId <= 0) {
            return null;
        }

        $db = new Database();
        $db->query("
            SELECT id
            FROM journal_entries
            WHERE reversal_of_entry_id = :orig
            ORDER BY id DESC
            LIMIT 1
        ");
        $db->bind(':orig', $originalJournalEntryId, PDO::PARAM_INT);
        $row = $db->single();
        if (!$row || empty($row['id'])) {
            return null;
        }

        return self::entryWithLines((int)$row['id']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function grnJournalBlocks(array $receive): array
    {
        $receiveId = (int)($receive['id'] ?? 0);
        $jeId = (int)($receive['journal_entry_id'] ?? 0);
        $status = strtolower(trim((string)($receive['status'] ?? '')));
        $isCancelled = $status === 'cancelled';

        return [[
            'title'          => 'GRN — Dr Inventory / Cr Supplier Payable',
            'entry'          => self::entryWithLines($jeId),
            'reversing'      => $isCancelled ? self::reversingEntry($jeId) : null,
            'reference_type' => 'purchase_receive',
            'reference_id'   => $receiveId,
            'empty_message'  => $jeId <= 0
                ? 'No journal linked on this GRN (pre-GL or posting failed).'
                : null,
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function returnJournalBlocks(array $return): array
    {
        $returnId = (int)($return['id'] ?? 0);
        $jeId = (int)($return['journal_entry_id'] ?? 0);
        $isReversed = !empty($return['is_reversed']);

        return [[
            'title'          => 'Purchase return — Dr Supplier Payable / Cr Inventory',
            'entry'          => self::entryWithLines($jeId),
            'reversing'      => $isReversed ? self::reversingEntry($jeId) : null,
            'reference_type' => 'purchase_return',
            'reference_id'   => $returnId,
            'empty_message'  => $jeId <= 0
                ? 'No journal linked on this return (pre-GL or posting failed).'
                : null,
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getReceiveReturns(int $receiveId): array
    {
        $receiveId = (int)$receiveId;
        if ($receiveId <= 0) {
            return [];
        }

        $db = new Database();
        $db->query("
            SELECT prt.id, prt.return_code, prt.return_date, prt.total_amount,
                   prt.journal_entry_id, prt.is_reversed
            FROM purchase_returns prt
            WHERE prt.purchase_receive_id = :rid
            ORDER BY prt.id DESC
        ");
        $db->bind(':rid', $receiveId, PDO::PARAM_INT);

        return $db->resultSet() ?: [];
    }
}
