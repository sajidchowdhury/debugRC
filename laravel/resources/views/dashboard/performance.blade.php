@extends('layouts.admin')

@push('css')
<style>
    /* ============================================================
       User Performance Dashboard — Phase 1 visual system
       (scoped under #perf-dashboard to avoid bleeding into Bootstrap)
       ============================================================ */

    /* Color palette — modern gradient-driven, no boring solid backgrounds */
    #perf-dashboard {
        --perf-bg:           #f8fafc;
        --perf-card:         #ffffff;
        --perf-text:         #0f172a;
        --perf-muted:        #64748b;
        --perf-border:       #e2e8f0;
        --perf-primary:      #4f46e5;     /* indigo-600 */
        --perf-primary-2:    #7c3aed;     /* violet-600 */
        --perf-success:      #10b981;     /* emerald-500 */
        --perf-success-2:    #059669;     /* emerald-600 */
        --perf-warning:      #f59e0b;     /* amber-500 */
        --perf-danger:       #ef4444;     /* red-500 */
        --perf-info:         #0ea5e9;     /* sky-500 */
        --perf-pink:         #ec4899;     /* pink-500 */
        --perf-shadow-sm:    0 1px 2px rgba(15, 23, 42, 0.05);
        --perf-shadow:       0 10px 30px -10px rgba(15, 23, 42, 0.18);
        --perf-shadow-lg:    0 20px 50px -20px rgba(15, 23, 42, 0.25);
    }

    #perf-dashboard .perf-hero {
        background: linear-gradient(120deg, #0f172a 0%, #1e3a8a 45%, #4f46e5 100%);
        color: #fff;
        border-radius: 1.25rem;
        padding: 1.5rem 1.75rem;
        position: relative;
        overflow: hidden;
        box-shadow: var(--perf-shadow);
    }
    #perf-dashboard .perf-hero::before {
        content: '';
        position: absolute;
        top: -60px; right: -60px;
        width: 240px; height: 240px;
        background: radial-gradient(circle, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0) 70%);
        border-radius: 50%;
        pointer-events: none;
    }
    #perf-dashboard .perf-hero::after {
        content: '';
        position: absolute;
        bottom: -80px; left: 30%;
        width: 200px; height: 200px;
        background: radial-gradient(circle, rgba(124,58,237,0.35) 0%, rgba(124,58,237,0) 70%);
        border-radius: 50%;
        pointer-events: none;
    }
    #perf-dashboard .perf-hero h2 {
        font-weight: 800;
        letter-spacing: -0.02em;
        position: relative;
        z-index: 2;
    }
    #perf-dashboard .perf-hero .sub {
        opacity: 0.85;
        font-size: 0.88rem;
        margin-top: 0.35rem;
        position: relative;
        z-index: 2;
    }
    #perf-dashboard .perf-hero .pill {
        background: rgba(255,255,255,0.15);
        border: 1px solid rgba(255,255,255,0.25);
        color: #fff;
        padding: 0.25rem 0.75rem;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 500;
        backdrop-filter: blur(6px);
    }
    #perf-dashboard .perf-hero select.form-select {
        background-color: rgba(255, 255, 255, 0.97);
        border: 0;
        font-weight: 600;
        min-width: 280px;
        box-shadow: 0 4px 20px -4px rgba(0,0,0,0.3);
    }

    /* Period switcher — pill bar */
    #perf-dashboard .perf-period-bar {
        background: #fff;
        border: 1px solid var(--perf-border);
        border-radius: 0.75rem;
        padding: 0.5rem 0.85rem;
        box-shadow: var(--perf-shadow-sm);
    }
    #perf-dashboard .perf-period-bar .btn-period {
        font-size: 0.8rem;
        padding: 0.35rem 0.95rem;
        border-radius: 999px;
        color: #475569;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.2s;
        display: inline-block;
    }
    #perf-dashboard .perf-period-bar .btn-period:hover {
        background: #f1f5f9;
        color: #0f172a;
        transform: translateY(-1px);
    }
    #perf-dashboard .perf-period-bar .btn-period.active {
        background: linear-gradient(135deg, var(--perf-primary), var(--perf-primary-2));
        color: #fff;
        box-shadow: 0 4px 12px -2px rgba(79, 70, 229, 0.4);
    }

    /* KPI cards — the headline visual. Each has a gradient strip + sparkline. */
    #perf-dashboard .kpi-card {
        position: relative;
        background: var(--perf-card);
        border: 1px solid var(--perf-border);
        border-radius: 0.9rem;
        padding: 1.1rem 1.25rem;
        overflow: hidden;
        height: 100%;
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }
    #perf-dashboard .kpi-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--perf-shadow);
    }
    #perf-dashboard .kpi-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 4px;
        background: var(--accent, var(--perf-primary));
    }
    #perf-dashboard .kpi-card .kpi-icon {
        width: 38px; height: 38px;
        border-radius: 0.65rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        color: #fff;
        background: var(--accent, var(--perf-primary));
        box-shadow: 0 4px 10px -2px var(--accent, var(--perf-primary));
        margin-bottom: 0.65rem;
    }
    #perf-dashboard .kpi-card .kpi-label {
        color: var(--perf-muted);
        font-size: 0.78rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 0.15rem;
    }
    #perf-dashboard .kpi-card .kpi-value {
        font-size: 1.6rem;
        font-weight: 800;
        color: var(--perf-text);
        letter-spacing: -0.02em;
        line-height: 1.15;
    }
    #perf-dashboard .kpi-card .kpi-sub {
        font-size: 0.78rem;
        color: var(--perf-muted);
        margin-top: 0.2rem;
    }
    #perf-dashboard .kpi-card .kpi-delta {
        display: inline-flex;
        align-items: center;
        gap: 0.2rem;
        font-size: 0.78rem;
        font-weight: 700;
        padding: 0.15rem 0.5rem;
        border-radius: 999px;
        margin-top: 0.4rem;
    }
    #perf-dashboard .kpi-delta.up   { background: #d1fae5; color: #065f46; }
    #perf-dashboard .kpi-delta.down { background: #fee2e2; color: #991b1b; }
    #perf-dashboard .kpi-delta.flat { background: #f1f5f9; color: #475569; }

    #perf-dashboard .kpi-card .spark {
        position: absolute;
        bottom: 0; right: 0; left: 0;
        height: 38px;
        opacity: 0.9;
    }

    /* Section heading */
    #perf-dashboard .section-h {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--perf-text);
        margin: 0 0 0.65rem 0;
        display: flex;
        align-items: center;
        gap: 0.55rem;
    }
    #perf-dashboard .section-h .bar {
        width: 4px;
        height: 18px;
        background: linear-gradient(180deg, var(--perf-primary), var(--perf-primary-2));
        border-radius: 2px;
    }

    /* Chart cards */
    #perf-dashboard .chart-card {
        background: var(--perf-card);
        border: 1px solid var(--perf-border);
        border-radius: 0.9rem;
        padding: 1.1rem 1.25rem 1rem;
        height: 100%;
        box-shadow: var(--perf-shadow-sm);
    }
    #perf-dashboard .chart-card .chart-title {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--perf-text);
        margin: 0 0 0.15rem 0;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    #perf-dashboard .chart-card .chart-sub {
        font-size: 0.74rem;
        color: var(--perf-muted);
        margin-bottom: 0.75rem;
    }
    #perf-dashboard .chart-card .chart-wrap {
        position: relative;
    }

    /* Product group horizontal bars */
    #perf-dashboard .pg-row {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.7rem;
    }
    #perf-dashboard .pg-row:last-child { margin-bottom: 0; }
    #perf-dashboard .pg-row .pg-name {
        width: 38%;
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--perf-text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    #perf-dashboard .pg-row .pg-track {
        flex: 1;
        height: 26px;
        background: #f1f5f9;
        border-radius: 6px;
        overflow: hidden;
        position: relative;
    }
    #perf-dashboard .pg-row .pg-fill {
        height: 100%;
        border-radius: 6px;
        background: linear-gradient(90deg, var(--perf-primary), var(--perf-primary-2));
        position: relative;
        animation: pg-grow 0.9s cubic-bezier(0.16, 1, 0.3, 1) both;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        padding-right: 0.5rem;
        color: #fff;
        font-size: 0.72rem;
        font-weight: 700;
    }
    @keyframes pg-grow {
        from { width: 0 !important; }
    }
    #perf-dashboard .pg-row .pg-share {
        font-size: 0.78rem;
        color: var(--perf-muted);
        font-weight: 600;
        width: 50px;
        text-align: right;
    }

    /* Customer leaderboard */
    #perf-dashboard .cust-row {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        padding: 0.6rem 0.4rem;
        border-bottom: 1px dashed #e2e8f0;
    }
    #perf-dashboard .cust-row:last-child { border-bottom: 0; }
    #perf-dashboard .cust-rank {
        width: 30px; height: 30px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: 0.78rem;
        background: #f1f5f9;
        color: #475569;
        flex-shrink: 0;
    }
    #perf-dashboard .cust-rank.r1 { background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #fff; box-shadow: 0 4px 12px -3px #f59e0b; }
    #perf-dashboard .cust-rank.r2 { background: linear-gradient(135deg, #cbd5e1, #94a3b8); color: #fff; }
    #perf-dashboard .cust-rank.r3 { background: linear-gradient(135deg, #fdba74, #fb923c); color: #fff; }
    #perf-dashboard .cust-info { flex: 1; min-width: 0; }
    #perf-dashboard .cust-name {
        font-weight: 700; color: var(--perf-text);
        font-size: 0.88rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    #perf-dashboard .cust-meta {
        font-size: 0.74rem;
        color: var(--perf-muted);
        margin-top: 0.1rem;
    }
    #perf-dashboard .cust-progress {
        height: 6px;
        background: #f1f5f9;
        border-radius: 3px;
        overflow: hidden;
        margin-top: 0.35rem;
    }
    #perf-dashboard .cust-progress > div {
        height: 100%;
        background: linear-gradient(90deg, var(--perf-success), var(--perf-info));
        border-radius: 3px;
        animation: pg-grow 0.9s cubic-bezier(0.16, 1, 0.3, 1) both;
    }
    #perf-dashboard .cust-revenue {
        font-weight: 800;
        color: var(--perf-text);
        font-size: 0.92rem;
        text-align: right;
        white-space: nowrap;
    }
    #perf-dashboard .cust-due {
        font-size: 0.7rem;
        color: var(--perf-danger);
        font-weight: 600;
        text-align: right;
        margin-top: 0.1rem;
    }

    /* Acquisition donut + legend */
    #perf-dashboard .acq-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.65rem;
    }
    #perf-dashboard .acq-tile {
        border-radius: 0.65rem;
        padding: 0.85rem 0.95rem;
        color: #fff;
        position: relative;
        overflow: hidden;
    }
    #perf-dashboard .acq-tile.new   { background: linear-gradient(135deg, #10b981, #059669); }
    #perf-dashboard .acq-tile.repeat{ background: linear-gradient(135deg, #0ea5e9, #2563eb); }
    #perf-dashboard .acq-tile .lbl  { font-size: 0.74rem; opacity: 0.92; font-weight: 500; }
    #perf-dashboard .acq-tile .val  { font-size: 1.5rem; font-weight: 800; line-height: 1.1; }
    #perf-dashboard .acq-tile .pct  { font-size: 0.72rem; opacity: 0.88; margin-top: 0.1rem; }

    /* Peak day callout */
    #perf-dashboard .peak-card {
        background: linear-gradient(135deg, #fef3c7, #fde68a);
        border: 1px solid #fbbf24;
        border-radius: 0.85rem;
        padding: 1rem 1.15rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        height: 100%;
    }
    #perf-dashboard .peak-card .peak-icon {
        width: 48px; height: 48px;
        border-radius: 12px;
        background: linear-gradient(135deg, #f59e0b, #d97706);
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.3rem;
        box-shadow: 0 8px 20px -4px rgba(245, 158, 11, 0.5);
        flex-shrink: 0;
    }
    #perf-dashboard .peak-card .peak-label {
        font-size: 0.72rem;
        color: #92400e;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    #perf-dashboard .peak-card .peak-value {
        font-size: 1.4rem;
        font-weight: 800;
        color: #78350f;
        line-height: 1.15;
    }
    #perf-dashboard .peak-card .peak-date {
        font-size: 0.78rem;
        color: #92400e;
        font-weight: 600;
    }

    /* Empty state */
    #perf-dashboard .empty-card {
        background: #fff;
        border: 1px dashed #cbd5e1;
        border-radius: 0.85rem;
        padding: 2rem 1.5rem;
        text-align: center;
        color: var(--perf-muted);
    }
    #perf-dashboard .empty-card i { font-size: 2rem; color: #cbd5e1; margin-bottom: 0.5rem; }

    /* Phase-tagged scaffold placeholder (Phase 2-4 placeholders remain) */
    #perf-dashboard .perf-scaffold-card {
        border: 1px dashed #cbd5e1;
        background: repeating-linear-gradient(45deg, #f8fafc, #f8fafc 10px, #f1f5f9 10px, #f1f5f9 20px);
        border-radius: 0.75rem;
        padding: 1.5rem;
        text-align: center;
        color: #64748b;
        min-height: 160px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        gap: 0.4rem;
    }
    #perf-dashboard .perf-scaffold-card i { font-size: 1.4rem; color: #94a3b8; }
    #perf-dashboard .perf-scaffold-card .title { font-weight: 600; color: #334155; font-size: 0.92rem; }
    #perf-dashboard .perf-scaffold-card .phase-tag {
        display: inline-block;
        font-size: 0.7rem;
        background: #e0e7ff;
        color: #4338ca;
        padding: 0.15rem 0.5rem;
        border-radius: 999px;
        margin-top: 0.25rem;
        font-weight: 600;
    }

    #perf-dashboard .perf-error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
        border-radius: 0.5rem;
        padding: 1rem;
    }

    /* Number-format helper visuals */
    #perf-dashboard .mono { font-variant-numeric: tabular-nums; }
</style>
@endpush

@section('content')
<div id="perf-dashboard" class="py-3">

    {{-- ============================================================
         HERO HEADER — title + (super-admin only) employee selector
         ============================================================ --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3 perf-hero">
        <div>
            <h2 class="h4 mb-1">
                <i class="fas fa-bolt me-2"></i>{{ $isSuperadmin && isset($targetEmployee) && $targetEmployee ? 'Performance Dashboard' : 'My Performance' }}
            </h2>
            <p class="mb-2 sub">
                @if (isset($targetEmployee) && $targetEmployee)
                    <span class="pill me-1"><i class="fas fa-user me-1"></i>{{ $targetEmployee->name }}</span>
                    @if ($targetEmployee->employee_code)<span class="pill me-1">{{ $targetEmployee->employee_code }}</span>@endif
                    <span class="pill me-1">{{ ucfirst($targetEmployee->role) }}</span>
                    @if ($targetEmployee->branch)<span class="pill"><i class="fas fa-map-marker-alt me-1"></i>{{ $targetEmployee->branch->branch_name }}</span>@endif
                @endif
            </p>
            <p class="mb-0 sub">
                <i class="far fa-calendar me-1"></i>{{ $periodLabel }}
                @if (isset($range) && $range) · {{ $range['start'] }} → {{ $range['end'] }} @endif
            </p>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2">
            @if ($isSuperadmin && isset($employeeOptions) && $employeeOptions->isNotEmpty())
            <form method="GET" action="{{ route('dashboard') }}" id="employeeSwitchForm" class="d-flex align-items-center gap-2">
                <input type="hidden" name="period" value="{{ $period }}">
                @if ($period === 'custom')
                    <input type="hidden" name="from" value="{{ $range['start'] }}">
                    <input type="hidden" name="to" value="{{ $range['end'] }}">
                @endif
                <label class="small text-white-50 mb-0 me-1">
                    <i class="fas fa-users me-1"></i>Employee:
                </label>
                <select name="employee_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">— Myself ({{ Auth::user()->username }}) —</option>
                    @foreach ($employeeOptions as $emp)
                        <option value="{{ $emp->id }}"
                            @if (isset($targetEmployee) && $targetEmployee && $targetEmployee->id === $emp->id) selected @endif>
                            {{ $emp->name }} ({{ $emp->employee_code }}) — {{ ucfirst($emp->role) }}@if ($emp->branch) · {{ $emp->branch->branch_name }}@endif
                        </option>
                    @endforeach
                </select>
            </form>
            @endif
        </div>
    </div>

    {{-- Error state (user not linked to an employee) --}}
    @if (isset($errorMessage))
    <div class="perf-error mb-3">
        <i class="fas fa-exclamation-triangle me-2"></i>{{ $errorMessage }}
    </div>
    @endif

    {{-- ============================================================
         PERIOD SWITCHER
         ============================================================ --}}
    @if (isset($targetEmployee) && $targetEmployee)
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 perf-period-bar">
        <span class="text-muted small me-2"><i class="far fa-calendar me-1"></i>Period:</span>
        @php
            $periods = [
                'today'  => 'Today',
                'mtd'    => 'MTD',
                'qtd'    => 'QTD',
                'ytd'    => 'YTD',
                'last30' => 'Last 30D',
            ];
            $baseQuery = [];
            if ($isSuperadmin && isset($targetEmployee) && $targetEmployee && $targetEmployee->id !== Auth::user()->employee?->id) {
                $baseQuery['employee_id'] = $targetEmployee->id;
            }
        @endphp
        @foreach ($periods as $key => $label)
            @php $q = array_merge($baseQuery, ['period' => $key]); @endphp
            <a href="{{ route('dashboard', $q) }}"
               class="btn-period @if ($period === $key) active @endif">{{ $label }}</a>
        @endforeach

        <form method="GET" action="{{ route('dashboard') }}" class="d-flex align-items-center gap-1 ms-2" id="customPeriodForm">
            @foreach ($baseQuery as $k => $v)
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endforeach
            <input type="hidden" name="period" value="custom">
            <input type="date" name="from" class="form-control form-control-sm" style="width:auto" value="{{ $range['start'] ?? '' }}" required>
            <span class="text-muted small">→</span>
            <input type="date" name="to" class="form-control form-control-sm" style="width:auto" value="{{ $range['end'] ?? '' }}" required>
            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-arrow-right"></i></button>
        </form>
    </div>
    @endif

    {{-- ============================================================
         PHASE 1 — SALES PERFORMANCE CORE
         ============================================================ --}}
    @if (isset($targetEmployee) && $targetEmployee && !$scaffoldingOnly)

    {{-- ===== KPI ROW — 5 gradient-topped cards with sparklines ===== --}}
    <h3 class="section-h"><span class="bar"></span><i class="fas fa-chart-line text-primary"></i> Sales Performance</h3>

    <div class="row g-3 mb-3">
        @php
            $kpis = $salesKpis ?? [
                'invoice_count' => 0, 'total_sales' => 0.0, 'aov' => 0.0,
                'growth_pct' => 0.0, 'active_days' => 0, 'peak_day_value' => 0.0,
                'peak_day_date' => null, 'prev_total_sales' => 0.0,
            ];
            $trend = $salesTrend ?? [];
            $trendValues = array_map(fn($r) => $r['total_sales'], $trend);
            $acq = $customerAcquisition ?? ['active' => 0, 'new' => 0, 'repeat' => 0, 'repeat_rate' => 0.0, 'new_rate' => 0.0];
        @endphp

        {{-- Sales Volume --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card" style="--accent: linear-gradient(135deg, #4f46e5, #7c3aed);">
                <div class="kpi-icon" style="background: linear-gradient(135deg, #4f46e5, #7c3aed);"><i class="fas fa-money-bill-wave"></i></div>
                <div class="kpi-label">Sales Volume</div>
                <div class="kpi-value mono">৳ {{ number_format($kpis['total_sales'], 0) }}</div>
                <div class="kpi-sub">{{ $kpis['invoice_count'] }} invoice{{ $kpis['invoice_count'] !== 1 ? 's' : '' }} this period</div>
                @php
                    $growth = $kpis['growth_pct'] ?? 0.0;
                    $gClass = $growth > 0.5 ? 'up' : ($growth < -0.5 ? 'down' : 'flat');
                    $gIcon  = $growth > 0.5 ? 'arrow-up' : ($growth < -0.5 ? 'arrow-down' : 'minus');
                @endphp
                <span class="kpi-delta {{ $gClass }}">
                    <i class="fas fa-{{ $gIcon }}"></i>{{ abs($growth) }}% vs prev
                </span>
                <canvas class="spark" data-values="{{ implode(',', $trendValues) }}" data-color="#4f46e5"></canvas>
            </div>
        </div>

        {{-- Avg Invoice Size --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card" style="--accent: linear-gradient(135deg, #0ea5e9, #2563eb);">
                <div class="kpi-icon" style="background: linear-gradient(135deg, #0ea5e9, #2563eb);"><i class="fas fa-calculator"></i></div>
                <div class="kpi-label">Avg Invoice Size</div>
                <div class="kpi-value mono">৳ {{ number_format($kpis['aov'], 0) }}</div>
                <div class="kpi-sub">Per-invoice average (AOV)</div>
                @php
                    $prevAov = $kpis['prev_total_sales'] > 0 && $kpis['invoice_count'] > 0
                        ? ($kpis['prev_total_sales'] / max(1, $kpis['invoice_count']))
                        : 0;
                    $aovDelta = $prevAov > 0 ? round((($kpis['aov'] - $prevAov) / $prevAov) * 100, 1) : 0;
                    $aClass = $aovDelta > 0.5 ? 'up' : ($aovDelta < -0.5 ? 'down' : 'flat');
                    $aIcon  = $aovDelta > 0.5 ? 'arrow-up' : ($aovDelta < -0.5 ? 'arrow-down' : 'minus');
                @endphp
                <span class="kpi-delta {{ $aClass }}">
                    <i class="fas fa-{{ $aIcon }}"></i>{{ abs($aovDelta) }}% vs prev
                </span>
            </div>
        </div>

        {{-- Active Selling Days --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card" style="--accent: linear-gradient(135deg, #10b981, #059669);">
                <div class="kpi-icon" style="background: linear-gradient(135deg, #10b981, #059669);"><i class="fas fa-calendar-check"></i></div>
                <div class="kpi-label">Active Selling Days</div>
                <div class="kpi-value mono">{{ $kpis['active_days'] }}</div>
                <div class="kpi-sub">Days with at least one invoice</div>
                @php
                    $periodLen = (isset($range) ? \Carbon\Carbon::parse($range['start'])->diffInDays(\Carbon\Carbon::parse($range['end'])) + 1 : 1);
                    $utilization = $periodLen > 0 ? round(($kpis['active_days'] / $periodLen) * 100, 0) : 0;
                @endphp
                <span class="kpi-delta {{ $utilization >= 70 ? 'up' : ($utilization >= 40 ? 'flat' : 'down') }}">
                    <i class="fas fa-{{ $utilization >= 70 ? 'fire' : 'clock' }}"></i>{{ $utilization }}% utilization
                </span>
            </div>
        </div>

        {{-- New Customers --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card" style="--accent: linear-gradient(135deg, #ec4899, #db2777);">
                <div class="kpi-icon" style="background: linear-gradient(135deg, #ec4899, #db2777);"><i class="fas fa-user-plus"></i></div>
                <div class="kpi-label">New Customers</div>
                <div class="kpi-value mono">{{ $acq['new'] }}</div>
                <div class="kpi-sub">First-time buyers in period</div>
                <span class="kpi-delta {{ $acq['new_rate'] >= 30 ? 'up' : 'flat' }}">
                    <i class="fas fa-percentage"></i>{{ $acq['new_rate'] }}% of {{ $acq['active'] }} active
                </span>
            </div>
        </div>
    </div>

    {{-- ===== Peak Day callout + Growth highlight ===== --}}
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-6 col-xl-4">
            <div class="peak-card">
                <div class="peak-icon"><i class="fas fa-trophy"></i></div>
                <div>
                    <div class="peak-label">Peak Sales Day</div>
                    @if ($kpis['peak_day_date'])
                        <div class="peak-value mono">৳ {{ number_format($kpis['peak_day_value'], 0) }}</div>
                        <div class="peak-date">{{ \Carbon\Carbon::parse($kpis['peak_day_date'])->format('D, M j, Y') }}</div>
                    @else
                        <div class="peak-value">No sales yet</div>
                        <div class="peak-date">Pick an active day to see your peak</div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Repeat customers tile --}}
        <div class="col-12 col-md-6 col-xl-4">
            <div class="acq-tile repeat" style="height:100%; display:flex; flex-direction:column; justify-content:center;">
                <div class="lbl"><i class="fas fa-redo me-1"></i>Repeat Customers</div>
                <div class="val mono">{{ $acq['repeat'] }}</div>
                <div class="pct">{{ $acq['repeat_rate'] }}% of {{ $acq['active'] }} active customers returned for a 2nd+ sale</div>
            </div>
        </div>

        {{-- Total active customers tile --}}
        <div class="col-12 col-md-6 col-xl-4">
            <div class="acq-tile" style="background: linear-gradient(135deg, #f59e0b, #d97706); height:100%; display:flex; flex-direction:column; justify-content:center;">
                <div class="lbl"><i class="fas fa-users me-1"></i>Active Customers</div>
                <div class="val mono">{{ $acq['active'] }}</div>
                <div class="pct">Unique customers billed this period</div>
            </div>
        </div>
    </div>

    {{-- ===== Charts row: Sales Trend (8) + Product Group bars (4) ===== --}}
    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-8">
            <div class="chart-card">
                <div class="chart-title"><i class="fas fa-chart-area text-primary"></i> Sales Trend</div>
                <div class="chart-sub">Daily invoice value & count over the selected period</div>
                <div class="chart-wrap" style="height:300px;">
                    <canvas id="salesTrendChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            <div class="chart-card">
                <div class="chart-title"><i class="fas fa-chart-bar text-info"></i> Sales by Product Group</div>
                <div class="chart-sub">Top groups by your revenue this period</div>
                <div class="chart-wrap" style="max-height:300px; overflow-y:auto;">
                    @php $pgroups = $salesByProductGroup ?? []; @endphp
                    @if (empty($pgroups))
                        <div class="empty-card">
                            <i class="fas fa-folder-open"></i>
                            <div>No product-group sales yet this period.</div>
                        </div>
                    @else
                        @php
                            $maxRev = max(array_map(fn($g) => $g['revenue'], $pgroups)) ?: 1;
                            $palette = ['#4f46e5', '#7c3aed', '#0ea5e9', '#10b981', '#f59e0b', '#ec4899', '#ef4444', '#14b8a6'];
                        @endphp
                        @foreach ($pgroups as $i => $g)
                            <div class="pg-row">
                                <div class="pg-name" title="{{ $g['group_name'] }}">{{ $g['group_name'] }}</div>
                                <div class="pg-track">
                                    <div class="pg-fill" style="width: {{ max(8, round(($g['revenue'] / $maxRev) * 100, 1)) }}%; background: linear-gradient(90deg, {{ $palette[$i % count($palette)] }}, {{ $palette[($i + 1) % count($palette)] }});">
                                        ৳ {{ number_format($g['revenue'], 0) }}
                                    </div>
                                </div>
                                <div class="pg-share">{{ $g['share'] }}%</div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ===== Top Customers leaderboard ===== --}}
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="chart-card">
                <div class="chart-title"><i class="fas fa-crown text-warning"></i> My Top 5 Customers</div>
                <div class="chart-sub">By your revenue this period — NOT a global top-5. Bar shows share of your total revenue.</div>
                <div class="chart-wrap">
                    @php $tcs = $topCustomers ?? []; @endphp
                    @if (empty($tcs))
                        <div class="empty-card">
                            <i class="fas fa-user-friends"></i>
                            <div>No customer sales yet this period.</div>
                        </div>
                    @else
                        @foreach ($tcs as $i => $c)
                            <div class="cust-row">
                                <div class="cust-rank r{{ min($i + 1, 3) }}">{{ $i + 1 }}</div>
                                <div class="cust-info">
                                    <div class="cust-name">{{ $c['name'] }}</div>
                                    <div class="cust-meta">{{ $c['invoice_count'] }} invoice{{ $c['invoice_count'] !== 1 ? 's' : '' }} · {{ $c['share'] }}% of your revenue</div>
                                    <div class="cust-progress"><div style="width: {{ $c['share'] }}%;"></div></div>
                                </div>
                                <div>
                                    <div class="cust-revenue mono">৳ {{ number_format($c['revenue'], 0) }}</div>
                                    @if ($c['due'] > 0)
                                        <div class="cust-due">৳ {{ number_format($c['due'], 0) }} due</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>

    @endif {{-- end of Phase 1 Sales block --}}

    {{-- ============================================================
         PHASE 2-4 — SCAFFOLDING PLACEHOLDERS (kept visible so the
         user sees what's coming next; will be filled in later phases)
         ============================================================ --}}
    @if (isset($targetEmployee) && $targetEmployee && !$scaffoldingOnly)

    <h3 class="section-h mt-4"><span class="bar"></span><i class="fas fa-hand-holding-usd text-success"></i> Collections & Returns</h3>
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-coins"></i><div class="title">Collection Volume</div><span class="phase-tag">Phase 2</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-percentage"></i><div class="title">Collection Rate</div><span class="phase-tag">Phase 2</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-wallet"></i><div class="title">My Outstanding</div><span class="phase-tag">Phase 2</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-exclamation-circle"></i><div class="title">Overdue Value</div><span class="phase-tag">Phase 2</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-undo"></i><div class="title">Return Rate</div><span class="phase-tag">Phase 2</span></div></div>
    </div>

    <h3 class="section-h mt-3"><span class="bar"></span><i class="fas fa-user-clock text-info"></i> How You Work</h3>
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-stopwatch"></i><div class="title">Avg Sale Velocity</div><span class="phase-tag">Phase 3</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-truck-fast"></i><div class="title">Same-Day Dispatch</div><span class="phase-tag">Phase 3</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-clock-rotate-left"></i><div class="title">Work Pattern</div><span class="phase-tag">Phase 3</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-tasks"></i><div class="title">Pipeline Snapshot</div><span class="phase-tag">Phase 3</span></div></div>
    </div>

    <h3 class="section-h mt-3"><span class="bar"></span><i class="fas fa-bullseye text-warning"></i> Commission, Stock Discipline & Accuracy</h3>
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-coins"></i><div class="title">Net Commission</div><span class="phase-tag">Phase 4</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-bullseye"></i><div class="title">Target Attainment</div><span class="phase-tag">Phase 4</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-warehouse"></i><div class="title">Stock Discipline</div><span class="phase-tag">Phase 4</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-bug"></i><div class="title">Error Rate</div><span class="phase-tag">Phase 4</span></div></div>
    </div>

    <div class="text-center text-muted small mt-4 mb-3">
        <i class="fas fa-info-circle me-1"></i>
        <strong>Phase 1 complete.</strong> Sales metrics are live. Collections, work pattern, and commission arrive in Phases 2–4.
        @if (isset($customerPaymentsTxnType))
            <br>G12 check: <code>customer_payments.transaction_type</code>
            @if ($customerPaymentsTxnType) <span class="text-success">exists</span> @else <span class="text-warning">missing</span> @endif
            (Phase 2 will use it for write-off metrics).
        @endif
    </div>

    @endif

</div>
@endsection

@push('scripts')
{{-- Chart.js — already on the legacy dashboard view; load locally for this page --}}
<script src="/assets/js/bootstrep/chart.umd.min.js"></script>

<script>
(function () {
    // ============================================================
    // 1. Sales Trend — dual-axis line+bar chart
    // ============================================================
    const trendData = @json($salesTrend ?? []);
    const trendEl = document.getElementById('salesTrendChart');
    if (trendEl && trendData.length) {
        const labels = trendData.map(d => {
            // Short date: "Jul 15"
            const dt = new Date(d.date + 'T00:00:00');
            return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        });
        const values = trendData.map(d => Number(d.total_sales));
        const counts = trendData.map(d => Number(d.invoice_count));

        // Gradient fill for the line
        const ctx = trendEl.getContext('2d');
        const grad = ctx.createLinearGradient(0, 0, 0, 280);
        grad.addColorStop(0, 'rgba(79, 70, 229, 0.35)');
        grad.addColorStop(1, 'rgba(79, 70, 229, 0.02)');

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        type: 'line',
                        label: 'Sales Value (৳)',
                        data: values,
                        borderColor: '#4f46e5',
                        backgroundColor: grad,
                        borderWidth: 2.5,
                        tension: 0.35,
                        fill: true,
                        pointRadius: 0,
                        pointHoverRadius: 6,
                        pointHoverBackgroundColor: '#4f46e5',
                        pointHoverBorderColor: '#fff',
                        pointHoverBorderWidth: 2,
                        yAxisID: 'y',
                    },
                    {
                        type: 'bar',
                        label: 'Invoice Count',
                        data: counts,
                        backgroundColor: 'rgba(14, 165, 233, 0.55)',
                        borderColor: 'rgba(14, 165, 233, 0.9)',
                        borderWidth: 0,
                        borderRadius: 4,
                        maxBarThickness: 14,
                        yAxisID: 'y1',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top', align: 'end',
                        labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, font: { size: 11 } }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        titleFont: { size: 12, weight: '600' },
                        bodyFont: { size: 12 },
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function (ctx) {
                                if (ctx.dataset.yAxisID === 'y') {
                                    return ' ' + ctx.dataset.label + ': ৳' + Number(ctx.parsed.y).toLocaleString();
                                }
                                return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10 }, color: '#64748b', maxRotation: 0, autoSkip: true, maxTicksLimit: 12 }
                    },
                    y: {
                        position: 'left',
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 10 }, color: '#64748b',
                            callback: function (v) { return '৳' + (v >= 1000 ? (v/1000).toFixed(0) + 'k' : v); }
                        }
                    },
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        grid: { drawOnChartArea: false },
                        ticks: { font: { size: 10 }, color: '#0ea5e9', stepSize: 1 }
                    }
                }
            }
        });
    } else if (trendEl) {
        // No data — show empty state inside the canvas wrap
        trendEl.parentElement.innerHTML = '<div class="empty-card"><i class="fas fa-folder-open"></i><div>No sales recorded in this period yet.</div></div>';
    }

    // ============================================================
    // 2. Mini sparklines on each KPI card
    // ============================================================
    document.querySelectorAll('canvas.spark').forEach(function (cv) {
        const raw = cv.getAttribute('data-values');
        if (!raw) return;
        const values = raw.split(',').map(Number).filter(n => !isNaN(n));
        if (values.length < 2) return;
        const color = cv.getAttribute('data-color') || '#4f46e5';
        // Compact sparkline — no axes, no legend, just the line + fill
        new Chart(cv.getContext('2d'), {
            type: 'line',
            data: {
                labels: values.map((_, i) => i),
                datasets: [{
                    data: values,
                    borderColor: color,
                    backgroundColor: color + '22',
                    borderWidth: 1.5,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: { x: { display: false }, y: { display: false } },
                animation: { duration: 800 }
            }
        });
    });
})();
</script>
@endpush
