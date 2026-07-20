<?php
// Shared period-close banner for accounting money modules (Phase 6B)

$periodBanner = $period_banner ?? null;
if (!is_array($periodBanner) || empty($periodBanner['closed_through'])) {
    return;
}

$closedLabel = date('d M Y', strtotime((string)$periodBanner['closed_through']));
$openFrom = !empty($periodBanner['earliest_open_date'])
    ? date('d M Y', strtotime((string)$periodBanner['earliest_open_date']))
    : null;
?>
<div class="accounting-period-banner alert alert-warning py-2 px-3 mb-2 d-flex flex-wrap align-items-center gap-2" role="status">
    <i class="fas fa-lock"></i>
    <span>
        <strong>Period closed</strong> through <?= htmlspecialchars($closedLabel, ENT_QUOTES) ?>.
        <?php if ($openFrom): ?>
        Earliest posting date: <strong><?= htmlspecialchars($openFrom, ENT_QUOTES) ?></strong>.
        <?php endif; ?>
    </span>
    <?php if (!empty($periodBanner['can_bypass'])): ?>
    <span class="badge bg-info text-dark">Override active</span>
    <?php endif; ?>
    <?php if (!empty($periodBanner['manage_url']) && ($periodBanner['can_manage'] ?? false)): ?>
    <a href="<?= htmlspecialchars($periodBanner['manage_url'], ENT_QUOTES) ?>" class="ms-auto btn btn-outline-dark btn-sm">Manage close</a>
    <?php endif; ?>
</div>
