<?php
$title = $title ?? 'New Warehouse Transfer';
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
            <h1><i class="fas fa-plus-circle me-2"></i>New warehouse transfer</h1>
            <p>Move stock between two warehouses in your branch — stock updates on save</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
        </div>
        <div class="purch-index-hero-actions">
            <a href="<?= BASE_URL ?>WarehouseTransfer" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i> List</a>
        </div>
    </header>

    <div class="st-section-card p-3">
        <form id="warehouseTransferForm">
            <div class="row g-3 mb-2">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">From warehouse <span class="text-danger">*</span></label>
                    <select name="from_warehouse_id" id="from_warehouse_id" class="form-select" required>
                        <option value="">— From warehouse —</option>
                        <?php foreach ($warehouses as $w): ?>
                        <option value="<?= (int)$w['id'] ?>"
                                data-branch-id="<?= (int)($w['branch_id'] ?? 0) ?>"
                                data-branch-name="<?= htmlspecialchars($w['branch_name'] ?? '', ENT_QUOTES) ?>"
                                data-warehouse-name="<?= htmlspecialchars($w['warehouse_name'] ?? '', ENT_QUOTES) ?>">
                            <?= htmlspecialchars($w['warehouse_name'] ?? '', ENT_QUOTES) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">To warehouse <span class="text-danger">*</span></label>
                    <select name="to_warehouse_id" id="to_warehouse_id" class="form-select" required>
                        <option value="">— To warehouse —</option>
                        <?php foreach ($warehouses as $w): ?>
                        <option value="<?= (int)$w['id'] ?>"
                                data-branch-id="<?= (int)($w['branch_id'] ?? 0) ?>"
                                data-branch-name="<?= htmlspecialchars($w['branch_name'] ?? '', ENT_QUOTES) ?>"
                                data-warehouse-name="<?= htmlspecialchars($w['warehouse_name'] ?? '', ENT_QUOTES) ?>">
                            <?= htmlspecialchars($w['warehouse_name'] ?? '', ENT_QUOTES) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p id="wt_route_hint" class="wt-branch-hint mb-3">Select two different warehouses in your branch.</p>
            <?php if (empty($warehouses)): ?>
            <div class="alert alert-warning py-2 small">No warehouses found for your branch. Add warehouses before creating a transfer.</div>
            <?php endif; ?>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Transfer date</label>
                    <input type="date" name="transfer_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold">Products</span>
                <button type="button" onclick="addItemRow()" class="btn btn-outline-primary btn-sm">+ Add line</button>
            </div>
            <div id="items_section"></div>

            <div class="st-count-summary-bar my-3">
                <span>Total value: <strong id="wt_total_value">0.00</strong></span>
                <span class="small text-muted">Rates from sender warehouse avg cost</span>
            </div>

            <div class="d-flex justify-content-between">
                <button type="button" onclick="resetForm()" class="btn btn-outline-secondary">Reset</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check me-1"></i> Save transfer</button>
            </div>
        </form>
    </div>
</div>

<script>
window.WT_BOOT = {
    baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?>,
    hasWarehouses: <?= count($warehouses) > 0 ? 'true' : 'false' ?>
};
</script>
<input type="hidden" id="base_url" value="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>">
<script src="<?= BASE_URL ?>assets/js/WarehouseTransfer.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';