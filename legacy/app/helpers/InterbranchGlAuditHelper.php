<?php

// app/helpers/InterbranchGlAuditHelper.php — linked journal entries for inter-branch GL audit (Phase 5D).



require_once __DIR__ . '/../models/JournalEntryModel.php';

require_once __DIR__ . '/../../core/Database.php';



class InterbranchGlAuditHelper

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

    public static function demandJournalBlocks(array $demand): array

    {

        $demandId = (int)($demand['id'] ?? 0);

        $status = (string)($demand['status'] ?? '');

        $isReversed = !empty($demand['is_reversed']);

        $totalValue = (float)($demand['total_value'] ?? 0);

        $needsGl = $status === 'received' && $totalValue >= 0.01 && !$isReversed;



        $creditorJeId = (int)($demand['journal_entry_id'] ?? 0);

        $debtorJeId = (int)($demand['journal_entry_id_debtor'] ?? 0);

        $supplierLabel = (string)($demand['to_branch'] ?? 'Supplier branch');

        $requesterLabel = (string)($demand['from_branch'] ?? 'Requester branch');



        $blocks = [];



        $blocks[] = [

            'title'          => "Supplier (creditor) — {$supplierLabel} · Dr Due from / Cr inventory",

            'entry'          => self::entryWithLines($creditorJeId),

            'reversing'      => $isReversed ? self::reversingEntry($creditorJeId) : null,

            'reference_type' => 'branch_demand',

            'reference_id'   => $demandId,

            'empty_message'  => self::demandEmptyMessage($needsGl, $creditorJeId, 'creditor journal'),

        ];



        $blocks[] = [

            'title'          => "Requester (debtor) — {$requesterLabel} · Dr inventory / Cr Due to",

            'entry'          => self::entryWithLines($debtorJeId),

            'reversing'      => $isReversed ? self::reversingEntry($debtorJeId) : null,

            'reference_type' => 'branch_demand',

            'reference_id'   => $demandId,

            'empty_message'  => self::demandEmptyMessage($needsGl, $debtorJeId, 'debtor journal'),

        ];



        return $blocks;

    }



    /**

     * @return array<int, array<string, mixed>>

     */

    public static function warehouseTransferJournalBlocks(array $transfer): array

    {

        $transferId = (int)($transfer['id'] ?? 0);

        $demandId = (int)($transfer['branch_demand_id'] ?? 0);

        $isReversed = !empty($transfer['is_reversed']);

        $totalAmount = (float)($transfer['total_amount'] ?? 0);

        $fromBranchId = (int)($transfer['from_branch_id'] ?? 0);

        $toBranchId = (int)($transfer['to_branch_id'] ?? 0);

        $crossBranch = $fromBranchId > 0 && $toBranchId > 0 && $fromBranchId !== $toBranchId;



        if ($demandId > 0) {

            return [[

                'title'          => 'Inter-branch GL on branch demand',

                'entry'          => null,

                'reversing'      => null,

                'reference_type' => 'branch_demand',

                'reference_id'   => $demandId,

                'empty_message'  => 'Cross-branch GL is posted on the linked branch demand ('

                    . ($transfer['branch_demand_code'] ?? ('#' . $demandId))

                    . '). Open Branch Demand for both branch journals.',

            ]];

        }



        if (!$crossBranch) {

            return [[

                'title'          => 'Same-branch transfer — no inter-branch GL',

                'entry'          => null,

                'reversing'      => null,

                'reference_type' => 'warehouse_transfer',

                'reference_id'   => $transferId,

                'empty_message'  => 'Internal warehouse moves update stock only; no Due from/to journals.',

            ]];

        }



        $senderJeId = (int)($transfer['journal_entry_id'] ?? 0);

        $receiverJeId = (int)($transfer['journal_entry_id_debtor'] ?? 0);

        $needsGl = $totalAmount >= 0.01 && !$isReversed;

        $senderLabel = (string)($transfer['from_branch'] ?? 'Sender branch');

        $receiverLabel = (string)($transfer['to_branch'] ?? 'Receiver branch');



        return [

            [

                'title'          => "Sender — {$senderLabel} · Dr Due from / Cr inventory",

                'entry'          => self::entryWithLines($senderJeId),

                'reversing'      => $isReversed ? self::reversingEntry($senderJeId) : null,

                'reference_type' => 'warehouse_transfer',

                'reference_id'   => $transferId,

                'empty_message'  => $needsGl && $senderJeId <= 0

                    ? 'Cross-branch transfer is missing sender-branch journal.'

                    : ($senderJeId <= 0 ? 'No GL amount or not posted yet.' : null),

            ],

            [

                'title'          => "Receiver — {$receiverLabel} · Dr inventory / Cr Due to",

                'entry'          => self::entryWithLines($receiverJeId),

                'reversing'      => $isReversed ? self::reversingEntry($receiverJeId) : null,

                'reference_type' => 'warehouse_transfer',

                'reference_id'   => $transferId,

                'empty_message'  => $needsGl && $receiverJeId <= 0

                    ? 'Cross-branch transfer is missing receiver-branch journal.'

                    : ($receiverJeId <= 0 ? 'No GL amount or not posted yet.' : null),

            ],

        ];

    }



    private static function demandEmptyMessage(bool $needsGl, int $jeId, string $label): ?string

    {

        if ($jeId > 0) {

            return null;

        }

        if ($needsGl) {

            return "Demand is received but {$label} is missing (run migration 021?).";

        }



        return 'GL posts when goods are sent and demand is received.';

    }

}

