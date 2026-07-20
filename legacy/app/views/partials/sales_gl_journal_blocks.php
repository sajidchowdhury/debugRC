<?php
/**
 * Render one or more journal_entry_card blocks for sales documents.
 *
 * Expects $journal_blocks — list of:
 *   title, entry, reversing (optional), reference_type, reference_id, empty_message (optional)
 */
$journalBlocks = $journal_blocks ?? [];
if ($journalBlocks === []) {
    return;
}
?>
<section class="branch-hub-panel mb-3 p-3">
    <div class="fw-semibold mb-3"><i class="fas fa-book me-1"></i> General ledger</div>
    <?php foreach ($journalBlocks as $block): ?>
        <?php
        $journal_entry = $block['entry'] ?? null;
        $journal_card_title = (string)($block['title'] ?? 'General ledger');
        $journal_card_show_report_link = true;
        $journal_card_reference_type = $block['reference_type'] ?? null;
        $journal_card_reference_id = (int)($block['reference_id'] ?? 0);
        $journal_card_empty_message = $journal_entry ? null : ($block['empty_message'] ?? 'No journal entry linked.');
        $journal_card_css_class = 'border-0 shadow-none p-0 mb-3';
        include __DIR__ . '/journal_entry_card.php';

        $reversing = $block['reversing'] ?? null;
        if ($reversing):
            $journal_entry = $reversing;
            $journal_card_title = 'Reversing entry';
            $journal_card_empty_message = null;
            include __DIR__ . '/journal_entry_card.php';
        endif;
        ?>
    <?php endforeach; ?>
</section>
