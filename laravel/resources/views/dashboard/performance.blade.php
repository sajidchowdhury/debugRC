@extends('layouts.admin')

@push('css')
<style>
    /* ============================================================
       User Performance Dashboard — Phase 0 scaffolding styles
       (scoped under #perf-dashboard so we don't leak into Bootstrap)
       ============================================================ */
    #perf-dashboard .perf-hero {
        background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 60%, #4338ca 100%);
        color: #fff;
        border-radius: 1rem;
        padding: 1.25rem 1.5rem;
        box-shadow: 0 10px 30px -10px rgba(15, 23, 42, 0.35);
    }
    #perf-dashboard .perf-hero h2 {
        font-weight: 700;
        letter-spacing: -0.01em;
    }
    #perf-dashboard .perf-hero .sub {
        opacity: 0.8;
        font-size: 0.85rem;
        margin-top: 0.25rem;
    }
    #perf-dashboard .perf-hero select.form-select {
        background-color: rgba(255, 255, 255, 0.95);
        border: 0;
        font-weight: 500;
        min-width: 260px;
    }
    #perf-dashboard .perf-period-bar {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 0.5rem 0.75rem;
    }
    #perf-dashboard .perf-period-bar .btn-period {
        font-size: 0.82rem;
        padding: 0.25rem 0.75rem;
        border-radius: 999px;
        color: #475569;
        text-decoration: none;
        transition: all 0.15s;
    }
    #perf-dashboard .perf-period-bar .btn-period:hover {
        background: #f1f5f9;
        color: #0f172a;
    }
    #perf-dashboard .perf-period-bar .btn-period.active {
        background: #1e3a8a;
        color: #fff;
    }
    #perf-dashboard .perf-scaffold-card {
        border: 1px dashed #cbd5e1;
        background: #f8fafc;
        border-radius: 0.75rem;
        padding: 1.5rem;
        text-align: center;
        color: #64748b;
        min-height: 200px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        gap: 0.5rem;
    }
    #perf-dashboard .perf-scaffold-card i {
        font-size: 1.5rem;
        color: #94a3b8;
    }
    #perf-dashboard .perf-scaffold-card .title {
        font-weight: 600;
        color: #334155;
        font-size: 0.95rem;
    }
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
    #perf-dashboard .perf-target-banner {
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        border-radius: 0.5rem;
        padding: 0.5rem 0.75rem;
        font-size: 0.85rem;
        color: #065f46;
    }
    #perf-dashboard .perf-target-banner strong { color: #064e3b; }
    #perf-dashboard .perf-error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
        border-radius: 0.5rem;
        padding: 1rem;
    }
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
                <i class="fas fa-user-chart me-2"></i>{{ $isSuperadmin && isset($targetEmployee) && $targetEmployee ? 'Performance Dashboard' : 'My Performance' }}
            </h2>
            <p class="mb-0 sub">
                @if (isset($targetEmployee) && $targetEmployee)
                    Viewing:
                    <strong>{{ $targetEmployee->name }}</strong>
                    @if ($targetEmployee->employee_code) ({{ $targetEmployee->employee_code }}) @endif
                    — {{ ucfirst($targetEmployee->role) }}
                    @if ($targetEmployee->branch) · {{ $targetEmployee->branch->branch_name }} @endif
                    · Period: {{ $periodLabel }}
                    @if (isset($range) && $range) ({{ $range['start'] }} → {{ $range['end'] }}) @endif
                @else
                    Period: {{ $periodLabel }}
                @endif
            </p>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2">
            {{-- Super-admin only: employee <select> --}}
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
         PERIOD SWITCHER — pill-style buttons preserving employee context
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
            @php
                $q = array_merge($baseQuery, ['period' => $key]);
            @endphp
            <a href="{{ route('dashboard', $q) }}"
               class="btn-period @if ($period === $key) active @endif">{{ $label }}</a>
        @endforeach

        {{-- Custom range form --}}
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
         PHASE 0 — SCAFFOLDING GRID
         Each card is a placeholder for a section that will be filled in
         Phases 1–4. The grid layout mirrors the end-state design so we
         can verify the visual structure now.
         ============================================================ --}}
    @if (isset($targetEmployee) && $targetEmployee)

    {{-- Target banner — confirms which employee's data we're scoped to --}}
    <div class="perf-target-banner mb-3">
        <i class="fas fa-bullseye me-1"></i>
        Showing <strong>{{ $targetEmployee->name }}</strong>'s performance for <strong>{{ $periodLabel }}</strong>
        @if (isset($range) && $range) · {{ $range['start'] }} → {{ $range['end'] }} @endif
        @if ($isSuperadmin && $targetEmployee->id === Auth::user()->employee?->id)
            · <em>(this is your own performance — pick another employee above to view theirs)</em>
        @endif
    </div>

    {{-- Row 1: Sales KPIs (Phase 1) --}}
    <div class="row g-3 mb-2">
        <div class="col-12">
            <h5 class="text-muted mb-2"><i class="fas fa-chart-line me-1 text-primary"></i>Sales Performance</h5>
        </div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-money-bill-wave"></i><div class="title">Sales Volume</div><div class="text-muted small">Invoice count + value</div><span class="phase-tag">Phase 1</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-calculator"></i><div class="title">Avg Invoice Size (AOV)</div><div class="text-muted small">With prev-period delta</div><span class="phase-tag">Phase 1</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-calendar-check"></i><div class="title">Active Selling Days</div><div class="text-muted small">Distinct invoice dates</div><span class="phase-tag">Phase 1</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-arrow-trend-up"></i><div class="title">Growth vs Prev Period</div><div class="text-muted small">▲ / ▼ percentage</div><span class="phase-tag">Phase 1</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-user-plus"></i><div class="title">New Customers</div><div class="text-muted small">First-time buyers</div><span class="phase-tag">Phase 1</span></div></div>
    </div>

    {{-- Row 2: Charts (Phase 1) --}}
    <div class="row g-3 mb-2">
        <div class="col-12 col-xl-8"><div class="perf-scaffold-card" style="min-height:280px"><i class="fas fa-chart-area"></i><div class="title">Sales Trend</div><div class="text-muted small">Daily count + value line chart (7/30/90-day toggle)</div><span class="phase-tag">Phase 1</span></div></div>
        <div class="col-12 col-xl-4"><div class="perf-scaffold-card" style="min-height:280px"><i class="fas fa-chart-bar"></i><div class="title">Sales by Product Group</div><div class="text-muted small">Horizontal bar chart</div><span class="phase-tag">Phase 1</span></div></div>
    </div>

    {{-- Row 3: My Top Customers (Phase 1) --}}
    <div class="row g-3 mb-3">
        <div class="col-12"><div class="perf-scaffold-card" style="min-height:140px"><i class="fas fa-trophy"></i><div class="title">My Top 5 Customers</div><div class="text-muted small">By the user's revenue this period (NOT a global top-5)</div><span class="phase-tag">Phase 1</span></div></div>
    </div>

    {{-- Row 4: Collections + Returns (Phase 2) --}}
    <div class="row g-3 mb-2">
        <div class="col-12">
            <h5 class="text-muted mb-2"><i class="fas fa-hand-holding-usd me-1 text-success"></i>Collections & Returns</h5>
        </div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-coins"></i><div class="title">Collection Volume</div><div class="text-muted small">Count + value</div><span class="phase-tag">Phase 2</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-percentage"></i><div class="title">Collection Rate</div><div class="text-muted small">Gauge 0–100%</div><span class="phase-tag">Phase 2</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-wallet"></i><div class="title">My Outstanding</div><div class="text-muted small">Snapshot</div><span class="phase-tag">Phase 2</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-exclamation-circle"></i><div class="title">Overdue Value</div><div class="text-muted small">>30 days</div><span class="phase-tag">Phase 2</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-undo"></i><div class="title">Return Rate</div><div class="text-muted small">Return/sales ratio</div><span class="phase-tag">Phase 2</span></div></div>
    </div>

    {{-- Row 5: Aging donut + Return reasons (Phase 2) --}}
    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-6"><div class="perf-scaffold-card" style="min-height:240px"><i class="fas fa-clock"></i><div class="title">Receivable Aging (My Book)</div><div class="text-muted small">5-bucket stacked donut</div><span class="phase-tag">Phase 2</span></div></div>
        <div class="col-12 col-xl-6"><div class="perf-scaffold-card" style="min-height:240px"><i class="fas fa-list-ul"></i><div class="title">Top Return Reasons</div><div class="text-muted small">Horizontal bar chart</div><span class="phase-tag">Phase 2</span></div></div>
    </div>

    {{-- Row 6: How You Work (Phase 3) --}}
    <div class="row g-3 mb-2">
        <div class="col-12">
            <h5 class="text-muted mb-2"><i class="fas fa-user-clock me-1 text-info"></i>How You Work</h5>
        </div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-stopwatch"></i><div class="title">Avg Sale Velocity</div><div class="text-muted small">Invoice → Challan (hours)</div><span class="phase-tag">Phase 3</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-truck-fast"></i><div class="title">Same-Day Dispatch</div><div class="text-muted small">Gauge percentage</div><span class="phase-tag">Phase 3</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-calendar-day"></i><div class="title">Active Days</div><div class="text-muted small">Cross-table activity</div><span class="phase-tag">Phase 3</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-list-ol"></i><div class="title">Txns per Day</div><div class="text-muted small">Throughput intensity</div><span class="phase-tag">Phase 3</span></div></div>
    </div>

    {{-- Row 7: Work pattern + Pipeline (Phase 3) --}}
    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-8"><div class="perf-scaffold-card" style="min-height:260px"><i class="fas fa-clock-rotate-left"></i><div class="title">Work Pattern (24h histogram)</div><div class="text-muted small">Hour-of-day activity, peak hour highlighted</div><span class="phase-tag">Phase 3</span></div></div>
        <div class="col-12 col-xl-4"><div class="perf-scaffold-card" style="min-height:260px"><i class="fas fa-tasks"></i><div class="title">Pipeline Snapshot</div><div class="text-muted small">Stale drafts / open pipeline / parked</div><span class="phase-tag">Phase 3</span></div></div>
    </div>

    {{-- Row 8: Commission + Stock + Accuracy (Phase 4) --}}
    <div class="row g-3 mb-2">
        <div class="col-12">
            <h5 class="text-muted mb-2"><i class="fas fa-bullseye me-1 text-warning"></i>Commission, Stock Discipline & Accuracy</h5>
        </div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-coins"></i><div class="title">Net Commission</div><div class="text-muted small">Salesman role only</div><span class="phase-tag">Phase 4</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-bullseye"></i><div class="title">Target Attainment</div><div class="text-muted small">Progress bar 0–150%</div><span class="phase-tag">Phase 4</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-warehouse"></i><div class="title">Adjustments Initiated</div><div class="text-muted small">Count + value</div><span class="phase-tag">Phase 4</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-exclamation-triangle"></i><div class="title">Accountable Damages</div><div class="text-muted small">Blamed-party view</div><span class="phase-tag">Phase 4</span></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="perf-scaffold-card"><i class="fas fa-bug"></i><div class="title">Composite Error Rate</div><div class="text-muted small">Reversed + cancelled</div><span class="phase-tag">Phase 4</span></div></div>
    </div>

    {{-- Phase 0 footnote --}}
    <div class="text-center text-muted small mt-4 mb-3">
        <i class="fas fa-info-circle me-1"></i>
        <strong>Phase 0 — Scaffolding complete.</strong>
        Metric cards above are placeholders; the data arrives in Phases 1–4.
        @if (isset($customerPaymentsTxnType))
            <br><span class="text-muted">G12 check: <code>customer_payments.transaction_type</code> column
                @if ($customerPaymentsTxnType) <span class="text-success">exists</span> @else <span class="text-warning">missing</span> @endif
                (logged for Phase 2 use).
            </span>
        @endif
    </div>

    @endif {{-- end of scaffold grid (only when target employee resolved) --}}

</div>
@endsection

@push('scripts')
{{-- Phase 0 has no JS — Chart.js + metric rendering arrives in Phase 1 --}}
@endpush
