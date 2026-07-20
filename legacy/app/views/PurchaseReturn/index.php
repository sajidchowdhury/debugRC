<?php
$title = 'Purchase Returns';
$filters = $filters ?? [];
$branchName = $session_branch_name ?? 'Branch';
$csrf = $csrf ?? ($_SESSION['csrf_token'] ?? '');
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-pos.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-return-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-return-create.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-dt-mobile.css">
<meta name="theme-color" content="#ea580c">

<div id="purchase-return-app" class="purchase-return-app container-fluid py-2">
    <header class="purchase-return-hero">
        <div>
            <h1><i class="fas fa-undo-alt me-2"></i>Purchase Returns</h1>
            <p>Return goods to suppliers — stock and GRN qty update automatically</p>
            <span class="purchase-return-branch-tag"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
        </div>
        <div class="purchase-return-hero-actions d-flex gap-2 flex-shrink-0">
            <button type="button" class="btn btn-light btn-sm" id="openPurchaseReturnCreate" data-bs-toggle="offcanvas" data-bs-target="#purchaseReturnCreateOffcanvas" aria-controls="purchaseReturnCreateOffcanvas">
                <i class="fas fa-plus"></i> Return
            </button>
            <a href="<?= BASE_URL ?>PurchaseReturn/create" class="btn btn-light btn-sm d-none d-md-inline-flex" title="Full page return">
                <i class="fas fa-external-link-alt"></i>
            </a>
  
            <a href="<?= BASE_URL ?>PurchaseReturn/audit" class="btn btn-light btn-sm">
                <i class="fas fa-clipboard-list"></i> Logs
            </a>
            <button type="button" class="btn btn-light btn-sm collapsed" id="togglePurchaseReturnFilters" data-bs-toggle="collapse" data-bs-target="#purchaseReturnFiltersCollapse" aria-expanded="false" aria-controls="purchaseReturnFiltersCollapse" title="Filters">
                <i class="fas fa-filter me-1"></i>Filters
            </button>
        </div>
    </header>

    <section class="purchase-return-filters-shell">
        <div class="collapse" id="purchaseReturnFiltersCollapse">
            <div class="purchase-return-smart-panel">
                <div class="purchase-return-smart-label">Quick period</div>
                <div class="purchase-return-preset-row">
                    <button type="button" class="purchase-return-preset-btn active" data-preset="today">Today</button>
                    <button type="button" class="purchase-return-preset-btn" data-preset="yesterday">Yesterday</button>
                    <button type="button" class="purchase-return-preset-btn" data-preset="week">Last 7 days</button>
                    <button type="button" class="purchase-return-preset-btn" data-preset="month">This month</button>
                    <button type="button" class="purchase-return-preset-btn" data-preset="custom">Custom</button>
                </div>

                <div class="purchase-return-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="search" id="filterSearch" class="form-control purchase-return-search-input"
                           placeholder="Smart search — return #, GRN, supplier…"
                           value="<?= htmlspecialchars($filters['search'] ?? '', ENT_QUOTES) ?>"
                           autocomplete="off">
                </div>

                <div class="purchase-return-smart-label">Status <small class="text-muted fw-normal">(live counts)</small></div>
                <div class="purchase-return-status-chips mb-3">
                    <button type="button" class="purchase-return-status-chip" data-status="all">
                        <span>All</span><span class="chip-count">0</span>
                    </button>
                    <button type="button" class="purchase-return-status-chip active" data-status="active">
                        <span>Active</span><span class="chip-count">0</span>
                    </button>
                    <button type="button" class="purchase-return-status-chip" data-status="reversed">
                        <span>Reversed</span><span class="chip-count">0</span>
                    </button>
                </div>
                <input type="hidden" id="filterStatus" value="<?= htmlspecialchars($filters['status'] ?? 'active', ENT_QUOTES) ?>">

                <div class="mt-3 pt-3 border-top">
                    <div class="row g-2 align-items-end">
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-0">From</label>
                            <input type="date" id="filterDateFrom" class="form-control"
                                   value="<?= htmlspecialchars($filters['date_from'] ?? date('Y-m-d'), ENT_QUOTES) ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-0">To</label>
                            <input type="date" id="filterDateTo" class="form-control"
                                   value="<?= htmlspecialchars($filters['date_to'] ?? date('Y-m-d'), ENT_QUOTES) ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="form-check mt-2 mt-md-4">
                                <input class="form-check-input" type="checkbox" id="filterSmartSort" checked>
                                <label class="form-check-label small" for="filterSmartSort">
                                    Priority sort — active first, then reversed
                                </label>
                            </div>
                        </div>
                        <div class="col-12 col-md-2">
                            <button type="button" id="clearFilters" class="btn btn-outline-secondary w-100">Reset all</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="purchase-return-active-bar" id="activeFilterBar"></div>

    <section class="purchase-return-results-card">
        <div class="purchase-return-results-head">
            <div class="fw-bold"><span id="resultsCountNum">0</span> return(s)</div>
        </div>
        <div class="p-2 p-md-3">
            <div id="returnCards" class="purchase-return-mobile-cards"></div>
            <div class="table-responsive sales-dt-mobile-controls">
                <table class="table table-hover align-middle mb-0" id="returnTable" style="width:100%">
                    <thead>
                        <tr>
                            <th>Return</th>
                            <th>GRN</th>
                            <th>Supplier</th>
                            <th>Date</th>
                            <th class="text-end">Amount</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<div class="offcanvas offcanvas-end purchase-return-create-offcanvas" tabindex="-1" id="purchaseReturnCreateOffcanvas" aria-labelledby="purchaseReturnCreateOffcanvasLabel">
    <div class="offcanvas-header">
        <div>
            <h5 class="offcanvas-title mb-0" id="purchaseReturnCreateOffcanvasLabel"><i class="fas fa-truck-loading me-2"></i>Quick return</h5>
            <p class="mb-0 small opacity-90">Search GRN → enter qty &amp; warehouse → save</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body pt-2">
        <?php
        $workspace_id = 'purchaseReturnOffcanvasRoot';
        $compact = true;
        require __DIR__ . '/partials/create_workspace.php';
        ?>
    </div>
</div>

<script>window.CSRF_TOKEN = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
<script>
window.PURCHASE_RETURN_CREATE_BOOT = <?= json_encode([
    'workspace_id' => 'purchaseReturnOffcanvasRoot',
    'prefill'      => trim($_GET['grn'] ?? $_GET['q'] ?? ''),
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/PurchaseReturn.js"></script>
<script>
window.PURCHASE_RETURN_BASE = <?= json_encode(BASE_URL) ?>;
window.PURCHASE_RETURN_BOOT = <?= json_encode([
    'date_from'   => $filters['date_from'] ?? date('Y-m-d'),
    'date_to'     => $filters['date_to'] ?? date('Y-m-d'),
    'status'      => $filters['status'] ?? 'active',
    'search'      => $filters['search'] ?? '',
    'smart_sort'  => !empty($filters['smart_sort']),
    'date_preset' => $filters['date_preset'] ?? 'today',
    'forceUrlParams' => !empty($_GET['date_from']) || !empty($_GET['status']) || !empty($_GET['q']),
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/purchase-return-index.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';