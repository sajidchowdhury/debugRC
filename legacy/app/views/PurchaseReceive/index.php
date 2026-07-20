<?php
$showReturned = !empty($showReturned);
$branchName = $_SESSION['branch_name'] ?? 'Branch';
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-dt-mobile.css">

<div class="purch-index-app purch-grn" id="purchase-receive-app">
    <div class="purch-index-hero">
        <div>
            <h1><i class="fas fa-dolly me-2"></i><?= $showReturned ? 'Returned GRNs' : 'Goods received (GRN)' ?></h1>
            <p>Record stock-in from suppliers — linked to PO or direct purchase.</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i><?= htmlspecialchars($branchName) ?></span>
            <span class="purch-index-tag is-alt"><i class="fas fa-warehouse me-1"></i>Stock in</span>
        </div>
        <div class="purch-index-hero-actions">
            <?php if ($showReturned): ?>
                <a href="<?= BASE_URL ?>PurchaseReceive" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Active GRNs</a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>PurchaseReceive/create" class="btn btn-light btn-sm"><i class="fas fa-plus me-1"></i>New receive</a>
                <a href="<?= BASE_URL ?>PurchaseReceive?returned=1" class="btn btn-outline-light btn-sm"><i class="fas fa-undo me-1"></i>Returned</a>
                <a href="<?= BASE_URL ?>PurchaseAudit/checklist" class="btn btn-outline-light btn-sm" title="Stock &amp; GL checklist"><i class="fas fa-clipboard-check"></i></a>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-light btn-sm collapsed" id="togglePurchFilters" data-bs-toggle="collapse" data-bs-target="#purchFiltersCollapse" aria-expanded="false" title="Filters">
                <i class="fas fa-filter me-1"></i>Filters
            </button>
        </div>
    </div>

    <div class="purch-index-filters-shell">
        <div class="collapse" id="purchFiltersCollapse">
            <div class="purch-index-smart-panel">
                <div class="purch-index-smart-label">Quick date range</div>
                <div class="purch-index-preset-row">
                    <button type="button" class="purch-index-preset-btn" data-preset="today">Today</button>
                    <button type="button" class="purch-index-preset-btn" data-preset="yesterday">Yesterday</button>
                    <button type="button" class="purch-index-preset-btn" data-preset="week">Last 7 days</button>
                    <button type="button" class="purch-index-preset-btn active" data-preset="month">This month</button>
                    <button type="button" class="purch-index-preset-btn" data-preset="custom">Custom</button>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1">From</label>
                        <input type="date" class="form-control form-control-sm" id="filterDateFrom">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1">To</label>
                        <input type="date" class="form-control form-control-sm" id="filterDateTo">
                    </div>
                </div>
                <?php if (!$showReturned): ?>
                <div class="purch-index-smart-label">Status</div>
                <div class="purch-index-status-chips mb-3">
                    <button type="button" class="purch-index-status-chip active" data-status="all">All</button>
                    <button type="button" class="purch-index-status-chip" data-status="received">Received</button>
                    <button type="button" class="purch-index-status-chip" data-status="partial">Partial</button>
                </div>
                <input type="hidden" id="filterStatus" value="">
                <?php else: ?>
                <input type="hidden" id="filterStatus" value="returned">
                <?php endif; ?>
                <div class="purch-index-smart-label">Smart search</div>
                <div class="purch-index-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="search" class="form-control purch-index-search-input" id="filterSearch" placeholder="GRN code, PO, supplier…" autocomplete="off">
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="clearFilters"><i class="fas fa-eraser me-1"></i>Clear filters</button>
            </div>
        </div>
    </div>

    <div class="purch-index-active-bar" id="activeFilterBar"></div>

    <div class="purch-index-results-card sales-dt-mobile-controls">
        <div class="purch-index-results-head">
            <span class="fw-semibold"><i class="fas fa-list me-1"></i> Results</span>
            <span class="text-muted small"><span id="resultsCountNum">0</span> record(s)</span>
        </div>
        <div class="purch-index-mobile-cards" id="receiveCards"></div>
        <div class="table-responsive p-2">
            <table id="receiveTable" class="table table-hover align-middle mb-0 w-100">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>GRN #</th>
                        <th>PO #</th>
                        <th>Supplier</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>Created by</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<script>
window.PURCHASE_RECEIVE_BOOT = {
    baseUrl: <?= json_encode(BASE_URL) ?>,
    showReturned: <?= $showReturned ? 'true' : 'false' ?>
};
</script>
<script src="<?= BASE_URL ?>assets/js/purchase-receive-index.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';