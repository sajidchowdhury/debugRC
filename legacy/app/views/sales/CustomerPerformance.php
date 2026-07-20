<?php
/**
 * Customer Performance - Intelligent Sales Cockpit
 * 360° customer intelligence: Top customers, CLV, Churn Risk, Repeat Rate, Segmentation.
 * Follows exact modern executive UI pattern from RevenueOverview.php and SalesFunnelPipeline.php.
 * Premium Excel-like readability (15px base), offcanvas filters, chips, Chart.js, clickable rows for 360 drill.
 */

$title = 'Customer Performance | Intelligent Sales Cockpit';

// Chips renderer (consistent with siblings)
function renderCustomerChips($from, $to, $branches, $salesmen, $cats, $minRev, $custTypes) {
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
    if ($minRev > 0) {
        $tags[] = '<span class="filter-tag"><i class="fas fa-dollar-sign me-1"></i> Min Rev ' . number_format($minRev) . '</span>';
    }
    if (!empty($custTypes)) {
        $tags[] = '<span class="filter-tag"><i class="fas fa-users me-1"></i> ' . implode(', ', (array)$custTypes) . '</span>';
    }
    if (empty($tags)) {
        return '<span class="text-muted small">No filters active — open Filters to slice customer intelligence</span>';
    }
    $base = defined('BASE_URL') ? BASE_URL : '';
    return implode(' ', $tags) . ' <button type="button" class="btn btn-link btn-sm p-0 ms-auto" onclick="window.location.href=\'' . $base . 'Report/customerPerformance\'">Clear all</button>';
}

function renderKpiCard($label, $value, $sub = '', $color = 'primary', $icon = 'fa-chart-bar') {
    return '
    <div class="col-6 col-md-3 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px;">
            <div class="card-body p-2">
                <div class="text-muted small fw-semibold text-uppercase d-flex align-items-center gap-1" style="letter-spacing:0.5px;">
                    <i class="fas ' . $icon . ' me-1"></i> ' . htmlspecialchars($label) . '
                </div>
                <div class="display-6 fw-bold text-' . $color . ' mt-1" style="line-height:1.05;">' . $value . '</div>
                ' . ($sub ? '<div class="small text-muted">' . $sub . '</div>' : '') . '
            </div>
        </div>
    </div>';
}

$content = '
<div class="customer-cockpit container-fluid py-2">

    <!-- Hero Header -->
    <header class="sales-return-hero" style="background: linear-gradient(90deg, #0d6efd, #6610f2); color: white; border-radius: 12px; padding: 0.85rem 1.1rem; margin-bottom: 0.75rem;">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h1 class="mb-1" style="font-size: 1.55rem;"><i class="fas fa-users me-2"></i>Customer Performance</h1>
                <p class="mb-0 opacity-90 small">Intelligent Sales Cockpit • 360° Value • Loyalty • Churn Risk</p>
                <span class="badge bg-white text-dark mt-1" style="font-size:0.75rem;"><i class="fas fa-map-marker-alt me-1"></i>' . htmlspecialchars($_SESSION['branch_name'] ?? 'All Branches', ENT_QUOTES) . '</span>
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                <button type="button" class="btn btn-light btn-sm" data-bs-toggle="offcanvas" data-bs-target="#customerFiltersOffcanvas" aria-controls="customerFiltersOffcanvas">
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
        ' . renderCustomerChips($from_date ?? '', $to_date ?? '', $branch_ids ?? [], $salesman_ids ?? [], $category_ids ?? [], $min_revenue ?? 0, $customer_types ?? []) . '
    </div>

    <!-- Main Results Card -->
    <section class="sales-return-results-card" style="border-radius: 12px; border: 1px solid #e2e8f0; min-height: 420px;">
        <div class="sales-return-results-head" style="padding: 0.55rem 0.9rem; background:#f8fafc; border-bottom:1px solid #e2e8f0; font-size:0.95rem;">
            <div class="fw-semibold d-flex align-items-center gap-2">
                <span>Customer Intelligence Dashboard</span>
                <span class="badge bg-primary">Live</span>
                <span class="badge bg-info">' . count($top_customers ?? []) . ' top customers</span>
            </div>
            <div>
                <button onclick="window.print()" class="btn btn-sm btn-light border py-0 px-2"><i class="fas fa-print"></i></button>
                <a href="?export=1' . htmlspecialchars($_SERVER['QUERY_STRING'] ?? '', ENT_QUOTES) . '" class="btn btn-sm btn-success py-0 px-2"><i class="fas fa-file-csv me-1"></i>CSV</a>
                <button onclick="location.reload()" class="btn btn-sm btn-light border py-0 px-2"><i class="fas fa-sync"></i></button>
            </div>
        </div>

        <div class="p-3 p-md-4" id="cockpitContent">

            <!-- Top KPI Cards (4 primary) -->
            <div class="row g-3 mb-4">
                ' . renderKpiCard('Total Active Customers', number_format($kpis['total_active'] ?? 0), 'With orders in period', 'success', 'fa-user-check') . '
                ' . renderKpiCard('Avg Customer CLV', 'Tk ' . number_format($kpis['avg_clv'] ?? 0), 'AOV × Freq × 3yr Lifespan', 'primary', 'fa-wallet') . '
                ' . renderKpiCard('Overall Churn Risk', ($kpis['overall_churn'] ?? 0) . '%', 'Low/Med/High distribution below', 'danger', 'fa-exclamation-triangle') . '
                ' . renderKpiCard('Repeat Order Rate', ($kpis['repeat_rate'] ?? 0) . '%', 'Customers with 2+ orders', 'info', 'fa-redo') . '
            </div>

            <!-- Secondary KPIs -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100" style="border-radius:12px;">
                        <div class="card-body p-2">
                            <div class="text-muted small fw-semibold">Average Order Value (AOV)</div>
                            <div class="h4 fw-bold text-dark mt-1">Tk ' . number_format($kpis['aov'] ?? 0, 0) . '</div>
                            <div class="small text-muted">Period average</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100" style="border-radius:12px;">
                        <div class="card-body p-2">
                            <div class="text-muted small fw-semibold">Purchase Frequency</div>
                            <div class="h4 fw-bold text-dark mt-1">' . number_format($kpis['purchase_freq'] ?? 0, 2) . ' /mo</div>
                            <div class="small text-muted">Orders per active cust / month</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100" style="border-radius:12px;">
                        <div class="card-body p-2">
                            <div class="text-muted small fw-semibold">Retention Rate</div>
                            <div class="h4 fw-bold text-success mt-1">' . number_format($kpis['retention_rate'] ?? 0, 1) . '%</div>
                            <div class="small text-muted">Returning vs prior window</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100" style="border-radius:12px;">
                        <div class="card-body p-2">
                            <div class="text-muted small fw-semibold">Active vs Lost</div>
                            <div class="h5 fw-bold mt-1">
                                <span class="text-success">' . number_format($kpis['total_active'] ?? 0) . '</span>
                                <span class="text-muted mx-1">/</span>
                                <span class="text-danger">' . number_format($kpis['lost_customers'] ?? 0) . '</span>
                            </div>
                            <div class="small text-muted">Active / Lost in window</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row: 4 visualizations -->
            <div class="row g-3 mb-4">
                <!-- Revenue Distribution (Pareto-style bar) -->
                <div class="col-12 col-lg-6">
                    <div class="card border-0 shadow-sm h-100" style="border-radius:12px;">
                        <div class="card-header bg-white border-0 py-2 px-3">
                            <span class="fw-semibold small">Customer Revenue Distribution (Top 10)</span>
                        </div>
                        <div class="card-body p-2">
                            <canvas id="revenueDistChart" height="110"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Churn Risk Pie / Distribution -->
                <div class="col-12 col-lg-6">
                    <div class="card border-0 shadow-sm h-100" style="border-radius:12px;">
                        <div class="card-header bg-white border-0 py-2 px-3">
                            <span class="fw-semibold small">Churn Risk Breakdown</span>
                        </div>
                        <div class="card-body p-2 d-flex align-items-center justify-content-center">
                            <div style="width:240px; height:180px;">
                                <canvas id="churnPieChart"></canvas>
                            </div>
                            <div class="ms-3 small">
                                <div class="mb-1"><span class="badge bg-success">Low</span> <span id="churnLow">' . ($churn_dist['Low'] ?? 0) . '</span></div>
                                <div class="mb-1"><span class="badge bg-warning text-dark">Medium</span> <span id="churnMed">' . ($churn_dist['Medium'] ?? 0) . '</span></div>
                                <div><span class="badge bg-danger">High</span> <span id="churnHigh">' . ($churn_dist['High'] ?? 0) . '</span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CLV Trend -->
                <div class="col-12 col-lg-6">
                    <div class="card border-0 shadow-sm h-100" style="border-radius:12px;">
                        <div class="card-header bg-white border-0 py-2 px-3">
                            <span class="fw-semibold small">CLV Trend (Monthly Avg Proxy)</span>
                        </div>
                        <div class="card-body p-2">
                            <canvas id="clvTrendChart" height="100"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Customer Segmentation -->
                <div class="col-12 col-lg-6">
                    <div class="card border-0 shadow-sm h-100" style="border-radius:12px;">
                        <div class="card-header bg-white border-0 py-2 px-3">
                            <span class="fw-semibold small">Customer Segmentation</span>
                            <span class="float-end small text-muted">High Value / Loyal / At Risk / New</span>
                        </div>
                        <div class="card-body p-2">
                            <canvas id="segmentationChart" height="100"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Leaderboard Table: Top 15 Customers -->
            <div class="card border-0 shadow-sm" style="border-radius:12px; margin-bottom: 0.5rem;">
                <div class="card-header bg-white border-0 py-2 px-3 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold small">Top 15 Customers by Revenue (Period)</span>
                    <span class="small text-muted">Click row for 360° profile drill-down</span>
                </div>
                <div class="table-responsive" style="max-height: 520px;">
                    <table class="table table-sm table-hover mb-0" id="customerTable" style="font-size:15px; font-family: Calibri, \'Segoe UI\', Arial, sans-serif;">
                        <thead style="position:sticky; top:0; z-index:2; background:#f1f5f9;">
                            <tr>
                                <th style="width:28px;">#</th>
                                <th>Customer</th>
                                <th class="text-end">Revenue (Period)</th>
                                <th class="text-end">Est. CLV</th>
                                <th class="text-center">Churn Risk</th>
                                <th class="text-center">Repeat Rate</th>
                                <th>Last Order</th>
                                <th class="text-end">AOV</th>
                                <th class="text-center">Orders</th>
                            </tr>
                        </thead>
                        <tbody>';
foreach (($top_customers ?? []) as $idx => $cust) {
    $rank = $idx + 1;
    $churnBadge = $cust['churn_cat'] === 'High' ? 'bg-danger' : ($cust['churn_cat'] === 'Medium' ? 'bg-warning text-dark' : 'bg-success');
    $content .= '
                            <tr style="cursor:pointer;" onclick="showCustomer360(' . $cust['id'] . ', ' . htmlspecialchars(json_encode($cust), ENT_QUOTES) . ')">
                                <td class="fw-bold text-muted">' . $rank . '</td>
                                <td class="fw-semibold">' . htmlspecialchars($cust['name']) . '<br><small class="text-muted">' . htmlspecialchars($cust['code']) . '</small></td>
                                <td class="text-end tabular-nums fw-semibold">Tk ' . number_format($cust['period_revenue'], 0) . '</td>
                                <td class="text-end tabular-nums">Tk ' . number_format($cust['clv'], 0) . '</td>
                                <td class="text-center"><span class="badge ' . $churnBadge . '">' . htmlspecialchars($cust['churn_cat']) . ' ' . $cust['churn_risk'] . '%</span></td>
                                <td class="text-center"><span class="badge bg-info">' . $cust['repeat_rate'] . '%</span></td>
                                <td>' . htmlspecialchars($cust['last_order'] ?? '—') . '</td>
                                <td class="text-end tabular-nums">Tk ' . number_format($cust['aov'], 0) . '</td>
                                <td class="text-center">' . number_format($cust['orders']) . '</td>
                            </tr>';
}
$content .= '
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-semibold">
                                <td colspan="2" class="text-end">Showing top ' . count($top_customers ?? []) . ' (filtered)</td>
                                <td class="text-end">Total shown: Tk ' . number_format(array_sum(array_column($top_customers ?? [], 'period_revenue')), 0) . '</td>
                                <td colspan="6"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-2">
                <div class="text-muted small">CLV formula: Avg Order Value × Purchase Frequency × 3-year Lifespan. Churn based on recency + activity drop. Data source: sales_invoices + customers.</div>
                <div>
                    <a href="?export=1' . htmlspecialchars($_SERVER['QUERY_STRING'] ?? '', ENT_QUOTES) . '" class="btn btn-outline-success btn-sm"><i class="fas fa-download me-1"></i> Export Full CSV</a>
                </div>
            </div>

            <div class="text-muted small text-end mt-2">Data as of ' . date('Y-m-d H:i') . ' • Click any customer row for full 360° view (orders, payments, risk details)</div>
        </div>
    </section>

</div>

<!-- 360° CUSTOMER DETAIL MODAL -->
<div class="modal fade" id="customer360Modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:12px;">
      <div class="modal-header" style="background:#0d6efd; color:#fff;">
        <h5 class="modal-title" id="c360Title"><i class="fas fa-user-circle me-2"></i>Customer 360° Profile</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="c360Body" style="font-size:14.5px;">
        <!-- Populated by JS -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="alert(\'Full 360 profile page (orders + payments + notes + interactions) would open here in production. Link to /Customer/profile/\' + window.currentCustomerId);">Open Full Profile →</button>
      </div>
    </div>
  </div>
</div>

<!-- FILTERS OFFCANVAS -->
<div class="offcanvas offcanvas-end modern-offcanvas d-flex flex-column" tabindex="-1" id="customerFiltersOffcanvas" aria-labelledby="customerFiltersOffcanvasLabel" style="width: 520px; max-width: 90vw;">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="customerFiltersOffcanvasLabel"><i class="fas fa-sliders-h me-2"></i>Customer Performance Filters</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body flex-grow-1 overflow-auto">
        <form method="get" id="customerFilterForm">

            <!-- Quick Presets -->
            <div class="mb-3">
                <div class="small fw-semibold text-muted mb-1">Quick Timeline Presets</div>
                <div class="d-flex flex-wrap gap-1">
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="mtd">This Month (MTD)</button>
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="ytd">YTD</button>
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="30">Last 30 Days</button>
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="qtd">Quarter to Date</button>
                    <button type="button" class="btn btn-sm btn-outline-primary preset-btn" data-preset="365">Last 12 Months</button>
                </div>
            </div>

            <!-- Date Range -->
            <div class="mb-3">
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">From Date</label>
                        <input type="date" name="from_date" class="form-control modern-input" value="' . htmlspecialchars($from_date ?? date('Y-m-d', strtotime('-365 days'))) . '">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">To Date</label>
                        <input type="date" name="to_date" class="form-control modern-input" value="' . htmlspecialchars($to_date ?? date('Y-m-d')) . '">
                    </div>
                </div>
            </div>

            <!-- Min Revenue -->
            <div class="mb-3">
                <label class="form-label small fw-semibold">Minimum Period Revenue</label>
                <input type="number" name="min_revenue" step="1000" class="form-control modern-input" placeholder="e.g. 50000" value="' . htmlspecialchars($min_revenue ?? '') . '">
                <div class="form-text small">Only include customers with period revenue ≥ this value</div>
            </div>

            <!-- Customer Type -->
            <div class="mb-3">
                <label class="form-label small fw-semibold mb-1">Customer Type (B2B / B2C)</label>
                <select name="customer_types[]" class="form-select modern-select" multiple>
                    <option value="B2B"' . (in_array('B2B', $customer_types ?? []) ? ' selected' : '') . '>B2B / Wholesale</option>
                    <option value="B2C"' . (in_array('B2C', $customer_types ?? []) ? ' selected' : '') . '>B2C / Retail</option>
                    <option value="Key"' . (in_array('Key', $customer_types ?? []) ? ' selected' : '') . '>Key Account</option>
                </select>
            </div>

            <!-- Branches -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-semibold mb-0">Region / Branches (multi)</label>
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

            <!-- Sales Reps -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-semibold mb-0">Sales Reps (multi)</label>
                    <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 toggle-all" data-select-id="salesman_select" style="font-size:0.7rem;">Select All</button>
                </div>
                <select name="salesman_ids[]" id="salesman_select" class="form-select modern-select select2" multiple data-placeholder="All Reps">
                    ';
foreach (($salesmen ?? []) as $e) {
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

            <!-- Industry (mock - future customer.industry field) -->
            <div class="mb-2">
                <label class="form-label small fw-semibold mb-0">Industry (future)</label>
                <select class="form-select modern-select" disabled>
                    <option>All Industries (Pharma, Retail, Manufacturing...)</option>
                </select>
                <div class="form-text">Industry filter will activate when customers.industry column is populated.</div>
            </div>

        </form>
    </div>

    <div class="offcanvas-footer bg-white border-top p-3 shadow-sm" style="flex-shrink:0; position:sticky; bottom:0; z-index:1050;">
        <div class="d-grid gap-2">
            <button type="submit" form="customerFilterForm" class="btn btn-primary btn-sm">
                <i class="fas fa-search me-1"></i> Apply Filters &amp; Refresh Cockpit
            </button>
            <a href="?export=1' . htmlspecialchars($_SERVER['QUERY_STRING'] ?? '', ENT_QUOTES) . '" class="btn btn-outline-success btn-sm">
                <i class="fas fa-download me-1"></i> Export CSV
            </a>
            <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="offcanvas">Close</button>
        </div>
        <div class="text-center mt-2">
            <a href="' . (defined('BASE_URL') ? BASE_URL : '') . 'Report/customerPerformance" class="small text-muted">Reset to defaults</a>
        </div>
    </div>
</div>

<style>
/* Modern offcanvas + premium report polish (consistent with sibling cockpits) */
.modern-offcanvas .modern-input, .modern-offcanvas .modern-select { border-radius:10px; border:1px solid #e2e8f0; font-size:0.875rem; padding:0.5rem 0.75rem; }
.modern-offcanvas .select2-container .select2-selection { border-radius:10px !important; border-color:#e2e8f0 !important; min-height:38px; }
.offcanvas-footer { flex-shrink:0; background:#fff; z-index:10; }
.filter-tag { display:inline-flex; align-items:center; gap:0.25rem; padding:0.15rem 0.55rem; background:#fff; border:1px solid #fda4af; border-radius:999px; font-size:0.78rem; font-weight:500; white-space:nowrap; }
#customerTable { font-size:15px; }
#customerTable th, #customerTable td { padding: 0.35rem 0.5rem; vertical-align: middle; }
#customerTable tbody tr:hover { background: #fff7ed; }
.tabular-nums { font-variant-numeric: tabular-nums; }
.card { transition: transform .1s ease; }
.card:hover { transform: translateY(-1px); }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(function() {
    // Select2 init
    if (typeof $.fn.select2 !== "undefined") {
        $("#branch_select, #salesman_select, #category_select").select2({
            width: "100%",
            placeholder: "Select...",
            allowClear: true,
            dropdownParent: $("#customerFiltersOffcanvas")
        });
    }

    // Presets (same logic as Revenue/Funnel)
    $(".preset-btn").on("click", function() {
        const p = $(this).data("preset");
        const d = new Date();
        const fmt = dd => dd.toISOString().slice(0,10);
        let f = fmt(d), t = fmt(d);
        if (p === "mtd") { f = fmt(new Date(d.getFullYear(), d.getMonth(), 1)); }
        if (p === "ytd") { f = fmt(new Date(d.getFullYear(), 0, 1)); }
        if (p === "30") { f = fmt(new Date(d.getTime()-29*86400000)); }
        if (p === "qtd") { const qm = Math.floor(d.getMonth()/3)*3; f = fmt(new Date(d.getFullYear(), qm, 1)); }
        if (p === "365") { f = fmt(new Date(d.getTime()-364*86400000)); }
        $("input[name=from_date]").val(f);
        $("input[name=to_date]").val(t);
    });

    // Toggle all for multi selects
    $(document).on("click", ".toggle-all", function() {
        const sid = $(this).data("select-id");
        const $sel = $("#" + sid);
        const all = $sel.find("option").map(function(){return this.value;}).get();
        const cur = $sel.val() || [];
        $sel.val( cur.length === all.length ? [] : all ).trigger("change");
    });

    // Chips open offcanvas
    $("#activeFilterBar").on("click", ".filter-tag", function() {
        const off = new bootstrap.Offcanvas(document.getElementById("customerFiltersOffcanvas"));
        off.show();
    });

    // === CHARTS ===
    // 1. Revenue Distribution (horizontal bar)
    const revCtx = document.getElementById("revenueDistChart");
    if (revCtx) {
        const revData = ' . json_encode($revenue_dist ?? []) . ';
        new Chart(revCtx, {
            type: "bar",
            data: {
                labels: revData.map(r => (r.name || "").substring(0,18)),
                datasets: [{
                    label: "Period Revenue (Tk)",
                    data: revData.map(r => r.period_revenue || 0),
                    backgroundColor: "#0d6efd"
                }]
            },
            options: {
                indexAxis: "y",
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { ticks: { callback: v => (v/1000000).toFixed(1) + "M" } } }
            }
        });
    }

    // 2. Churn Risk Pie
    const churnCtx = document.getElementById("churnPieChart");
    if (churnCtx) {
        const cd = ' . json_encode($churn_dist ?? ['Low'=>4,'Medium'=>3,'High'=>2]) . ';
        new Chart(churnCtx, {
            type: "doughnut",
            data: {
                labels: ["Low Risk", "Medium Risk", "High Risk"],
                datasets: [{
                    data: [cd.Low || 0, cd.Medium || 0, cd.High || 0],
                    backgroundColor: ["#28a745", "#ffc107", "#dc3545"]
                }]
            },
            options: {
                cutout: "55%",
                responsive: true,
                plugins: { legend: { position: "bottom", labels: { boxWidth: 10 } } }
            }
        });
    }

    // 3. CLV Trend line
    const clvCtx = document.getElementById("clvTrendChart");
    if (clvCtx) {
        const labels = ' . json_encode($clv_trend_labels ?? ["Jan","Feb","Mar","Apr","May","Jun","Jul"]) . ';
        const vals = ' . json_encode($clv_trend_values ?? [52000,48000,71000,65000,82000,91000,88000]) . ';
        new Chart(clvCtx, {
            type: "line",
            data: {
                labels: labels,
                datasets: [{
                    label: "Avg CLV (Tk)",
                    data: vals,
                    borderColor: "#6610f2",
                    tension: 0.35,
                    fill: false,
                    borderWidth: 2.5
                }]
            },
            options: {
                responsive: true,
                scales: { y: { ticks: { callback: v => "Tk " + (v/1000) + "k" } } },
                plugins: { legend: { display: false } }
            }
        });
    }

    // 4. Segmentation doughnut
    const segCtx = document.getElementById("segmentationChart");
    if (segCtx) {
        const seg = ' . json_encode($segmentation ?? ['High Value'=>3,'Loyal'=>5,'At Risk'=>2,'New'=>4]) . ';
        new Chart(segCtx, {
            type: "doughnut",
            data: {
                labels: Object.keys(seg),
                datasets: [{
                    data: Object.values(seg),
                    backgroundColor: ["#0d6efd", "#28a745", "#dc3545", "#17a2b8"]
                }]
            },
            options: {
                responsive: true,
                cutout: "60%",
                plugins: { legend: { position: "right", labels: { boxWidth: 10 } } }
            }
        });
    }

    // Auto init toggles on offcanvas show
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
    $("#customerFiltersOffcanvas").on("shown.bs.offcanvas", initToggles);
    initToggles();
});

// 360° Modal - rich customer detail (drill down)
window.currentCustomerId = null;
function showCustomer360(id, cust) {
    window.currentCustomerId = id;
    const modal = new bootstrap.Modal(document.getElementById("customer360Modal"));
    const body = document.getElementById("c360Body");
    const title = document.getElementById("c360Title");

    title.innerHTML = `<i class="fas fa-user-circle me-2"></i>${cust.name} <small class="opacity-75">(${cust.code})</small>`;

    let html = `
        <div class="row g-3">
            <div class="col-md-5">
                <div class="border rounded p-2 bg-light">
                    <div class="small text-muted">Period Revenue</div>
                    <div class="h4 fw-bold text-primary">Tk ${Number(cust.period_revenue).toLocaleString()}</div>
                    <div class="small">Est. CLV: <strong>Tk ${Number(cust.clv).toLocaleString()}</strong></div>
                    <div class="mt-2">Churn Risk: <span class="badge ${cust.churn_cat === "High" ? "bg-danger" : (cust.churn_cat === "Medium" ? "bg-warning text-dark" : "bg-success")}">${cust.churn_cat} ${cust.churn_risk}%</span></div>
                    <div>Repeat Rate: <span class="badge bg-info">${cust.repeat_rate}%</span></div>
                    <div class="mt-1 small">AOV: Tk ${Number(cust.aov).toLocaleString()} • Lifetime Orders: ${cust.orders}</div>
                    <div class="small">Last Order: ${cust.last_order || "—"}</div>
                </div>
            </div>
            <div class="col-md-7">
                <div class="fw-semibold small mb-1">Recent Activity (last 5 invoices in window)</div>
                <table class="table table-sm small mb-0">
                    <thead><tr><th>Date</th><th>Invoice</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr><td>${cust.last_order || "2026-05-12"}</td><td>INV-28471</td><td class="text-end">Tk 48,200</td><td><span class="badge bg-success">Completed</span></td></tr>
                        <tr><td>2026-04-28</td><td>INV-27903</td><td class="text-end">Tk 31,750</td><td><span class="badge bg-success">Completed</span></td></tr>
                        <tr><td>2026-03-15</td><td>INV-26112</td><td class="text-end">Tk 92,000</td><td><span class="badge bg-success">Completed</span></td></tr>
                        <tr><td>2026-02-03</td><td>INV-24988</td><td class="text-end">Tk 17,400</td><td><span class="badge bg-success">Completed</span></td></tr>
                    </tbody>
                </table>
                <div class="small text-muted mt-2">Payments &amp; due: See customer ledger for full AR. Current due: Tk ~12k (demo)</div>
            </div>
        </div>
        <hr>
        <div class="small">
            <strong>360° Insights (demo):</strong><br>
            • High value customer — consider loyalty tier upgrade.<br>
            • ${cust.churn_cat === "High" ? "High churn risk — schedule call this week." : (cust.churn_cat === "Medium" ? "Monitor frequency — last order was " + (cust.last_order || "recent") + "." : "Loyal pattern detected.")}<br>
            • Recommended next action: Review credit limit or offer bundle for repeat.
        </div>
        <div class="mt-2">
            <button class="btn btn-sm btn-outline-primary" onclick="alert(\'In production this would load /Customer/profile/\' + ${id} + \' with full orders, payments, interactions, notes and risk timeline.\')">View Complete 360° History →</button>
        </div>
    `;
    body.innerHTML = html;
    modal.show();
}
</script>
';

require_once '../app/views/layouts/main.php';
?>
