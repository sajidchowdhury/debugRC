<?php
/** @var array<string, mixed> $je */
$sourceUrl = $je['source_url'] ?? null;
$entryDate = $je['entry_date'] ?? '';
$formattedDate = $entryDate ? date('d M Y', strtotime((string)$entryDate)) : '—';
?>
<article class="acct-mobile-card">
    <div class="acct-mobile-card-head">
        <div class="acct-mobile-card-title">
            <code><?= htmlspecialchars($je['entry_no'] ?? '', ENT_QUOTES) ?></code>
        </div>
        <span class="badge bg-light text-dark border"><?= htmlspecialchars($je['reference_label'] ?? '—', ENT_QUOTES) ?></span>
    </div>
    <dl class="acct-mobile-card-meta">
        <dt>Date</dt>
        <dd><?= htmlspecialchars($formattedDate, ENT_QUOTES) ?></dd>
        <dt>Amount</dt>
        <dd>Tk <?= number_format((float)($je['total_debit'] ?? 0), 2) ?></dd>
        <dt>By</dt>
        <dd><?= htmlspecialchars($je['created_by_name'] ?? '—', ENT_QUOTES) ?></dd>
    </dl>
    <?php if (!empty($je['description'])): ?>
    <p class="small text-muted mb-2"><?= htmlspecialchars($je['description'], ENT_QUOTES) ?></p>
    <?php endif; ?>
    <div class="acct-mobile-card-actions">
        <?php if ($sourceUrl): ?>
        <a href="<?= htmlspecialchars($sourceUrl, ENT_QUOTES) ?>" class="btn btn-outline-primary btn-sm">View source</a>
        <?php endif; ?>
    </div>
</article>
