<?php
$report = $report ?? [];
$totals = $report['totals'] ?? [];
$sessions = $report['sessions'] ?? [];
$topProducts = $report['top_products'] ?? [];
$dateFrom = $date_from ?? date('Y-m-d', strtotime('-7 days'));
$dateTo = $date_to ?? date('Y-m-d');
$isAdmin = !empty($is_admin);
$branches = $branches ?? [];

$title = $title ?? 'Stock Take Weekly';
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-take.css">

<div class="purch-index-app st-take-app container-fluid py-2">
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-chart-line me-2"></i>Weekly variance control</h1>
            <p>Posted sessions, gain/loss totals, and top SKU variances for the period</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>StockTake" class="btn btn-light btn-sm"><i class="fas fa-cubes me-1"></i> Sessions</a>
            <a href="<?= BASE_URL ?>StockTake/checklist" class="btn btn-light btn-sm"><i class="fas fa-clipboard-check me-1"></i> Audit</a>
            <a href="<?= BASE_URL ?>StockTake/variance" class="btn btn-light btn-sm"><i class="fas fa-table me-1"></i> Variance</a>
        </div>
    </header>

    <div class="st-section-card mb-3">
        <div class="st-section-head"><i class="fas fa-filter me-1"></i> Period</div>
        <div class="p-3">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">From</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">To</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($dateTo, ENT_QUOTES) ?>">
                </div>
                <?php if ($isAdmin): ?>
                <div class="col-md-3">
                    <label class="form-label small">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="0">All branches</option>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= (int)($branch_id ?? 0) === (int)$b['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['branch_name'] ?? '', ENT_QUOTES) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="fas fa-search me-1"></i> Run</button>
                    <a href="<?= BASE_URL ?>StockTake/exportWeekly?<?= htmlspecialchars(http_build_query(['date_from' => $dateFrom, 'date_to' => $dateTo, 'branch_id' => $branch_id ?? 0]), ENT_QUOTES) ?>"
                       class="btn btn-success btn-sm"><i class="fas fa-file-csv"></i></a>
                </div>
            </form>
        </div>
    </div>

    <div class="st-count-summary-bar mb-3">
        <span>Sessions: <strong><?= (int)($totals['sessions'] ?? 0) ?></strong></span>
        <span>Posted: <strong><?= (int)($totals['posted'] ?? 0) ?></strong></span>
        <span>Reversed: <strong><?= (int)($totals['reversed'] ?? 0) ?></strong></span>
        <span>Open: <strong><?= (int)($totals['open'] ?? 0) ?></strong></span>
        <span>Gain: <strong class="st-diff-pos"><?= number_format((float)($totals['gain_value'] ?? 0), 2) ?></strong></span>
        <span>Loss: <strong class="st-diff-neg"><?= number_format((float)($totals['loss_value'] ?? 0), 2) ?></strong></span>
        <span>Net: <strong><?= number_format((float)($totals['net_value'] ?? 0), 2) ?></strong></span>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <section class="st-section-card">
                <div class="st-section-head">Sessions in period</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Session</th>
                                <th>Date</th>
                                <th>Branch</th>
                                <th>Status</th>
                                <th class="text-end">Lines</th>
                                <th class="text-end">Net</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($sessions)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">No sessions in this period</td></tr>
                        <?php else: ?>
                            <?php foreach ($sessions as $s):
                                $st = !empty($s['is_reversed']) ? 'reversed' : ($s['status'] ?? '');
                                ?>
                            <tr>
                                <td><?= htmlspecialchars($s['session_code'] ?? '', ENT_QUOTES) ?></td>
                                <td><?= !empty($s['take_date']) ? date('d M Y', strtotime($s['take_date'])) : '' ?></td>
                                <td><?= htmlspecialchars($s['branch_name'] ?? '', ENT_QUOTES) ?></td>
                                <td><span class="badge-status <?= htmlspecialchars($st, ENT_QUOTES) ?>"><?= htmlspecialchars($st, ENT_QUOTES) ?></span></td>
                                <td class="text-end"><?= (int)($s['variance_lines'] ?? 0) ?></td>
                                <td class="text-end <?= ($s['net_value'] ?? 0) >= 0 ? 'st-diff-pos' : 'st-diff-neg' ?>"><?= number_format((float)($s['net_value'] ?? 0), 2) ?></td>
                                <td><a href="<?= BASE_URL ?>StockTake/details/<?= (int)($s['id'] ?? 0) ?>" class="btn btn-outline-primary btn-sm py-0">View</a></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
        <div class="col-lg-5">
            <section class="st-section-card">
                <div class="st-section-head">Top variances by value</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Abs value</th>
                                <th class="text-end">+/− lines</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($topProducts)): ?>
                            <tr><td colspan="3" class="text-muted text-center py-3">No variances</td></tr>
                        <?php else: ?>
                            <?php foreach ($topProducts as $p): ?>
                            <tr>
                                <td><?= htmlspecialchars(($p['product_code'] ?? '') . ' ' . ($p['product_name'] ?? ''), ENT_QUOTES) ?></td>
                                <td class="text-end"><?= number_format((float)($p['abs_value_variance'] ?? 0), 2) ?></td>
                                <td class="text-end text-muted small">
                                    +<?= (int)($p['surplus_lines'] ?? 0) ?> / −<?= (int)($p['shortage_lines'] ?? 0) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';