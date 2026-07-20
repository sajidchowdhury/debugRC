<?php
$demand = $demand ?? [];
$items = $items ?? [];
$toWarehouses = $toWarehouses ?? [];
$stockTrace = $stock_trace ?? [];
$settlements = $settlements ?? [];
$journal_blocks = $journal_blocks ?? [];
$risk = $risk_flags ?? ['flags' => [], 'has_alerts' => false];

$demandCode = $demand['demand_code'] ?? '';
$status = !empty($demand['is_reversed']) ? 'reversed' : ($demand['status'] ?? 'pending');
$isFromBranch = (int)($demand['from_branch_id'] ?? 0) === (int)($_SESSION['branch_id'] ?? 0);
$isToBranch = (int)($demand['to_branch_id'] ?? 0) === (int)($_SESSION['branch_id'] ?? 0);

$totalValue = (float)($demand['total_value'] ?? 0);
$settled = (float)($demand['settlement_amount'] ?? 0);
$outstanding = max(0, $totalValue - $settled);
$settlePct = $totalValue > 0 ? min(100, round(($settled / $totalValue) * 100)) : 0;

$formatMoney = static fn ($n) => 'Tk ' . number_format((float)$n, 2);

$title = 'Demand — ' . $demandCode;
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-demand.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">

<div class="purch-index-app bd-demand-app container-fluid py-2">
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-exchange-alt me-2"></i><?= htmlspecialchars($demandCode, ENT_QUOTES) ?></h1>
            <p>Inter-branch transfer — locked principal, FIFO settlement from bank sales &amp; transfers</p>
            <span class="bd-status-pill <?= htmlspecialchars($status, ENT_QUOTES) ?>"><?= htmlspecialchars(strtoupper($status), ENT_QUOTES) ?></span>
        </div>
        <div class="purch-index-hero-actions d-flex flex-wrap gap-2">
            <?php if ($isToBranch && $status === 'pending'): ?>
            <button type="button" onclick="sendGoods(<?= (int)($demand['id'] ?? 0) ?>)" class="btn btn-success btn-sm">
                <i class="fas fa-truck me-1"></i> Send goods
            </button>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>BranchDemand/checklist" class="btn btn-outline-light btn-sm" title="Inter-branch GL audit">
                <i class="fas fa-clipboard-check"></i>
            </a>
            <a href="<?= BASE_URL ?>BranchDemand/weekly" class="btn btn-outline-light btn-sm">
                <i class="fas fa-chart-line me-1"></i> Weekly control
            </a>
            <a href="<?= BASE_URL ?>BranchDemand" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> List
            </a>
        </div>
    </header>

    <div class="bd-detail-stats">
        <div class="bd-detail-stat">
            <span class="label">Transfer value</span>
            <span class="value"><?= $formatMoney($totalValue) ?></span>
            <span class="sub">Frozen at send (locked cost)</span>
        </div>
        <div class="bd-detail-stat ok">
            <span class="label">Settled</span>
            <span class="value"><?= $formatMoney($settled) ?></span>
            <span class="sub"><?= count($settlements) ?> allocation(s)</span>
        </div>
        <div class="bd-detail-stat <?= $outstanding > 0 ? 'warn' : 'ok' ?>">
            <span class="label">Outstanding</span>
            <span class="value"><?= $formatMoney($outstanding) ?></span>
            <span class="sub">Principal still due</span>
        </div>
        <div class="bd-detail-stat">
            <span class="label">Branches</span>
            <span class="value" style="font-size:0.9rem"><?= htmlspecialchars($demand['from_branch'] ?? '', ENT_QUOTES) ?></span>
            <span class="sub">→ <?= htmlspecialchars($demand['to_branch'] ?? '', ENT_QUOTES) ?></span>
        </div>
    </div>

    <?php if ($status === 'received' && $totalValue > 0): ?>
    <div class="bd-settle-progress">
        <div class="d-flex justify-content-between small mb-1">
            <span class="text-muted">Settlement progress</span>
            <span class="fw-semibold"><?= (int)$settlePct ?>%</span>
        </div>
        <div class="progress" style="height: 10px;">
            <div class="progress-bar <?= $settlePct >= 100 ? 'bg-success' : 'bg-primary' ?>"
                 style="width: <?= (int)$settlePct ?>%"></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-2 mb-3">
        <div class="col-md-4">
            <div class="bd-section-card h-100">
                <div class="bd-section-head">Requester (debtor)</div>
                <div class="bd-section-body">
                    <strong><?= htmlspecialchars($demand['from_branch'] ?? '', ENT_QUOTES) ?></strong>
                    <p class="small text-muted mb-0">Receives stock · owes principal</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="bd-section-card h-100">
                <div class="bd-section-head">Supplier (creditor)</div>
                <div class="bd-section-body">
                    <strong><?= htmlspecialchars($demand['to_branch'] ?? '', ENT_QUOTES) ?></strong>
                    <p class="small text-muted mb-0">Ships stock · receives settlement</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="bd-section-card h-100">
                <div class="bd-section-head">Dates</div>
                <div class="bd-section-body small">
                    <div><strong>Demand:</strong> <?= !empty($demand['demand_date']) ? date('d M Y', strtotime($demand['demand_date'])) : '—' ?></div>
                    <?php if (!empty($demand['updated_at'])): ?>
                    <div class="mt-1"><strong>Updated:</strong> <?= date('d M Y H:i', strtotime($demand['updated_at'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($risk['has_alerts'])): ?>
    <div class="alert alert-warning border-warning mb-3">
        <strong><i class="fas fa-shield-alt me-1"></i> Control flags</strong>
        <ul class="mb-0 mt-2 small">
            <?php foreach ($risk['flags'] as $flag):
                $icon = ($flag['severity'] ?? '') === 'danger' ? 'exclamation-circle text-danger' : 'exclamation-triangle text-warning';
                ?>
            <li><i class="fas fa-<?= $icon ?> me-1"></i> <?= htmlspecialchars($flag['message'] ?? '', ENT_QUOTES) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="small text-muted mb-0 mt-2">
            Locked <code>cost_rate</code> is not repriced when catalog changes.
            <a href="<?= BASE_URL ?>BranchDemand/weekly">Weekly inter-branch control</a>
        </p>
    </div>
    <?php endif; ?>

    <section class="bd-section-card">
        <div class="bd-section-head">
            <span><i class="fas fa-boxes me-1"></i> Line items</span>
            <span class="text-muted small fw-normal"><?= count($items) ?> product(s)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="itemsTable">
                <thead class="table-light">
                    <tr>
                        <th>Product</th>
                        <th class="text-end">Qty</th>
                        <?php if ($isToBranch && $status === 'pending'): ?>
                        <th>From warehouse (my stock)</th>
                        <th>To warehouse (requester)</th>
                        <?php else: ?>
                        <th class="text-end">Locked rate</th>
                        <th class="text-end">Line value</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item):
                    $qty = (float)($item['qty'] ?? 0);
                    $rate = (float)($item['cost_rate'] ?? 0);
                    $lineVal = $qty * $rate;
                    ?>
                    <tr data-product-id="<?= (int)($item['product_id'] ?? 0) ?>" data-qty="<?= $qty ?>">
                        <td>
                            <strong><?= htmlspecialchars($item['product_code'] ?? '', ENT_QUOTES) ?></strong>
                            <span class="text-muted d-block small"><?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES) ?></span>
                        </td>
                        <td class="text-end"><?= number_format($qty, 2) ?></td>
                        <?php if ($isToBranch && $status === 'pending'): ?>
                        <td>
                            <select class="form-select form-select-sm from-warehouse" required>
                                <option value="">— Select —</option>
                                <?php foreach (($item['from_warehouses'] ?? []) as $wh):
                                    $avail = (float)($wh['available_qty'] ?? 0);
                                    $physical = (float)($wh['physical_qty'] ?? $avail);
                                    $pipeline = (float)($wh['pipeline_qty'] ?? max(0, $physical - $avail));
                                    $stockLabel = number_format($avail, 2) . ' avail';
                                    if ($pipeline > 0.0001) {
                                        $stockLabel .= ' (' . number_format($physical, 2) . ' phys, ' . number_format($pipeline, 2) . ' sales hold)';
                                    }
                                ?>
                                <option value="<?= (int)($wh['id'] ?? 0) ?>" data-stock="<?= $avail ?>">
                                    <?= htmlspecialchars($wh['warehouse_name'] ?? '', ENT_QUOTES) ?> (<?= $stockLabel ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select class="form-select form-select-sm to-warehouse" required>
                                <option value="">— Select —</option>
                                <?php foreach ($toWarehouses as $wh): ?>
                                <option value="<?= (int)($wh['id'] ?? 0) ?>">
                                    <?= htmlspecialchars($wh['warehouse_name'] ?? '', ENT_QUOTES) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <?php else: ?>
                        <td class="text-end"><?= number_format($rate, 2) ?></td>
                        <td class="text-end fw-semibold"><?= number_format($lineVal, 2) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($status === 'received'): ?>
    <section class="bd-section-card">
        <div class="bd-section-head">
            <span><i class="fas fa-hand-holding-usd me-1"></i> Settlement history</span>
            <span class="text-muted small fw-normal">Bank payments (FIFO) · inter-branch money transfers</span>
        </div>
        <div class="table-responsive">
            <table class="table bd-settle-table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Source</th>
                        <th>Reference</th>
                        <th>Counterparty</th>
                        <th class="text-end">Allocated</th>
                        <th class="text-end">Source total</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($settlements)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            No settlements yet. Principal is repaid when the requester branch records
                            <strong>bank customer payments</strong> (auto FIFO) or sends a
                            <strong>money transfer</strong> to the supplying branch.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($settlements as $s):
                        $src = $s['source_type'] ?? '';
                        $isRev = !empty($s['is_reversed']);
                        $srcLabel = $src === 'money_transfer' ? 'Money transfer' : 'Customer payment';
                        ?>
                    <tr class="<?= $isRev ? 'is-reversed' : '' ?>">
                        <td><?= !empty($s['transaction_date']) ? date('d M Y', strtotime($s['transaction_date'])) : '—' ?></td>
                        <td>
                            <span class="bd-source-badge <?= $src === 'money_transfer' ? 'transfer' : '' ?>">
                                <?= htmlspecialchars($srcLabel, ENT_QUOTES) ?>
                            </span>
                            <?php if ($isRev): ?><span class="badge bg-secondary ms-1">Reversed</span><?php endif; ?>
                        </td>
                        <td class="fw-semibold"><?= htmlspecialchars($s['reference_code'] ?? '', ENT_QUOTES) ?></td>
                        <td class="small"><?= htmlspecialchars($s['counterparty_label'] ?? '', ENT_QUOTES) ?></td>
                        <td class="text-end text-success fw-bold"><?= number_format((float)($s['amount'] ?? 0), 2) ?></td>
                        <td class="text-end text-muted"><?= number_format((float)($s['source_total'] ?? 0), 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <?php if (!empty($settlements)): ?>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="4" class="text-end fw-semibold">Total allocated</td>
                        <td class="text-end fw-bold text-success"><?= number_format($settled, 2) ?></td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="4" class="text-end fw-semibold">Outstanding principal</td>
                        <td class="text-end fw-bold <?= $outstanding > 0 ? 'text-warning' : 'text-success' ?>"><?= number_format($outstanding, 2) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
        <div class="bd-section-body border-top small text-muted">
            <i class="fas fa-info-circle me-1"></i>
            Settlement does not change locked transfer rates. Catalog price changes only affect new demands.
        </div>
    </section>
    <?php endif; ?>

    <?php include __DIR__ . '/../partials/sales_gl_journal_blocks.php'; ?>

    <?php if (!empty($stockTrace)): ?>
    <section class="bd-section-card">
        <div class="bd-section-head"><i class="fas fa-route me-1"></i> Stock trace</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Product</th>
                        <th>Warehouse</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Rate</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($stockTrace as $st): ?>
                    <tr>
                        <td><?= htmlspecialchars($st['transaction_date'] ?? '', ENT_QUOTES) ?></td>
                        <td><code class="small"><?= htmlspecialchars($st['reference_type'] ?? '', ENT_QUOTES) ?></code></td>
                        <td><?= htmlspecialchars(($st['product_code'] ?? '') . ' ' . ($st['product_name'] ?? ''), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($st['warehouse_name'] ?? '', ENT_QUOTES) ?></td>
                        <td class="text-end"><?= number_format((float)($st['qty'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float)($st['rate'] ?? 0), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</div>

<script>
window.BD_BOOT = { baseUrl: <?= json_encode(BASE_URL) ?> };
</script>
<script src="<?= BASE_URL ?>assets/js/BranchDemand.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';