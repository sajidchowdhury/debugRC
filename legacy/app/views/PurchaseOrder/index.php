<?php
$showCancelled = !empty($showCancelled);
$branchName = $_SESSION['branch_name'] ?? 'Branch';
$csrf = $csrf ?? ($_SESSION['csrf_token'] ?? '');
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-dt-mobile.css">

<div class="purch-index-app" id="purchase-order-app">
    <div class="purch-index-hero">
        <div>
            <h1><i class="fas fa-file-invoice me-2"></i><?= $showCancelled ? 'Cancelled purchase orders' : 'Purchase orders' ?></h1>
            <p>Plan supplier buys, track receipt progress, and manage PO lifecycle.</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i><?= htmlspecialchars($branchName) ?></span>
            <span class="purch-index-tag is-alt"><i class="fas fa-truck-loading me-1"></i>Procurement</span>
        </div>
        <div class="purch-index-hero-actions">
            <?php if ($showCancelled): ?>
                <a href="<?= BASE_URL ?>PurchaseOrder" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Active POs</a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>PurchaseOrder/create" class="btn btn-light btn-sm"><i class="fas fa-plus me-1"></i>New PO</a>
                <a href="<?= BASE_URL ?>PurchaseOrder?cancelled=1" class="btn btn-outline-light btn-sm"><i class="fas fa-ban me-1"></i>Cancelled</a>
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
                <div class="purch-index-smart-label">Status</div>
                <div class="purch-index-status-chips mb-3">
                    <button type="button" class="purch-index-status-chip active" data-status="all">All</button>
                    <button type="button" class="purch-index-status-chip" data-status="draft">Draft</button>
                    <button type="button" class="purch-index-status-chip" data-status="pending">Pending</button>
                    <button type="button" class="purch-index-status-chip" data-status="partially_received">Partial</button>
                    <button type="button" class="purch-index-status-chip" data-status="received">Received</button>
                    <?php if ($showCancelled): ?>
                        <button type="button" class="purch-index-status-chip chip-warn active" data-status="cancelled">Cancelled</button>
                    <?php endif; ?>
                </div>
                <input type="hidden" id="filterStatus" value="<?= $showCancelled ? 'cancelled' : '' ?>">
                <div class="purch-index-smart-label">Smart search</div>
                <div class="purch-index-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="search" class="form-control purch-index-search-input" id="filterSearch" placeholder="PO code, supplier, branch…" autocomplete="off">
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
        <div class="purch-index-mobile-cards" id="poCards"></div>
        <div class="table-responsive p-2">
            <table id="poTable" class="table table-hover align-middle mb-0 w-100">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>PO #</th>
                        <th>Supplier</th>
                        <th>Branch</th>
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
window.PURCHASE_ORDER_BOOT = {
    baseUrl: <?= json_encode(BASE_URL) ?>,
    csrf: <?= json_encode($csrf) ?>,
    showCancelled: <?= $showCancelled ? 'true' : 'false' ?>
};
</script>
<script src="<?= BASE_URL ?>assets/js/purchase-order-index.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';