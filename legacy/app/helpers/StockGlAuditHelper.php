<?php

// app/helpers/StockGlAuditHelper.php — load linked journal entries for stock GL audit surfaces (Phase 5C).



require_once __DIR__ . '/../models/JournalEntryModel.php';

require_once __DIR__ . '/../../core/Database.php';



class StockGlAuditHelper

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

    public static function stockTakeJournalBlocks(array $session, float $lossValue = 0.0, float $gainValue = 0.0): array

    {

        $sessionId = (int)($session['id'] ?? 0);

        $jeId = (int)($session['journal_entry_id'] ?? 0);

        $isReversed = !empty($session['is_reversed']);

        $status = (string)($session['status'] ?? '');

        $hasGlAmount = ($lossValue + $gainValue) >= 0.01;



        $emptyMessage = null;

        if ($jeId <= 0) {

            if ($status === 'adjusted' && $hasGlAmount && !$isReversed) {

                $emptyMessage = 'Session is posted but journal_entry_id is missing (shrinkage/surplus should be posted).';

            } elseif ($status !== 'adjusted') {

                $emptyMessage = 'GL posts when the session is finalized (Post adjustments).';

            } elseif (!$hasGlAmount) {

                $emptyMessage = 'No shrinkage/surplus value — journal not required.';

            }

        }



        return [[

            'title'          => 'Stock take — shrinkage / surplus',

            'entry'          => self::entryWithLines($jeId),

            'reversing'      => $isReversed ? self::reversingEntry($jeId) : null,

            'reference_type' => 'stock_take',

            'reference_id'   => $sessionId,

            'empty_message'  => $emptyMessage,

        ]];

    }



    /**

     * @return array<int, array<string, mixed>>

     */

    public static function adjustmentJournalBlocks(array $adjustment): array

    {

        $adjustmentId = (int)($adjustment['id'] ?? 0);

        $jeId = (int)($adjustment['journal_entry_id'] ?? 0);

        $isReversed = !empty($adjustment['is_reversed']);

        $totalAmount = (float)($adjustment['total_amount'] ?? 0);

        $type = (string)($adjustment['adjustment_type'] ?? '');



        $title = $type === 'decrease'

            ? 'Adjustment decrease — Dr shrinkage / Cr inventory'

            : ($type === 'increase'

                ? 'Adjustment increase — Dr inventory / Cr surplus'

                : 'Stock adjustment — GL');



        $emptyMessage = null;

        if ($jeId <= 0 && $totalAmount >= 0.01 && !$isReversed) {

            $emptyMessage = 'Adjustment has value but journal_entry_id is missing (run migration 025?).';

        } elseif ($jeId <= 0 && $totalAmount < 0.01) {

            $emptyMessage = 'Zero value — journal not required.';

        }



        return [[

            'title'          => $title,

            'entry'          => self::entryWithLines($jeId),

            'reversing'      => $isReversed ? self::reversingEntry($jeId) : null,

            'reference_type' => 'stock_adjustment',

            'reference_id'   => $adjustmentId,

            'empty_message'  => $emptyMessage,

        ]];

    }



    /**

     * @return array<int, array<string, mixed>>

     */

    public static function damageJournalBlocks(array $damage): array

    {

        $damageId = (int)($damage['id'] ?? 0);

        $jeId = (int)($damage['journal_entry_id'] ?? 0);

        $isReversed = !empty($damage['is_reversed']);

        $totalValue = (float)($damage['total_value'] ?? 0);



        $emptyMessage = null;

        if ($jeId <= 0 && $totalValue >= 0.01 && !$isReversed) {

            $emptyMessage = 'Damage has value but journal_entry_id is missing (run migration 027?).';

        } elseif ($jeId <= 0 && $totalValue < 0.01) {

            $emptyMessage = 'Zero value — journal not required.';

        }



        return [[

            'title'          => 'Damage write-off — Dr shrinkage / Cr inventory',

            'entry'          => self::entryWithLines($jeId),

            'reversing'      => $isReversed ? self::reversingEntry($jeId) : null,

            'reference_type' => 'damage',

            'reference_id'   => $damageId,

            'empty_message'  => $emptyMessage,

        ]];

    }

}

