<?php
ob_start();
$report = $report ?? [];
$sections = $report['sections'] ?? [];
$summary = $report['summary'] ?? [];
$branchName = $report['branch_name'] ?? 'Branch';
$fromDate = $report['from_date'] ?? date('Y-01-01');
$toDate = $report['to_date'] ?? date('Y-12-31');
$year = (int)($year ?? $report['year'] ?? date('Y'));
$branchId = (int)($branch_id ?? $report['branch_id'] ?? 0);
$canPickBranch = !empty($can_pick_branch);
$canClose = !empty($report['can_close_period']);
$ranAt = $report['ran_at'] ?? date('Y-m-d H:i:s');

$statusIcon = static function (string $status): string {
    return match ($status) {
        'pass' => 'fa-check',
        'warn' => 'fa-exclamation-triangle',
        'fail' => 'fa-times',
        default => 'fa-info-circle',
    };
};

$exportBase = BASE_URL . 'AccountingPeriod/export_year_tb?' . http_build_query([
    'year' => $year,
    'from_date' => $fromDate,
    'to_date' => $toDate,
]);
$exportGl = BASE_URL . 'AccountingPeriod/export_year_gl?' . http_build_query(array_filter([
    'year' => $year,
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'branch_id' => $branchId > 0 ? $branchId : null,
]));

$title = $title ?? 'Year-End Pre-Close Checklist';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-audit-checklist.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/year-end-checklist.css">
<input type="hidden" id="base_url" value="<?= BASE_URL ?>">
<input type="hidden" id="ye_year" value="<?= $year ?>">
<input type="hidden" id="ye_branch_id" value="<?= $branchId ?>">

<div class="sales-audit-app year-end-app container-fluid py-2" id="yearEndChecklist">
    <header class="sales-audit-hero">
        <div>
            <h1><i class="fas fa-calendar-check me-2"></i>Year-end pre-close checklist</h1>
            <p><?= htmlspecialchars($fromDate, ENT_QUOTES) ?> → <?= htmlspecialchars($toDate, ENT_QUOTES) ?> · <?= htmlspecialchars($branchName, ENT_QUOTES) ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-light btn-sm" id="btnRefreshYearEnd"><i class="fas fa-sync-alt me-1"></i> Re-run</button>
            <a href="<?= htmlspecialchars($exportBase, ENT_QUOTES) ?>" class="btn btn-outline-light btn-sm"><i class="fas fa-file-csv me-1"></i> Export TB</a>
            <a href="<?= htmlspecialchars($exportGl, ENT_QUOTES) ?>" class="btn btn-outline-light btn-sm"><i class="fas fa-file-export me-1"></i> Export GL archive</a>
            <a href="<?= BASE_URL ?>AccountingPeriod/index" class="btn btn-light btn-sm"><i class="fas fa-lock me-1"></i> Period close</a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/accounting_quick_nav.php'; ?>

    <form method="get" action="<?= BASE_URL ?>AccountingPeriod/year_end" class="year-end-filters row g-2 align-items-end mb-3">
        <div class="col-auto">
            <label class="form-label small mb-0">Year</label>
            <input type="number" name="year" class="form-control form-control-sm" value="<?= $year ?>" min="2020" max="2099" style="width:6rem">
        </div>
        <?php if ($canPickBranch): ?>
        <div class="col-auto">
            <label class="form-label small mb-0">Branch</label>
            <select name="branch_id" class="form-select form-select-sm">
                <option value="">All branches</option>
                <?php foreach ($branches ?? [] as $b): ?>
                <option value="<?= (int)$b['id'] ?>" <?= $branchId === (int)$b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['branch_name'] ?? '', ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        </div>
    </form>

    <p class="sales-audit-meta" id="yearEndMeta">Last run: <?= htmlspecialchars($ranAt, ENT_QUOTES) ?></p>

    <div class="year-end-close-banner <?= $canClose ? 'ok' : 'blocked' ?>">
        <?php if ($canClose): ?>
        <i class="fas fa-circle-check me-2"></i>
        <strong>Ready for period close</strong> — reconciliation and trial balance passed.
        <?php else: ?>
        <i class="fas fa-circle-xmark me-2"></i>
        <strong>Period close blocked</strong> — <?= htmlspecialchars($report['close_blocked_reason'] ?? 'Fix failing checks first.', ENT_QUOTES) ?>
        <?php endif; ?>
    </div>

    <div class="sales-audit-summary" id="yearEndSummary">
        <span class="chip pass"><i class="fas fa-check"></i> <?= (int)($summary['pass'] ?? 0) ?> pass</span>
        <span class="chip warn"><i class="fas fa-exclamation-triangle"></i> <?= (int)($summary['warn'] ?? 0) ?> warn</span>
        <span class="chip fail"><i class="fas fa-times"></i> <?= (int)($summary['fail'] ?? 0) ?> fail</span>
        <span class="chip info"><i class="fas fa-info-circle"></i> <?= (int)($summary['info'] ?? 0) ?> info</span>
    </div>

    <div id="yearEndSections">
        <?php foreach ($sections as $section): ?>
        <section class="sales-audit-section">
            <div class="sales-audit-section-head">
                <i class="fas <?= htmlspecialchars($section['icon'] ?? 'fa-folder', ENT_QUOTES) ?>"></i>
                <?= htmlspecialchars($section['title'] ?? '', ENT_QUOTES) ?>
            </div>
            <?php foreach ($section['items'] ?? [] as $item):
                $st = (string)($item['status'] ?? 'info');
                ?>
            <article class="sales-audit-item status-<?= htmlspecialchars($st, ENT_QUOTES) ?>">
                <div class="status-icon"><i class="fas <?= $statusIcon($st) ?>"></i></div>
                <div>
                    <h3><?= htmlspecialchars($item['title'] ?? '', ENT_QUOTES) ?></h3>
                    <p class="expected"><?= htmlspecialchars($item['expected'] ?? '', ENT_QUOTES) ?></p>
                    <?php if (!empty($item['actual'])): ?>
                    <p class="actual"><strong>Result:</strong> <?= htmlspecialchars($item['actual'], ENT_QUOTES) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($item['route'])): ?>
                    <a href="<?= BASE_URL . htmlspecialchars($item['route'], ENT_QUOTES) ?>" class="small">Open detail →</a>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </section>
        <?php endforeach; ?>
    </div>

    <div class="year-end-ops-note small text-muted mt-3">
        <p class="mb-1"><strong>Backup:</strong> <code>php database/scripts/backup_accounting_core.php</code> — store under <code>/backups</code>.</p>
        <p class="mb-0">Applying period close on <?= BASE_URL ?>AccountingPeriod/index runs the same gate — close is rejected if reconciliation or trial balance fails.</p>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/year-end-checklist.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
