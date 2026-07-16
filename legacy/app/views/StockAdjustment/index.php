<?php
$title = $title ?? 'Stock Adjustments';
$adjustments = $adjustments ?? [];
$filters = $filters ?? [];
$warehouses = $warehouses ?? [];
$branchName = $branch_name ?? 'Branch';

ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-take.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-adjustment.css">

<div class="purch-index-app st-take-app sa-adjust-app container-fluid py-2">
    <header class="purch-index-hero sa-hero">
        <div>
            <h1><i class="fas fa-balance-scale me-2"></i>Stock adjustments</h1>
            <p>Manual increase/decrease — stock and GL post immediately (damage, found stock, corrections)</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
        </div>
        <div class="purch-index-hero-actions d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>StockAdjustment/create" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New adjustment
            </a>
            <a href="<?= BASE_URL ?>StockAdjustment/checklist" class="btn btn-outline-light btn-sm" title="Audit checklist">
                <i class="fas fa-clipboard-check"></i>
            </a>
            <a href="<?= BASE_URL ?>StockTake" class="btn btn-outline-light btn-sm" title="Physical stock take">
                <i class="fas fa-cubes"></i>
            </a>
            <button type="button" class="btn btn-outline-light btn-sm collapsed" data-bs-toggle="collapse"
                    data-bs-target="#saFiltersCollapse">
                <i class="fas fa-filter me-1"></i> Filters
            </button>
        </div>
    </header>

    <div class="purch-index-filters-shell">
        <div class="collapse show" id="saFiltersCollapse">
            <div class="purch-index-smart-panel">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">From</label>
                        <input type="date" name="date_from" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($filters['date_from'] ?? date('Y-m-d'), ENT_QUOTES) ?>">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">To</label>
                        <input type="date" name="date_to" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($filters['date_to'] ?? date('Y-m-d'), ENT_QUOTES) ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small mb-1">Warehouse</label>
                        <select name="warehouse_id" class="form-select form-select-sm">
                            <option value="">All warehouses</option>
                            <?php foreach ($warehouses as $w): ?>
                            <option value="<?= (int)$w['id'] ?>" <?= (int)($filters['warehouse_id'] ?? 0) === (int)$w['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($w['warehouse_name'] ?? '', ENT_QUOTES) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">Type</label>
                        <select name="adjustment_type" class="form-select form-select-sm">
                            <option value="all">All</option>
                            <option value="increase" <?= ($filters['adjustment_type'] ?? '') === 'increase' ? 'selected' : '' ?>>Increase</option>
                            <option value="decrease" <?= ($filters['adjustment_type'] ?? '') === 'decrease' ? 'selected' : '' ?>>Decrease</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="all">All</option>
                            <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="reversed" <?= ($filters['status'] ?? '') === 'reversed' ? 'selected' : '' ?>>Reversed</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="fas fa-search me-1"></i> Search</button>
                        <a href="<?= BASE_URL ?>StockAdjustment" class="btn btn-outline-secondary btn-sm">Reset</a>
                        <a href="<?= BASE_URL ?>StockAdjustment/export?<?= htmlspecialchars(http_build_query($_GET ?? []), ENT_QUOTES) ?>"
                           class="btn btn-success btn-sm" title="CSV"><i class="fas fa-file-csv"></i></a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="st-section-card">
        <div class="st-section-head d-flex justify-content-between align-items-center">
            <span>Adjustments <span class="text-muted fw-normal">(<?= count($adjustments) ?>)</span></span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 st-index-table">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Code</th>
                        <th>Warehouse</th>
                        <th>Type</th>
                        <th class="text-end">Amount</th>
                        <th>GL</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($adjustments)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No adjustments in this period</td></tr>
                <?php else: ?>
                    <?php foreach ($adjustments as $a):
                        $isRev = !empty($a['is_reversed']);
                        $type = $a['adjustment_type'] ?? '';
                        ?>
                    <tr class="<?= $isRev ? 'is-reversed' : '' ?>">
                        <td><?= !empty($a['adjustment_date']) ? date('d M Y', strtotime($a['adjustment_date'])) : '' ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>StockAdjustment/details/<?= (int)$a['id'] ?>" class="fw-semibold text-decoration-none">
                                <?= htmlspecialchars($a['adjustment_code'] ?? '', ENT_QUOTES) ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($a['warehouse_name'] ?? '', ENT_QUOTES) ?></td>
                        <td>
                            <span class="badge sa-type-<?= htmlspecialchars($type, ENT_QUOTES) ?>"><?= ucfirst($type) ?></span>
                        </td>
                        <td class="text-end fw-semibold"><?= number_format((float)($a['total_amount'] ?? 0), 2) ?></td>
                        <td><?= !empty($a['journal_entry_id']) ? '<span class="badge bg-success">Yes</span>' : '<span class="text-muted small">—</span>' ?></td>
                        <td>
                            <span class="badge-status <?= $isRev ? 'reversed' : 'adjusted' ?>"><?= $isRev ? 'reversed' : 'active' ?></span>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="<?= BASE_URL ?>StockAdjustment/details/<?= (int)$a['id'] ?>" class="btn btn-outline-primary btn-sm py-0">View</a>
                            <?php if (!$isRev): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm py-0 js-sa-reverse"
                                    data-adjustment-id="<?= (int)$a['id'] ?>"
                                    data-adjustment-code="<?= htmlspecialchars($a['adjustment_code'] ?? '', ENT_QUOTES) ?>">Reverse</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<input type="hidden" id="base_url" value="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>">
<script src="<?= BASE_URL ?>assets/js/StockAdjustment.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';