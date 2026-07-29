@extends('layouts.admin')

@section('content')
@php
    $sections = $sections ?? [];
    $summary  = $summary ?? ['pass' => 0, 'warn' => 0, 'fail' => 0, 'total' => 0];
    $userBranchId = $userBranchId ?? null;

    $statusMeta = function (string $status): array {
        return [
            'pass' => ['cls' => 'bg-success-subtle text-success',       'icon' => 'fa-circle-check',         'label' => 'PASS'],
            'fail' => ['cls' => 'bg-danger-subtle text-danger',         'icon' => 'fa-circle-xmark',         'label' => 'FAIL'],
            'warn' => ['cls' => 'bg-warning-subtle text-warning',       'icon' => 'fa-triangle-exclamation', 'label' => 'WARN'],
        ][$status] ?? ['cls' => 'bg-secondary-subtle text-secondary', 'icon' => 'fa-circle-question', 'label' => '—'];
    };

    $fmtDate = function ($d): string {
        if (!$d) return '';
        try { return \Carbon\Carbon::parse($d)->format('d M Y'); } catch (\Throwable $e) { return (string) $d; }
    };
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0f766e,#0891b2);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-clipboard-check me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                7-section integrity checklist — workflow, GL links, ledger nature, stock↔GL, data integrity, operations, approval workflow.
                @if ($userBranchId)
                    Scoped to your branch.
                @else
                    All branches (admin view).
                @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.stock-adjustments.checklist') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-rotate me-1"></i> Re-run checks
            </a>
            <a href="{{ route('admin.stock-adjustments.export') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-file-csv me-1"></i> Export CSV
            </a>
            <a href="{{ route('admin.stock-adjustments.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Summary banner --}}
    <div class="card border-0 shadow-sm mb-3
                {{ $summary['fail'] > 0 ? 'border-start border-danger border-4' : ($summary['warn'] > 0 ? 'border-start border-warning border-4' : 'border-start border-success border-4') }}">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center">
                <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                     style="width:56px;height:56px;background:{{ $summary['fail'] > 0 ? '#dc3545' : ($summary['warn'] > 0 ? '#d97706' : '#198754') }};">
                    @if ($summary['fail'] > 0)
                        <i class="fas fa-triangle-exclamation fa-lg"></i>
                    @elseif ($summary['warn'] > 0)
                        <i class="fas fa-circle-exclamation fa-lg"></i>
                    @else
                        <i class="fas fa-circle-check fa-lg"></i>
                    @endif
                </div>
                <div>
                    <div class="h4 mb-0">{{ $summary['pass'] }} of {{ $summary['total'] }} checks passed</div>
                    <div class="text-muted small">
                        @if ($summary['fail'] > 0)
                            <span class="text-danger fw-semibold">{{ $summary['fail'] }} failing</span>
                            @if ($summary['warn'] > 0) · {{ $summary['warn'] }} warning(s) @endif
                        @elseif ($summary['warn'] > 0)
                            <span class="text-warning fw-semibold">{{ $summary['warn'] }} warning(s)</span> · no failures
                        @else
                            All checks green — no integrity issues detected.
                        @endif
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <span class="badge bg-success-subtle text-success fs-6">
                    <i class="fas fa-circle-check me-1"></i>{{ $summary['pass'] }} pass
                </span>
                <span class="badge bg-warning-subtle text-warning fs-6">
                    <i class="fas fa-triangle-exclamation me-1"></i>{{ $summary['warn'] }} warn
                </span>
                <span class="badge bg-danger-subtle text-danger fs-6">
                    <i class="fas fa-circle-xmark me-1"></i>{{ $summary['fail'] }} fail
                </span>
            </div>
        </div>
    </div>

    {{-- Section cards --}}
    @foreach ($sections as $section)
        @php
            $sectionFail = $section['fail'] ?? 0;
            $sectionWarn = $section['warn'] ?? 0;
            $sectionBorder = $sectionFail > 0 ? 'border-danger' : ($sectionWarn > 0 ? 'border-warning' : 'border-success');
        @endphp
        <div class="card border-0 shadow-sm mb-3 border-top border-3 {{ $sectionBorder }}">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">
                    <i class="fas {{ $section['icon'] ?? 'fa-list-check' }} me-1 text-primary"></i>
                    {{ $section['title'] ?? 'Section' }}
                    <span class="badge bg-secondary-subtle text-secondary ms-1">{{ count($section['checks'] ?? []) }}</span>
                </h2>
                <div class="d-flex gap-1">
                    @if (($section['pass'] ?? 0) > 0)
                        <span class="badge bg-success-subtle text-success">{{ $section['pass'] }} pass</span>
                    @endif
                    @if (($section['warn'] ?? 0) > 0)
                        <span class="badge bg-warning-subtle text-warning">{{ $section['warn'] }} warn</span>
                    @endif
                    @if (($section['fail'] ?? 0) > 0)
                        <span class="badge bg-danger-subtle text-danger">{{ $section['fail'] }} fail</span>
                    @endif
                </div>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush mb-0">
                    @foreach ($section['checks'] ?? [] as $check)
                        @php
                            $status = $check['status'] ?? 'warn';
                            $meta   = $statusMeta($status);
                            $count  = (int) ($check['count'] ?? 0);
                            $samples = $check['samples'] ?? [];
                        @endphp
                        <li class="list-group-item py-3 px-3">
                            <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="badge {{ $meta['cls'] }} rounded-circle d-flex align-items-center justify-content-center"
                                          style="width:34px;height:34px;font-size:.95rem;flex-shrink:0;">
                                        <i class="fas {{ $meta['icon'] }}"></i>
                                    </span>
                                    <div>
                                        <div class="fw-semibold">{{ $check['label'] ?? $check['key'] }}</div>
                                        <div class="small text-muted">
                                            @if (!empty($check['meta'])){{ $check['meta'] }} · @endif
                                            @switch($status)
                                                @case('pass') No issues found. @break
                                                @case('fail') <span class="text-danger">Action required.</span> @break
                                                @case('warn') <span class="text-warning">Review recommended.</span> @break
                                            @endswitch
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-secondary-subtle text-secondary">
                                        <i class="fas fa-hashtag me-1"></i>{{ number_format($count) }} record(s)
                                    </span>
                                    <span class="badge {{ $meta['cls'] }}">{{ $meta['label'] }}</span>
                                </div>
                            </div>

                            {{-- Sample rows (only when count > 0) --}}
                            @if (!empty($samples))
                                <div class="table-responsive mt-2 ms-sm-5" style="max-height:240px;overflow-y:auto;">
                                    <table class="table table-sm table-striped table-hover mb-0 align-middle" style="font-size:.85rem;">
                                        <thead class="table-light sticky-top">
                                            <tr>
                                                <th style="width:90px;">Code</th>
                                                <th style="width:90px;">Date</th>
                                                <th>Detail</th>
                                                <th class="text-end" style="width:70px;">View</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($samples as $s)
                                                <tr>
                                                    <td>
                                                        @if (!empty($s['id']))
                                                            <a href="{{ route('admin.stock-adjustments.show', $s['id']) }}"
                                                               class="text-decoration-none fw-semibold">{{ $s['code'] ?? ('SA#' . $s['id']) }}</a>
                                                        @else
                                                            {{ $s['code'] ?? '—' }}
                                                        @endif
                                                    </td>
                                                    <td>{{ $fmtDate($s['date'] ?? null) }}</td>
                                                    <td class="text-muted">{{ $s['extra'] ?? '' }}</td>
                                                    <td class="text-end">
                                                        @if (!empty($s['id']))
                                                            <a href="{{ route('admin.stock-adjustments.show', $s['id']) }}"
                                                               class="btn btn-sm btn-outline-primary py-0 px-2">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                        @else
                                                            —
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endforeach

    {{-- Footer --}}
    <div class="card border-0 shadow-sm">
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <span class="text-muted small">
                <i class="fas fa-clock-rotate-left me-1"></i>
                Last run: {{ now()->format('d M Y, H:i:s') }}
            </span>
            <a href="{{ route('admin.stock-adjustments.checklist') }}" class="btn btn-sm btn-primary">
                <i class="fas fa-rotate me-1"></i> Re-run checks
            </a>
        </div>
    </div>
</div>
@endsection
