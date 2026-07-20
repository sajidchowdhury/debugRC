<?php

// Helper functions defined early so they are available during $content string evaluation
function renderActiveChips($from, $to, $branches, $whs, $cats, $prods) {
    $tags = [];
    if ($from && $to) {
        $tags[] = '<span class="filter-tag"><i class="fas fa-calendar me-1"></i> ' . htmlspecialchars($from) . ' → ' . htmlspecialchars($to) . '</span>';
    }
    if (!empty($branches)) {
        $cnt = count($branches);
        $tags[] = '<span class="filter-tag"><i class="fas fa-code-branch me-1"></i> ' . $cnt . ' branch' . ($cnt > 1 ? 'es' : '') . '</span>';
    }
    if (!empty($whs)) {
        $cnt = count($whs);
        $tags[] = '<span class="filter-tag"><i class="fas fa-warehouse me-1"></i> ' . $cnt . ' warehouse' . ($cnt > 1 ? 's' : '') . '</span>';
    }
    if (!empty($cats)) {
        $cnt = count($cats);
        $tags[] = '<span class="filter-tag"><i class="fas fa-tags me-1"></i> ' . $cnt . ' categor' . ($cnt > 1 ? 'ies' : 'y') . '</span>';
    }
    if (!empty($prods)) {
        $cnt = count($prods);
        $tags[] = '<span class="filter-tag"><i class="fas fa-box me-1"></i> ' . $cnt . ' product' . ($cnt > 1 ? 's' : '') . '</span>';
    }
    if (empty($tags)) {
        return '<span class="text-muted small">No filters active — open the Filters sidebar to design your view</span>';
    }
    $base = defined('BASE_URL') ? BASE_URL : '';
    return implode(' ', $tags) . ' <button type="button" class="btn btn-link btn-sm p-0 ms-auto" onclick="window.location.href=\'' . $base . 'Report/ProductStockAnalysis\'">Clear all</button>';
}

function renderResultsContent($data, $searchFlag) {
    if (empty($searchFlag)) {
        return '
        <div class="text-center py-5 text-muted">
            <i class="fas fa-filter fa-3x mb-3 opacity-50"></i>
            <h6 class="fw-normal">Report area ready.</h6>
            <p class="small mb-0">Click the <strong>Filters</strong> button in the header to open the sidebar.<br>Choose your criteria (multi-select supported), then click <strong>Search &amp; Show Report</strong> inside the sidebar.</p>
            <p class="small">The results will appear here in this maximized space only after you search.</p>
        </div>';
    }
    if (empty($data)) {
        return '<div class="alert alert-warning mb-0">No matching data for your current filters + date range.<br>Tip: Try widening the date range (e.g. 2025-01-01 to today) or deselect some filters to see the 1500+ seeded stock positions.</div>';
    }

    $total = count($data);
    $sumOpen = 0; $sumRec = 0; $sumIss = 0; $sumVal = 0;
    foreach ($data as $r) {
        $sumOpen += (float)($r['opening_qty'] ?? 0);
        $sumRec  += (float)($r['receipt_qty'] ?? 0);
        $sumIss  += (float)($r['issue_qty'] ?? 0);
        $sumVal  += (float)($r['closing_value'] ?? 0);
    }
    $net = $sumRec - $sumIss;

    $html = '
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-2"><div class="p-2 bg-light rounded text-center"><div class="small text-muted">Rows</div><div class="h5 mb-0 fw-bold">' . $total . '</div></div></div>
        <div class="col-6 col-md-2"><div class="p-2 bg-light rounded text-center"><div class="small text-muted">Opening</div><div class="h6 mb-0">' . number_format($sumOpen, 2) . '</div></div></div>
        <div class="col-6 col-md-2"><div class="p-2 bg-light rounded text-center"><div class="small text-muted">+ Receipts</div><div class="h6 mb-0 text-success">+' . number_format($sumRec, 2) . '</div></div></div>
        <div class="col-6 col-md-2"><div class="p-2 bg-light rounded text-center"><div class="small text-muted">- Issues</div><div class="h6 mb-0 text-danger">-' . number_format($sumIss, 2) . '</div></div></div>
        <div class="col-6 col-md-2"><div class="p-2 bg-light rounded text-center"><div class="small text-muted">Net Movement</div><div class="h6 mb-0 ' . ($net >= 0 ? 'text-success' : 'text-danger') . '">' . ($net >= 0 ? '+' : '') . number_format($net, 2) . '</div></div></div>
        <div class="col-6 col-md-2"><div class="p-2 bg-light rounded text-center"><div class="small text-muted">Closing Value</div><div class="h6 mb-0">Tk ' . number_format($sumVal, 0) . '</div></div></div>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" id="stockTable">
            <thead class="table-light">
                <tr>
                    <th>Branch</th>
                    <th>Warehouse</th>
                    <th>Category</th>
                    <th>Product</th>
                    <th class="text-end">Opening</th>
                    <th class="text-end text-success">+ Receipt</th>
                    <th class="text-end text-danger">- Issue</th>
                    <th class="text-end fw-bold">Closing</th>
                    <th class="text-end">Value</th>
                </tr>
            </thead>
            <tbody>';
    foreach ($data as $row) {
        $html .= '<tr>
            <td><span class="badge bg-secondary">' . htmlspecialchars($row['branch_name'] ?? '') . '</span></td>
            <td>' . htmlspecialchars($row['warehouse_name'] ?? '') . '</td>
            <td><span class="badge bg-light text-dark border">' . htmlspecialchars($row['category_name'] ?? '—') . '</span></td>
            <td><strong>' . htmlspecialchars($row['product_code'] ?? '') . '</strong><br><small class="text-muted">' . htmlspecialchars($row['product_name'] ?? '') . '</small></td>
            <td class="text-end">' . number_format($row['opening_qty'] ?? 0, 2) . '</td>
            <td class="text-end text-success">+' . number_format($row['receipt_qty'] ?? 0, 2) . '</td>
            <td class="text-end text-danger">-' . number_format($row['issue_qty'] ?? 0, 2) . '</td>
            <td class="text-end fw-bold">' . number_format($row['closing_qty'] ?? 0, 2) . '</td>
            <td class="text-end">Tk ' . number_format($row['closing_value'] ?? 0, 2) . '</td>
        </tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

$content = '
<div class="product-stock-analysis-app container-fluid py-2">

    <!-- Hero Header modeled after SalesReturn layout -->
    <header class="sales-return-hero" style="background: linear-gradient(90deg, #0d6efd, #6610f2); color: white; border-radius: 12px; padding: 0.85rem 1.1rem; margin-bottom: 0.75rem;">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h1 class="mb-1" style="font-size: 1.55rem;"><i class="fas fa-chart-area me-2"></i>Product Stock Analysis</h1>
                <p class="mb-0 opacity-90 small">Period movement • Opening / Closing • Full filter freedom</p>
                <span class="badge bg-white text-dark mt-1" style="font-size:0.75rem;"><i class="fas fa-map-marker-alt me-1"></i>' . htmlspecialchars($_SESSION['branch_name'] ?? 'Branch', ENT_QUOTES) . '</span>
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="offcanvas" data-bs-target="#reportFiltersOffcanvas" aria-controls="reportFiltersOffcanvas">
                    <i class="fas fa-filter me-1"></i> Filters
                </button>
                <a href="' . (defined('BASE_URL') ? BASE_URL : '') . 'Report" class="btn btn-outline-light btn-sm d-none d-sm-inline-flex">
                    <i class="fas fa-arrow-left me-1"></i> Reports
                </a>
            </div>
        </div>
    </header>

    <!-- Polished Active Filter Chips Bar - always visible, shows selected items nicely -->
    <div class="sales-return-active-bar" id="activeFilterBar" style="margin-bottom: 0.65rem; padding: 0.5rem 0.75rem;">
        ' . renderActiveChips($from_date ?? '', $to_date ?? '', $branch_ids ?? [], $warehouse_ids ?? [], $category_ids ?? [], $product_ids ?? []) . '
    </div>

    <!-- Main Report Area - MAXIMUM SPACE -->
    <section class="sales-return-results-card" style="border-radius: 12px; border: 1px solid #e2e8f0; min-height: 420px;">
        <div class="sales-return-results-head" style="padding: 0.55rem 0.9rem; background:#f8fafc; border-bottom:1px solid #e2e8f0; font-size:0.95rem;">
            <div class="fw-semibold d-flex align-items-center gap-2">
                <span>Results</span>
                ' . ((isset($stock_data) && $stock_data) ? '<span class="badge bg-primary">' . count($stock_data) . '</span>' : '') . '
            </div>
            ' . ( ( ($searched ?? false) || !empty($_GET['search']) ) && !empty($stock_data) ? '
            <div>
                <button onclick="window.print()" class="btn btn-sm btn-light border py-0 px-2"><i class="fas fa-print"></i></button>
                <a href="?search=1&export=1' . htmlspecialchars($_SERVER['QUERY_STRING'] ?? '', ENT_QUOTES) . '" class="btn btn-sm btn-success py-0 px-2"><i class="fas fa-file-csv me-1"></i>CSV</a>
            </div>' : '') . '
        </div>

        <div class="p-2 p-md-3" id="analysisResults">
            ' . renderResultsContent($stock_data ?? null, ($searched ?? $_GET['search'] ?? null)) . '
        </div>
    </section>

</div>

<!-- FILTERS OFFCANVAS (sidebar) - everything hidden here, report gets max space -->
<div class="offcanvas offcanvas-end modern-offcanvas d-flex flex-column" tabindex="-1" id="reportFiltersOffcanvas" aria-labelledby="reportFiltersOffcanvasLabel" style="width: 520px; max-width: 90vw;">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="reportFiltersOffcanvasLabel"><i class="fas fa-sliders-h me-2"></i>Filters &amp; Search</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body flex-grow-1 overflow-auto">
        <form method="post" id="stockFilterForm">

            <!-- Modern Quick Presets -->
            <div class="mb-3">
                <div class="small fw-semibold text-muted mb-1">Quick presets</div>
                <div class="d-flex flex-wrap gap-1">
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="today">Today</button>
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="7">Last 7 days</button>
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="30">Last 30 days</button>
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="mtd">This month</button>
                </div>
            </div>

            <!-- Modern Date Inputs -->
            <div class="mb-3">
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">From date</label>
                        <input type="date" name="from_date" id="from_date" class="form-control modern-input" value="' . htmlspecialchars($from_date ?? date('Y-m-d', strtotime('-30 days'))) . '">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">To date</label>
                        <input type="date" name="to_date" id="to_date" class="form-control modern-input" value="' . htmlspecialchars($to_date ?? date('Y-m-d')) . '">
                    </div>
                </div>
            </div>

            <!-- Categories with Toggle -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-semibold mb-0">Categories (multi)</label>
                    <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 toggle-all" data-select-id="category_select" style="font-size: 0.7rem;">Select All</button>
                </div>
                <select name="category_ids[]" id="category_select" class="form-select modern-select select2" multiple data-placeholder="Select categories...">
                    ';
foreach (($categories ?? []) as $c) {
    $sel = in_array($c['id'], $category_ids ?? []) ? 'selected' : '';
    $content .= '<option value="' . $c['id'] . '" ' . $sel . '>' . htmlspecialchars($c['category_name']) . '</option>';
}
$content .= '
                </select>
            </div>

            <!-- Branches with Toggle -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-semibold mb-0">Branches (multi)</label>
                    <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 toggle-all" data-select-id="branch_select" style="font-size: 0.7rem;">Select All</button>
                </div>
                <select name="branch_ids[]" id="branch_select" class="form-select modern-select select2" multiple data-placeholder="Select branches...">
                    ';
foreach (($branches ?? []) as $b) {
    $sel = in_array($b['id'], $branch_ids ?? []) ? 'selected' : '';
    $content .= '<option value="' . $b['id'] . '" ' . $sel . '>' . htmlspecialchars($b['branch_name']) . '</option>';
}
$content .= '
                </select>
            </div>

            <!-- Warehouses with Toggle -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-semibold mb-0">Warehouses (multi)</label>
                    <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 toggle-all" data-select-id="warehouse_select" style="font-size: 0.7rem;">Select All</button>
                </div>
                <select name="warehouse_ids[]" id="warehouse_select" class="form-select modern-select select2" multiple data-placeholder="Select warehouses...">
                    ';
foreach (($warehouses ?? []) as $w) {
    $sel = in_array($w['id'], $warehouse_ids ?? []) ? 'selected' : '';
    $content .= '<option value="' . $w['id'] . '" ' . $sel . '>' . htmlspecialchars($w['warehouse_name']) . '</option>';
}
$content .= '
                </select>
            </div>

            <!-- Products with Toggle -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-semibold mb-0">Products (multi + search)</label>
                    <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 toggle-all" data-select-id="product_select" style="font-size: 0.7rem;">Select All</button>
                </div>
                <select name="product_ids[]" id="product_select" class="form-select modern-select select2" multiple data-placeholder="Type to search products...">
                    ';
foreach (($products ?? []) as $p) {
    $sel = in_array($p['id'], $product_ids ?? []) ? 'selected' : '';
    $content .= '<option value="' . $p['id'] . '" ' . $sel . '>' . htmlspecialchars($p['product_code']) . ' — ' . htmlspecialchars($p['product_name']) . '</option>';
}
$content .= '
                </select>
            </div>

        </form>
    </div>

    <!-- FIXED FOOTER in Offcanvas - always visible, no scrolling needed for buttons -->
    <div class="offcanvas-footer bg-white border-top p-3 shadow-sm" style="flex-shrink: 0; position: sticky; bottom: 0; z-index: 1050;">
        <div class="d-grid gap-2">
            <button type="submit" form="stockFilterForm" name="search" value="1" class="btn btn-primary btn-sm">
                <i class="fas fa-search me-1"></i> Search &amp; Show Report
            </button>
            <button type="submit" form="stockFilterForm" name="export" value="1" class="btn btn-outline-success btn-sm">
                <i class="fas fa-download me-1"></i> Export CSV
            </button>
            <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="offcanvas">Close sidebar</button>
        </div>
        <div class="text-center mt-2">
            <a href="' . (defined('BASE_URL') ? BASE_URL : '') . 'Report/ProductStockAnalysis" class="small text-muted">Reset everything</a>
        </div>
    </div>
</div>

<style>
/* Polished chips like in SalesReturn */
.sales-return-active-bar .filter-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.15rem 0.55rem;
    background: #fff;
    border: 1px solid #fda4af;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 500;
    white-space: nowrap;
}

/* Modern offcanvas form styles */
.modern-offcanvas .modern-input,
.modern-offcanvas .modern-select {
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    font-size: 0.875rem;
    padding: 0.5rem 0.75rem;
    transition: all 0.2s ease;
    box-shadow: none;
}

.modern-offcanvas .modern-input:focus,
.modern-offcanvas .modern-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
}

.modern-offcanvas .form-label {
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.25rem;
}

.modern-offcanvas .btn-xs {
    font-size: 0.65rem;
    padding: 0.1rem 0.35rem;
    line-height: 1.1;
}

.modern-offcanvas .select2-container .select2-selection {
    border-radius: 10px !important;
    border-color: #e2e8f0 !important;
    min-height: 38px;
}

.modern-offcanvas .select2-container--focus .select2-selection {
    border-color: #0d6efd !important;
    box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1) !important;
}

/* Fixed Offcanvas Footer */
.offcanvas-footer {
    flex-shrink: 0;
    background: #fff;
    z-index: 10;
}

.offcanvas .offcanvas-body {
    padding-bottom: 1rem; /* some breathing room before the fixed footer */
}

/* Excel-like report grid */
.excel-grid {
    border-collapse: collapse;
    font-family: Calibri, "Segoe UI", Arial, sans-serif;
    font-size: 11px;
    width: 100%;
    background: #fff;
}

.excel-grid th {
    background-color: #4472C4;
    color: #fff;
    font-weight: 600;
    border: 1px solid #2F5496;
    padding: 5px 6px;
    text-align: left;
    position: sticky;
    top: 0;
    z-index: 2;
    white-space: nowrap;
}

.excel-grid td {
    border: 1px solid #B4C6E7;
    padding: 3px 5px;
    vertical-align: middle;
}

.excel-grid tr:nth-child(even) {
    background-color: #E2EFDA; /* light green tint like Excel */
}

.excel-grid tr:hover {
    background-color: #FFF2CC !important; /* yellow hover like Excel */
}

.excel-grid .text-end {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.excel-grid .number-col {
    text-align: right;
}

.excel-kpi {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 6px 8px;
    text-align: center;
}

.excel-kpi .label {
    font-size: 9px;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.excel-kpi .value {
    font-size: 15px;
    font-weight: 600;
    line-height: 1.1;
}
</style>

<script>
$(function() {
    // Select2 inside offcanvas
    if (typeof $.fn.select2 !== "undefined") {
        $("#branch_select, #warehouse_select, #category_select, #product_select").select2({
            width: "100%",
            placeholder: "Select...",
            allowClear: true,
            dropdownParent: $("#reportFiltersOffcanvas")
        });
    }

    // Dynamic warehouses
    $("#branch_select").on("change", function() {
        const bids = $(this).val() || [];
        const $wh = $("#warehouse_select");
        if (!bids.length) { $wh.html("").trigger("change"); return; }
        $.get("' . (defined('BASE_URL') ? BASE_URL : '') . 'report/getWarehousesByBranches", {branch_ids: bids}, function(resp){
            $wh.html("");
            const list = resp.data || resp || [];
            const pre = ' . json_encode(array_map('strval', $warehouse_ids ?? [])) . ';
            list.forEach(function(w){
                const s = pre.includes(String(w.id)) ? "selected" : "";
                $wh.append(`<option value="${w.id}" ${s}>${w.warehouse_name}</option>`);
            });
            $wh.trigger("change");
        });
    });
    if ($("#branch_select").val() && $("#branch_select").val().length) $("#branch_select").trigger("change");

    // Date presets
    $(".preset-btn").on("click", function() {
        const p = $(this).data("preset");
        const d = new Date();
        const fmt = dd => dd.toISOString().slice(0,10);
        let f = fmt(d), t = fmt(d);
        if (p === "7") f = fmt(new Date(d.getTime()-6*86400000));
        if (p === "30") f = fmt(new Date(d.getTime()-29*86400000));
        if (p === "mtd") f = fmt(new Date(d.getFullYear(), d.getMonth(), 1));
        $("#from_date").val(f); $("#to_date").val(t);
    });

    // Optional: make chips clickable to re-open offcanvas (nice UX)
    $("#activeFilterBar").on("click", ".filter-tag", function() {
        const off = new bootstrap.Offcanvas(document.getElementById("reportFiltersOffcanvas"));
        off.show();
    });

    // Initialize toggle button texts based on current selection
    function initToggleButtons() {
        $(\'.toggle-all\').each(function() {
            const selectId = $(this).data(\'select-id\');
            const $select = $(\'#\' + selectId);
            if (!$select.length) return;

            const $options = $select.find(\'option\');
            const selected = $select.val() || [];
            if (selected.length > 0 && selected.length === $options.length) {
                $(this).text(\'Deselect All\');
            } else {
                $(this).text(\'Select All\');
            }
        });
    }

    // All Select / Deselect Toggle for each group in offcanvas
    $(document).on(\'click\', \'.toggle-all\', function() {
        const selectId = $(this).data(\'select-id\');
        const $select = $(\'#\' + selectId);
        if (!$select.length) return;

        const $options = $select.find(\'option\');
        const currentSelected = $select.val() || [];
        const allValues = $options.map(function() { return this.value; }).get();

        if (currentSelected.length === allValues.length) {
            // All selected → deselect all
            $select.val([]).trigger(\'change\');
            $(this).text(\'Select All\');
        } else {
            // Not all selected → select all
            $select.val(allValues).trigger(\'change\');
            $(this).text(\'Deselect All\');
        }
    });

    // Update toggle button text when select changes manually
    $(\'#category_select, #branch_select, #warehouse_select, #product_select\').on(\'change\', function() {
        const $btn = $(\'.toggle-all[data-select-id="\' + this.id + \'"]\');
        if (!$btn.length) return;

        const $options = $(this).find(\'option\');
        const selected = $(this).val() || [];
        if (selected.length === $options.length) {
            $btn.text(\'Deselect All\');
        } else {
            $btn.text(\'Select All\');
        }
    });

    // Init on offcanvas show (so initial state is correct)
    $(\'#reportFiltersOffcanvas\').on(\'shown.bs.offcanvas\', function() {
        initToggleButtons();
    });

    // Also init once on page load in case offcanvas is already open somehow
    initToggleButtons();

    // Also init once on page load in case offcanvas is already open somehow
    initToggleButtons();
});
</script>

<!-- 
  Implementation notes for the user request:
  - The form now submits via POST (method="post"). This completely solves the "long URL" problem when selecting many products.
  - Controller was updated to read filters from POST or GET.
  - The results table uses .excel-grid CSS (blue header, cell borders, alternating rows, sticky header, Excel-like fonts and hover).
  - Fixed footer in the offcanvas keeps the Search/Export/Close buttons always visible.
  - For true no-reload AJAX, the backend supports returning JSON when ajax=1. The client JS can be added on top without server changes.
-->
';

require_once '../app/views/layouts/main.php';
?>