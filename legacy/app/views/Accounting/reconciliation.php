<?php
ob_start();

$title = $title ?? 'GL Reconciliation';
$report = $report ?? [];
$from = $from ?? date('Y-m-01');
$to = $to ?? date('Y-m-d');
$branchId = (int)($branch_id ?? 0);
$branches = $branches ?? [];
$canOverrideBranch = !empty($can_override_branch);
$ar = $report['ar'] ?? [];
$ap = $report['ap'] ?? [];
$employee = $report['employee'] ?? [];
$cashBank = $report['cash_bank'] ?? [];
$inv = $report['inventory'] ?? [];
$cogs = $report['cogs'] ?? [];
$tolerance = (float)($report['tolerance'] ?? 0.02);
$toleranceDefined = !empty($tolerance_defined);
$toleranceConfig = (float)($tolerance_value ?? $tolerance);
$hasIssues = !empty($report['has_issues']);
$recentAlerts = $recent_alerts ?? [];
$alertLogPath = $alert_log_path ?? '';
$hubUrl = BASE_URL . 'Reconciliation/index';

$sections = ReconciliationService::sectionDefinitions();

$overallStatus = $hasIssues ? 'fail' : 'ok';
foreach ($sections as $key => $_meta) {
    $st = ReconciliationService::sectionStatus($key, $report);
    if ($st === 'fail') {
        $overallStatus = 'fail';
        break;
    }
    if ($st === 'warn' && $overallStatus === 'ok') {
        $overallStatus = 'warn';
    }
}

$filterQuery = static function (array $extra = []) use ($from, $to, $branchId, $canOverrideBranch): string {
    $params = array_merge([
        'from' => $from,
        'to'   => $to,
    ], $extra);
    if ($canOverrideBranch) {
        $params['branch_id'] = $branchId === 0 ? 'all' : (string)$branchId;
    }
    return http_build_query($params);
};
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/reconciliation-hub.css">

<div class="branch-hub recon-hub container-fluid py-2">
    <header class="branch-hub-hero recon-hub-hero">
        <div>
            <h1><i class="fas fa-scale-balanced me-2"></i>GL reconciliation hub</h1>
            <p>
                Sub-ledger balances vs GL control accounts ·
                <?= htmlspecialchars($report['branch_name'] ?? 'All branches', ENT_QUOTES) ?>
            </p>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= $hubUrl ?>?<?= $filterQuery() ?>" class="btn btn-light btn-sm">
                <i class="fas fa-sync me-1"></i> Refresh
            </a>
            <a href="<?= BASE_URL ?>Report/index#cat-ops" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clipboard-check me-1"></i> Control reports
            </a>
            <a href="<?= BASE_URL ?>ledger" class="btn btn-outline-light btn-sm">
                <i class="fas fa-book me-1"></i> Chart of accounts
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/accounting_quick_nav.php'; ?>

    <div class="recon-filter-panel">
        <form method="get" action="<?= $hubUrl ?>" class="row g-2 align-items-end">
            <?php if ($canOverrideBranch): ?>
            <div class="col-sm-6 col-md-3">
                <label class="form-label small fw-semibold mb-1">Branch</label>
                <select name="branch_id" class="form-select form-select-sm">
                    <option value="all" <?= $branchId === 0 ? 'selected' : '' ?>>All branches (company-wide)</option>
                    <?php foreach ($branches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $branchId === (int)$b['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['branch_name'] ?? '', ENT_QUOTES) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-sm-6 col-md-2">
                <label class="form-label small fw-semibold mb-1">COGS period from</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($from, ENT_QUOTES) ?>">
            </div>
            <div class="col-sm-6 col-md-2">
                <label class="form-label small fw-semibold mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($to, ENT_QUOTES) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-filter me-1"></i> Apply
                </button>
            </div>
        </form>
    </div>

    <div class="recon-tolerance-callout">
        <strong><i class="fas fa-sliders me-1"></i> Tolerance:</strong>
        Differences within <strong><?= number_format($tolerance, 2) ?></strong> Tk are treated as balanced.
        Configure via <code>GL_RECONCILIATION_TOLERANCE</code> in <code>config/local.php</code>
        <?php if ($toleranceDefined): ?>
            (current: <code><?= number_format($toleranceConfig, 4) ?></code>).
        <?php else: ?>
            (using default <code>0.02</code> from <code>config/config.php</code>).
        <?php endif; ?>
        Optional env override: <code>GL_RECONCILIATION_TOLERANCE=0.05</code>.
        Matches CLI: <code>php database/scripts/run_gl_reconciliation.php --branch=<?= $branchId > 0 ? $branchId : 'all' ?> --from=<?= htmlspecialchars($from, ENT_QUOTES) ?> --to=<?= htmlspecialchars($to, ENT_QUOTES) ?></code>
    </div>

    <div class="recon-status-banner recon-<?= $overallStatus ?>">
        <div class="fs-3">
            <i class="fas <?= $overallStatus === 'ok' ? 'fa-circle-check' : ($overallStatus === 'warn' ? 'fa-triangle-exclamation' : 'fa-circle-xmark') ?>"></i>
        </div>
        <div>
            <h4 class="mb-1 fw-bold">
                <?= $overallStatus === 'ok' ? 'All sections within tolerance' : ($overallStatus === 'warn' ? 'Review warnings below' : 'Reconciliation issues detected') ?>
            </h4>
            <p class="mb-0 small text-muted">
                Last run <strong><?= htmlspecialchars($report['ran_at'] ?? '', ENT_QUOTES) ?></strong>
                · COGS period <?= htmlspecialchars($from, ENT_QUOTES) ?> → <?= htmlspecialchars($to, ENT_QUOTES) ?>
            </p>
        </div>
    </div>

    <?php if (!empty($report['issues'])): ?>
    <div class="alert alert-warning py-2">
        <ul class="mb-0 small">
            <?php foreach ($report['issues'] as $issue): ?>
            <li><?= htmlspecialchars($issue, ENT_QUOTES) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="recon-traffic-grid">
        <?php foreach ($sections as $key => $meta):
            $st = ReconciliationService::sectionStatus($key, $report);
        ?>
        <a href="#<?= $meta['id'] ?>" class="recon-traffic-pill recon-<?= $st ?>">
            <span class="recon-light" aria-hidden="true"></span>
            <span class="recon-pill-label"><?= htmlspecialchars($meta['label'], ENT_QUOTES) ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <?php
    // --- AR ---
    $arStatus = ReconciliationService::sectionStatus('ar', $report);
    $mismatches = $ar['ledger_mismatches'] ?? [];
    $integrityOk = ((int)($ar['ledger_mismatch_count'] ?? 0)) === 0;
    $nullBranch = (int)($ar['null_branch_ledger_rows'] ?? 0);
    ?>
    <section id="recon-ar" class="recon-section recon-<?= $arStatus ?>">
        <div class="recon-section-head">
            <h2><i class="fas fa-hand-holding-dollar me-1"></i> Accounts receivable</h2>
            <span class="recon-section-badge"><?= ReconciliationService::sectionStatusLabel($arStatus) ?></span>
        </div>
        <div class="recon-section-body">
            <div class="recon-metrics">
                <div class="recon-metric">
                    <div class="recon-metric-label">Customer ledger net</div>
                    <div class="recon-metric-value"><?= number_format((float)($ar['customer_ledger_net'] ?? 0), 2) ?></div>
                </div>
                <div class="recon-metric">
                    <div class="recon-metric-label">GL AR control</div>
                    <div class="recon-metric-value"><?= number_format((float)($ar['gl_ar_net'] ?? 0), 2) ?></div>
                </div>
                <div class="recon-metric <?= !empty($ar['within_tolerance']) ? 'diff-ok' : 'diff-bad' ?>">
                    <div class="recon-metric-label">Difference</div>
                    <div class="recon-metric-value"><?= number_format((float)($ar['difference'] ?? 0), 2) ?></div>
                </div>
            </div>
            <div class="recon-integrity-row">
                <span class="recon-integrity-chip <?= $integrityOk ? 'ok' : 'warn' ?>">
                    Running balance: <?= $integrityOk ? 'OK' : (int)($ar['ledger_mismatch_count'] ?? 0) . ' mismatch(es)' ?>
                </span>
                <span class="recon-integrity-chip <?= $nullBranch === 0 ? 'ok' : 'warn' ?>">
                    branch_id on ledger: <?= $nullBranch === 0 ? 'OK' : "{$nullBranch} NULL row(s)" ?>
                </span>
            </div>
            <?php if (!$integrityOk && $mismatches !== []): ?>
            <div class="table-responsive">
                <table class="table table-sm recon-mismatch-table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Customer</th>
                            <th class="text-end">Last balance</th>
                            <th class="text-end">Computed</th>
                            <th class="text-end">Diff</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mismatches as $row):
                            $cid = (int)($row['customer_id'] ?? 0);
                            $name = $row['shop_name'] ?? $row['customer_name'] ?? ('#' . $cid);
                        ?>
                        <tr>
                            <td>
                                <?php if ($cid > 0): ?>
                                <a href="<?= BASE_URL ?>customer/show/<?= $cid ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></a>
                                <?php else: ?>
                                <?= htmlspecialchars($name, ENT_QUOTES) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= number_format((float)($row['last_balance'] ?? 0), 2) ?></td>
                            <td class="text-end"><?= number_format((float)($row['computed_balance'] ?? 0), 2) ?></td>
                            <td class="text-end text-danger"><?= number_format((float)($row['difference'] ?? 0), 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <?php
    // --- AP ---
    $apStatus = ReconciliationService::sectionStatus('ap', $report);
    $apMismatches = $ap['ledger_mismatches'] ?? [];
    ?>
    <section id="recon-ap" class="recon-section recon-<?= $apStatus ?>">
        <div class="recon-section-head">
            <h2><i class="fas fa-truck-field me-1"></i> Accounts payable</h2>
            <span class="recon-section-badge"><?= ReconciliationService::sectionStatusLabel($apStatus) ?></span>
        </div>
        <div class="recon-section-body">
            <div class="recon-metrics">
                <div class="recon-metric">
                    <div class="recon-metric-label">Supplier ledger net</div>
                    <div class="recon-metric-value"><?= number_format((float)($ap['supplier_ledger_net'] ?? 0), 2) ?></div>
                </div>
                <div class="recon-metric">
                    <div class="recon-metric-label">GL AP control</div>
                    <div class="recon-metric-value"><?= number_format((float)($ap['gl_ap_net'] ?? 0), 2) ?></div>
                </div>
                <div class="recon-metric <?= !empty($ap['within_tolerance']) ? 'diff-ok' : 'diff-bad' ?>">
                    <div class="recon-metric-label">Difference</div>
                    <div class="recon-metric-value"><?= number_format((float)($ap['difference'] ?? 0), 2) ?></div>
                </div>
            </div>
            <?php if ($apMismatches !== []): ?>
            <div class="table-responsive">
                <table class="table table-sm recon-mismatch-table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Supplier</th>
                            <th class="text-end">Last balance</th>
                            <th class="text-end">Computed</th>
                            <th class="text-end">Diff</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($apMismatches as $row):
                            $sid = (int)($row['supplier_id'] ?? 0);
                            $name = $row['supplier_name'] ?? ('#' . $sid);
                        ?>
                        <tr>
                            <td>
                                <?php if ($sid > 0): ?>
                                <a href="<?= BASE_URL ?>supplier/show/<?= $sid ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></a>
                                <?php else: ?>
                                <?= htmlspecialchars($name, ENT_QUOTES) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= number_format((float)($row['last_balance'] ?? 0), 2) ?></td>
                            <td class="text-end"><?= number_format((float)($row['computed_balance'] ?? 0), 2) ?></td>
                            <td class="text-end text-danger"><?= number_format((float)($row['difference'] ?? 0), 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <?php
    // --- Employee ---
    $empStatus = ReconciliationService::sectionStatus('employee', $report);
    $employeeMismatches = $employee['ledger_mismatches'] ?? [];
    ?>
    <section id="recon-employee" class="recon-section recon-<?= $empStatus ?>">
        <div class="recon-section-head">
            <h2><i class="fas fa-user-tie me-1"></i> Employee payable</h2>
            <span class="recon-section-badge"><?= ReconciliationService::sectionStatusLabel($empStatus) ?></span>
        </div>
        <div class="recon-section-body">
            <?php if (!empty($employee['note'])): ?>
            <p class="text-muted small"><?= htmlspecialchars($employee['note'], ENT_QUOTES) ?></p>
            <?php endif; ?>
            <div class="recon-metrics">
                <div class="recon-metric">
                    <div class="recon-metric-label">Employee ledger net</div>
                    <div class="recon-metric-value"><?= number_format((float)($employee['employee_ledger_net'] ?? 0), 2) ?></div>
                </div>
                <div class="recon-metric">
                    <div class="recon-metric-label">GL employee control</div>
                    <div class="recon-metric-value"><?= number_format((float)($employee['gl_employee_net'] ?? 0), 2) ?></div>
                </div>
                <div class="recon-metric <?= !empty($employee['within_tolerance']) ? 'diff-ok' : 'diff-bad' ?>">
                    <div class="recon-metric-label">Difference</div>
                    <div class="recon-metric-value"><?= number_format((float)($employee['difference'] ?? 0), 2) ?></div>
                </div>
            </div>
            <?php if ($employeeMismatches !== []): ?>
            <div class="table-responsive">
                <table class="table table-sm recon-mismatch-table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Employee</th>
                            <th class="text-end">Last</th>
                            <th class="text-end">Computed</th>
                            <th class="text-end">Diff</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employeeMismatches as $row):
                            $eid = (int)($row['employee_id'] ?? 0);
                            $name = $row['employee_name'] ?? ('#' . $eid);
                        ?>
                        <tr>
                            <td>
                                <?php if ($eid > 0): ?>
                                <a href="<?= BASE_URL ?>EmployeeTransaction?employee_id=<?= $eid ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></a>
                                <?php else: ?>
                                <?= htmlspecialchars($name, ENT_QUOTES) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?= number_format((float)($row['last_balance'] ?? 0), 2) ?></td>
                            <td class="text-end"><?= number_format((float)($row['computed_balance'] ?? 0), 2) ?></td>
                            <td class="text-end text-danger"><?= number_format((float)($row['difference'] ?? 0), 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <?php
    // --- Cash / bank ---
    $cashStatus = ReconciliationService::sectionStatus('cash_bank', $report);
    $bankMismatches = $cashBank['mapping_mismatches'] ?? [];
    ?>
    <section id="recon-cash" class="recon-section recon-<?= $cashStatus ?>">
        <div class="recon-section-head">
            <h2><i class="fas fa-building-columns me-1"></i> Cash / bank</h2>
            <span class="recon-section-badge"><?= ReconciliationService::sectionStatusLabel($cashStatus) ?></span>
        </div>
        <div class="recon-section-body">
            <?php if (!empty($cashBank['branch_scoped_note'])): ?>
            <p class="text-muted small"><?= htmlspecialchars($cashBank['branch_scoped_note'], ENT_QUOTES) ?></p>
            <?php endif; ?>
            <div class="recon-metrics">
                <div class="recon-metric">
                    <div class="recon-metric-label">Bank register total</div>
                    <div class="recon-metric-value"><?= number_format((float)($cashBank['banks_total_balance'] ?? 0), 2) ?></div>
                </div>
                <div class="recon-metric">
                    <div class="recon-metric-label">GL cash_bank net</div>
                    <div class="recon-metric-value"><?= number_format((float)($cashBank['gl_cash_bank_net'] ?? 0), 2) ?></div>
                </div>
                <div class="recon-metric <?= !empty($cashBank['within_tolerance']) ? 'diff-ok' : 'diff-bad' ?>">
                    <div class="recon-metric-label">Difference</div>
                    <div class="recon-metric-value"><?= number_format((float)($cashBank['difference'] ?? 0), 2) ?></div>
                </div>
            </div>
            <?php if ($bankMismatches !== []): ?>
            <div class="table-responsive">
                <table class="table table-sm recon-mismatch-table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Bank</th>
                            <th>Mapped ledger</th>
                            <th class="text-end">Register</th>
                            <th class="text-end">GL</th>
                            <th class="text-end">Diff</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bankMismatches as $row):
                            $bid = (int)($row['bank_id'] ?? 0);
                            $bname = $row['bank_name'] ?? ('#' . $bid);
                        ?>
                        <tr>
                            <td>
                                <?php if ($bid > 0): ?>
                                <a href="<?= BASE_URL ?>bank/show/<?= $bid ?>"><?= htmlspecialchars($bname, ENT_QUOTES) ?></a>
                                <?php else: ?>
                                <?= htmlspecialchars($bname, ENT_QUOTES) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= !empty($row['is_unmapped']) ? '<span class="text-warning">Unmapped</span>' : htmlspecialchars($row['mapped_ledger_name'] ?? '', ENT_QUOTES) ?></td>
                            <td class="text-end"><?= number_format((float)($row['bank_balance'] ?? 0), 2) ?></td>
                            <td class="text-end"><?= number_format((float)($row['gl_net'] ?? 0), 2) ?></td>
                            <td class="text-end text-danger"><?= number_format((float)($row['difference'] ?? 0), 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <?php
    // --- Inventory ---
    $invStatus = ReconciliationService::sectionStatus('inventory', $report);
    ?>
    <section id="recon-inventory" class="recon-section recon-<?= $invStatus ?>">
        <div class="recon-section-head">
            <h2><i class="fas fa-boxes-stacked me-1"></i> Inventory</h2>
            <span class="recon-section-badge"><?= ReconciliationService::sectionStatusLabel($invStatus) ?></span>
        </div>
        <div class="recon-section-body">
            <?php if (!empty($inv['note'])): ?>
            <p class="text-muted small"><?= htmlspecialchars($inv['note'], ENT_QUOTES) ?></p>
            <?php endif; ?>
            <div class="recon-metrics">
                <div class="recon-metric">
                    <div class="recon-metric-label">Warehouse stock value</div>
                    <div class="recon-metric-value"><?= number_format((float)($inv['warehouse_stock_value'] ?? 0), 2) ?></div>
                </div>
                <div class="recon-metric">
                    <div class="recon-metric-label">GL inventory net</div>
                    <div class="recon-metric-value"><?= number_format((float)($inv['gl_inventory_net'] ?? 0), 2) ?></div>
                </div>
                <div class="recon-metric <?= !empty($inv['within_tolerance']) ? 'diff-ok' : 'diff-bad' ?>">
                    <div class="recon-metric-label">Difference</div>
                    <div class="recon-metric-value"><?= number_format((float)($inv['difference'] ?? 0), 2) ?></div>
                </div>
            </div>
        </div>
    </section>

    <?php
    // --- COGS ---
    $cogsStatus = ReconciliationService::sectionStatus('cogs', $report);
    ?>
    <section id="recon-cogs" class="recon-section recon-<?= $cogsStatus ?>">
        <div class="recon-section-head">
            <h2><i class="fas fa-chart-line me-1"></i> COGS tie-out</h2>
            <span class="recon-section-badge"><?= ReconciliationService::sectionStatusLabel($cogsStatus) ?></span>
        </div>
        <div class="recon-section-body">
            <?php if (!empty($cogs['timing_note'])): ?>
            <p class="text-muted small"><?= htmlspecialchars($cogs['timing_note'], ENT_QUOTES) ?></p>
            <?php endif; ?>
            <div class="recon-metrics">
                <div class="recon-metric">
                    <div class="recon-metric-label">Stock OUT (challans)</div>
                    <div class="recon-metric-value"><?= number_format((float)($cogs['stock_cogs_amount'] ?? 0), 2) ?></div>
                </div>
                <div class="recon-metric">
                    <div class="recon-metric-label">GL COGS debits</div>
                    <div class="recon-metric-value"><?= number_format((float)($cogs['gl_cogs_amount'] ?? 0), 2) ?></div>
                </div>
                <div class="recon-metric <?= !empty($cogs['within_tolerance']) ? 'diff-ok' : 'diff-bad' ?>">
                    <div class="recon-metric-label">Difference</div>
                    <div class="recon-metric-value"><?= number_format((float)($cogs['difference'] ?? 0), 2) ?></div>
                </div>
            </div>
        </div>
    </section>

    <p class="text-muted small mb-3">
        Scheduled job: <code>php database/scripts/run_gl_reconciliation.php</code>
        · Alerts: <code>logs/reconciliation_alerts.log</code>
        · Review CLI: <code>php database/scripts/review_reconciliation_alerts.php</code>
    </p>

    <?php if ($recentAlerts !== []): ?>
    <div class="recon-alert-panel">
        <div class="recon-alert-panel-head d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-bell text-warning me-1"></i> Recent alert log</h6>
            <small class="text-muted">Newest first · last <?= count($recentAlerts) ?> entries</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 11rem;">When</th>
                        <th>Message</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentAlerts as $alert):
                        $ctx = $alert['context'] ?? [];
                        $branchLabel = trim((string)($ctx['branch_name'] ?? ''));
                        if ($branchLabel === '' && !empty($ctx['branch_id'])) {
                            $branchLabel = 'Branch #' . (int)$ctx['branch_id'];
                        }
                        $issues = $ctx['issues'] ?? [];
                        $failCount = (int)($ctx['fail'] ?? 0);
                    ?>
                    <tr>
                        <td class="text-nowrap small"><?= htmlspecialchars($alert['timestamp'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($alert['message'] ?? '', ENT_QUOTES) ?></td>
                        <td class="small text-muted">
                            <?php if ($branchLabel !== ''): ?>
                            <span class="d-block"><?= htmlspecialchars($branchLabel, ENT_QUOTES) ?></span>
                            <?php endif; ?>
                            <?php if ($failCount > 0): ?>
                            <span class="d-block"><?= $failCount ?> audit failure(s), <?= (int)($ctx['warn'] ?? 0) ?> warning(s)</span>
                            <?php endif; ?>
                            <?php if (is_array($issues) && $issues !== []): ?>
                            <ul class="mb-0 ps-3">
                                <?php foreach (array_slice($issues, 0, 3) as $issue): ?>
                                <li><?= htmlspecialchars((string)$issue, ENT_QUOTES) ?></li>
                                <?php endforeach; ?>
                                <?php if (count($issues) > 3): ?>
                                <li>… +<?= count($issues) - 3 ?> more</li>
                                <?php endif; ?>
                            </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($alertLogPath !== ''): ?>
        <div class="px-3 py-2 border-top small text-muted">
            Full log on server: <code><?= htmlspecialchars($alertLogPath, ENT_QUOTES) ?></code>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <p class="text-muted small mb-0">
        <i class="fas fa-check-circle text-success me-1"></i>
        No entries in <code>logs/reconciliation_alerts.log</code> yet.
    </p>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
