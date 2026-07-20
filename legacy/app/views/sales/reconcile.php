<?php
ob_start();
$title = $title ?? 'GL Reconciliation';
$report = $report ?? [];
$from = $from ?? date('Y-m-01');
$to = $to ?? date('Y-m-d');
$ar = $report['ar'] ?? [];
$ap = $report['ap'] ?? [];
$employee = $report['employee'] ?? [];
$cashBank = $report['cash_bank'] ?? [];
$inv = $report['inventory'] ?? [];
$cogs = $report['cogs'] ?? [];
$mismatches = $ar['ledger_mismatches'] ?? [];
$apMismatches = $ap['ledger_mismatches'] ?? [];
$employeeMismatches = $employee['ledger_mismatches'] ?? [];
$bankMismatches = $cashBank['mapping_mismatches'] ?? [];
$tolerance = (float)($report['tolerance'] ?? 0.02);
$hasIssues = !empty($report['has_issues']);
$recentAlerts = $recent_alerts ?? [];
$alertLogPath = $alert_log_path ?? '';
?>
<div class="container-fluid py-3">
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center py-3 gap-2">
            <div>
                <h4 class="card-title mb-0 fw-semibold">
                    <i class="fas fa-scale-balanced text-primary me-2"></i>
                    GL reconciliation
                </h4>
                <small class="text-muted">
                    Phase 5 — <?= htmlspecialchars($report['branch_name'] ?? '', ENT_QUOTES) ?>
                </small>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= BASE_URL ?>sales/reconcile" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-sync me-1"></i> Refresh
                </a>
                <a href="<?= BASE_URL ?>SalesAudit/checklist" class="btn btn-outline-secondary btn-sm">Audit checklist</a>
            </div>
        </div>
        <div class="card-body">
            <form method="get" action="<?= BASE_URL ?>sales/reconcile" class="row g-2 align-items-end mb-4">
                <div class="col-auto">
                    <label class="form-label small mb-0">COGS period from</label>
                    <input type="date" name="from" class="form-control form-control-sm" value="<?= htmlspecialchars($from, ENT_QUOTES) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">To</label>
                    <input type="date" name="to" class="form-control form-control-sm" value="<?= htmlspecialchars($to, ENT_QUOTES) ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                </div>
            </form>

            <p class="text-muted small mb-3">
                Last run: <strong><?= htmlspecialchars($report['ran_at'] ?? '', ENT_QUOTES) ?></strong>
                · Tolerance: <?= number_format($tolerance, 2) ?>
                <?php if ($hasIssues): ?>
                    <span class="badge bg-danger ms-1">Issues detected</span>
                <?php else: ?>
                    <span class="badge bg-success ms-1">Within tolerance</span>
                <?php endif; ?>
            </p>

            <?php if (!empty($report['issues'])): ?>
                <div class="alert alert-warning">
                    <ul class="mb-0">
                        <?php foreach ($report['issues'] as $issue): ?>
                            <li><?= htmlspecialchars($issue, ENT_QUOTES) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <h5 class="fw-semibold mb-2">Accounts receivable</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100">
                        <div class="text-muted small">Customer ledger net</div>
                        <div class="fs-4 fw-bold"><?= number_format((float)($ar['customer_ledger_net'] ?? 0), 2) ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100">
                        <div class="text-muted small">GL AR control</div>
                        <div class="fs-4 fw-bold"><?= number_format((float)($ar['gl_ar_net'] ?? 0), 2) ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100 <?= !empty($ar['within_tolerance']) ? 'border-success' : 'border-danger' ?>">
                        <div class="text-muted small">Difference</div>
                        <div class="fs-4 fw-bold <?= !empty($ar['within_tolerance']) ? 'text-success' : 'text-danger' ?>">
                            <?= number_format((float)($ar['difference'] ?? 0), 2) ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            $integrityOk = ((int)($ar['ledger_mismatch_count'] ?? 0)) === 0;
            $nullBranch = (int)($ar['null_branch_ledger_rows'] ?? 0);
            ?>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="alert <?= $integrityOk ? 'alert-success' : 'alert-warning' ?> mb-0">
                        <strong>Running balance integrity:</strong>
                        <?= $integrityOk ? 'OK' : (int)($ar['ledger_mismatch_count'] ?? 0) . ' mismatch(es)' ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="alert <?= $nullBranch === 0 ? 'alert-success' : 'alert-warning' ?> mb-0">
                        <strong>branch_id on ledger:</strong>
                        <?= $nullBranch === 0 ? 'OK' : "{$nullBranch} NULL row(s)" ?>
                    </div>
                </div>
            </div>

            <?php if (!$integrityOk && $mismatches !== []): ?>
                <div class="table-responsive mb-4">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Customer</th>
                                <th class="text-end">Last balance</th>
                                <th class="text-end">Computed</th>
                                <th class="text-end">Diff</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($mismatches, 0, 50) as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['shop_name'] ?? $row['customer_name'] ?? ('#' . ($row['customer_id'] ?? '')), ENT_QUOTES) ?></td>
                                    <td class="text-end"><?= number_format((float)($row['last_balance'] ?? 0), 2) ?></td>
                                    <td class="text-end"><?= number_format((float)($row['computed_balance'] ?? 0), 2) ?></td>
                                    <td class="text-end text-danger"><?= number_format((float)($row['difference'] ?? 0), 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <h5 class="fw-semibold mb-2">Accounts payable</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100">
                        <div class="text-muted small">Supplier ledger net</div>
                        <div class="fs-4 fw-bold"><?= number_format((float)($ap['supplier_ledger_net'] ?? 0), 2) ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100">
                        <div class="text-muted small">GL AP control</div>
                        <div class="fs-4 fw-bold"><?= number_format((float)($ap['gl_ap_net'] ?? 0), 2) ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3 h-100 <?= !empty($ap['within_tolerance']) ? 'border-success' : 'border-warning' ?>">
                        <div class="text-muted small">Difference</div>
                        <div class="fs-4 fw-bold"><?= number_format((float)($ap['difference'] ?? 0), 2) ?></div>
                    </div>
                </div>
            </div>
            <?php if ($apMismatches !== []): ?>
                <p class="small text-warning mb-2">Supplier running balance mismatches (top <?= count($apMismatches) ?>):</p>
                <div class="table-responsive mb-4">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light"><tr><th>Supplier</th><th class="text-end">Last balance</th><th class="text-end">Computed</th><th class="text-end">Diff</th></tr></thead>
                        <tbody>
                        <?php foreach ($apMismatches as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['supplier_name'] ?? ('#' . ($row['supplier_id'] ?? '')), ENT_QUOTES) ?></td>
                                <td class="text-end"><?= number_format((float)($row['last_balance'] ?? 0), 2) ?></td>
                                <td class="text-end"><?= number_format((float)($row['computed_balance'] ?? 0), 2) ?></td>
                                <td class="text-end text-danger"><?= number_format((float)($row['difference'] ?? 0), 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <h5 class="fw-semibold mb-2">Employee payable</h5>
            <p class="text-muted small"><?= htmlspecialchars($employee['note'] ?? '', ENT_QUOTES) ?></p>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="border rounded p-3">
                        <div class="text-muted small">Employee ledger net</div>
                        <div class="fs-5 fw-bold"><?= number_format((float)($employee['employee_ledger_net'] ?? 0), 2) ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3">
                        <div class="text-muted small">GL employee control</div>
                        <div class="fs-5 fw-bold"><?= number_format((float)($employee['gl_employee_net'] ?? 0), 2) ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3 <?= !empty($employee['within_tolerance']) ? 'border-success' : 'border-warning' ?>">
                        <div class="text-muted small">Difference</div>
                        <div class="fs-5 fw-bold"><?= number_format((float)($employee['difference'] ?? 0), 2) ?></div>
                    </div>
                </div>
            </div>
            <?php if ($employeeMismatches !== []): ?>
                <div class="table-responsive mb-4">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light"><tr><th>Employee</th><th class="text-end">Last</th><th class="text-end">Computed</th><th class="text-end">Diff</th></tr></thead>
                        <tbody>
                        <?php foreach ($employeeMismatches as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['employee_name'] ?? ('#' . ($row['employee_id'] ?? '')), ENT_QUOTES) ?></td>
                                <td class="text-end"><?= number_format((float)($row['last_balance'] ?? 0), 2) ?></td>
                                <td class="text-end"><?= number_format((float)($row['computed_balance'] ?? 0), 2) ?></td>
                                <td class="text-end text-danger"><?= number_format((float)($row['difference'] ?? 0), 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <h5 class="fw-semibold mb-2">Cash / bank</h5>
            <?php if (!empty($cashBank['branch_scoped_note'])): ?>
                <p class="text-muted small"><?= htmlspecialchars($cashBank['branch_scoped_note'], ENT_QUOTES) ?></p>
            <?php endif; ?>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="border rounded p-3">
                        <div class="text-muted small">Bank register total</div>
                        <div class="fs-5 fw-bold"><?= number_format((float)($cashBank['banks_total_balance'] ?? 0), 2) ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3">
                        <div class="text-muted small">GL cash_bank net</div>
                        <div class="fs-5 fw-bold"><?= number_format((float)($cashBank['gl_cash_bank_net'] ?? 0), 2) ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3 <?= !empty($cashBank['within_tolerance']) ? 'border-success' : 'border-warning' ?>">
                        <div class="text-muted small">Difference</div>
                        <div class="fs-5 fw-bold"><?= number_format((float)($cashBank['difference'] ?? 0), 2) ?></div>
                    </div>
                </div>
            </div>
            <?php if ($bankMismatches !== []): ?>
                <div class="table-responsive mb-4">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light"><tr><th>Bank</th><th>Mapped ledger</th><th class="text-end">Register</th><th class="text-end">GL</th><th class="text-end">Diff</th></tr></thead>
                        <tbody>
                        <?php foreach ($bankMismatches as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['bank_name'] ?? '', ENT_QUOTES) ?></td>
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

            <h5 class="fw-semibold mb-2">Inventory</h5>
            <p class="text-muted small"><?= htmlspecialchars($inv['note'] ?? '', ENT_QUOTES) ?></p>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="border rounded p-3">
                        <div class="text-muted small">Warehouse stock value</div>
                        <div class="fs-5 fw-bold"><?= number_format((float)($inv['warehouse_stock_value'] ?? 0), 2) ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3">
                        <div class="text-muted small">GL inventory net</div>
                        <div class="fs-5 fw-bold"><?= number_format((float)($inv['gl_inventory_net'] ?? 0), 2) ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3 <?= !empty($inv['within_tolerance']) ? 'border-success' : 'border-warning' ?>">
                        <div class="text-muted small">Difference</div>
                        <div class="fs-5 fw-bold"><?= number_format((float)($inv['difference'] ?? 0), 2) ?></div>
                    </div>
                </div>
            </div>

            <h5 class="fw-semibold mb-2">COGS tie-out</h5>
            <p class="text-muted small"><?= htmlspecialchars($cogs['timing_note'] ?? '', ENT_QUOTES) ?></p>
            <p class="small mb-3">
                <a href="<?= BASE_URL ?>Report/grossMargin?search=1&amp;date_basis=delivery" class="text-decoration-none">
                    <i class="fas fa-percent me-1"></i>Gross margin report
                </a>
                — invoice revenue vs challan COGS with delivery / invoice date basis.
            </p>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="border rounded p-3">
                        <div class="text-muted small">Stock OUT (challans)</div>
                        <div class="fs-5 fw-bold"><?= number_format((float)($cogs['stock_cogs_amount'] ?? 0), 2) ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3">
                        <div class="text-muted small">GL COGS debits</div>
                        <div class="fs-5 fw-bold"><?= number_format((float)($cogs['gl_cogs_amount'] ?? 0), 2) ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-3 <?= !empty($cogs['within_tolerance']) ? 'border-success' : 'border-warning' ?>">
                        <div class="text-muted small">Difference</div>
                        <div class="fs-5 fw-bold"><?= number_format((float)($cogs['difference'] ?? 0), 2) ?></div>
                    </div>
                </div>
            </div>

            <p class="text-muted small mb-3">
                Scheduled job: <code>php database/scripts/run_gl_reconciliation.php</code>
                · Alerts: <code>logs/reconciliation_alerts.log</code>
                · Review CLI: <code>php database/scripts/review_reconciliation_alerts.php</code>
                · Optional email: <code>RECON_ALERT_EMAIL</code> in config/local.php
                · Telegram: admin + accountant when cron detects issues (see <code>docs/TELEGRAM_ALERTS.md</code>)
            </p>

            <?php if ($recentAlerts !== []): ?>
                <div class="border rounded mt-4">
                    <div class="bg-light px-3 py-2 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h6 class="mb-0 fw-semibold">
                            <i class="fas fa-bell text-warning me-1"></i>
                            Recent alert log
                        </h6>
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
                                <?php foreach ($recentAlerts as $alert): ?>
                                    <?php
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
                <p class="text-muted small mb-0 mt-3">
                    <i class="fas fa-check-circle text-success me-1"></i>
                    No entries in <code>logs/reconciliation_alerts.log</code> yet.
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';