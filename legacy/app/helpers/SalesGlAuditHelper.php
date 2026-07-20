<?php
// app/helpers/SalesGlAuditHelper.php — load linked journal entries for sales GL audit surfaces (Phase 5A).

require_once __DIR__ . '/../models/JournalEntryModel.php';
require_once __DIR__ . '/../../core/Database.php';

class SalesGlAuditHelper
{
    public static function entryWithLines(?int $journalEntryId): ?array
    {
        $journalEntryId = (int)($journalEntryId ?? 0);
        if ($journalEntryId <= 0) {
            return null;
        }

        return (new JournalEntryModel())->getEntryWithLines($journalEntryId);
    }

    /**
     * Reversing journal created for an original entry (reference_type = reversal).
     */
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
    public static function invoiceJournalBlocks(array $invoice): array
    {
        $invoiceId = (int)($invoice['id'] ?? 0);
        $jeId = (int)($invoice['journal_entry_id'] ?? 0);
        $blocks = [];

        $blocks[] = [
            'title'         => 'Invoice — AR & revenue',
            'entry'         => self::entryWithLines($jeId),
            'reversing'     => !empty($invoice['is_reversed']) ? self::reversingEntry($jeId) : null,
            'reference_type'=> 'sales_invoice',
            'reference_id'  => $invoiceId,
            'empty_message' => $jeId <= 0
                ? 'No journal linked on this invoice yet (draft or pre-GL).'
                : null,
        ];

        return $blocks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function challanJournalBlocks(array $challan): array
    {
        $challanId = (int)($challan['id'] ?? 0);
        $cogsJeId = (int)($challan['journal_entry_id'] ?? 0);
        $adjJeId = (int)($challan['adjustment_journal_entry_id'] ?? 0);
        $isReversed = !empty($challan['is_reversed']);
        $blocks = [];

        $blocks[] = [
            'title'         => 'Challan — COGS & inventory',
            'entry'         => self::entryWithLines($cogsJeId),
            'reversing'     => $isReversed ? self::reversingEntry($cogsJeId) : null,
            'reference_type'=> 'sales_challan',
            'reference_id'  => $challanId,
            'empty_message' => $cogsJeId <= 0 ? 'No COGS journal linked on this challan.' : null,
        ];

        $transportAdj = (float)($challan['transport_adjustment'] ?? 0);
        if ($adjJeId > 0 || abs($transportAdj) > 0.0001) {
            $blocks[] = [
                'title'         => 'Transport / total adjustment',
                'entry'         => self::entryWithLines($adjJeId),
                'reversing'     => $isReversed ? self::reversingEntry($adjJeId) : null,
                'reference_type'=> 'sales_invoice_adjustment',
                'reference_id'  => (int)($challan['sales_invoice_id'] ?? 0),
                'empty_message' => $adjJeId <= 0 && abs($transportAdj) > 0.0001
                    ? 'Transport adjustment amount is set but adjustment_journal_entry_id is missing.'
                    : null,
            ];
        }

        return $blocks;
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
            'title'         => 'Sales return — revenue & inventory restore',
            'entry'         => self::entryWithLines($jeId),
            'reversing'     => $isReversed ? self::reversingEntry($jeId) : null,
            'reference_type'=> 'sales_return',
            'reference_id'  => $returnId,
            'empty_message' => $jeId <= 0
                ? 'No journal linked (pending warehouse confirm or pre-GL).'
                : null,
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getInvoiceChallans(int $invoiceId): array
    {
        $invoiceId = (int)$invoiceId;
        if ($invoiceId <= 0) {
            return [];
        }

        $db = new Database();
        $db->query("
            SELECT sc.id, sc.challan_code, sc.challan_date, sc.journal_entry_id,
                   sc.adjustment_journal_entry_id, sc.transport_adjustment, sc.is_reversed
            FROM sales_challans sc
            WHERE sc.sales_invoice_id = :iid
            ORDER BY sc.id DESC
        ");
        $db->bind(':iid', $invoiceId, PDO::PARAM_INT);

        return $db->resultSet() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getInvoicePayments(int $invoiceId): array
    {
        $invoiceId = (int)$invoiceId;
        if ($invoiceId <= 0) {
            return [];
        }

        $db = new Database();
        $db->query("
            SELECT cp.id, cp.payment_code, cp.payment_date, cp.amount,
                   cp.journal_entry_id, cp.is_reversed, cp.transaction_type
            FROM customer_payments cp
            INNER JOIN invoice_payment_allocations ipa ON ipa.customer_payment_id = cp.id
            WHERE ipa.sales_invoice_id = :iid
            ORDER BY cp.payment_date DESC, cp.id DESC
        ");
        $db->bind(':iid', $invoiceId, PDO::PARAM_INT);

        return $db->resultSet() ?: [];
    }
}
