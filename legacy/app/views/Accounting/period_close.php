<?php
ob_start();
$title = $title ?? 'Accounting Period Close';
$periods = $periods ?? [];
$branches = $branches ?? [];
$canReopen = !empty($can_reopen);
$overrideEnabled = !empty($override_enabled);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/manual-journal-theme.css">
<input type="hidden" id="base_url" value="<?= BASE_URL ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

<div class="branch-hub manual-journal-theme acct-money-app container-fluid py-2" id="periodCloseApp">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-lock me-2"></i>Accounting period close</h1>
            <p>Soft lock — block GL posting on or before <code>closed_through_date</code> per branch</p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>AccountingPeriod/year_end" class="btn btn-outline-light btn-sm"><i class="fas fa-calendar-check me-1"></i> Year-end checklist</a>
            <a href="<?= BASE_URL ?>ledger" class="btn btn-outline-light btn-sm"><i class="fas fa-book me-1"></i> Chart of accounts</a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/accounting_quick_nav.php'; ?>

    <div class="alert alert-info py-2 small">
        <strong>Override rules:</strong>
        Superadmin always bypasses the lock.
        Admin bypass requires <code>PERIOD_CLOSE_ADMIN_OVERRIDE=true</code> in config/local.php
        (currently: <?= $overrideEnabled ? '<strong>enabled</strong>' : 'disabled' ?>).
        Reopen (remove lock) is superadmin only.
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="branch-hub-panel p-3">
                <h2 class="h6 fw-bold mb-3"><i class="fas fa-calendar-check me-1"></i> Close period</h2>
                <form id="periodCloseForm" class="row g-2" aria-label="Close accounting period">
                    <div class="col-12">
                        <label class="form-label small fw-semibold" for="pcBranch">Branch</label>
                        <select name="branch_id" id="pcBranch" class="form-select form-select-sm" required>
                            <?php foreach ($branches as $b): ?>
                            <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['branch_name'] ?? '', ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold" for="pcClosedDate">Closed through date</label>
                        <input type="date" name="closed_through_date" id="pcClosedDate" class="form-control form-control-sm" required>
                        <div class="form-text">No postings on or before this date (unless override).</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold" for="pcNotes">Notes</label>
                        <input type="text" name="notes" id="pcNotes" class="form-control form-control-sm" placeholder="Month-end close, audit sign-off…">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-lock me-1"></i> Apply close</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="branch-hub-panel p-0 acct-has-mobile-cards">
                <div class="px-3 py-2 border-bottom bg-light">
                    <h2 class="h6 fw-bold mb-0">Branch status</h2>
                </div>
                <div class="table-responsive acct-desktop-table">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Branch</th>
                                <th>Closed through</th>
                                <th>Closed by</th>
                                <th>Notes</th>
                                <?php if ($canReopen): ?><th></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($periods as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['branch_name'] ?? '', ENT_QUOTES) ?></td>
                                <td>
                                    <?php if (!empty($row['closed_through_date'])): ?>
                                    <span class="badge bg-warning text-dark"><?= htmlspecialchars($row['closed_through_date'], ENT_QUOTES) ?></span>
                                    <?php else: ?>
                                    <span class="text-success">Open</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= htmlspecialchars($row['closed_by_name'] ?? '—', ENT_QUOTES) ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($row['notes'] ?? '—', ENT_QUOTES) ?></td>
                                <?php if ($canReopen): ?>
                                <td class="text-end">
                                    <?php if (!empty($row['closed_through_date'])): ?>
                                    <button type="button" class="btn btn-outline-danger btn-sm js-reopen-period" data-branch-id="<?= (int)$row['branch_id'] ?>">
                                        Reopen
                                    </button>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="acct-mobile-list acct-mobile-only p-2" aria-label="Branch period status">
                    <?php foreach ($periods as $row): ?>
                    <article class="acct-mobile-card">
                        <div class="acct-mobile-card-head">
                            <div class="acct-mobile-card-title"><?= htmlspecialchars($row['branch_name'] ?? '', ENT_QUOTES) ?></div>
                            <?php if (!empty($row['closed_through_date'])): ?>
                            <span class="badge bg-warning text-dark">Closed</span>
                            <?php else: ?>
                            <span class="badge bg-success">Open</span>
                            <?php endif; ?>
                        </div>
                        <dl class="acct-mobile-card-meta">
                            <dt>Closed through</dt>
                            <dd><?= !empty($row['closed_through_date']) ? htmlspecialchars($row['closed_through_date'], ENT_QUOTES) : '—' ?></dd>
                            <dt>Closed by</dt>
                            <dd><?= htmlspecialchars($row['closed_by_name'] ?? '—', ENT_QUOTES) ?></dd>
                        </dl>
                        <?php if (!empty($row['notes'])): ?>
                        <p class="small text-muted mb-2"><?= htmlspecialchars($row['notes'], ENT_QUOTES) ?></p>
                        <?php endif; ?>
                        <?php if ($canReopen && !empty($row['closed_through_date'])): ?>
                        <div class="acct-mobile-card-actions">
                            <button type="button" class="btn btn-outline-danger btn-sm js-reopen-period" data-branch-id="<?= (int)$row['branch_id'] ?>">Reopen</button>
                        </div>
                        <?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/accounting-period-close.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
