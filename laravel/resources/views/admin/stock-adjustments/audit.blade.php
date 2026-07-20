@extends('layouts.admin')

@section('content')
@php
    $checks    = $checks ?? [];
    $total     = count($checks);
    $passed    = 0;
    $failed    = 0;
    $warnings  = 0;
    foreach ($checks as $c) {
        $status = $c['status'] ?? 'warn';
        if ($status === 'pass')      $passed++;
        elseif ($status === 'fail')  $failed++;
        else                         $warnings++;
    }

    $statusMeta = function (string $status): array {
        switch ($status) {
            case 'pass': return ['cls' => 'bg-success-subtle text-success',        'icon' => 'fa-circle-check',          'label' => 'PASS'];
            case 'fail': return ['cls' => 'bg-danger-subtle text-danger',           'icon' => 'fa-circle-xmark',          'label' => 'FAIL'];
            case 'warn': return ['cls' => 'bg-warning-subtle text-warning',         'icon' => 'fa-triangle-exclamation',  'label' => 'WARN'];
            default:     return ['cls' => 'bg-secondary-subtle text-secondary',     'icon' => 'fa-circle-question',       'label' => '—'];
        }
    };
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0f766e,#0891b2);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-clipboard-check me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Health checks for stock adjustments — GL integrity, stock movement completeness, draft staleness.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.stock-adjustments.audit') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-rotate me-1"></i> Re-run checks
            </a>
            <a href="{{ route('admin.stock-adjustments.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Summary banner --}}
    <div class="card border-0 shadow-sm mb-3
                {{ $failed > 0 ? 'border-start border-danger border-4' : ($warnings > 0 ? 'border-start border-warning border-4' : 'border-start border-success border-4') }}">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center">
                <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                     style="width:56px;height:56px;background:{{ $failed > 0 ? '#dc3545' : ($warnings > 0 ? '#d97706' : '#198754') }};">
                    @if ($failed > 0)
                        <i class="fas fa-triangle-exclamation fa-lg"></i>
                    @elseif ($warnings > 0)
                        <i class="fas fa-circle-exclamation fa-lg"></i>
                    @else
                        <i class="fas fa-circle-check fa-lg"></i>
                    @endif
                </div>
                <div>
                    <div class="h4 mb-0">{{ $passed }} of {{ $total }} checks passed</div>
                    <div class="text-muted small">
                        @if ($failed > 0)
                            <span class="text-danger fw-semibold">{{ $failed }} failing</span>
                            @if ($warnings > 0) · {{ $warnings }} warning(s) @endif
                        @elseif ($warnings > 0)
                            <span class="text-warning fw-semibold">{{ $warnings }} warning(s)</span> · no failures
                        @else
                            All checks green — no integrity issues detected.
                        @endif
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <span class="badge bg-success-subtle text-success fs-6">
                    <i class="fas fa-circle-check me-1"></i>{{ $passed }} pass
                </span>
                <span class="badge bg-danger-subtle text-danger fs-6">
                    <i class="fas fa-circle-xmark me-1"></i>{{ $failed }} fail
                </span>
                <span class="badge bg-warning-subtle text-warning fs-6">
                    <i class="fas fa-triangle-exclamation me-1"></i>{{ $warnings }} warn
                </span>
            </div>
        </div>
    </div>

    {{-- Checks list --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h2 class="h6 mb-0"><i class="fas fa-list-check me-1 text-primary"></i> Checks ({{ $total }})</h2>
        </div>
        <div class="card-body p-0">
            @if ($total === 0)
                <div class="text-center text-muted py-5">
                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                    No checks defined.
                </div>
            @else
                <ul class="list-group list-group-flush mb-0">
                    @foreach ($checks as $i => $c)
                        @php
                            $status = $c['status'] ?? 'warn';
                            $meta   = $statusMeta($status);
                            $count  = (int) ($c['count'] ?? 0);
                        @endphp
                        <li class="list-group-item d-flex align-items-center justify-content-between gap-3 py-3">
                            <div class="d-flex align-items-center gap-3">
                                <span class="badge {{ $meta['cls'] }} rounded-circle d-flex align-items-center justify-content-center"
                                      style="width:36px;height:36px;font-size:1rem;">
                                    <i class="fas {{ $meta['icon'] }}"></i>
                                </span>
                                <div>
                                    <div class="fw-semibold">{{ $c['label'] ?? ('Check #' . ($i + 1)) }}</div>
                                    <div class="small text-muted">
                                        @switch($status)
                                            @case('pass')
                                                No issues found for this check.
                                                @break
                                            @case('fail')
                                                <span class="text-danger">Action required — see records below.</span>
                                                @break
                                            @case('warn')
                                                <span class="text-warning">Review recommended.</span>
                                                @break
                                        @endswitch
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-secondary-subtle text-secondary fs-6">
                                    <i class="fas fa-hashtag me-1"></i>{{ number_format($count) }} record(s)
                                </span>
                                <span class="badge {{ $meta['cls'] }}">
                                    {{ $meta['label'] }}
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <span class="text-muted small">
                <i class="fas fa-clock-rotate-left me-1"></i>
                Last run: {{ now()->format('d M Y, H:i:s') }}
            </span>
            <a href="{{ route('admin.stock-adjustments.audit') }}" class="btn btn-sm btn-primary">
                <i class="fas fa-rotate me-1"></i> Re-run checks
            </a>
        </div>
    </div>
</div>
@endsection
