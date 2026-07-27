@extends('layouts.admin')

@php
    $accentColors = [
        'sales'     => ['bg' => '#2563eb', 'soft' => 'primary',   'text' => 'primary'],
        'purchase'  => ['bg' => '#f59e0b', 'soft' => 'warning',    'text' => 'warning text-dark'],
        'inventory' => ['bg' => '#14b8a6', 'soft' => 'info',       'text' => 'info text-dark'],
        'finance'   => ['bg' => '#6366f1', 'soft' => 'indigo',     'text' => 'primary'],
        'ops'       => ['bg' => '#64748b', 'soft' => 'secondary',  'text' => 'secondary'],
    ];
    $accentMap = [
        'sales'     => '#2563eb',
        'purchase'  => '#f59e0b',
        'inventory' => '#14b8a6',
        'finance'   => '#6366f1',
        'ops'       => '#64748b',
    ];
@endphp

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white" style="background:linear-gradient(135deg,#1e293b,#475569);">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-chart-pie me-2"></i> Reports
            </h1>
            <p class="mb-0 opacity-75">Phase 5 reporting layer — 23 financial &amp; operational reports across 5 categories.</p>
        </div>
        <div class="d-flex flex-wrap gap-1">
            <a href="{{ route('admin.reconciliation.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-scale-balanced me-1"></i> GL Reconciliation
            </a>
        </div>
    </header>

    {{-- Featured reports --}}
    @if (!empty($featured))
    <div class="mb-4">
        <h2 class="h6 text-uppercase text-muted mb-2"><i class="fas fa-star me-1"></i> Featured reports</h2>
        <div class="row g-3">
            @foreach ($featured as $r)
                @php $params = \App\Helpers\ReportsCatalog::buildRunParams($r, 'mtd'); @endphp
                <div class="col-md-6 col-xl-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-start mb-2">
                                <span class="rounded-3 d-flex align-items-center justify-content-center text-white flex-shrink-0"
                                      style="width:42px;height:42px;background:{{ $accentMap[$r['category_accent'] ?? 'finance'] }};">
                                    <i class="fas {{ $r['icon'] }}"></i>
                                </span>
                                <div class="ms-2">
                                    <div class="fw-semibold">{{ $r['title'] }}</div>
                                    <small class="text-muted">{{ $r['tagline'] }}</small>
                                </div>
                            </div>
                            <div class="mb-2">
                                @foreach (($r['tags'] ?? []) as $tag)
                                    <span class="badge bg-light text-dark border me-1">{{ $tag }}</span>
                                @endforeach
                            </div>
                        </div>
                        <div class="card-footer bg-white border-0 pt-0">
                            <a href="{{ route($r['route'], $params) }}" class="btn btn-outline-primary btn-sm w-100">
                                <i class="fas fa-play me-1"></i> Run report
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Category sections --}}
    @foreach ($categories as $cat)
        @php $accent = $accentMap[$cat['accent']] ?? '#64748b'; @endphp
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex align-items-center gap-2 py-2">
                <span class="rounded-3 d-flex align-items-center justify-content-center text-white"
                      style="width:38px;height:38px;background:{{ $accent }};">
                    <i class="fas {{ $cat['icon'] }}"></i>
                </span>
                <div class="flex-grow-1">
                    <div class="fw-semibold">{{ $cat['label'] }}</div>
                    <small class="text-muted">{{ $cat['tagline'] }}</small>
                </div>
                <span class="badge bg-light text-dark border">{{ count($cat['reports']) }} reports</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach ($cat['reports'] as $r)
                        @php $params = \App\Helpers\ReportsCatalog::buildRunParams($r, 'mtd'); @endphp
                        <div class="col-md-6 col-xl-4">
                            <div class="card border h-100" style="border-color:#e9ecef;">
                                <div class="card-body d-flex flex-column">
                                    <div class="d-flex align-items-start mb-2">
                                        <span class="rounded-3 d-flex align-items-center justify-content-center text-white flex-shrink-0"
                                              style="width:36px;height:36px;background:{{ $accent }};">
                                            <i class="fas {{ $r['icon'] }}"></i>
                                        </span>
                                        <div class="ms-2 flex-grow-1">
                                            <div class="fw-semibold small">{{ $r['title'] }}</div>
                                            <small class="text-muted" style="font-size:.78rem;">{{ $r['tagline'] }}</small>
                                        </div>
                                    </div>
                                    <div class="mb-2">
                                        @foreach (($r['tags'] ?? []) as $tag)
                                            <span class="badge bg-light text-secondary border me-1" style="font-size:.68rem;">{{ $tag }}</span>
                                        @endforeach
                                    </div>
                                    <div class="mt-auto">
                                        <a href="{{ route($r['route'], $params) }}" class="btn btn-sm btn-outline-secondary w-100">
                                            <i class="fas fa-play me-1"></i> Run report
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach

</div>
@endsection
