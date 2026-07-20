<?php
/**
 * Reusable journal entry card for detail pages and ledger hub.
 *
 * Expects:
 *   $journal_entry (array|null) — entry with 'lines' from JournalEntryModel::getEntryWithLines()
 * Optional:
 *   $journal_card_title (string)
 *   $journal_card_css_class (string) — extra section classes
 *   $journal_card_empty_message (string|null) — warning when entry missing
 *   $journal_card_show_report_link (bool)
 *   $journal_card_reference_type (string|null)
 *   $journal_card_reference_id (int|null)
 */
$journalEntry = $journal_entry ?? null;
$cardTitle = $journal_card_title ?? 'General ledger';
$cardClass = trim('branch-hub-panel mb-3 p-3 ' . ($journal_card_css_class ?? ''));
$showReportLink = !empty($journal_card_show_report_link);
$emptyMessage = $journal_card_empty_message ?? null;
$refType = $journal_card_reference_type ?? ($journalEntry['reference_type'] ?? null);
$refId = (int)($journal_card_reference_id ?? ($journalEntry['reference_id'] ?? 0));
$entryDate = (string)($journalEntry['entry_date'] ?? date('Y-m-d'));
$journalReportUrl = BASE_URL . 'Report/JournalEntries?search=1'
    . '&from_date=' . urlencode($entryDate)
    . '&to_date=' . urlencode($entryDate);
if ($refType) {
    $journalReportUrl .= '&reference_type=' . urlencode((string)$refType);
}
if (!empty($journalEntry['entry_no'])) {
    $journalReportUrl .= '&q=' . urlencode((string)$journalEntry['entry_no']);
} elseif ($refId > 0) {
    $journalReportUrl .= '&q=' . urlencode((string)$refId);
}
?>
<?php if ($journalEntry): ?>
<section class="<?= htmlspecialchars($cardClass, ENT_QUOTES) ?>">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
        <div class="fw-semibold"><i class="fas fa-book me-1"></i> <?= htmlspecialchars($cardTitle, ENT_QUOTES) ?></div>
        <?php if ($showReportLink && !empty($journalEntry['id'])): ?>
        <a href="<?= htmlspecialchars($journalReportUrl, ENT_QUOTES) ?>"
           class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-list me-1"></i> Journal report
        </a>
        <?php endif; ?>
    </div>
    <p class="small mb-2">
        <strong>
            <?php if ($showReportLink): ?>
            <a href="<?= htmlspecialchars($journalReportUrl, ENT_QUOTES) ?>" class="text-decoration-none">
                <?= htmlspecialchars($journalEntry['entry_no'] ?? '', ENT_QUOTES) ?>
            </a>
            <?php else: ?>
            <?= htmlspecialchars($journalEntry['entry_no'] ?? '', ENT_QUOTES) ?>
            <?php endif; ?>
        </strong>
        <?php if (!empty($journalEntry['entry_date'])): ?>
        <span class="text-muted">· <?= date('d M Y', strtotime($journalEntry['entry_date'])) ?></span>
        <?php endif; ?>
        <?php if (!empty($journalEntry['is_reversed'])): ?>
        <span class="badge bg-danger">Reversed</span>
        <?php endif; ?>
        <?php if (!empty($journalEntry['description'])): ?>
        <span class="d-block text-muted mt-1"><?= htmlspecialchars($journalEntry['description'], ENT_QUOTES) ?></span>
        <?php endif; ?>
    </p>
    <div class="table-responsive">
        <table class="table table-sm mb-0 journal-entry-card-table">
            <thead class="table-light">
                <tr>
                    <th>Ledger</th>
                    <th class="text-end">Debit</th>
                    <th class="text-end">Credit</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($journalEntry['lines'] ?? [] as $jl): ?>
                <tr>
                    <td>
                        <?php if (!empty($jl['ledger_id'])): ?>
                        <a href="<?= BASE_URL ?>Report/GeneralLedger?search=1&amp;ledger_id=<?= (int)$jl['ledger_id'] ?>&amp;from_date=<?= urlencode((string)($journalEntry['entry_date'] ?? date('Y-m-01'))) ?>&amp;to_date=<?= urlencode((string)($journalEntry['entry_date'] ?? date('Y-m-d'))) ?>"
                           class="text-decoration-none">
                            <?= htmlspecialchars($jl['ledger_name'] ?? '', ENT_QUOTES) ?>
                        </a>
                        <?php else: ?>
                        <?= htmlspecialchars($jl['ledger_name'] ?? '', ENT_QUOTES) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= (float)($jl['debit'] ?? 0) > 0 ? number_format((float)$jl['debit'], 2) : '—' ?></td>
                    <td class="text-end"><?= (float)($jl['credit'] ?? 0) > 0 ? number_format((float)$jl['credit'], 2) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if (!empty($journalEntry['total_debit']) || !empty($journalEntry['total_credit'])): ?>
            <tfoot class="table-light">
                <tr>
                    <th>Total</th>
                    <th class="text-end"><?= number_format((float)($journalEntry['total_debit'] ?? 0), 2) ?></th>
                    <th class="text-end"><?= number_format((float)($journalEntry['total_credit'] ?? 0), 2) ?></th>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</section>
<?php elseif ($emptyMessage !== null && $emptyMessage !== ''): ?>
<div class="alert alert-warning mb-3">
    <i class="fas fa-exclamation-triangle me-1"></i> <?= htmlspecialchars($emptyMessage, ENT_QUOTES) ?>
</div>
<?php endif; ?>
