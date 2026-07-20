<?php
$title = $title ?? 'Warehouse Transfers';
$transfers = $transfers ?? [];
$filters = $filters ?? [];
$warehouses = $warehouses ?? [];
$branchName = $branch_name ?? 'Branch';

ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-take.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/warehouse-transfer.css">

<div class="purch-index-app st-take-app wt-transfer-app container-fluid py-2">
    <header class="purch-index-hero wt-hero">
        <div>
            <h1><i class="fas fa-truck-loading me-2"></i>Warehouse transfers</h1>
            <p>Move stock between warehouses in your branch — full stock audit trail</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
        </div>
        <div class="purch-index-hero-actions d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>WarehouseTransfer/create" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New transfer
            </a>
            <a href="<?= BASE_URL ?>WarehouseTransfer/checklist" class="btn btn-outline-light btn-sm" title="Audit">
                <i class="fas fa-clipboard-check"></i>
            </a>
            <a href="<?= BASE_URL ?>BranchDemand" class="btn btn-outline-light btn-sm" title="Branch demand">
                <i class="fas fa-share-alt"></i>
            </a>
            <button type="button" class="btn btn-outline-light btn-sm collapsed" data-bs-toggle="collapse" data-bs-target="#wtFiltersCollapse">
                <i class="fas fa-filter me-1"></i> Filters
            </button>
        </div>
    </header>

    <div class="purch-index-filters-shell">
        <div class="collapse show" id="wtFiltersCollapse">
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
                        <label class="form-label small mb-1">From warehouse</label>
                        <select name="from_warehouse_id" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach ($warehouses as $w): ?>
                            <option value="<?= (int)$w['id'] ?>" <?= (int)($filters['from_warehouse_id'] ?? 0) === (int)$w['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($w['warehouse_name'] ?? '', ENT_QUOTES) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small mb-1">To warehouse</label>
                        <select name="to_warehouse_id" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach ($warehouses as $w): ?>
                            <option value="<?= (int)$w['id'] ?>" <?= (int)($filters['to_warehouse_id'] ?? 0) === (int)$w['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($w['warehouse_name'] ?? '', ENT_QUOTES) ?>
                            </option>
                            <?php endforeach; ?>
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
                    <div class="col-12 col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="fas fa-search me-1"></i> Search</button>
                        <a href="<?= BASE_URL ?>WarehouseTransfer" class="btn btn-outline-secondary btn-sm">Reset</a>
                        <a href="<?= BASE_URL ?>WarehouseTransfer/export?<?= htmlspecialchars(http_build_query($_GET ?? []), ENT_QUOTES) ?>" class="btn btn-success btn-sm"><i class="fas fa-file-csv"></i></a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="st-section-card">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 st-index-table">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Code</th>
                        <th>Route</th>
                        <th class="text-end">Amount</th>
                        <th>GL</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($transfers)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No warehouse transfers in this period</td></tr>
                <?php else: ?>
                    <?php foreach ($transfers as $t):
                        $rev = !empty($t['is_reversed']);
                        $hasGl = !empty($t['branch_demand_id']) || (!empty($t['journal_entry_id']) && !empty($t['journal_entry_id_debtor']));
                        ?>
                    <tr class="<?= $rev ? 'is-reversed' : '' ?>">
                        <td><?= !empty($t['transfer_date']) ? date('d M Y', strtotime($t['transfer_date'])) : '' ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>WarehouseTransfer/details/<?= (int)$t['id'] ?>" class="fw-semibold text-decoration-none">
                                <?= htmlspecialchars($t['transfer_code'] ?? '', ENT_QUOTES) ?>
                            </a>
                            <?php if (!empty($t['branch_demand_id'])): ?>
                            <span class="badge bg-secondary ms-1" title="From branch demand">Demand</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?= htmlspecialchars($t['from_warehouse'] ?? '', ENT_QUOTES) ?>
                            <i class="fas fa-arrow-right mx-1 text-muted"></i>
                            <?= htmlspecialchars($t['to_warehouse'] ?? '', ENT_QUOTES) ?>
                        </td>
                        <td class="text-end fw-semibold"><?= number_format((float)($t['total_amount'] ?? 0), 2) ?></td>
                        <td><?= $hasGl ? '<span class="badge bg-success">Yes</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td><span class="badge-status <?= $rev ? 'reversed' : 'counting' ?>"><?= $rev ? 'reversed' : 'active' ?></span></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= BASE_URL ?>WarehouseTransfer/details/<?= (int)$t['id'] ?>" class="btn btn-outline-primary btn-sm py-0">View</a>
                            <?php if (!empty($t['can_reverse'])): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm py-0 js-wt-reverse"
                                    data-transfer-id="<?= (int)$t['id'] ?>"
                                    data-transfer-code="<?= htmlspecialchars($t['transfer_code'] ?? '', ENT_QUOTES) ?>"
                                    title="Reverse transfer">
                                <i class="fas fa-undo"></i>
                            </button>
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

<script>window.WT_BOOT = { baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?> };</script>
<input type="hidden" id="base_url" value="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>">
<script src="<?= BASE_URL ?>assets/js/WarehouseTransfer.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';