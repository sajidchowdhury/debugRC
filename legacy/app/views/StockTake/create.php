<?php
$title = $title ?? 'New Stock Take';
$branches = $branches ?? [];
$branchName = $branch_name ?? 'Branch';
$sessionBranch = (int)($_SESSION['branch_id'] ?? 0);

ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-take.css">

<div class="purch-index-app st-take-app container-fluid py-2">
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-plus-circle me-2"></i>New stock take session</h1>
            <p>Select branch and warehouses — counts save without moving stock until you post</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
        </div>
        <div class="purch-index-hero-actions">
            <a href="<?= BASE_URL ?>StockTake" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> List
            </a>
        </div>
    </header>

    <div class="st-section-card p-3">
        <form id="stockTakeForm">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Branch <span class="text-danger">*</span></label>
                    <select name="branch_id" id="branch_id" class="form-select" required>
                        <option value="">— Select branch —</option>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?= (int)$b['id'] ?>"
                            <?= (int)$b['id'] === $sessionBranch ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['branch_name'], ENT_QUOTES) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Take date</label>
                    <input type="date" name="take_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Warehouses to count <span class="text-danger">*</span></label>
                <div id="warehouse_list" class="border rounded p-3 bg-light" style="min-height:4rem">
                    <span class="text-muted small">Select a branch to load warehouses…</span>
                </div>
            </div>
            <div class="d-flex justify-content-between">
                <a href="<?= BASE_URL ?>StockTake" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-play me-1"></i> Start session
                </button>
            </div>
        </form>
    </div>
</div>

<script>window.ST_BOOT = { baseUrl: <?= json_encode(BASE_URL) ?> };</script>
<script src="<?= BASE_URL ?>assets/js/StockTake.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';