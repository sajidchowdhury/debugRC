<?php
$title = $title ?? 'Inter-branch Weekly Control';
$filters = $filters ?? [];
$report = $report ?? null;
$branches = $branches ?? [];
$canPickBranch = !empty($can_pick_branch);
$summary = $report['summary'] ?? null;

ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-demand.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-demand-weekly.css">

<div class="purch-index-app bd-demand-app bd-weekly-app container-fluid py-2">
    <header class="purch-index-hero bd-weekly-hero">
        <div>
            <h1><i class="fas fa-chart-line me-2"></i>Inter-branch weekly control</h1>
            <p>Demands, settlements, outstanding principal, floor stock, and anti-gaming alerts (catalog drops, below-cost sales, stale debt)</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>BranchDemand" class="btn btn-light btn-sm">
                <i class="fas fa-clipboard-list me-1"></i> Demands
            </a>
        </div>
    </header>

    <div class="bd-section-card mb-3">
        <div class="bd-section-head"><i class="fas fa-filter me-1"></i> Report period</div>
        <div class="p-3">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small">From</label>
                    <input type="date" name="from_date" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($filters['from_date'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">To</label>
                    <input type="date" name="to_date" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($filters['to_date'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Your branch (perspective)</label>
                    <select name="branch_id" class="form-select form-select-sm" <?= $canPickBranch ? '' : 'disabled' ?>>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>"
                            <?= (int)($filters['branch_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['branch_name'], ENT_QUOTES) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$canPickBranch): ?>
                    <input type="hidden" name="branch_id" value="<?= (int)($filters['branch_id'] ?? 0) ?>">
                    <?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Counterparty branch</label>
                    <select name="counterparty_branch_id" class="form-select form-select-sm">
                        <option value="0">All branches</option>
                        <?php foreach ($branches as $b): ?>
                        <?php if ((int)$b['id'] === (int)($filters['branch_id'] ?? 0)) continue; ?>
                        <option value="<?= (int)$b['id'] ?>"
                            <?= (int)($filters['counterparty_branch_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['branch_name'], ENT_QUOTES) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" name="search" value="1" class="btn btn-primary btn-sm flex-fill">
                        <i class="fas fa-search me-1"></i> Run
                    </button>
                    <?php if ($report): ?>
                    <button type="submit" name="export" value="1" class="btn btn-success btn-sm" title="Export CSV">
                        <i class="fas fa-file-csv"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </form>
            <p class="small text-muted mb-0 mt-2">
                Bank customer payments auto-settle demands; cash settlements apply when you transfer funds to the supplying branch.
                Floor stock uses <strong>locked demand cost</strong> or warehouse avg cost — not today&apos;s catalog price.
            </p>
        </div>
    </div>

    <?php if ($summary): ?>
    <?php
        $reconDiff = (float)($summary['ledger_recon_diff_owe'] ?? 0);
        $reconOk = abs($reconDiff) < 1.0;
        $anti = $report['anti_gaming'] ?? [];
        $staleDays = (int)($anti['stale_days_threshold'] ?? 30);
        $alertTotal = (int)($summary['anti_gaming_alert_count'] ?? 0);
    ?>
    <div class="bd-weekly-stats">
        <div class="bd-weekly-stat">
            <div class="label">Demands approved</div>
            <div class="value"><?= (int)($summary['demands_approved_count'] ?? 0) ?></div>
        </div>
        <div class="bd-weekly-stat">
            <div class="label">Approved value (period)</div>
            <div class="value">Tk <?= number_format((float)($summary['demands_approved_value'] ?? 0), 2) ?></div>
        </div>
        <div class="bd-weekly-stat ok">
            <div class="label">Settled (period)</div>
            <div class="value">Tk <?= number_format((float)($summary['settlements_in_period'] ?? 0), 2) ?></div>
        </div>
        <div class="bd-weekly-stat warn">
            <div class="label">I owe (outstanding)</div>
            <div class="value">Tk <?= number_format((float)($summary['outstanding_i_owe'] ?? 0), 2) ?></div>
        </div>
        <div class="bd-weekly-stat">
            <div class="label">Owed to me</div>
            <div class="value">Tk <?= number_format((float)($summary['outstanding_owed_to_me'] ?? 0), 2) ?></div>
        </div>
        <div class="bd-weekly-stat">
            <div class="label">Floor stock value</div>
            <div class="value">Tk <?= number_format((float)($summary['floor_stock_value'] ?? 0), 2) ?></div>
        </div>
        <div class="bd-weekly-stat <?= $alertTotal > 0 ? 'alert' : 'ok' ?>">
            <div class="label">Anti-gaming alerts</div>
            <div class="value"><?= $alertTotal ?></div>
        </div>
    </div>

    <div class="bd-weekly-panel">
        <div class="bd-weekly-recon <?= $reconOk ? 'is-ok' : '' ?>">
            <strong>Ledger check (I owe):</strong>
            Demands outstanding Tk <?= number_format((float)($summary['outstanding_i_owe'] ?? 0), 2) ?>
            vs branch ledger net Tk <?= number_format((float)($summary['ledger_net_i_owe'] ?? 0), 2) ?>
            — difference Tk <?= number_format($reconDiff, 2) ?>
            <?= $reconOk ? '(OK)' : '(review branch_ledger / settlements)' ?>
        </div>
    </div>

    <div class="bd-weekly-panel bd-weekly-antigaming">
        <div class="bd-weekly-panel-head">
            <i class="fas fa-shield-alt me-1"></i> Anti-gaming review
            <?php if ($alertTotal === 0): ?>
            <span class="badge bg-success ms-2">Clear</span>
            <?php else: ?>
            <span class="badge bg-danger ms-2"><?= $alertTotal ?> flag(s)</span>
            <?php endif; ?>
        </div>
        <div class="p-3 small text-muted border-bottom">
            Principal on open demands stays at <strong>locked transfer cost</strong> even if catalog price falls.
            Flags: catalog below locked (open demands), receiver sales under locked cost (period), stale outstanding (&gt;<?= $staleDays ?> days).
        </div>

        <div class="bd-weekly-alert-block">
            <div class="bd-weekly-alert-title">Catalog below locked rate</div>
            <div class="table-responsive">
                <table class="table bd-weekly-table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Demand</th>
                            <th>Product</th>
                            <th class="text-end">Locked</th>
                            <th class="text-end">Catalog now</th>
                            <th class="text-end">Gap</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($anti['price_drops'])): ?>
                        <tr><td colspan="5" class="text-muted text-center py-2">None on open received demands.</td></tr>
                        <?php else: ?>
                        <?php foreach ($anti['price_drops'] as $row): ?>
                        <tr class="bd-row-warn">
                            <td>
                                <a href="<?= BASE_URL ?>BranchDemand/details/<?= (int)($row['demand_id'] ?? 0) ?>">
                                    <?= htmlspecialchars($row['demand_code'] ?? '', ENT_QUOTES) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars(($row['product_code'] ?? '') . ' ' . ($row['product_name'] ?? ''), ENT_QUOTES) ?></td>
                            <td class="text-end"><?= number_format((float)($row['locked_rate'] ?? 0), 2) ?></td>
                            <td class="text-end"><?= number_format((float)($row['catalog_rate'] ?? 0), 2) ?></td>
                            <td class="text-end fw-bold text-danger"><?= number_format((float)($row['rate_gap'] ?? 0), 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bd-weekly-alert-block">
            <div class="bd-weekly-alert-title">Sales below locked transfer cost (report period)</div>
            <div class="table-responsive">
                <table class="table bd-weekly-table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Demand</th>
                            <th>Product</th>
                            <th class="text-end">Sale</th>
                            <th class="text-end">Locked</th>
                            <th class="text-end">Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($anti['below_cost_sales'])): ?>
                        <tr><td colspan="7" class="text-muted text-center py-2">None in this period.</td></tr>
                        <?php else: ?>
                        <?php foreach ($anti['below_cost_sales'] as $row): ?>
                        <tr class="bd-row-danger">
                            <td><?= htmlspecialchars($row['invoice_code'] ?? '', ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($row['invoice_date'] ?? '', ENT_QUOTES) ?></td>
                            <td>
                                <a href="<?= BASE_URL ?>BranchDemand/details/<?= (int)($row['demand_id'] ?? 0) ?>">
                                    <?= htmlspecialchars($row['demand_code'] ?? '', ENT_QUOTES) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars(($row['product_code'] ?? '') . ' ' . ($row['product_name'] ?? ''), ENT_QUOTES) ?></td>
                            <td class="text-end"><?= number_format((float)($row['sale_rate'] ?? 0), 2) ?></td>
                            <td class="text-end"><?= number_format((float)($row['locked_rate'] ?? 0), 2) ?></td>
                            <td class="text-end"><?= number_format((float)($row['sale_qty'] ?? 0), 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bd-weekly-alert-block">
            <div class="bd-weekly-alert-title">Stale outstanding (principal open &gt;<?= $staleDays ?> days)</div>
            <div class="table-responsive">
                <table class="table bd-weekly-table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Demand</th>
                            <th>Date</th>
                            <th>Branches</th>
                            <th class="text-end">Age</th>
                            <th class="text-end">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($anti['stale_outstanding'])): ?>
                        <tr><td colspan="5" class="text-muted text-center py-2">None.</td></tr>
                        <?php else: ?>
                        <?php foreach ($anti['stale_outstanding'] as $row): ?>
                        <tr class="bd-row-warn">
                            <td>
                                <a href="<?= BASE_URL ?>BranchDemand/details/<?= (int)($row['id'] ?? 0) ?>">
                                    <?= htmlspecialchars($row['demand_code'] ?? '', ENT_QUOTES) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($row['demand_date'] ?? '', ENT_QUOTES) ?></td>
                            <td><?= htmlspecialchars($row['counterparty_name'] ?? '', ENT_QUOTES) ?></td>
                            <td class="text-end"><?= (int)($row['age_days'] ?? 0) ?> d</td>
                            <td class="text-end fw-bold">Tk <?= number_format((float)($row['outstanding'] ?? 0), 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if (!empty($report['pairs'])): ?>
    <div class="bd-weekly-panel">
        <div class="bd-weekly-panel-head">Outstanding by counterparty (all)</div>
        <div class="table-responsive">
            <table class="table bd-weekly-table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Branch</th>
                        <th class="text-end">Open demands</th>
                        <th class="text-end">Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['pairs'] as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['counterparty_name'] ?? '', ENT_QUOTES) ?></td>
                        <td class="text-end"><?= (int)($p['demand_count'] ?? 0) ?></td>
                        <td class="text-end fw-bold">Tk <?= number_format((float)($p['outstanding'] ?? 0), 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="bd-weekly-panel">
        <div class="bd-weekly-panel-head">Demands in period</div>
        <div class="table-responsive">
            <table class="table bd-weekly-table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Code</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Status</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Settled</th>
                        <th class="text-end">Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report['demands'])): ?>
                    <tr><td colspan="8" class="text-center text-muted py-3">No demands in this period.</td></tr>
                    <?php else: ?>
                    <?php foreach ($report['demands'] as $d): ?>
                    <tr>
                        <td><?= htmlspecialchars($d['demand_date'] ?? '', ENT_QUOTES) ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>BranchDemand/details/<?= (int)($d['id'] ?? 0) ?>">
                                <?= htmlspecialchars($d['demand_code'] ?? '', ENT_QUOTES) ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($d['from_branch'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($d['to_branch'] ?? '', ENT_QUOTES) ?></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($d['status'] ?? '', ENT_QUOTES) ?></span></td>
                        <td class="text-end"><?= number_format((float)($d['total_value'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float)($d['settlement_amount'] ?? 0), 2) ?></td>
                        <td class="text-end fw-bold"><?= number_format((float)($d['outstanding'] ?? 0), 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bd-weekly-panel">
        <div class="bd-weekly-panel-head">Settlements in period</div>
        <div class="table-responsive">
            <table class="table bd-weekly-table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Source</th>
                        <th>Counterparty</th>
                        <th class="text-end">Amount</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report['settlements'])): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">No settlements in this period.</td></tr>
                    <?php else: ?>
                    <?php foreach ($report['settlements'] as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['transaction_date'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($s['source_type'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($s['counterparty_name'] ?? '', ENT_QUOTES) ?></td>
                        <td class="text-end text-success fw-bold">Tk <?= number_format((float)($s['amount'] ?? 0), 2) ?></td>
                        <td class="small"><?= htmlspecialchars($s['remarks'] ?? '', ENT_QUOTES) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bd-weekly-panel">
        <div class="bd-weekly-panel-head">Floor stock (receiver warehouse · locked / avg cost)</div>
        <div class="table-responsive">
            <table class="table bd-weekly-table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Demand</th>
                        <th>Product</th>
                        <th>Warehouse</th>
                        <th class="text-end">Locked rate</th>
                        <th class="text-end">WH qty</th>
                        <th class="text-end">Floor value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report['floor_stock'])): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">No remaining transfer stock on hand.</td></tr>
                    <?php else: ?>
                    <?php foreach ($report['floor_stock'] as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['demand_code'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars(($row['product_code'] ?? '') . ' ' . ($row['product_name'] ?? ''), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($row['warehouse_name'] ?? '', ENT_QUOTES) ?></td>
                        <td class="text-end"><?= number_format((float)($row['cost_rate'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float)($row['warehouse_qty'] ?? 0), 2) ?></td>
                        <td class="text-end fw-bold"><?= number_format((float)($row['floor_value'] ?? 0), 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif (isset($_GET['search'])): ?>
    <div class="alert alert-info">No data for the selected filters.</div>
    <?php else: ?>
    <div class="alert alert-light border">Choose dates and click <strong>Run</strong> to generate the weekly control report.</div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';