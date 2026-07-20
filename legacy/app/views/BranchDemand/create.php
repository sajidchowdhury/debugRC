<?php
$title = $title ?? 'Create Branch Demand';
$branchName = $branch_name ?? ($_SESSION['branch_name'] ?? 'Branch');

ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-demand.css">

<div class="purch-index-app bd-demand-app container-fluid py-2">
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-plus-circle me-2"></i>New branch demand</h1>
            <p>Request products from another branch — cost locks when they send goods</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?> (requester)</span>
        </div>
        <div class="purch-index-hero-actions">
            <a href="<?= BASE_URL ?>BranchDemand" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> List
            </a>
        </div>
    </header>

    <div class="bd-form-card">
        <form id="branchDemandForm">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Supplying branch <span class="text-danger">*</span></label>
                    <select name="to_branch_id" id="to_branch_id" class="form-select" required>
                        <option value="">Loading branches…</option>
                    </select>
                    <div class="form-text">Branch that will ship stock to you</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Demand date</label>
                    <input type="date" name="demand_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
            </div>

            <div class="bd-items-toolbar">
                <h2 class="h6 mb-0 fw-bold"><i class="fas fa-boxes me-1"></i> Line items</h2>
                <button type="button" class="btn btn-outline-primary btn-sm" id="bdAddLineBtn">
                    <i class="fas fa-plus me-1"></i> Add product
                </button>
            </div>

            <div id="items_section" class="mb-3"></div>

            <div class="d-flex justify-content-between pt-3 border-top">
                <a href="<?= BASE_URL ?>BranchDemand" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-paper-plane me-1"></i> Submit demand
                </button>
            </div>
        </form>
    </div>
</div>

<script>
window.BD_BOOT = { baseUrl: <?= json_encode(BASE_URL) ?> };
</script>
<script src="<?= BASE_URL ?>assets/js/BranchDemand.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';