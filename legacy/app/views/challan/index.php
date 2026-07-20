<?php
$title = 'Warehouse — Godown & Challan';
$filters = $filters ?? [];
$branchName = $session_branch_name ?? 'Branch';
$openQueueCount = (int)($open_queue_count ?? 0);
$needsChallanCount = (int)($needs_challan_count ?? 0);
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-pos.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/challan-index.css">
<meta name="theme-color" content="#d97706">

<div id="challan-index-app" class="challan-index-app container-fluid py-2">
    <header class="challan-index-hero">
        <div>
            <h1><i class="fas fa-warehouse me-2"></i>Godown & Challan</h1>
            <p>Invoice → godown setup → finalize challan &amp; deduct stock</p>
            <div class="challan-journey-steps" aria-label="Workflow">
                <div class="challan-journey-step"><span class="num">1</span> Invoice</div>
                <i class="fas fa-chevron-right challan-journey-arrow" aria-hidden="true"></i>
                <div class="challan-journey-step"><span class="num">2</span> Godown</div>
                <i class="fas fa-chevron-right challan-journey-arrow" aria-hidden="true"></i>
                <div class="challan-journey-step"><span class="num">3</span> Challan</div>
            </div>
            <span class="challan-branch-tag"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
            <?php if ($openQueueCount > 0): ?>
            <button type="button" class="challan-queue-badge border-0" id="filterOpenQueue" title="Show all invoices needing warehouse action">
                <i class="fas fa-bolt me-1"></i><span id="heroOpenCount"><?= $openQueueCount ?></span> need warehouse action
            </button>
            <?php endif; ?>
            <?php if ($needsChallanCount > 0): ?>
            <button type="button" class="challan-queue-badge challan-queue-badge--ready border-0" id="filterReadyChallan" title="Godown saved — ready to finalize">
                <i class="fas fa-truck me-1"></i><span id="heroReadyCount"><?= $needsChallanCount ?></span> ready for challan
            </button>
            <?php endif; ?>
        </div>
        <div class="challan-hero-actions d-flex gap-2 flex-shrink-0">
           
            <a href="<?= BASE_URL ?>sales/today" class="btn btn-light btn-sm">
                <i class="fas fa-receipt"></i> Sales
            </a>
            <a href="<?= BASE_URL ?>SalesAudit/checklist" class="btn btn-outline-light btn-sm" title="Sales ecosystem checklist">
                <i class="fas fa-clipboard-check"></i>
            </a>
            <button type="button" class="btn btn-light btn-sm collapsed" id="toggleChallanFilters" data-bs-toggle="collapse" data-bs-target="#challanFiltersCollapse" aria-expanded="false" aria-controls="challanFiltersCollapse" title="Filters">
                <i class="fas fa-filter me-1"></i>Filters
            </button>
        </div>
    </header>

    <section class="challan-filters-shell">
        

        <div class="collapse" id="challanFiltersCollapse">
        <div class="challan-smart-panel">
        <div class="challan-smart-label">Quick period</div>
        <div class="challan-preset-row">
            <button type="button" class="challan-preset-btn active" data-preset="today">Today</button>
            <button type="button" class="challan-preset-btn" data-preset="yesterday">Yesterday</button>
            <button type="button" class="challan-preset-btn" data-preset="week">Last 7 days</button>
            <button type="button" class="challan-preset-btn" data-preset="month">This month</button>
            <button type="button" class="challan-preset-btn" data-preset="custom">Custom</button>
        </div>

        <div class="challan-search-wrap">
            <i class="fas fa-search"></i>
            <input type="search" id="filterSearch" class="form-control challan-search-input"
                   placeholder="Smart search — invoice #, shop, customer, mobile, address, salesman…"
                   value="<?= htmlspecialchars($filters['search'] ?? '', ENT_QUOTES) ?>"
                   autocomplete="off">
        </div>

        <div class="challan-smart-label">Workflow filter <small class="text-muted fw-normal">(counts update live)</small></div>
        <div class="challan-status-chips">
            <button type="button" class="challan-status-chip chip-urgent" data-status="open">
                <span>Needs warehouse</span>
                <span class="chip-count">0</span>
            </button>
            <button type="button" class="challan-status-chip" data-status="needs_godown">
                <span>Pending godown</span>
                <span class="chip-count">0</span>
            </button>
            <button type="button" class="challan-status-chip" data-status="needs_challan">
                <span>Ready for challan</span>
                <span class="chip-count">0</span>
            </button>
            <button type="button" class="challan-status-chip" data-status="challan_completed">
                <span>Completed</span>
                <span class="chip-count">0</span>
            </button>
            <button type="button" class="challan-status-chip" data-status="all">
                <span>All</span>
                <span class="chip-count">0</span>
            </button>
        </div>
        <input type="hidden" id="filterStatus" value="<?= htmlspecialchars($filters['status'] ?? 'open', ENT_QUOTES) ?>">

        <button type="button" class="challan-advanced-toggle" id="toggleAdvancedFilters" data-bs-toggle="collapse" data-bs-target="#advancedFilters">
            <i class="fas fa-sliders-h me-1"></i> Custom dates & options
        </button>
        <div class="collapse challan-advanced-panel" id="advancedFilters">
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
                            Priority sort — pending godown first, then ready for challan
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

    <div class="challan-active-bar" id="activeFilterBar"></div>

    <section class="challan-results-card">
        <div class="challan-results-head">
            <div class="challan-results-count">
                Showing <span id="resultsCountNum">0</span> invoice(s)
            </div>
            <a href="<?= BASE_URL ?>challan/export" id="exportChallanBtn" class="btn btn-success btn-sm">
                <i class="fas fa-file-csv"></i> Export CSV
            </a>
        </div>
        <div class="card-body p-0">
            <div id="challanCards" class="challan-mobile-cards"></div>
            <div class="table-responsive sales-dt-mobile-controls p-2 p-md-3">
                <table class="table table-hover align-middle mb-0" id="challanTable" style="width:100%">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Sales person</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<script>
window.CHALLAN_BASE = <?= json_encode(BASE_URL . 'challan/') ?>;
window.CHALLAN_INDEX_BOOT = <?= json_encode([
    'date_from'  => $filters['date_from'] ?? date('Y-m-d'),
    'date_to'    => $filters['date_to'] ?? date('Y-m-d'),
    'status'     => $filters['status'] ?? 'open',
    'search'     => $filters['search'] ?? '',
    'smart_sort' => !empty($filters['smart_sort']),
    'date_preset'=> $filters['date_preset'] ?? 'today',
    'forceUrlParams' => !empty($_GET['date_from']) || !empty($_GET['status']) || !empty($_GET['q']),
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/challan-index.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';