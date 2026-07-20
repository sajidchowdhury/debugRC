<?php
/**
 * Product Movement Report (new source-aware version)
 * - Multi product + multi category + multi branch/wh + date range (POST, like ProductStockAnalysis)
 * - Pulls from stock_transactions (which records every line from purchase_receive_items, purchase_return_items,
 *   sales/challan/dispatches, sales_return_items, damage_invoice_items, stock_adjustment_items, stock_take_items,
 *   warehouse_transfer_items, branch_demand_items + all reversals)
 * - Enriched with actual document codes (PR-xxx, Challan-xxx, etc.) by joining the source tables
 * - Clean: One Opening at top + actual tx (rich desc from sources, In/Out, running per wh) + ONE Final Closing row at very bottom of table
 * - NO repeated "Closing Balance" rows inside the transaction list (fixed per requirements)
 * - "Explain this report" button at bottom (on demand) shows Stock Validity Explanation with per-wh Computed vs Live + MATCH status
 * - The final computed balance after all ins/outs = the accurate stock "right now"
 * - Premium readable table (15px font, good spacing, Excel-like professional look)
 */

$title = 'Product Movement Report';

// Reusable chip renderer (multi product + category + br/wh)
function renderMovementChips($from, $to, $branches, $whs, $cats, $productName, $productCount = 0) {
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
    if (!empty($productName)) {
        $tags[] = '<span class="filter-tag"><i class="fas fa-box me-1"></i> ' . htmlspecialchars($productName) . '</span>';
    } elseif ($productCount > 1) {
        $tags[] = '<span class="filter-tag"><i class="fas fa-boxes me-1"></i> ' . $productCount . ' products</span>';
    }
    if (empty($tags)) {
        return '<span class="text-muted small">No filters active — open Filters to choose product(s)/category + date range</span>';
    }
    $base = defined('BASE_URL') ? BASE_URL : '';
    return implode(' ', $tags) . ' <button type="button" class="btn btn-link btn-sm p-0 ms-auto" onclick="window.location.href=\'' . $base . 'Report/ProductMovement\'">Clear all</button>';
}

function renderMovementResults($data, $searchFlag, $productName = '', $reconciliation = [], $totals = [], $prodInfo = [], $categories = [], $productCount = 0) {
    if (empty($searchFlag)) {
        return '
        <div class="text-center py-5 text-muted">
            <i class="fas fa-route fa-3x mb-3 opacity-50"></i>
            <h6 class="fw-normal">Product Movement Ledger ready.</h6>
            <p class="small mb-0">Select a product (single focus), multi branch/warehouse, date range.<br>
            Then click <strong>Search &amp; Show Report</strong>. Shows movements from purchase items, sales, returns, damage, adjustments, physical stock, transfers, branch movements + reversals, with running balance.</p>
            <p class="small"><strong>Running balance accumulates: In adds, Out subtracts. Last balance after all = current stock for the product.</strong></p>
        </div>';
    }
    if (empty($data) && empty($reconciliation)) {
        return '<div class="alert alert-warning mb-0">No movements found in the selected period for the chosen product + locations.<br>Try widening the date range (use "all" preset) or clear some filters.</div>';
    }

    $html = '';

    // Light KPIs computed from the movement rows themselves (no extra DB for speed)
    $pin = 0; $pout = 0; $rowsN = count($data);
    $lastBalances = [];
    foreach ($data as $r) {
        if (!empty($r['is_opening'])) continue;
        $q = (float)($r['qty'] ?? 0);
        if ($q > 0) $pin += $q; else $pout += abs($q);
        // track last running per wh for "current computed"
        $wid = $r['warehouse_id'] ?? 0;
        if ($wid) $lastBalances[$wid] = (float)($r['running_balance'] ?? 0);
    }
    $pnet = $pin - $pout;
    $endComp = array_sum($lastBalances);

    $html .= '
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-2"><div class="p-2 bg-light rounded text-center"><div class="small text-muted">Rows</div><div class="h5 mb-0 fw-bold">' . $rowsN . '</div></div></div>
        <div class="col-6 col-md-2"><div class="p-2 bg-light rounded text-center"><div class="small text-muted">Period +In</div><div class="h6 mb-0 text-success">+' . number_format($pin, 2) . '</div></div></div>
        <div class="col-6 col-md-2"><div class="p-2 bg-light rounded text-center"><div class="small text-muted">Period -Out</div><div class="h6 mb-0 text-danger">-' . number_format($pout, 2) . '</div></div></div>
        <div class="col-6 col-md-2"><div class="p-2 bg-light rounded text-center"><div class="small text-muted">Period Net</div><div class="h6 mb-0 ' . ($pnet >= 0 ? 'text-success' : 'text-danger') . '">' . ($pnet >= 0 ? '+' : '') . number_format($pnet, 2) . '</div></div></div>
        <div class="col-6 col-md-2"><div class="p-2 bg-light rounded text-center"><div class="small text-muted">End Balance (computed)</div><div class="h6 mb-0 fw-bold">' . number_format($endComp, 2) . '</div></div></div>
    </div>';

    // Premium Excel-like table: SI, Date, User, Description, In, Out, Closing Stock
    // Rich Description from source tables, correct running balance accumulation
    $html .= '
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 excel-report" id="movementTable">
            <thead>
                <tr>
                    <th style="width:28px;">SI</th>
                    <th style="width:110px;">Date &amp; Time</th>
                    <th style="width:90px;">User</th>
                    <th>Description (Source Document Details)</th>
                    <th class="text-end" style="width:80px;">In Qty</th>
                    <th class="text-end" style="width:80px;">Out Qty</th>
                    <th class="text-end" style="width:95px;">Closing Stock</th>
                </tr>
            </thead>
            <tbody>';

    $si = 1;
    foreach ($data as $row) {
        $isOpening = !empty($row['is_opening']);
        // Note: is_closing rows are no longer added to main data to avoid repeated green rows in tx list.

        $qty = (float)($row['qty'] ?? 0);
        $abs = (float)($row['abs_qty'] ?? abs($qty));
        $run = (float)($row['running_balance'] ?? 0);
        $displayDate = $row['display_date'] ?? $row['transaction_date'] ?? '';

        if ($isOpening) {
            $in  = ($run > 0) ? number_format($run, 2) : '';
            $out = ($run < 0) ? number_format(abs($run), 2) : '';
            $desc = htmlspecialchars($row['description'] ?? 'Opening Balance');
            $rowClass = 'table-info fw-semibold';
        } else {
            $in  = ($qty > 0) ? number_format($abs, 2) : '';
            $out = ($qty < 0) ? number_format($abs, 2) : '';
            $desc = htmlspecialchars($row['description'] ?? ($row['document_code'] ?? ''));
            $rowClass = '';
        }

        $user = htmlspecialchars($row['created_by_name'] ?? 'System');

        $html .= '
            <tr class="' . $rowClass . '">
                <td>' . $si++ . '</td>
                <td><small>' . htmlspecialchars($displayDate) . '</small></td>
                <td><small>' . $user . '</small></td>
                <td style="white-space: normal; max-width: 520px;">' . $desc . '</td>
                <td class="text-end text-success fw-bold">' . $in . '</td>
                <td class="text-end text-danger fw-bold">' . $out . '</td>
                <td class="text-end fw-bold" style="font-variant-numeric: tabular-nums;">' . number_format($run, 2) . '</td>
            </tr>';
    }

    // Add ONE Final Closing Balance row at the VERY BOTTOM of the table (no repeats in list)
    // Compute from last running per warehouse in the data for accuracy
    $finalByWh = [];
    foreach ($data as $r) {
        if (!empty($r['is_opening'])) continue;
        $wid = $r['warehouse_id'] ?? 0;
        if ($wid) {
            $finalByWh[$wid] = [
                'name' => $r['warehouse_name'] ?? ('Wh#' . $wid),
                'bal' => (float)($r['running_balance'] ?? 0)
            ];
        }
    }
    $totalFinal = 0;
    foreach ($finalByWh as $f) $totalFinal += $f['bal'];

    if (!empty($finalByWh)) {
        $finalLabel = 'FINAL CLOSING BALANCE (as of To Date) - Total';
        if (count($finalByWh) === 1) {
            $only = reset($finalByWh);
            $finalLabel = 'FINAL CLOSING BALANCE for ' . $only['name'] . ' (as of To Date)';
            $totalFinal = $only['bal'];
        }
        $html .= '
            <tr class="table-success fw-bold" style="border-top: 3px double #166534; font-size:15px;">
                <td colspan="3"><strong>' . $finalLabel . '</strong></td>
                <td colspan="4" class="text-end" style="font-size:15px;">' . number_format($totalFinal, 2) . '</td>
            </tr>';
    }

    $html .= '
            </tbody>
        </table>
    </div>
    <div class="small text-muted mt-1"><strong></div>';

    // Button that submits the filter form again with explain=1 so the explanation (recon) is computed only on explicit user action
    $html .= '
    <div class="mt-3">
        <form method="post" id="explainForm" style="display:inline-block">
            <input type="hidden" name="search" value="1">
            <input type="hidden" name="explain" value="1">
            <button type="submit" class="btn btn-outline-primary btn-sm" form="explainForm">
                <i class="fas fa-balance-scale me-1"></i> Explain this report 
            </button>
        </form>
    </div>';

    // If recon data is present (because explain=1 was sent), show it now
    if (!empty($reconciliation)) {
        $html .= '<div class="mt-2 p-2 border rounded bg-light"><strong>Stock Validity Explanation</strong>';
        $html .= '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Warehouse</th><th class="text-end">Computed End</th><th class="text-end">Live Stock</th><th class="text-end">Diff</th><th></th></tr></thead><tbody>';
        $sc = 0; $sl = 0;
        foreach ($reconciliation as $r) {
            $sc += (float)($r['computed_ending'] ?? 0);
            $sl += (float)($r['current_stock'] ?? 0);
            $ok = !empty($r['matches']);
            $html .= '<tr class="' . ($ok ? '' : 'table-warning') . '"><td>' . htmlspecialchars($r['warehouse_name'] ?? '') . '</td><td class="text-end">' . number_format($r['computed_ending'] ?? 0, 2) . '</td><td class="text-end fw-bold">' . number_format($r['current_stock'] ?? 0, 2) . '</td><td class="text-end">' . number_format($r['diff'] ?? 0, 2) . '</td><td>' . ($ok ? '<span class="badge bg-success">MATCH</span>' : '<span class="badge bg-danger">MISMATCH</span>') . '</td></tr>';
        }
        $html .= '</tbody></table></div><div class="small">Computed ' . number_format($sc,2) . ' vs Live ' . number_format($sl,2) . '</div></div>';
    }

    return $html;
}

$content = '
<div class="product-movement-app container-fluid py-2">

    <!-- Hero Header (new locked layout) -->
    <header class="sales-return-hero" style="background: linear-gradient(90deg, #0d6efd, #6610f2); color: white; border-radius: 12px; padding: 0.85rem 1.1rem; margin-bottom: 0.75rem;">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h1 class="mb-1" style="font-size: 1.55rem;"><i class="fas fa-route me-2"></i>Product Movement Report</h1>
                <p class="mb-0 opacity-90 small">Source document movement ledger withLive stock reconciliation at bottom</p>
                <span class="badge bg-white text-dark mt-1" style="font-size:0.75rem;"><i class="fas fa-map-marker-alt me-1"></i>' . htmlspecialchars($_SESSION['branch_name'] ?? 'All Branches', ENT_QUOTES) . '</span>
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="offcanvas" data-bs-target="#movementFiltersOffcanvas" aria-controls="movementFiltersOffcanvas">
                    <i class="fas fa-filter me-1"></i> Filters
                </button>
                <a href="' . (defined('BASE_URL') ? BASE_URL : '') . 'Report" class="btn btn-outline-light btn-sm d-none d-sm-inline-flex">
                    <i class="fas fa-arrow-left me-1"></i> Reports
                </a>
            </div>
        </div>
    </header>

    <!-- Active Filters Chips -->
    <div class="sales-return-active-bar" id="activeFilterBar" style="margin-bottom: 0.65rem; padding: 0.5rem 0.75rem;">
        ' . renderMovementChips($from_date ?? '', $to_date ?? '', $branch_ids ?? [], $warehouse_ids ?? [], $category_ids ?? [], $product_name ?? '', count($product_ids ?? [])) . '
    </div>

    <!-- MAX SPACE Results Card -->
    <section class="sales-return-results-card" style="border-radius: 12px; border: 1px solid #e2e8f0; min-height: 420px;">
        <div class="sales-return-results-head" style="padding: 0.55rem 0.9rem; background:#f8fafc; border-bottom:1px solid #e2e8f0; font-size:0.95rem;">
      
            ' . ((($searched ?? false) || !empty($_GET['search'])) && (!empty($movement_data) || !empty($movement_reconciliation)) ? '
            <div>
                <button onclick="window.print()" class="btn btn-sm btn-light border py-0 px-2"><i class="fas fa-print"></i></button>
                <a href="?search=1&export=1' . htmlspecialchars($_SERVER['QUERY_STRING'] ?? '', ENT_QUOTES) . '" class="btn btn-sm btn-success py-0 px-2"><i class="fas fa-file-csv me-1"></i>CSV</a>
            </div>' : '') . '
        </div>

        <div class="p-2 p-md-3" id="movementResults">
            ' . renderMovementResults($movement_data ?? null, ($searched ?? $_GET['search'] ?? null), $product_name ?? '', $movement_reconciliation ?? []) . '
        </div>
    </section>

</div>

<!-- FILTERS OFFCANVAS (large, modern, fixed footer) -->
<div class="offcanvas offcanvas-end modern-offcanvas d-flex flex-column" tabindex="-1" id="movementFiltersOffcanvas" aria-labelledby="movementFiltersOffcanvasLabel" style="width: 520px; max-width: 90vw;">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="movementFiltersOffcanvasLabel"><i class="fas fa-sliders-h me-2"></i>Filters — Product Movement (source documents)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body flex-grow-1 overflow-auto">
        <form method="post" id="movementFilterForm">

            <!-- Date Presets -->
            <div class="mb-3">
                <div class="small fw-semibold text-muted mb-1">Date Range</div>
                <div class="d-flex flex-wrap gap-1 mb-2">
                    <button type="button" class="btn btn-xs btn-outline-secondary preset-btn" data-preset="7">Last 7d</button>
                    <button type="button" class="btn btn-xs btn-outline-secondary preset-btn" data-preset="30">Last 30d</button>
                    <button type="button" class="btn btn-xs btn-outline-secondary preset-btn" data-preset="mtd">MTD</button>
                    <button type="button" class="btn btn-xs btn-outline-secondary preset-btn" data-preset="ytd">YTD</button>
                    <button type="button" class="btn btn-xs btn-outline-secondary preset-btn" data-preset="all">All (2025+)</button>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small">From</label>
                        <input type="date" name="from_date" id="from_date" class="form-control modern-input" value="' . htmlspecialchars($from_date ?? '') . '">
                    </div>
                    <div class="col-6">
                        <label class="form-label small">To</label>
                        <input type="date" name="to_date" id="to_date" class="form-control modern-input" value="' . htmlspecialchars($to_date ?? '') . '">
                    </div>
                </div>
            </div>

            <!-- Categories (multi) -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-semibold mb-0">Categories (multi)</label>
                    <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 toggle-all" data-select-id="category_select" style="font-size:0.7rem;">Select All</button>
                </div>
                <select name="category_ids[]" id="category_select" class="form-select modern-select select2" multiple data-placeholder="Select categories (optional)...">
                    ';
foreach (($categories ?? []) as $c) {
    $sel = in_array($c['id'], $category_ids ?? []) ? 'selected' : '';
    $content .= '<option value="' . $c['id'] . '" ' . $sel . '>' . htmlspecialchars($c['category_name']) . '</option>';
}
$content .= '
                </select>
            </div>

            <!-- Multi Branches -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-semibold mb-0">Branches (multi)</label>
                    <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 toggle-all" data-select-id="branch_select">Select All</button>
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

            <!-- Warehouses (dynamic, multi) -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-semibold mb-0">Warehouses (multi)</label>
                    <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 toggle-all" data-select-id="warehouse_select">Select All</button>
                </div>
                <select name="warehouse_ids[]" id="warehouse_select" class="form-select modern-select select2" multiple data-placeholder="Select warehouses (auto by branches)...">
                    ';
foreach (($warehouses ?? []) as $w) {
    $sel = in_array($w['id'], $warehouse_ids ?? []) ? 'selected' : '';
    $content .= '<option value="' . $w['id'] . '" ' . $sel . '>' . htmlspecialchars($w['warehouse_name']) . '</option>';
}
$content .= '
                </select>
                <div class="form-text small">Warehouses update when you change branches.</div>
            </div>

            <!-- Products (multi supported - the heart of the report; or use category) -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-semibold mb-0">Products (multi — recommended)</label>
                    <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 toggle-all" data-select-id="product_select" style="font-size:0.7rem;">Select All</button>
                </div>
                <select name="product_ids[]" id="product_select" class="form-select modern-select select2" multiple data-placeholder="Search &amp; select one or more products...">
                    ';
foreach (($products ?? []) as $p) {
    $sel = in_array($p['id'], $product_ids ?? []) ? 'selected' : '';
    $content .= '<option value="' . $p['id'] . '" ' . $sel . '>' . htmlspecialchars($p['product_code'] . ' - ' . $p['product_name']) . '</option>';
}
$content .= '
                </select>
                <div class="form-text small text-muted">You can also filter by category above instead of (or with) specific products. The ledger will show movements for all selected, with per-product running balances.</div>
            </div>

        </form>
    </div>

    <!-- FIXED FOOTER -->
    <div class="offcanvas-footer bg-white border-top p-3 shadow-sm" style="flex-shrink:0; position:sticky; bottom:0; z-index:1050;">
        <div class="d-grid gap-2">
            <button type="submit" form="movementFilterForm" name="search" value="1" class="btn btn-primary btn-sm">
                <i class="fas fa-search me-1"></i> Search &amp; Show Report
            </button>
            <button type="submit" form="movementFilterForm" name="export" value="1" class="btn btn-outline-success btn-sm">
                <i class="fas fa-download me-1"></i> Export CSV
            </button>
            <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="offcanvas">Close sidebar</button>
        </div>
        <div class="text-center mt-2">
            <a href="' . (defined('BASE_URL') ? BASE_URL : '') . 'Report/ProductMovement" class="small text-muted">Reset everything</a>
        </div>
    </div>
</div>

<style>
/* Modern offcanvas styles (match ProductStockAnalysis) */
.modern-offcanvas .modern-input,
.modern-offcanvas .modern-select {
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    font-size: 0.875rem;
    padding: 0.5rem 0.75rem;
}
.modern-offcanvas .select2-container .select2-selection {
    border-radius: 10px !important;
    border-color: #e2e8f0 !important;
    min-height: 38px;
}
.offcanvas-footer {
    flex-shrink: 0;
    background: #fff;
    z-index: 10;
}

/* Premium Excel-like Stock Movement Report look (user used to see in Excel) */
#movementTable, .excel-report {
    border-collapse: collapse;
    font-family: Calibri, "Segoe UI", Arial, sans-serif;
    font-size: 15px;  /* Increased for comfortable reading, as per requirement (was too small) */
    line-height: 1.4;
    width: 100%;
    background: #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
}
#movementTable th, .excel-report th {
    background: linear-gradient(to bottom, #4472C4, #2F5496);
    color: #fff;
    font-weight: 600;
    border: 1px solid #1F4E79;
    padding: 8px 10px;
    text-align: left;
    position: sticky;
    top: 0;
    z-index: 3;
    font-size: 14px;
    white-space: nowrap;
}
#movementTable td, .excel-report td {
    border: 1px solid #BDD7EE;
    padding: 6px 8px;
    vertical-align: top;
    line-height: 1.35;
}
#movementTable tr:nth-child(even) td { background-color: #DEEBF7; }
#movementTable tr:hover td { background-color: #FFF2CC !important; }
#movementTable tr.table-info td { background-color: #BDD7EE !important; }
#movementTable tr.table-success td { background-color: #C6EFCE !important; }

.sales-return-hero, .sales-return-active-bar, .sales-return-results-card, .filter-tag {
    /* reuse existing styles from ProductStockAnalysis if present */
}
.filter-tag {
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
</style>

<script>
$(function() {
    // Select2 (multi for products + categories now)
    if (typeof $.fn.select2 !== "undefined") {
        $("#branch_select, #warehouse_select, #product_select, #category_select").select2({
            width: "100%",
            placeholder: "Select...",
            allowClear: true,
            dropdownParent: $("#movementFiltersOffcanvas")
        });
    }

    // Dynamic warehouses (reuse existing endpoint)
    $("#branch_select").on("change", function() {
        const bids = $(this).val() || [];
        const $wh = $("#warehouse_select");
        if (!bids.length) {
            // No branches selected: clear or keep initial server-rendered options (acceptable UX)
            $wh.val([]).trigger("change");
            return;
        }
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
        if (p === "ytd") f = fmt(new Date(d.getFullYear(), 0, 1));
        if (p === "all") { f = "2025-01-01"; t = fmt(d); }
        $("#from_date").val(f); $("#to_date").val(t);
    });

    // Toggle All / Deselect for branches, warehouses, products, categories
    $(document).on("click", ".toggle-all", function() {
        const selectId = $(this).data("select-id");
        const $select = $("#" + selectId);
        if (!$select.length) return;
        const $opts = $select.find("option");
        const allVals = $opts.map(function(){ return this.value; }).get();
        const cur = $select.val() || [];
        if (cur.length === allVals.length) {
            $select.val([]).trigger("change");
            $(this).text("Select All");
        } else {
            $select.val(allVals).trigger("change");
            $(this).text("Deselect All");
        }
    });

    // Update toggle text on manual change
    $("#branch_select, #warehouse_select, #product_select, #category_select").on("change", function() {
        const $btn = $(".toggle-all[data-select-id=\"" + this.id + "\"]");
        if (!$btn.length) return;
        const $opts = $(this).find("option");
        const sel = $(this).val() || [];
        $btn.text( sel.length === $opts.length ? "Deselect All" : "Select All" );
    });

    // Chips click -> open filters
    $("#activeFilterBar").on("click", ".filter-tag", function() {
        const off = new bootstrap.Offcanvas(document.getElementById("movementFiltersOffcanvas"));
        off.show();
    });

    // Init toggles
    function initToggles() {
        $(".toggle-all").each(function() {
            const sid = $(this).data("select-id");
            const $sel = $("#" + sid);
            if (!$sel.length) return;
            const sel = $sel.val() || [];
            const total = $sel.find("option").length;
            $(this).text( (sel.length > 0 && sel.length === total) ? "Deselect All" : "Select All" );
        });
    }
    $("#movementFiltersOffcanvas").on("shown.bs.offcanvas", initToggles);
    initToggles();

    // Make the explain button (in results) submit the main filter form + explain flag
    // so all current filter values are sent and explanation is shown on the resulting page.
    $(document).on("submit", "#explainForm", function(e){
      var mainForm = document.getElementById("movementFilterForm");
      if (mainForm) {
        $(mainForm).find("input, select").each(function(){
          var name = this.name;
          if (!name) return;
          if (this.type === "checkbox" || this.type === "radio") {
            if (!this.checked) return;
          }
          $("<input type=\"hidden\">").attr("name", name).val($(this).val()).appendTo("#explainForm");
        });
      }
    });
});
</script>

';

require_once '../app/views/layouts/main.php';
?>