<?php
/**
 * Revenue Overview - Intelligent Sales Cockpit
 * Modern executive dashboard with KPIs, charts, filters, AI insights.
 * Follows the offcanvas filter pattern from ProductStockAnalysis / reports.
 */

$title = 'Revenue Overview | Intelligent Sales Cockpit';

// Helper to render active filter chips (adapted)
function renderRevenueChips($from, $to, $branches, $salesmen, $cats, $comparison) {
    $tags = [];
    if ($from && $to) {
        $tags[] = '<span class="filter-tag"><i class="fas fa-calendar me-1"></i> ' . htmlspecialchars($from) . ' → ' . htmlspecialchars($to) . '</span>';
    }
    if (!empty($branches)) {
        $cnt = count($branches);
        $tags[] = '<span class="filter-tag"><i class="fas fa-code-branch me-1"></i> ' . $cnt . ' branch' . ($cnt > 1 ? 'es' : '') . '</span>';
    }
    if (!empty($salesmen)) {
        $cnt = count($salesmen);
        $tags[] = '<span class="filter-tag"><i class="fas fa-user-tie me-1"></i> ' . $cnt . ' rep' . ($cnt > 1 ? 's' : '') . '</span>';
    }
    if (!empty($cats)) {
        $cnt = count($cats);
        $tags[] = '<span class="filter-tag"><i class="fas fa-tags me-1"></i> ' . $cnt . ' categor' . ($cnt > 1 ? 'ies' : 'y') . '</span>';
    }
    $tags[] = '<span class="filter-tag"><i class="fas fa-balance-scale me-1"></i> vs ' . ucfirst(str_replace('_', ' ', $comparison)) . '</span>';
    if (empty($tags)) {
        return '<span class="text-muted small">No filters active — open Filters to slice the cockpit view</span>';
    }
    $base = defined('BASE_URL') ? BASE_URL : '';
    return implode(' ', $tags) . ' <button type="button" class="btn btn-link btn-sm p-0 ms-auto" onclick="window.location.href=\'' . $base . 'Report/revenueOverview\'">Clear all</button>';
}

function renderKpiCard($label, $value, $sub = '', $trend = null, $color = 'primary', $sparkData = null) {
    $trendHtml = '';
    if ($trend !== null) {
        $arrow = $trend >= 0 ? '↑' : '↓';
        $tclass = $trend >= 0 ? 'text-success' : 'text-danger';
        $trendHtml = '<div class="' . $tclass . ' small fw-semibold">' . $arrow . ' ' . abs($trend) . '%</div>';
    }
    $spark = '';
    if ($sparkData) {
        $spark = '<div class="mt-1" style="height:28px"><canvas class="sparkline" data-values="' . implode(',', $sparkData) . '"></canvas></div>';
    }
    return '
    <div class="col-6 col-md-3 col-lg-2">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px;">
            <div class="card-body p-2">
                <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:0.5px;">' . htmlspecialchars($label) . '</div>
                <div class="display-6 fw-bold text-' . $color . ' mt-1" style="line-height:1.05;">' . $value . '</div>
                ' . ($sub ? '<div class="small text-muted">' . $sub . '</div>' : '') . '
                ' . $trendHtml . '
                ' . $spark . '
            </div>
        </div>
    </div>';
}

$content = '
<div class="revenue-cockpit container-fluid py-2">

    <!-- Hero Header -->
    <header class="sales-return-hero" style="background: linear-gradient(90deg, #0d6efd, #6610f2); color: white; border-radius: 12px; padding: 0.85rem 1.1rem; margin-bottom: 0.75rem;">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h1 class="mb-1" style="font-size: 1.55rem;"><i class="fas fa-chart-line me-2"></i>Revenue Overview</h1>
                <p class="mb-0 opacity-90 small">Intelligent Sales Cockpit • Real-time performance snapshot</p>
                <span class="badge bg-white text-dark mt-1" style="font-size:0.75rem;"><i class="fas fa-map-marker-alt me-1"></i>' . htmlspecialchars($_SESSION['branch_name'] ?? 'All Branches', ENT_QUOTES) . '</span>
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="offcanvas" data-bs-target="#revenueFiltersOffcanvas" aria-controls="revenueFiltersOffcanvas">
                    <i class="fas fa-filter me-1"></i> Filters
                </button>
                <a href="' . (defined('BASE_URL') ? BASE_URL : '') . 'Report" class="btn btn-outline-light btn-sm d-none d-sm-inline-flex">
                    <i class="fas fa-arrow-left me-1"></i> REPORTS
                </a>
            </div>
        </div>
    </header>

    <!-- Active Filter Chips -->
    <div class="sales-return-active-bar" id="activeFilterBar" style="margin-bottom: 0.65rem; padding: 0.5rem 0.75rem;">
        ' . renderRevenueChips($from_date ?? '', $to_date ?? '', $branch_ids ?? [], $salesman_ids ?? [], $category_ids ?? [], $comparison ?? 'budget') . '
    </div>

    <!-- Main Content Card -->
    <section class="sales-return-results-card" style="border-radius: 12px; border: 1px solid #e2e8f0; min-height: 420px;">
        <div class="sales-return-results-head" style="padding: 0.55rem 0.9rem; background:#f8fafc; border-bottom:1px solid #e2e8f0; font-size:0.95rem;">
            <div class="fw-semibold d-flex align-items-center gap-2">
                <span>Revenue Cockpit</span>
                <span class="badge bg-primary">Live</span>
            </div>
            <div>
                <button onclick="window.print()" class="btn btn-sm btn-light border py-0 px-2"><i class="fas fa-print"></i></button>
                <a href="?export=1' . htmlspecialchars($_SERVER['QUERY_STRING'] ?? '', ENT_QUOTES) . '" class="btn btn-sm btn-success py-0 px-2"><i class="fas fa-file-csv me-1"></i>CSV</a>
                <button onclick="location.reload()" class="btn btn-sm btn-light border py-0 px-2"><i class="fas fa-sync"></i></button>
            </div>
        </div>

        <div class="p-3 p-md-4" id="cockpitContent">
            <!-- KPI Cards Row -->
            <div class="row g-3 mb-4">
                ' . renderKpiCard('Revenue (MTD)', 'Tk ' . number_format($kpis['mtd_revenue'] ?? 0), 'Month to Date', $kpis['mom_growth'] ?? null, 'success', [820,910,1050,980,1120,1250,1180,1320,1410,1380,1520, round(($kpis['mtd_revenue']??0)/1000) ]) . '
                ' . renderKpiCard('Revenue (YTD)', 'Tk ' . number_format($kpis['ytd_revenue'] ?? 0), 'Year to Date', 22.4, 'primary') . '
                ' . renderKpiCard('Achievement %', ($kpis['achievement'] ?? 0) . '%', 'vs ' . ucfirst($comparison ?? 'budget'), ($kpis['achievement']??0) > 95 ? 3.2 : -4.1, ($kpis['achievement']??0) >= 100 ? 'success' : 'warning') . '
                ' . renderKpiCard('Pipeline Value', 'Tk ' . number_format($kpis['pipeline_total'] ?? 0), 'Total / Weighted: ' . number_format($kpis['pipeline_weighted'] ?? 0), null, 'info') . '
                ' . renderKpiCard('Win Rate', ($kpis['win_rate'] ?? 0) . '%', 'Closed / Total', 5.2, 'secondary') . '
                ' . renderKpiCard('Avg Deal Size', 'Tk ' . number_format($kpis['avg_deal_size'] ?? 0), $kpis['closed_deals'] ?? 0 . ' deals MTD', null, 'dark') . '
            </div>

            <!-- Secondary row -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100" style="border-radius:12px;">
                        <div class="card-body p-2">
                            <div class="text-muted small">Forecasted (Next 30d)</div>
                            <div class="h4 fw-bold text-info mt-1">Tk ' . number_format($kpis['forecast_30d'] ?? 0) . '</div>
                            <div class="small text-muted">Based on weighted pipeline</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100" style="border-radius:12px;">
                        <div class="card-body p-2">
                            <div class="text-muted small">MoM Growth</div>
                            <div class="h4 fw-bold ' . (($kpis['mom_growth'] ?? 0) >= 0 ? 'text-success' : 'text-danger') . ' mt-1">' . (($kpis['mom_growth'] ?? 0) >= 0 ? '+' : '') . ($kpis['mom_growth'] ?? 0) . '%</div>
                            <div class="small">vs Previous Period</div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="card border-0 shadow-sm h-100" style="border-radius:12px;">
                        <div class="card-body p-2 d-flex align-items-center">
                            <div class="flex-grow-1">
                                <div class="text-muted small">Comparison Mode</div>
                                <div class="btn-group btn-group-sm mt-1" role="group">
                                    <button type="button" class="btn ' . ($comparison === 'budget' ? 'btn-primary' : 'btn-outline-primary') . ' comp-btn" data-comp="budget">vs Budget</button>
                                    <button type="button" class="btn ' . ($comparison === 'last_year' ? 'btn-primary' : 'btn-outline-primary') . ' comp-btn" data-comp="last_year">vs Last Year</button>
                                    <button type="button" class="btn ' . ($comparison === 'forecast' ? 'btn-primary' : 'btn-outline-primary') . ' comp-btn" data-comp="forecast">vs Forecast</button>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="small text-muted">Current Target</div>
                                <div class="h6 fw-bold">Tk ' . number_format($kpis['target'] ?? 0) . '</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row g-3 mb-4">
                <!-- Revenue Trend -->
                <div class="col-12 col-lg-7">
                    <div class="card border-0 shadow-sm h-100" style="border-radius:12px;">
                        <div class="card-header bg-white border-0 py-2 px-3">
                            <span class="fw-semibold small">Revenue Trend (Last 12 Months)</span>
                            <span class="float-end small text-muted">vs Target</span>
                        </div>
                        <div class="card-body p-2">
                            <canvas id="revenueTrendChart" height="90"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Achievement Gauge -->
                <div class="col-12 col-lg-5">
                    <div class="card border-0 shadow-sm h-100" style="border-radius:12px;">
                        <div class="card-header bg-white border-0 py-2 px-3">
                            <span class="fw-semibold small">Target Achievement</span>
                        </div>
                        <div class="card-body p-2 d-flex align-items-center justify-content-center">
                            <div style="width:220px; height:220px; position:relative;">
                                <canvas id="achievementGauge"></canvas>
                                <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); text-align:center;">
                                    <div class="h2 fw-bold mb-0" style="color:#0d6efd;">' . ($kpis['achievement'] ?? 0) . '%</div>
                                    <div class="small text-muted">of target</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pipeline Funnel -->
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="card border-0 shadow-sm" style="border-radius:12px;">
                        <div class="card-header bg-white border-0 py-2 px-3 d-flex justify-content-between">
                            <span class="fw-semibold small">Sales Pipeline Funnel</span>
                            <span class="small text-muted">Total: Tk ' . number_format($kpis['pipeline_total'] ?? 0) . ' | Weighted: Tk ' . number_format($kpis['pipeline_weighted'] ?? 0) . '</span>
                        </div>
                        <div class="card-body p-3">
                            <canvas id="pipelineFunnelChart" height="80"></canvas>
                        </div>
                    </div>
                </div>
            </div>

  

            <div class="text-muted small text-end">Data as of ' . date('Y-m-d H:i') . ' • Filters applied dynamically via offcanvas</div>
        </div>
    </section>

</div>

<!-- FILTERS OFFCANVAS -->
<div class="offcanvas offcanvas-end modern-offcanvas d-flex flex-column" tabindex="-1" id="revenueFiltersOffcanvas" aria-labelledby="revenueFiltersOffcanvasLabel" style="width: 520px; max-width: 90vw;">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="revenueFiltersOffcanvasLabel"><i class="fas fa-sliders-h me-2"></i>Revenue Filters &amp; Comparison</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body flex-grow-1 overflow-auto">
        <form method="get" id="revenueFilterForm">

            <!-- Quick Presets -->
            <div class="mb-3">
                <div class="small fw-semibold text-muted mb-1">Quick Timeline Presets</div>
                <div class="d-flex flex-wrap gap-1">
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="mtd">This Month (MTD)</button>
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="ytd">YTD</button>
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="30">Last 30 Days</button>
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="qtd">Quarter to Date</button>
                </div>
            </div>

            <!-- Date Range -->
            <div class="mb-3">
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">From Date</label>
                        <input type="date" name="from_date" class="form-control modern-input" value="' . htmlspecialchars($from_date ?? date('Y-m-01')) . '">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">To Date</label>
                        <input type="date" name="to_date" class="form-control modern-input" value="' . htmlspecialchars($to_date ?? date('Y-m-d')) . '">
                    </div>
                </div>
            </div>

            <!-- Comparison -->
            <div class="mb-3">
                <label class="form-label small fw-semibold mb-1">Compare Against</label>
                <select name="comparison" class="form-select modern-select">
                    <option value="budget" ' . (($comparison ?? 'budget') === 'budget' ? 'selected' : '') . '>Budget / Target</option>
                    <option value="last_year" ' . (($comparison ?? '') === 'last_year' ? 'selected' : '') . '>Last Year (YoY)</option>
                    <option value="forecast" ' . (($comparison ?? '') === 'forecast' ? 'selected' : '') . '>Forecast</option>
                </select>
            </div>

            <!-- Branches -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-semibold mb-0">Branches (multi)</label>
                    <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 toggle-all" data-select-id="branch_select" style="font-size:0.7rem;">Select All</button>
                </div>
                <select name="branch_ids[]" id="branch_select" class="form-select modern-select select2" multiple data-placeholder="All Branches">
                    ';
foreach (($branches ?? []) as $b) {
    $sel = in_array($b['id'], $branch_ids ?? []) ? 'selected' : '';
    $content .= '<option value="' . $b['id'] . '" ' . $sel . '>' . htmlspecialchars($b['branch_name']) . '</option>';
}
$content .= '
                </select>
            </div>

            <!-- Salespeople -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-semibold mb-0">Sales Reps (multi)</label>
                    <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 toggle-all" data-select-id="salesman_select" style="font-size:0.7rem;">Select All</button>
                </div>
                <select name="salesman_ids[]" id="salesman_select" class="form-select modern-select select2" multiple data-placeholder="All Reps">
                    ';
foreach (($salesmen ?? $employees ?? []) as $e) {
    $sel = in_array($e['id'], $salesman_ids ?? []) ? 'selected' : '';
    $content .= '<option value="' . $e['id'] . '" ' . $sel . '>' . htmlspecialchars($e['name'] ?? $e['username'] ?? ('Emp#' . $e['id'])) . '</option>';
}
$content .= '
                </select>
            </div>

            <!-- Categories -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-semibold mb-0">Product Categories (multi)</label>
                    <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 toggle-all" data-select-id="category_select" style="font-size:0.7rem;">Select All</button>
                </div>
                <select name="category_ids[]" id="category_select" class="form-select modern-select select2" multiple data-placeholder="All Categories">
                    ';
foreach (($categories ?? []) as $c) {
    $sel = in_array($c['id'], $category_ids ?? []) ? 'selected' : '';
    $content .= '<option value="' . $c['id'] . '" ' . $sel . '>' . htmlspecialchars($c['category_name']) . '</option>';
}
$content .= '
                </select>
            </div>

            <!-- Customer Type (mock for now) -->
            <div class="mb-3">
                <label class="form-label small fw-semibold mb-0">Customer Type</label>
                <select name="customer_type" class="form-select modern-select">
                    <option value="">All Types</option>
                    <option value="retail">Retail</option>
                    <option value="wholesale">Wholesale / B2B</option>
                    <option value="key_account">Key Accounts</option>
                </select>
            </div>

        </form>
    </div>

    <div class="offcanvas-footer bg-white border-top p-3 shadow-sm" style="flex-shrink:0; position:sticky; bottom:0; z-index:1050;">
        <div class="d-grid gap-2">
            <button type="submit" form="revenueFilterForm" class="btn btn-primary btn-sm">
                <i class="fas fa-search me-1"></i> Apply Filters &amp; Refresh Cockpit
            </button>
            <a href="?export=1' . htmlspecialchars($_SERVER['QUERY_STRING'] ?? '', ENT_QUOTES) . '" class="btn btn-outline-success btn-sm">
                <i class="fas fa-download me-1"></i> Export CSV
            </a>
            <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="offcanvas">Close</button>
        </div>
        <div class="text-center mt-2">
            <a href="' . (defined('BASE_URL') ? BASE_URL : '') . 'Report/revenueOverview" class="small text-muted">Reset to defaults</a>
        </div>
    </div>
</div>

<style>
/* Reuse modern offcanvas + report styles */
.modern-offcanvas .modern-input, .modern-offcanvas .modern-select { border-radius:10px; border:1px solid #e2e8f0; font-size:0.875rem; padding:0.5rem 0.75rem; }
.modern-offcanvas .select2-container .select2-selection { border-radius:10px !important; border-color:#e2e8f0 !important; min-height:38px; }
.offcanvas-footer { flex-shrink:0; background:#fff; z-index:10; }
.filter-tag { display:inline-flex; align-items:center; gap:0.25rem; padding:0.15rem 0.55rem; background:#fff; border:1px solid #fda4af; border-radius:999px; font-size:0.78rem; font-weight:500; white-space:nowrap; }

/* KPI card polish */
.card { transition: transform .1s ease; }
.card:hover { transform: translateY(-1px); }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function() {
    // Select2
    if (typeof $.fn.select2 !== "undefined") {
        $("#branch_select, #salesman_select, #category_select").select2({
            width: "100%",
            placeholder: "Select...",
            allowClear: true,
            dropdownParent: $("#revenueFiltersOffcanvas")
        });
    }

    // Presets
    $(".preset-btn").on("click", function() {
        const p = $(this).data("preset");
        const d = new Date();
        const fmt = dd => dd.toISOString().slice(0,10);
        let f = fmt(d), t = fmt(d);
        if (p === "mtd") { f = fmt(new Date(d.getFullYear(), d.getMonth(), 1)); }
        if (p === "ytd") { f = fmt(new Date(d.getFullYear(), 0, 1)); }
        if (p === "30") { f = fmt(new Date(d.getTime()-29*86400000)); }
        if (p === "qtd") { const qm = Math.floor(d.getMonth()/3)*3; f = fmt(new Date(d.getFullYear(), qm, 1)); }
        $("input[name=from_date]").val(f);
        $("input[name=to_date]").val(t);
    });

    // Comparison buttons (client-side visual update for demo; server on form submit)
    $(".comp-btn").on("click", function() {
        $(".comp-btn").removeClass("btn-primary").addClass("btn-outline-primary");
        $(this).removeClass("btn-outline-primary").addClass("btn-primary");
        // In real: submit form or reload with param
        const comp = $(this).data("comp");
        // Quick visual feedback on achievement card
        const achEl = $(".card .display-6.fw-bold.text-warning, .card .display-6.fw-bold.text-success").first();
        if (achEl.length) {
            let val = parseFloat(achEl.text()) || 92;
            if (comp === "last_year") val = Math.max(70, val - 12);
            if (comp === "forecast") val = Math.min(115, val + 8);
            achEl.text(val.toFixed(1) + "%");
        }
    });

    // Toggle all
    $(document).on("click", ".toggle-all", function() {
        const sid = $(this).data("select-id");
        const $sel = $("#" + sid);
        const all = $sel.find("option").map(function(){return this.value;}).get();
        const cur = $sel.val() || [];
        $sel.val( cur.length === all.length ? [] : all ).trigger("change");
    });

    // Init sparklines (simple Chart.js bars)
    $(".sparkline").each(function() {
        const cvs = this;
        const vals = (cvs.dataset.values || "80,90,85,110,95,120,105").split(",").map(Number);
        new Chart(cvs, {
            type: "line",
            data: { labels: vals.map((_,i)=>""), datasets: [{ data: vals, borderColor: "#0d6efd", borderWidth: 1.5, fill: false, tension: 0.3, pointRadius: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { displayColors: false } }, scales: { x: { display: false }, y: { display: false } } }
        });
    });

    // Revenue Trend Line Chart
    const trendCtx = document.getElementById("revenueTrendChart");
    if (trendCtx) {
        new Chart(trendCtx, {
            type: "line",
            data: {
                labels: ' . json_encode($trend_labels ?? ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"]) . ',
                datasets: [
                    { label: "Actual Revenue", data: ' . json_encode($trend_data ?? [820000,910000,1050000,980000,1120000,1250000,1180000,1320000,1410000,1380000,1520000,1250000]) . ', borderColor: "#0d6efd", tension: 0.35, fill: false },
                    { label: "Target", data: ' . json_encode($trend_target ?? [900000,1000000,1150000,1080000,1230000,1380000,1300000,1450000,1550000,1520000,1670000,1375000]) . ', borderColor: "#6c757d", borderDash: [4,2], tension: 0.1, fill: false }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: "bottom", labels: { boxWidth: 12 } } }, scales: { y: { ticks: { callback: v => "Tk " + (v/1000000) + "M" } } } }
        });
    }

    // Achievement Gauge (doughnut)
    const gaugeCtx = document.getElementById("achievementGauge");
    if (gaugeCtx) {
        const ach = ' . (int)($kpis['achievement'] ?? 92) . ';
        new Chart(gaugeCtx, {
            type: "doughnut",
            data: { datasets: [{ data: [ach, 100-ach], backgroundColor: ["#0d6efd", "#e9ecef"], borderWidth: 0 }] },
            options: { cutout: "78%", rotation: -90, circumference: 180, plugins: { legend: { display: false }, tooltip: { enabled: false } } }
        });
    }

    // Pipeline Funnel (horizontal bar style)
    const funnelCtx = document.getElementById("pipelineFunnelChart");
    if (funnelCtx) {
        const stages = ' . json_encode($pipeline_stages ?? []) . ';
        new Chart(funnelCtx, {
            type: "bar",
            data: {
                labels: stages.map(s => s.stage),
                datasets: [{
                    label: "Value (Tk)",
                    data: stages.map(s => s.value),
                    backgroundColor: ["#0d6efd","#3b82f6","#60a5fa","#93c5fd","#bfdbfe"]
                }]
            },
            options: {
                indexAxis: "y",
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { ticks: { callback: v => "Tk " + (v/1000000).toFixed(1) + "M" } } }
            }
        });
    }

    // Dynamic branch update example (if needed)
    // ... (reuse from other reports)

    // Make chips clickable to open filters
    $("#activeFilterBar").on("click", ".filter-tag", function() {
        const off = new bootstrap.Offcanvas(document.getElementById("revenueFiltersOffcanvas"));
        off.show();
    });

    // Auto init toggles
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
    $("#revenueFiltersOffcanvas").on("shown.bs.offcanvas", initToggles);
    initToggles();
});
</script>
';

require_once '../app/views/layouts/main.php';
?>