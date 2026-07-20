<?php
/**
 * Sales Funnel & Pipeline - Intelligent Sales Cockpit
 * Complete deal flow visibility with funnel, metrics, table, trends.
 * Follows exact UI pattern of RevenueOverview and other modern reports.
 */

$title = 'Sales Funnel & Pipeline | Intelligent Sales Cockpit';

// Chips renderer
function renderFunnelChips($from, $to, $branches, $salesmen, $cats, $minProb, $minSize, $maxSize) {
    $tags = [];
    if ($from && $to) $tags[] = '<span class="filter-tag"><i class="fas fa-calendar me-1"></i> ' . htmlspecialchars($from) . ' → ' . htmlspecialchars($to) . '</span>';
    if (!empty($branches)) $tags[] = '<span class="filter-tag"><i class="fas fa-code-branch me-1"></i> ' . count($branches) . ' branch(es)</span>';
    if (!empty($salesmen)) $tags[] = '<span class="filter-tag"><i class="fas fa-user-tie me-1"></i> ' . count($salesmen) . ' rep(s)</span>';
    if (!empty($cats)) $tags[] = '<span class="filter-tag"><i class="fas fa-tags me-1"></i> ' . count($cats) . ' cat(s)</span>';
    if ($minProb > 0) $tags[] = '<span class="filter-tag"><i class="fas fa-percent me-1"></i> Prob ≥ ' . $minProb . '%</span>';
    if ($minSize > 0 || $maxSize > 0) $tags[] = '<span class="filter-tag"><i class="fas fa-dollar-sign me-1"></i> Deal size filter</span>';
    if (empty($tags)) return '<span class="text-muted small">No filters — open Filters for full control</span>';
    $base = defined('BASE_URL') ? BASE_URL : '';
    return implode(' ', $tags) . ' <button type="button" class="btn btn-link btn-sm p-0 ms-auto" onclick="window.location.href=\'' . $base . 'Report/salesFunnelPipeline\'">Clear</button>';
}

$content = '
<div class="sales-funnel-cockpit container-fluid py-2">

    <!-- Hero -->
    <header class="sales-return-hero" style="background: linear-gradient(90deg, #0d6efd, #6610f2); color: white; border-radius: 12px; padding: 0.85rem 1.1rem; margin-bottom: 0.75rem;">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h1 class="mb-1" style="font-size: 1.55rem;"><i class="fas fa-filter me-2"></i>Sales Funnel &amp; Pipeline</h1>
                <p class="mb-0 opacity-90 small">Deal flow visibility • Conversion rates • Velocity • Expected revenue</p>
                <span class="badge bg-white text-dark mt-1" style="font-size:0.75rem;"><i class="fas fa-map-marker-alt me-1"></i>' . htmlspecialchars($_SESSION['branch_name'] ?? 'All Branches', ENT_QUOTES) . '</span>
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="offcanvas" data-bs-target="#funnelFiltersOffcanvas" aria-controls="funnelFiltersOffcanvas">
                    <i class="fas fa-filter me-1"></i> Filters
                </button>
                <a href="' . (defined('BASE_URL') ? BASE_URL : '') . 'reports" class="btn btn-outline-light btn-sm d-none d-sm-inline-flex">
                    <i class="fas fa-arrow-left me-1"></i> REPORTS
                </a>
            </div>
        </div>
    </header>

    <!-- Active Chips -->
    <div class="sales-return-active-bar" id="activeFilterBar" style="margin-bottom: 0.65rem; padding: 0.5rem 0.75rem;">
        ' . renderFunnelChips($from_date ?? '', $to_date ?? '', $branch_ids ?? [], $salesman_ids ?? [], $category_ids ?? [], $min_prob ?? 0, $min_deal_size ?? 0, $max_deal_size ?? 0) . '
    </div>

    <!-- Main Card -->
    <section class="sales-return-results-card" style="border-radius: 12px; border: 1px solid #e2e8f0;">
        <div class="sales-return-results-head" style="padding: 0.55rem 0.9rem; background:#f8fafc; border-bottom:1px solid #e2e8f0; font-size:0.95rem;">
            <div class="fw-semibold d-flex align-items-center gap-2">
                <span>Pipeline Overview</span>
                <span class="badge bg-primary">' . count($opportunities ?? []) . ' open</span>
            </div>
            <div>
                <button onclick="window.print()" class="btn btn-sm btn-light border py-0 px-2"><i class="fas fa-print"></i></button>
                <a href="?export=1' . htmlspecialchars($_SERVER['QUERY_STRING'] ?? '', ENT_QUOTES) . '" class="btn btn-sm btn-success py-0 px-2"><i class="fas fa-file-csv me-1"></i>CSV</a>
            </div>
        </div>

        <div class="p-3 p-md-4">
            <!-- KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body p-2">
                        <div class="text-muted small fw-semibold">Total Pipeline Value</div>
                        <div class="display-6 fw-bold text-primary mt-1">Tk ' . number_format($kpis['total_pipeline'] ?? 0) . '</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body p-2">
                        <div class="text-muted small fw-semibold">Weighted Expected Revenue</div>
                        <div class="display-6 fw-bold text-success mt-1">Tk ' . number_format($kpis['weighted_revenue'] ?? 0) . '</div>
                        <div class="small text-muted">Probability weighted</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body p-2">
                        <div class="text-muted small fw-semibold">Win Rate</div>
                        <div class="display-6 fw-bold text-info mt-1">' . ($kpis['win_rate'] ?? 0) . '%</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body p-2">
                        <div class="text-muted small fw-semibold">Pipeline Velocity</div>
                        <div class="display-6 fw-bold text-warning mt-1">' . ($kpis['velocity_days'] ?? 28) . ' days</div>
                        <div class="small text-muted">Avg days to close</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body p-2">
                        <div class="text-muted small fw-semibold">Avg Deal Size</div>
                        <div class="display-6 fw-bold mt-1">Tk ' . number_format($kpis['avg_deal_size'] ?? 0) . '</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body p-2">
                        <div class="text-muted small fw-semibold">Expected (30/60/90d)</div>
                        <div class="small"><strong>30d:</strong> Tk ' . number_format($kpis['expected_30'] ?? 0) . '<br><strong>90d:</strong> Tk ' . number_format($kpis['expected_90'] ?? 0) . '</div>
                    </div></div>
                </div>
            </div>

            <!-- Funnel Chart + Conversion -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-lg-7">
                    <div class="card border-0 shadow-sm h-100"><div class="card-header bg-white py-2 px-3 fw-semibold small">Sales Funnel (Value &amp; Count by Stage)</div>
                        <div class="card-body p-2">
                            <canvas id="funnelChart" height="140"></canvas>
                            <div class="small text-muted mt-2">Click bars in legend or use table below for drill-down. Color = health.</div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-5">
                    <div class="card border-0 shadow-sm h-100"><div class="card-header bg-white py-2 px-3 fw-semibold small">Stage Conversion Rates</div>
                        <div class="card-body p-2">
                            <ul class="list-unstyled small mb-0">';
            foreach (($conversion_rates ?? []) as $cr) {
                $content .= '<li class="mb-1"><strong>' . htmlspecialchars($cr['from']) . '</strong> → ' . htmlspecialchars($cr['to']) . ': <span class="fw-bold">' . $cr['rate'] . '%</span></li>';
            }
            $content .= '</ul>
                            <div class="mt-2 small text-muted">Arrows indicate drop-off. Healthy funnel has gradual conversion.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Trend Charts -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-lg-6">
                    <div class="card border-0 shadow-sm"><div class="card-header bg-white py-2 px-3 fw-semibold small">Pipeline Value Trend (Period)</div>
                        <div class="card-body p-2"><canvas id="pipelineTrendChart" height="90"></canvas></div>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="card border-0 shadow-sm"><div class="card-header bg-white py-2 px-3 fw-semibold small">Velocity Trend (Days to Close)</div>
                        <div class="card-body p-2"><canvas id="velocityTrendChart" height="90"></canvas></div>
                    </div>
                </div>
            </div>

            <!-- Open Opportunities Table (drillable) -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-2 px-3 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold small">Open Opportunities (Click row or stage to filter)</span>
                    <span class="small text-muted">' . count($opportunities ?? []) . ' deals</span>
                </div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm table-hover mb-0" id="opportunitiesTable">
                        <thead class="table-light"><tr>
                            <th>Deal</th><th>Customer</th><th>Stage</th><th class="text-end">Value</th><th>Prob %</th><th class="text-end">Weighted</th><th>Days Open</th><th>Owner</th><th>Health</th>
                        </tr></thead>
                        <tbody>';
            foreach (($opportunities ?? []) as $opp) {
                $healthClass = $opp['health'] === 'red' ? 'bg-danger text-white' : ($opp['health'] === 'yellow' ? 'bg-warning' : 'bg-success text-white');
                $content .= '<tr data-stage="' . htmlspecialchars($opp['stage']) . '" style="cursor:pointer" onclick="filterTableByStage(\'' . htmlspecialchars($opp['stage']) . '\')">
                    <td><strong>' . htmlspecialchars($opp['code']) . '</strong><br><small class="text-muted">' . htmlspecialchars($opp['date']) . '</small></td>
                    <td>' . htmlspecialchars($opp['customer']) . '</td>
                    <td><span class="badge" style="background:' . ($stage_defs[$opp['stage']]['color'] ?? '#6c757d') . '">' . htmlspecialchars($opp['stage_name']) . '</span></td>
                    <td class="text-end">Tk ' . number_format($opp['value'], 2) . '</td>
                    <td>' . number_format($opp['prob'], 0) . '%</td>
                    <td class="text-end fw-bold">Tk ' . number_format($opp['weighted'], 2) . '</td>
                    <td>' . $opp['days_open'] . '</td>
                    <td><small>' . htmlspecialchars($opp['salesman']) . '</small></td>
                    <td><span class="badge ' . $healthClass . '">' . strtoupper($opp['health']) . '</span></td>
                </tr>';
            }
            $content .= '</tbody></table>
                </div>
                <div class="card-footer small text-muted p-2">Click any row to highlight same-stage deals. Use Filters for probability & size. Colors: Green=Healthy, Yellow=At risk, Red=Stalled.</div>
            </div>

            <!-- Summary + Export note -->
            <div class="small text-end text-muted">Data filtered to selected period & criteria • Click funnel bars (future) or table rows for drill-down • Probability is stage-based (adjustable in full CRM module)</div>
        </div>
    </section>

</div>

<!-- FILTERS OFFCANVAS -->
<div class="offcanvas offcanvas-end modern-offcanvas d-flex flex-column" tabindex="-1" id="funnelFiltersOffcanvas" style="width: 520px; max-width: 90vw;">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title"><i class="fas fa-sliders-h me-2"></i>Sales Funnel Filters</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
        <form method="get" id="funnelFilterForm">
            <div class="mb-3">
                <div class="small fw-semibold text-muted mb-1">Quick Presets</div>
                <div class="d-flex flex-wrap gap-1">
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="30">Last 30d</button>
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="90">Last 90d</button>
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="mtd">MTD</button>
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="ytd">YTD</button>
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label small">From</label><input type="date" name="from_date" class="form-control modern-input" value="' . htmlspecialchars($from_date ?? date('Y-m-d', strtotime('-90 days'))) . '"></div>
                <div class="col-6"><label class="form-label small">To</label><input type="date" name="to_date" class="form-control modern-input" value="' . htmlspecialchars($to_date ?? date('Y-m-d')) . '"></div>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-semibold">Deal Size Range</label>
                <div class="row g-2">
                    <div class="col-6"><input type="number" name="min_deal_size" class="form-control" placeholder="Min" value="' . ($min_deal_size ?? '') . '"></div>
                    <div class="col-6"><input type="number" name="max_deal_size" class="form-control" placeholder="Max" value="' . ($max_deal_size ?? '') . '"></div>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label small fw-semibold">Min Probability %</label>
                <input type="range" name="min_prob" min="0" max="100" step="5" value="' . ($min_prob ?? 0) . '" class="form-range" oninput="this.nextElementSibling.value = this.value + \'%\'">
                <output>' . ($min_prob ?? 0) . '%</output>
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between"><label class="form-label small fw-semibold">Branches</label><button type="button" class="btn btn-xs btn-outline-secondary toggle-all" data-select-id="branch_select">All</button></div>
                <select name="branch_ids[]" id="branch_select" class="form-select modern-select select2" multiple>';
            foreach (($branches ?? []) as $b) {
                $sel = in_array($b['id'], $branch_ids ?? []) ? 'selected' : '';
                $content .= '<option value="' . $b['id'] . '" ' . $sel . '>' . htmlspecialchars($b['branch_name']) . '</option>';
            }
            $content .= '</select>
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between"><label class="form-label small fw-semibold">Sales Reps</label><button type="button" class="btn btn-xs btn-outline-secondary toggle-all" data-select-id="salesman_select">All</button></div>
                <select name="salesman_ids[]" id="salesman_select" class="form-select modern-select select2" multiple>';
            foreach (($salesmen ?? []) as $e) {
                $sel = in_array($e['id'], $salesman_ids ?? []) ? 'selected' : '';
                $content .= '<option value="' . $e['id'] . '" ' . $sel . '>' . htmlspecialchars($e['name'] ?? $e['username'] ?? 'Emp ' . $e['id']) . '</option>';
            }
            $content .= '</select>
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between"><label class="form-label small fw-semibold">Categories</label><button type="button" class="btn btn-xs btn-outline-secondary toggle-all" data-select-id="category_select">All</button></div>
                <select name="category_ids[]" id="category_select" class="form-select modern-select select2" multiple>';
            foreach (($categories ?? []) as $c) {
                $sel = in_array($c['id'], $category_ids ?? []) ? 'selected' : '';
                $content .= '<option value="' . $c['id'] . '" ' . $sel . '>' . htmlspecialchars($c['category_name']) . '</option>';
            }
            $content .= '</select>
            </div>
        </form>
    </div>
    <div class="offcanvas-footer bg-white border-top p-3">
        <div class="d-grid gap-2">
            <button type="submit" form="funnelFilterForm" class="btn btn-primary btn-sm">Apply Filters</button>
            <a href="?export=1' . htmlspecialchars($_SERVER['QUERY_STRING'] ?? '', ENT_QUOTES) . '" class="btn btn-outline-success btn-sm">Export CSV</a>
            <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="offcanvas">Close</button>
        </div>
    </div>
</div>

<style>
.modern-offcanvas .modern-input, .modern-offcanvas .modern-select { border-radius:10px; border:1px solid #e2e8f0; font-size:.875rem; }
.filter-tag { display:inline-flex; align-items:center; gap:.25rem; padding:.15rem .55rem; background:#fff; border:1px solid #fda4af; border-radius:999px; font-size:.78rem; font-weight:500; white-space:nowrap; }
#opportunitiesTable tbody tr:hover { background:#fff3cd; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function(){
    if (typeof $.fn.select2 !== "undefined") {
        $("#branch_select,#salesman_select,#category_select").select2({width:"100%", placeholder:"Select...", allowClear:true, dropdownParent: $("#funnelFiltersOffcanvas")});
    }

    $(".preset-btn").on("click", function(){
        const p = $(this).data("preset"); const d = new Date(); const fmt = dd => dd.toISOString().slice(0,10);
        let f = fmt(d), t = fmt(d);
        if (p==="30") f = fmt(new Date(d.getTime()-29*86400000));
        if (p==="90") f = fmt(new Date(d.getTime()-89*86400000));
        if (p==="mtd") f = fmt(new Date(d.getFullYear(), d.getMonth(), 1));
        if (p==="ytd") f = fmt(new Date(d.getFullYear(), 0, 1));
        $("input[name=from_date]").val(f); $("input[name=to_date]").val(t);
    });

    $(document).on("click", ".toggle-all", function(){
        const $sel = $("#" + $(this).data("select-id"));
        const all = $sel.find("option").map(function(){return this.value;}).get();
        $sel.val( ($sel.val() || []).length === all.length ? [] : all ).trigger("change");
    });

    // Main Funnel Chart (horizontal bars for classic funnel feel)
    const funnelCtx = document.getElementById("funnelChart");
    if (funnelCtx && typeof Chart !== "undefined") {
        const stages = ' . json_encode($funnel_stages ?? []) . ';
        new Chart(funnelCtx, {
            type: "bar",
            data: {
                labels: stages.map(s => s.name),
                datasets: [{
                    label: "Value (Tk)",
                    data: stages.map(s => s.value),
                    backgroundColor: stages.map(s => s.color || "#0d6efd"),
                    borderColor: "#1f4e79",
                    borderWidth: 1
                }, {
                    label: "Count",
                    data: stages.map(s => s.count),
                    type: "line",
                    borderColor: "#dc3545",
                    borderWidth: 2,
                    fill: false,
                    yAxisID: "y1"
                }]
            },
            options: {
                indexAxis: "y",
                responsive: true,
                plugins: { legend: { position: "bottom" } },
                scales: {
                    x: { ticks: { callback: v => "Tk " + (v/1000000).toFixed(1) + "M" } },
                    y1: { position: "right", grid: { drawOnChartArea: false } }
                }
            }
        });
    }

    // Pipeline Trend
    const trendCtx = document.getElementById("pipelineTrendChart");
    if (trendCtx && typeof Chart !== "undefined") {
        new Chart(trendCtx, {
            type: "line",
            data: {
                labels: ' . json_encode($trend_labels ?? []) . ',
                datasets: [{ label: "Pipeline Value", data: ' . json_encode($trend_values ?? []) . ', borderColor: "#0d6efd", tension: 0.3, fill: false }]
            },
            options: { responsive: true, scales: { y: { ticks: { callback: v => "Tk " + (v/1000000).toFixed(1) + "M" } } } }
        });
    }

    // Velocity Trend
    const velCtx = document.getElementById("velocityTrendChart");
    if (velCtx && typeof Chart !== "undefined") {
        new Chart(velCtx, {
            type: "line",
            data: { labels: ["W1","W2","W3","W4","W5","W6"], datasets: [{ label: "Avg Days to Close", data: ' . json_encode($velocity_trend ?? [28,25,32,22,30,27]) . ', borderColor: "#17a2b8", tension: 0.4 }] },
            options: { responsive: true }
        });
    }

    // Client-side stage filter for table
    window.filterTableByStage = function(stage) {
        $("#opportunitiesTable tbody tr").each(function(){
            $(this).toggle( $(this).data("stage") === stage );
        });
    };

    // Make chips open filters
    $("#activeFilterBar").on("click", ".filter-tag", function(){
        new bootstrap.Offcanvas(document.getElementById("funnelFiltersOffcanvas")).show();
    });

    // Basic table search hint
    console.log("Funnel & Pipeline loaded. Use filters offcanvas and click rows for stage focus.");
});
</script>
';

require_once '../app/views/layouts/main.php';
?>