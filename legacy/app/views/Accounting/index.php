<?php
require_once __DIR__ . '/../../helpers/AccountingNavHelper.php';
ob_start();
$title = $title ?? 'Accounting';
$hubSections = $hub_sections ?? AccountingNavHelper::hubSections();
$dash = $dashboard ?? [];
$tb = $dash['trial_balance'] ?? [];
$recon = $dash['reconciliation'] ?? [];
$period = $dash['period'] ?? [];
$recentJournals = $dash['recent_journals'] ?? [];
$branchName = $dash['branch_name'] ?? 'Branch';
$fromDate = $dash['from_date'] ?? date('Y-m-01');
$toDate = $dash['to_date'] ?? date('Y-m-d');
$ranAt = $dash['ran_at'] ?? '';

$statusClass = static fn (string $st): string => match ($st) {
    'ok'   => 'ok',
    'warn' => 'warn',
    'fail' => 'fail',
    default => 'neutral',
};
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">

<div class="branch-hub accounting-hub container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-calculator me-2"></i>Accounting dashboard</h1>
            <p>GL health at a glance — trial balance, reconciliation traffic lights, and recent journal activity.</p>
            <span class="hero-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
            <span class="hero-badge ms-1"><i class="fas fa-calendar"></i> <?= htmlspecialchars($fromDate, ENT_QUOTES) ?> → <?= htmlspecialchars($toDate, ENT_QUOTES) ?></span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>Accounting/index" class="btn btn-outline-light btn-sm"><i class="fas fa-sync me-1"></i> Refresh</a>
            <a href="<?= BASE_URL ?>Accounting/guide" class="btn btn-outline-light btn-sm"><i class="fas fa-book-open me-1"></i> User guide</a>
            <a href="<?= htmlspecialchars($recon['url'] ?? BASE_URL . 'Reconciliation/index', ENT_QUOTES) ?>" class="btn btn-light btn-sm"><i class="fas fa-scale-balanced me-1"></i> Reconciliation</a>
            <a href="<?= htmlspecialchars($dash['reports_url'] ?? BASE_URL . 'Report/index', ENT_QUOTES) ?>" class="btn btn-outline-light btn-sm"><i class="fas fa-table-cells-large me-1"></i> Reports</a>
        </div>
    </header>

    <div class="accounting-dash-health branch-hub-stats">
        <a href="<?= htmlspecialchars($tb['url'] ?? '#', ENT_QUOTES) ?>" class="accounting-health-card branch-stat-card <?= $statusClass($tb['status'] ?? 'neutral') ?>">
            <div class="branch-stat-icon <?= ($tb['status'] ?? '') === 'ok' ? 'teal' : 'amber' ?>"><i class="fas fa-scale-balanced"></i></div>
            <div>
                <div class="stat-value"><?= !empty($tb['is_balanced']) ? 'Balanced' : 'Out of balance' ?></div>
                <div class="stat-label">Trial balance (MTD)</div>
                <?php if (empty($tb['is_balanced'])): ?>
                <div class="stat-meta">Diff Tk <?= number_format(abs((float)($tb['difference'] ?? 0)), 2) ?></div>
                <?php else: ?>
                <div class="stat-meta">Dr <?= number_format((float)($tb['grand_debit'] ?? 0), 0) ?> = Cr <?= number_format((float)($tb['grand_credit'] ?? 0), 0) ?></div>
                <?php endif; ?>
            </div>
        </a>

        <a href="<?= htmlspecialchars($recon['url'] ?? '#', ENT_QUOTES) ?>" class="accounting-health-card branch-stat-card <?= $statusClass($recon['overall_status'] ?? 'neutral') ?>">
            <div class="branch-stat-icon <?= ($recon['overall_status'] ?? '') === 'ok' ? 'teal' : (($recon['overall_status'] ?? '') === 'warn' ? 'amber' : 'slate') ?>"><i class="fas fa-traffic-light"></i></div>
            <div>
                <div class="stat-value"><?= htmlspecialchars($recon['overall_label'] ?? 'Reconciliation', ENT_QUOTES) ?></div>
                <div class="stat-label">GL reconciliation</div>
                <div class="stat-meta"><?= !empty($recon['has_issues']) ? count($recon['issues'] ?? []) . ' issue(s)' : 'All sections within tolerance' ?></div>
            </div>
        </a>

        <a href="<?= htmlspecialchars($period['manage_url'] ?? BASE_URL . 'AccountingPeriod/index', ENT_QUOTES) ?>" class="accounting-health-card branch-stat-card <?= $statusClass($period['status'] ?? 'ok') ?>">
            <div class="branch-stat-icon indigo"><i class="fas fa-lock"></i></div>
            <div>
                <div class="stat-value"><?= htmlspecialchars($period['label'] ?? 'Period', ENT_QUOTES) ?></div>
                <div class="stat-label">Accounting period</div>
                <?php if (!empty($period['earliest_open_date'])): ?>
                <div class="stat-meta">Post from <?= date('d M Y', strtotime((string)$period['earliest_open_date'])) ?></div>
                <?php endif; ?>
            </div>
        </a>
    </div>

    <?php if (!empty($recon['sections'])): ?>
    <section class="accounting-recon-lights branch-hub-panel" aria-label="Reconciliation section status">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h2 class="accounting-hub-section-title mb-0"><i class="fas fa-traffic-light me-1"></i> Reconciliation traffic lights</h2>
            <?php if ($ranAt): ?>
            <span class="small text-muted">As of <?= htmlspecialchars($ranAt, ENT_QUOTES) ?></span>
            <?php endif; ?>
        </div>
        <div class="accounting-recon-light-grid">
            <?php foreach ($recon['sections'] as $section):
                $st = $section['status'] ?? 'ok';
            ?>
            <a href="<?= htmlspecialchars($recon['url'] ?? '#', ENT_QUOTES) ?>#<?= htmlspecialchars($section['id'] ?? '', ENT_QUOTES) ?>"
               class="accounting-recon-light <?= $statusClass($st) ?>"
               title="<?= htmlspecialchars($section['status_label'] ?? '', ENT_QUOTES) ?>">
                <span class="light-dot" aria-hidden="true"></span>
                <i class="fas <?= htmlspecialchars($section['icon'] ?? 'fa-circle', ENT_QUOTES) ?>"></i>
                <span><?= htmlspecialchars($section['label'] ?? '', ENT_QUOTES) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php include __DIR__ . '/../partials/accounting_quick_nav.php'; ?>

    <section class="accounting-journal-feed branch-hub-panel acct-has-mobile-cards">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h2 class="accounting-hub-section-title mb-0"><i class="fas fa-clock-rotate-left me-1"></i> Recent journal activity</h2>
            <a href="<?= BASE_URL ?>Report/JournalEntries?search=1&amp;from_date=<?= urlencode($fromDate) ?>&amp;to_date=<?= urlencode($toDate) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-book-open me-1"></i> All journal entries
            </a>
        </div>
        <?php if (empty($recentJournals)): ?>
        <p class="text-muted small mb-0">No journal entries yet for this branch.</p>
        <?php else: ?>
        <div class="table-responsive acct-desktop-table">
            <table class="table table-sm table-hover align-middle mb-0 accounting-journal-table">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Entry</th>
                        <th>Source</th>
                        <th>Description</th>
                        <th class="text-end">Amount</th>
                        <th>By</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentJournals as $je):
                        $sourceUrl = $je['source_url'] ?? null;
                    ?>
                    <tr>
                        <td class="text-nowrap"><?= htmlspecialchars(date('d M Y', strtotime((string)($je['entry_date'] ?? ''))), ENT_QUOTES) ?></td>
                        <td class="text-nowrap"><code><?= htmlspecialchars($je['entry_no'] ?? '', ENT_QUOTES) ?></code></td>
                        <td><?= htmlspecialchars($je['reference_label'] ?? '—', ENT_QUOTES) ?></td>
                        <td class="text-truncate" style="max-width:220px"><?= htmlspecialchars($je['description'] ?? '—', ENT_QUOTES) ?></td>
                        <td class="text-end text-nowrap">Tk <?= number_format((float)($je['total_debit'] ?? 0), 2) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($je['created_by_name'] ?? '—', ENT_QUOTES) ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($sourceUrl): ?>
                            <a href="<?= htmlspecialchars($sourceUrl, ENT_QUOTES) ?>" class="btn btn-outline-primary btn-sm py-0">Source</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="acct-mobile-list acct-mobile-only" aria-label="Recent journal entries">
            <?php foreach ($recentJournals as $je):
                include __DIR__ . '/../partials/accounting_mobile_journal_card.php';
            endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <div class="accounting-hub-grid">
        <h2 class="accounting-hub-section-title"><i class="fas fa-th-large me-1"></i> Quick links</h2>
        <?php foreach ($hubSections as $section): ?>
        <section class="accounting-hub-section branch-hub-panel">
            <h3 class="accounting-hub-section-title h6"><?= htmlspecialchars($section['title'] ?? '', ENT_QUOTES) ?></h3>
            <div class="row g-2">
                <?php foreach ($section['items'] ?? [] as $tile): ?>
                <div class="col-md-6 col-xl-4">
                    <a href="<?= htmlspecialchars(AccountingNavHelper::linkHref($tile['route'] ?? ''), ENT_QUOTES) ?>" class="accounting-hub-tile">
                        <i class="fas <?= htmlspecialchars($tile['icon'] ?? 'fa-circle', ENT_QUOTES) ?>"></i>
                        <div>
                            <strong><?= htmlspecialchars($tile['label'] ?? '', ENT_QUOTES) ?></strong>
                            <?php if (!empty($tile['blurb'])): ?>
                            <span><?= htmlspecialchars($tile['blurb'], ENT_QUOTES) ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../layouts/main.php';
