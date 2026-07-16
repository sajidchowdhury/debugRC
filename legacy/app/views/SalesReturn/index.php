<?php
$title = 'Sales Returns';
$filters = $filters ?? [];
$branchName = $session_branch_name ?? 'Branch';
$pendingCount = (int)($pending_count ?? 0);
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-pos.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-return-index.css">
<meta name="theme-color" content="#e11d48">

<div id="sales-return-app" class="sales-return-app container-fluid py-2">
    <header class="sales-return-hero">
        <div>
            <h1><i class="fas fa-undo-alt me-2"></i>Sales Returns</h1>
            <p>Two-step flow — receive from customer, then warehouse confirms stock</p>
            <div class="sr-journey-steps sr-journey-steps--hero" aria-label="Return process">
                <div class="sr-journey-step">
                    <span class="sr-journey-num">1</span>
                    <span class="sr-journey-label">Receive from customer</span>
                </div>
                <span class="sr-journey-arrow" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>
                <div class="sr-journey-step">
                    <span class="sr-journey-num">2</span>
                    <span class="sr-journey-label">Warehouse confirm</span>
                </div>
                <span class="sr-journey-arrow" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>
                <div class="sr-journey-step is-muted">
                    <span class="sr-journey-num">3</span>
                    <span class="sr-journey-label">Damage write-off (if damaged)</span>
                </div>
            </div>
            <span class="sales-return-branch-tag"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
            <?php if ($pendingCount > 0): ?>
            <button type="button" class="sales-return-pending-badge border-0" id="filterPendingOnly">
                <i class="fas fa-clock me-1"></i><span id="heroPendingCount"><?= $pendingCount ?></span> awaiting warehouse confirm
            </button>
            <?php endif; ?>
        </div>
        <div class="sales-return-hero-actions d-flex gap-2 flex-shrink-0">
       
            <button type="button" class="btn btn-light btn-sm" id="openSalesReturnCreate" data-bs-toggle="offcanvas" data-bs-target="#salesReturnCreateOffcanvas" aria-controls="salesReturnCreateOffcanvas">
                <i class="fas fa-box-open"></i> Step 1 — Receive
            </button>
            <a href="<?= BASE_URL ?>SalesReturn/create" class="btn btn-light btn-sm d-none d-md-inline-flex" title="Full page receive">
                <i class="fas fa-external-link-alt"></i>
            </a>
            <a href="<?= BASE_URL ?>SalesAudit/checklist" class="btn btn-outline-light btn-sm" title="Sales ecosystem checklist">
                <i class="fas fa-clipboard-check"></i>
            </a>
            <a href="<?= BASE_URL ?>SalesReturn/audit" class="btn btn-light btn-sm">
                <i class="fas fa-clipboard-list"></i> Audit
            </a>
            <a href="<?= BASE_URL ?>Damage" class="btn btn-outline-light btn-sm" title="Damage write-offs (linked from returns or manual)">
                <i class="fas fa-heart-crack"></i> Damage
            </a>
            <button type="button" class="btn btn-light btn-sm collapsed" id="toggleSalesReturnFilters" data-bs-toggle="collapse" data-bs-target="#salesReturnFiltersCollapse" aria-expanded="false" aria-controls="salesReturnFiltersCollapse" title="Filters">
                <i class="fas fa-filter me-1"></i>Filters
            </button>
        </div>
    </header>

    <?php
    $returnDamageLinks = $_SESSION['return_damage_links'] ?? null;
    unset($_SESSION['return_damage_links']);
    if (!empty($returnDamageLinks) && is_array($returnDamageLinks)):
    ?>
    <div class="alert alert-info border-0 shadow-sm mb-2 py-2 px-3 d-flex flex-wrap align-items-center gap-2">
        <span><i class="fas fa-heart-crack me-1"></i><strong>Linked damage write-off</strong> created from this return:</span>
        <?php foreach ($returnDamageLinks as $dmg): ?>
        <a href="<?= BASE_URL ?>Damage/details/<?= (int)($dmg['id'] ?? 0) ?>" class="btn btn-sm btn-outline-primary">
            <?= htmlspecialchars($dmg['damage_code'] ?? 'Damage', ENT_QUOTES) ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <section class="sales-return-filters-shell">
       

        <div class="collapse" id="salesReturnFiltersCollapse">
            <div class="sales-return-smart-panel">
                <div class="sales-return-smart-label">Quick period</div>
                <div class="sales-return-preset-row">
                    <button type="button" class="sales-return-preset-btn active" data-preset="today">Today</button>
                    <button type="button" class="sales-return-preset-btn" data-preset="yesterday">Yesterday</button>
                    <button type="button" class="sales-return-preset-btn" data-preset="week">Last 7 days</button>
                    <button type="button" class="sales-return-preset-btn" data-preset="month">This month</button>
                    <button type="button" class="sales-return-preset-btn" data-preset="custom">Custom</button>
                </div>

                <div class="sales-return-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="search" id="filterSearch" class="form-control sales-return-search-input"
                           placeholder="Smart search — return #, invoice, customer, mobile, product…"
                           value="<?= htmlspecialchars($filters['search'] ?? '', ENT_QUOTES) ?>"
                           autocomplete="off">
                </div>

                <div class="sales-return-smart-label">Status <small class="text-muted fw-normal">(live counts)</small></div>
                <div class="sales-return-status-chips">
                    <button type="button" class="sales-return-status-chip" data-status="all">
                        <span>All</span><span class="chip-count">0</span>
                    </button>
                    <button type="button" class="sales-return-status-chip chip-urgent" data-status="pending">
                        <span>Awaiting warehouse</span><span class="chip-count">0</span>
                    </button>
                    <button type="button" class="sales-return-status-chip" data-status="active">
                        <span>Active</span><span class="chip-count">0</span>
                    </button>
                    <button type="button" class="sales-return-status-chip" data-status="completed">
                        <span>Completed</span><span class="chip-count">0</span>
                    </button>
                    <button type="button" class="sales-return-status-chip" data-status="reversed">
                        <span>Reversed</span><span class="chip-count">0</span>
                    </button>
                </div>
                <input type="hidden" id="filterStatus" value="<?= htmlspecialchars($filters['status'] ?? 'all', ENT_QUOTES) ?>">

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
                                    Priority sort — pending first, then completed, then reversed
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

    <div class="sales-return-active-bar" id="activeFilterBar"></div>

    <section class="sales-return-results-card">
        <div class="sales-return-results-head">
            <div class="fw-bold"><span id="resultsCountNum">0</span> return(s)</div>
        </div>
        <div class="p-2 p-md-3">
            <div id="returnCards" class="sales-return-mobile-cards"></div>
            <div class="table-responsive sales-dt-mobile-controls">
                <table class="table table-hover align-middle mb-0" id="returnsTable" style="width:100%">
                    <thead>
                        <tr>
                            <th>Return</th>
                            <th>Invoice</th>
                            <th>Customer</th>
                            <th>Branch</th>
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

<div class="offcanvas offcanvas-end sales-return-create-offcanvas" tabindex="-1" id="salesReturnCreateOffcanvas" aria-labelledby="salesReturnCreateOffcanvasLabel">
    <div class="offcanvas-header">
        <div>
            <h5 class="offcanvas-title mb-0" id="salesReturnCreateOffcanvasLabel"><i class="fas fa-box-open me-2"></i>Step 1 — Receive from customer</h5>
            <p class="mb-0 small opacity-90">Search invoice → enter qty → save (warehouse confirms later)</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body pt-2">
        <?php
        $workspace_id = 'salesReturnOffcanvasRoot';
        $compact = true;
        require __DIR__ . '/partials/create_workspace.php';
        ?>
    </div>
</div>

<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-return-create.css">
<script>window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
<script>
window.SALES_RETURN_CREATE_BOOT = <?= json_encode([
    'workspace_id' => 'salesReturnOffcanvasRoot',
    'prefill'        => '',
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/SalesReturn.js"></script>

<script>
window.SALES_RETURN_BASE = <?= json_encode(BASE_URL) ?>;
window.SALES_RETURN_BOOT = <?= json_encode([
    'date_from'   => $filters['date_from'] ?? date('Y-m-d'),
    'date_to'     => $filters['date_to'] ?? date('Y-m-d'),
    'status'      => $filters['status'] ?? 'all',
    'search'      => $filters['search'] ?? '',
    'smart_sort'  => !empty($filters['smart_sort']),
    'date_preset' => $filters['date_preset'] ?? 'today',
    'forceUrlParams' => !empty($_GET['date_from']) || !empty($_GET['status']) || !empty($_GET['q']),
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/sales-return-index.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';