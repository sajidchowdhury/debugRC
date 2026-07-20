<?php
$title = $title ?? 'New Stock Adjustment';
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
            <h1><i class="fas fa-plus-circle me-2"></i>New stock adjustment</h1>
            <p>Applies stock and GL when you save — use avg cost from warehouse</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
        </div>
        <div class="purch-index-hero-actions">
            <a href="<?= BASE_URL ?>StockAdjustment" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i> List</a>
        </div>
    </header>

    <div class="st-section-card p-3">
        <form id="stockAdjustmentForm">
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Warehouse <span class="text-danger">*</span></label>
                    <select name="warehouse_id" id="sa_warehouse_id" class="form-select" required>
                        <option value="">— Select warehouse —</option>
                        <?php foreach ($warehouses as $w): ?>
                        <option value="<?= (int)$w['id'] ?>"><?= htmlspecialchars($w['warehouse_name'] ?? '', ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                    <select name="adjustment_type" id="sa_adjustment_type" class="form-select" required>
                        <option value="increase">Increase stock</option>
                        <option value="decrease">Decrease stock</option>
                    </select>
                    <div class="form-text small" id="sa_type_hint">Dr inventory / Cr surplus on post</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Date</label>
                    <input type="date" name="adjustment_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Narration (optional)</label>
                <textarea name="narration" class="form-control" rows="2" placeholder="Notes for this adjustment"></textarea>
            </div>

            <div class="sa-lines-head d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold">Line items</span>
                <button type="button" onclick="addItemRow()" class="btn btn-outline-primary btn-sm">+ Add line</button>
            </div>
            <div id="items_section" class="mb-2"></div>

            <div class="st-count-summary-bar mb-3" id="sa_total_bar">
                <span>Lines: <strong id="sa_line_count">0</strong></span>
                <span>Total value: <strong id="sa_total_value">0.00</strong></span>
                <span class="small text-muted" id="sa_gl_preview">GL posts on save when value &gt; 0</span>
            </div>

            <div class="d-flex justify-content-between">
                <button type="button" onclick="resetForm()" class="btn btn-outline-secondary">Reset</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check me-1"></i> Save &amp; post
                </button>
            </div>
        </form>
    </div>
</div>

<input type="hidden" id="base_url" value="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>">
<script src="<?= BASE_URL ?>assets/js/StockAdjustment.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';