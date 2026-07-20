<?php
ob_start();
$m = $manual ?? [];
$journalEntry = $journal_entry ?? null;
$canReverse = !empty($can_reverse);
$id = (int)($m['id'] ?? 0);
$isReversed = !empty($m['is_reversed']);
$title = 'Manual Journal — ' . ($m['entry_no'] ?? '');
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/manual-journal-theme.css">
<input type="hidden" id="base_url" value="<?= BASE_URL ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

<div class="branch-hub manual-journal-theme acct-money-app container-fluid py-2" id="manualJournalDetails">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-pen-to-square me-2"></i><?= htmlspecialchars($m['entry_no'] ?? '', ENT_QUOTES) ?></h1>
            <p><?= htmlspecialchars($m['description'] ?? '', ENT_QUOTES) ?></p>
            <span class="hero-badge">
                <?= $isReversed ? '<i class="fas fa-circle-xmark"></i> Reversed' : '<i class="fas fa-circle-check"></i> Posted' ?>
            </span>
            <span class="hero-badge ms-1">Tk <?= number_format((float)($m['total_debit'] ?? 0), 2) ?></span>
        </div>
        <div class="branch-hub-actions d-flex flex-wrap gap-2">
            <?php if ($canReverse): ?>
            <button type="button" class="btn btn-warning btn-sm js-mj-reverse" data-id="<?= $id ?>" data-entry-no="<?= htmlspecialchars($m['entry_no'] ?? '', ENT_QUOTES) ?>">
                <i class="fas fa-undo me-1"></i> Reverse journal
            </button>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>Report/GeneralLedger?search=1&amp;from_date=<?= urlencode($m['entry_date'] ?? date('Y-m-d')) ?>&amp;to_date=<?= urlencode($m['entry_date'] ?? date('Y-m-d')) ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-book-open me-1"></i> GL report
            </a>
            <a href="<?= BASE_URL ?>ManualJournal" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i> List</a>
        </div>
    </header>

    <?php if ($isReversed): ?>
    <div class="alert alert-warning"><i class="fas fa-triangle-exclamation me-1"></i> This manual journal was reversed. Original lines remain for audit; a reversing entry was posted.</div>
    <?php endif; ?>

    <div class="oe-detail-grid mb-3">
        <div class="oe-detail-item"><div class="label">Date</div><div class="value"><?= date('d M Y', strtotime($m['entry_date'] ?? 'now')) ?></div></div>
        <div class="oe-detail-item"><div class="label">Branch</div><div class="value"><?= htmlspecialchars($m['branch_name'] ?? '—', ENT_QUOTES) ?></div></div>
        <div class="oe-detail-item"><div class="label">Posted by</div><div class="value"><?= htmlspecialchars($m['created_by_name'] ?? '—', ENT_QUOTES) ?></div></div>
        <div class="oe-detail-item"><div class="label">Reference</div><div class="value"><code>manual</code> #<?= $id ?></div></div>
        <?php if (!empty($m['internal_note'])): ?>
        <div class="oe-detail-item span-2"><div class="label">Internal note</div><div class="value"><?= nl2br(htmlspecialchars($m['internal_note'], ENT_QUOTES)) ?></div></div>
        <?php endif; ?>
        <?php if (!empty($m['attachment_path'])): ?>
        <div class="oe-detail-item span-2">
            <div class="label">Attachment</div>
            <div class="value">
                <a href="<?= BASE_URL . htmlspecialchars($m['attachment_path'], ENT_QUOTES) ?>" target="_blank" rel="noopener">
                    <?= htmlspecialchars($m['attachment_filename'] ?? 'Download', ENT_QUOTES) ?>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php
    $journal_card_title = 'Posted journal lines';
    $journal_card_show_report_link = true;
    $journal_card_reference_type = 'manual';
    $journal_card_reference_id = $id;
    require __DIR__ . '/../../partials/journal_entry_card.php';
    ?>
</div>

<script src="<?= BASE_URL ?>assets/js/manual-journal.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
