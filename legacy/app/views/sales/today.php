<?php
$title = "Today's Sales";
$filters = $filters ?? [];
$branchName = $session_branch_name ?? 'Branch';
$defaultTo = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-6 days'));
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-pos.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-today-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-receive-payment.css">
<meta name="theme-color" content="#4f46e5">

<div id="sales-today-app" class="sales-today-app container-fluid py-2">
    <header class="sales-today-hero">
        <div>
            <h1><i class="fas fa-receipt me-2"></i>Today's Sales</h1>
            <p>Collect payments invoice by invoice · remove from list when done</p>
            <span class="sales-today-branch-tag"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($branchName, ENT_QUOTES) ?></span>
        </div>
        <div class="sales-today-hero-actions d-flex gap-2 flex-shrink-0">
            
            <a href="<?= BASE_URL ?>sales/create" class="btn btn-light btn-sm">
                <i class="fas fa-plus"></i> New
            </a>
            <a href="<?= BASE_URL ?>SalesAudit/checklist" class="btn btn-outline-light btn-sm" title="Ecosystem checklist">
                <i class="fas fa-clipboard-check"></i>
            </a>
            <a href="<?= BASE_URL ?>sales/audit" class="btn btn-light btn-sm">
                <i class="fas fa-clipboard-list"></i> Audit
            </a>
            <a href="<?= BASE_URL ?>SalesReturn" class="btn btn-outline-light btn-sm" title="Sales returns">
                <i class="fas fa-undo-alt"></i> Returns
            </a>
            <a href="<?= BASE_URL ?>Damage" class="btn btn-outline-light btn-sm" title="Damage write-offs">
                <i class="fas fa-heart-crack"></i> Damage
            </a>
            <button type="button" class="btn btn-light btn-sm collapsed" id="toggleSalesTodayFilters" data-bs-toggle="collapse" data-bs-target="#salesTodayFiltersCollapse" aria-expanded="false" aria-controls="salesTodayFiltersCollapse" title="Filters">
                <i class="fas fa-filter me-1"></i>Filters
            </button>
        </div>
    </header>

    <section class="sales-today-filters-shell">
    

        <div class="collapse" id="salesTodayFiltersCollapse">
            <div class="sales-today-smart-panel">
                <div class="sales-today-smart-label">Quick period</div>
                <div class="sales-today-preset-row">
                    <button type="button" class="sales-today-preset-btn" data-preset="today">Today</button>
                    <button type="button" class="sales-today-preset-btn" data-preset="yesterday">Yesterday</button>
                    <button type="button" class="sales-today-preset-btn active" data-preset="week">Last 7 days</button>
                    <button type="button" class="sales-today-preset-btn" data-preset="month">This month</button>
                    <button type="button" class="sales-today-preset-btn" data-preset="custom">Custom</button>
                </div>

                <div class="sales-today-search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="search" id="filterSearch" class="form-control sales-today-search-input"
                           placeholder="Smart search — invoice, customer, mobile, branch, salesman, product…"
                           value="<?= htmlspecialchars($filters['search'] ?? '', ENT_QUOTES) ?>"
                           autocomplete="off">
                </div>

                <div class="sales-today-smart-label">Filter <small class="text-muted fw-normal">(live counts)</small></div>
                <div class="sales-today-status-chips">
                    <button type="button" class="sales-today-status-chip" data-status="all">
                        <span>All</span><span class="chip-count">0</span>
                    </button>
                    <button type="button" class="sales-today-status-chip chip-urgent" data-status="awaiting_payment">
                        <span>Awaiting payment</span><span class="chip-count">0</span>
                    </button>
                    <button type="button" class="sales-today-status-chip" data-status="open_pipeline">
                        <span>In progress</span><span class="chip-count">0</span>
                    </button>
                    <button type="button" class="sales-today-status-chip" data-status="pending">
                        <span>Draft</span><span class="chip-count">0</span>
                    </button>
                    <button type="button" class="sales-today-status-chip" data-status="godown_copy">
                        <span>Godown issued</span><span class="chip-count">0</span>
                    </button>
                    <button type="button" class="sales-today-status-chip" data-status="challan_generated">
                        <span>Challan done</span><span class="chip-count">0</span>
                    </button>
                </div>
                <input type="hidden" id="filterChallanStatus" value="<?= htmlspecialchars($filters['challan_status'] ?? 'all', ENT_QUOTES) ?>">

                <div class="mt-3 pt-3 border-top">
                    <div class="row g-2 align-items-end">
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-0">From</label>
                            <input type="date" id="filterDateFrom" class="form-control"
                                   value="<?= htmlspecialchars($filters['date_from'] ?? $defaultFrom, ENT_QUOTES) ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-0">To</label>
                            <input type="date" id="filterDateTo" class="form-control"
                                   value="<?= htmlspecialchars($filters['date_to'] ?? $defaultTo, ENT_QUOTES) ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="form-check mt-2 mt-md-4">
                                <input class="form-check-input" type="checkbox" id="filterSmartSort" checked>
                                <label class="form-check-label small" for="filterSmartSort">
                                    Priority sort — unpaid first, then oldest invoice date
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

    <div class="sales-today-active-bar" id="activeFilterBar"></div>

    <section class="sales-today-results-card">
        <div class="sales-today-results-head">
            <div class="fw-bold">
                <span id="resultsCountNum">0</span> invoice(s) on your collection list
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-warning btn-sm" id="callItADayBtn" title="Remove selected invoices from your daily list">
                    <i class="fas fa-check-circle"></i> Call It A Day
                </button>
                <a href="<?= BASE_URL ?>sales/export" id="exportTodayBtn" class="btn btn-success btn-sm">
                    <i class="fas fa-file-csv"></i> Export
                </a>
            </div>
        </div>
        <div class="p-2 p-md-3">
            <div id="invoiceCards" class="sales-today-mobile-cards"></div>
            <div class="table-responsive sales-dt-mobile-controls">
                <table class="table table-hover align-middle mb-0" id="todayInvoiceTable" style="width:100%">
                    <thead>
                        <tr>
                            <th class="text-center" width="40"><input type="checkbox" id="select_all" class="form-check-input"></th>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Branch</th>
                            <th>Sales Person</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Due</th>
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

<div class="modal fade" id="receiveModal" tabindex="-1" aria-labelledby="receiveModalLabel" aria-hidden="true" data-bs-focus="false">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" id="receiveModalContent"></div>
    </div>
</div>

<script>
window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
window.SALES_TODAY_BASE = <?= json_encode(BASE_URL) ?>;
window.SALES_RECEIPT_BASE = <?= json_encode(defined('PUBLIC_URL') ? PUBLIC_URL : BASE_URL) ?>;
window.SALES_TODAY_BOOT = <?= json_encode([
    'date_from'      => $filters['date_from'] ?? $defaultFrom,
    'date_to'        => $filters['date_to'] ?? $defaultTo,
    'challan_status' => $filters['challan_status'] ?? 'all',
    'search'         => $filters['search'] ?? '',
    'smart_sort'     => !empty($filters['smart_sort']),
    'date_preset'    => $filters['date_preset'] ?? 'week',
    'forceUrlParams' => !empty($_GET['date_from']) || !empty($_GET['challan_status']) || !empty($_GET['q']),
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/sales-receive-payment.js"></script>
<script src="<?= BASE_URL ?>assets/js/sales-today-index.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
