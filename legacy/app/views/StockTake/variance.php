<?php
$title = $title ?? 'Stock Take Variance Report';
$isAdmin = !empty($is_admin);
$branches = $branches ?? [];
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-take.css">

<input type="hidden" id="base_url" value="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>">

<div class="purch-index-app st-take-app container-fluid py-2">
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-table me-2"></i>Variance detail report</h1>
            <p>All count lines where physical ≠ system — filter by session, warehouse, or product</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>StockTake" class="btn btn-light btn-sm"><i class="fas fa-cubes me-1"></i> Sessions</a>
            <a href="<?= BASE_URL ?>StockTake/weekly" class="btn btn-light btn-sm"><i class="fas fa-chart-line me-1"></i> Weekly</a>
            <a href="<?= BASE_URL ?>StockTake/checklist" class="btn btn-light btn-sm"><i class="fas fa-clipboard-check me-1"></i> Audit</a>
        </div>
    </header>

    <div class="st-section-card mb-3">
        <div class="p-3">
            <div class="row g-2 align-items-end">
                <?php if ($isAdmin): ?>
                <div class="col-md-2">
                    <label class="form-label small">Branch</label>
                    <select id="branch_id" class="form-select form-select-sm">
                        <option value="">-- All --</option>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['branch_name'] ?? '', ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-3">
                    <label class="form-label small">Session</label>
                    <select id="session_id" class="form-select form-select-sm"><option value="">-- All --</option></select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Warehouse</label>
                    <select id="warehouse_id" class="form-select form-select-sm"><option value="">-- All --</option></select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Product</label>
                    <select id="product_id" class="form-select form-select-sm"><option value="">-- All --</option></select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="button" class="btn btn-primary btn-sm flex-fill" id="btnGenerate"><i class="fas fa-search me-1"></i> Run</button>
                    <button type="button" class="btn btn-success btn-sm" id="btnExport" title="CSV"><i class="fas fa-file-csv"></i></button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-2 mb-3" id="summaryCards"></div>

    <div class="st-section-card">
        <div class="table-responsive p-2">
            <table class="table table-sm table-hover" id="stockTakeTable">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Code</th>
                        <th>Product</th>
                        <th>Warehouse</th>
                        <th class="text-end">System</th>
                        <th class="text-end">Physical</th>
                        <th class="text-end">Diff</th>
                        <th class="text-end">Value</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/StockTakeReport.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';