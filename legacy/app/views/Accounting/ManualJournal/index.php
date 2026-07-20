<?php
ob_start();
$title = $title ?? 'Manual Journals';
$entries = $entries ?? [];
$stats = $stats ?? ['total' => 0, 'active' => 0, 'reversed' => 0, 'today' => 0];
$showReversed = !empty($show_reversed);
$branch_name = $branch_name ?? 'Branch';
$canOverride = !empty($can_override);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/manual-journal-theme.css">

<div class="branch-hub manual-journal-theme acct-money-app container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-pen-to-square me-2"></i><?= htmlspecialchars($title, ENT_QUOTES) ?></h1>
            <p>Balanced multi-line GL adjustments · reference type <code>manual</code></p>
            <span class="hero-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($branch_name, ENT_QUOTES) ?></span>
        </div>
        <div class="branch-hub-actions">
            <?php if (!$showReversed): ?>
            <a href="<?= BASE_URL ?>ManualJournal/create" class="btn btn-light btn-sm"><i class="fas fa-plus me-1"></i> New journal</a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>ManualJournal/audit" class="btn btn-outline-dark btn-sm"><i class="fas fa-history me-1"></i> Audit</a>
            <?php if ($showReversed): ?>
            <a href="<?= BASE_URL ?>ManualJournal" class="btn btn-outline-light btn-sm">Active journals</a>
            <?php else: ?>
            <a href="<?= BASE_URL ?>ManualJournal?reversed=1" class="btn btn-outline-light btn-sm"><i class="fas fa-undo me-1"></i> Reversed</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="branch-hub-stats">
        <div class="branch-stat-card"><div class="branch-stat-icon indigo"><i class="fas fa-book"></i></div><div><div class="stat-value"><?= (int)$stats['total'] ?></div><div class="stat-label">Total</div></div></div>
        <div class="branch-stat-card"><div class="branch-stat-icon teal"><i class="fas fa-check"></i></div><div><div class="stat-value"><?= (int)$stats['active'] ?></div><div class="stat-label">Active</div></div></div>
        <div class="branch-stat-card"><div class="branch-stat-icon slate"><i class="fas fa-rotate-left"></i></div><div><div class="stat-value"><?= (int)$stats['reversed'] ?></div><div class="stat-label">Reversed</div></div></div>
        <div class="branch-stat-card"><div class="branch-stat-icon amber"><i class="fas fa-calendar-day"></i></div><div><div class="stat-value"><?= (int)$stats['today'] ?></div><div class="stat-label">Posted today</div></div></div>
    </div>

    <?php include __DIR__ . '/../../partials/accounting_quick_nav.php'; ?>

    <div class="branch-hub-panel acct-has-mobile-cards">
        <form method="get" action="<?= BASE_URL ?>ManualJournal" class="branch-hub-filters acct-touch-filters" aria-label="Filter manual journals">
            <details class="acct-filter-drawer" open>
                <summary><i class="fas fa-filter"></i> Filters</summary>
                <div class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="mjFilterFrom">From</label>
                    <input type="date" name="from_date" id="mjFilterFrom" class="form-control form-control-sm" value="<?= htmlspecialchars($from_date ?? date('Y-m-01'), ENT_QUOTES) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="mjFilterTo">To</label>
                    <input type="date" name="to_date" id="mjFilterTo" class="form-control form-control-sm" value="<?= htmlspecialchars($to_date ?? date('Y-m-d'), ENT_QUOTES) ?>">
                </div>
                <?php if ($canOverride): ?>
                <div class="col-12 col-md-2">
                    <label class="filter-label" for="mjFilterBranch">Branch</label>
                    <select name="branch_id" id="mjFilterBranch" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        <?php foreach ($branches ?? [] as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= (int)($branch_id ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['branch_name'] ?? '', ENT_QUOTES) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-12 col-md-3">
                    <label class="filter-label" for="mjFilterSearch">Search</label>
                    <input type="search" name="search" id="mjFilterSearch" class="form-control form-control-sm" value="<?= htmlspecialchars($search ?? '', ENT_QUOTES) ?>" placeholder="Entry no, narration…">
                </div>
                <?php if ($showReversed): ?>
                <input type="hidden" name="reversed" value="1">
                <?php endif; ?>
                <div class="col-12 col-md-auto">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-filter me-1"></i> Apply</button>
                </div>
                </div>
            </details>
        </form>

        <div class="table-responsive mt-3 acct-desktop-table">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Entry no</th>
                        <th>Description</th>
                        <th>Branch</th>
                        <th class="text-end">Amount</th>
                        <th class="text-center">Lines</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($entries === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No manual journals in this period.</td></tr>
                    <?php else: ?>
                    <?php foreach ($entries as $row): ?>
                    <tr>
                        <td class="text-nowrap"><?= htmlspecialchars($row['entry_date'] ?? '', ENT_QUOTES) ?></td>
                        <td><code><?= htmlspecialchars($row['entry_no'] ?? '', ENT_QUOTES) ?></code></td>
                        <td><?= htmlspecialchars(mb_strimwidth((string)($row['description'] ?? ''), 0, 60, '…'), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['branch_name'] ?? '—', ENT_QUOTES) ?></td>
                        <td class="text-end fw-semibold"><?= number_format((float)($row['total_debit'] ?? 0), 2) ?></td>
                        <td class="text-center"><?= (int)($row['line_count'] ?? 0) ?></td>
                        <td>
                            <?php if (!empty($row['is_reversed'])): ?>
                            <span class="badge bg-secondary">Reversed</span>
                            <?php else: ?>
                            <span class="badge bg-success">Posted</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="<?= BASE_URL ?>ManualJournal/details/<?= (int)$row['id'] ?>" class="btn btn-outline-primary btn-sm">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="acct-mobile-list acct-mobile-only" aria-live="polite" aria-label="Manual journals">
            <?php if ($entries === []): ?>
            <p class="acct-mobile-empty">No manual journals in this period.</p>
            <?php else: ?>
            <?php foreach ($entries as $row): ?>
            <article class="acct-mobile-card<?= !empty($row['is_reversed']) ? ' is-reversed' : '' ?>">
                <div class="acct-mobile-card-head">
                    <div class="acct-mobile-card-title"><code><?= htmlspecialchars($row['entry_no'] ?? '', ENT_QUOTES) ?></code></div>
                    <?php if (!empty($row['is_reversed'])): ?>
                    <span class="badge bg-secondary">Reversed</span>
                    <?php else: ?>
                    <span class="badge bg-success">Posted</span>
                    <?php endif; ?>
                </div>
                <dl class="acct-mobile-card-meta">
                    <dt>Date</dt>
                    <dd><?= htmlspecialchars($row['entry_date'] ?? '', ENT_QUOTES) ?></dd>
                    <dt>Amount</dt>
                    <dd><?= number_format((float)($row['total_debit'] ?? 0), 2) ?></dd>
                    <dt>Lines</dt>
                    <dd><?= (int)($row['line_count'] ?? 0) ?></dd>
                    <dt>Branch</dt>
                    <dd><?= htmlspecialchars($row['branch_name'] ?? '—', ENT_QUOTES) ?></dd>
                </dl>
                <?php if (!empty($row['description'])): ?>
                <p class="small text-muted mb-2"><?= htmlspecialchars(mb_strimwidth((string)$row['description'], 0, 80, '…'), ENT_QUOTES) ?></p>
                <?php endif; ?>
                <div class="acct-mobile-card-actions">
                    <a href="<?= BASE_URL ?>ManualJournal/details/<?= (int)$row['id'] ?>" class="btn btn-outline-primary btn-sm">View</a>
                </div>
            </article>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
