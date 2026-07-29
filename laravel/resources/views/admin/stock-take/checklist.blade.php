@extends('layouts.admin')

@section('content')
@php
    $statusIcon = [
        'pass' => 'fa-circle-check text-success',
        'warn' => 'fa-triangle-exclamation text-warning',
        'fail' => 'fa-circle-xmark text-danger',
        'info' => 'fa-circle-info text-info',
    ];
    $statusBadge = [
        'pass' => 'bg-success-subtle text-success',
        'warn' => 'bg-warning-subtle text-warning',
        'fail' => 'bg-danger-subtle text-danger',
        'info' => 'bg-info-subtle text-info',
    ];
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0f766e,#0d9488);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-clipboard-check me-2"></i>{{ $title }}
            </h1>
            <p class="mb-0 small opacity-75">
                <i class="fas fa-clock me-1"></i> Ran at {{ $ranAt }}
                @if ($viewAllBranches)
                    · <i class="fas fa-globe me-1"></i>All branches
                @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            @if ($canViewAllBranches)
                <a href="?{{ http_build_query(['all_branches' => $viewAllBranches ? 0 : 1]) }}"
                   class="btn btn-outline-light btn-sm">
                    <i class="fas fa-{{ $viewAllBranches ? 'building' : 'globe' }} me-1"></i>
                    {{ $viewAllBranches ? 'My branch only' : 'All branches' }}
                </a>
            @endif
            <a href="{{ route('admin.stock-take.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to sessions
            </a>
            <a href="{{ route('admin.stock-take.audit') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit log
            </a>
        </div>
    </header>

    {{-- Summary band --}}
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-2">
                    <div class="display-6 text-success">{{ $summary['pass'] }}</div>
                    <div class="small text-muted">Pass</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-2">
                    <div class="display-6 text-warning">{{ $summary['warn'] }}</div>
                    <div class="small text-muted">Warning</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-2">
                    <div class="display-6 text-danger">{{ $summary['fail'] }}</div>
                    <div class="small text-muted">Failure</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-2">
                    <div class="display-6 text-info">{{ $summary['info'] }}</div>
                    <div class="small text-muted">Info</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Sections --}}
    <div class="row g-3">
        @foreach ($sections as $section)
            <div class="col-12 col-lg-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0">
                            <i class="fas {{ $section['icon'] ?? 'fa-list' }} me-1 text-primary"></i>
                            {{ $section['title'] }}
                        </h2>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            @foreach ($section['items'] as $it)
                                <li class="list-group-item d-flex align-items-start gap-2 py-2">
                                    <i class="fas {{ $statusIcon[$it['status']] ?? 'fa-circle' }} mt-1"></i>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between gap-2 flex-wrap">
                                            <strong class="small">{{ $it['title'] }}</strong>
                                            <span class="badge {{ $statusBadge[$it['status']] ?? 'bg-light' }}">{{ strtoupper($it['status']) }}</span>
                                        </div>
                                        <div class="small text-muted">{{ $it['expected'] }}</div>
                                        @if (!empty($it['detail']))
                                            <div class="small fw-semibold">{{ $it['detail'] }}</div>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Actionable list: posted sessions missing GL journals --}}
    @if (!empty($missingSessionJournals))
        <div class="card border-0 shadow-sm mt-3 border-start border-danger border-4">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0 text-danger">
                    <i class="fas fa-triangle-exclamation me-1"></i>
                    Posted sessions missing GL journal ({{ count($missingSessionJournals) }})
                </h2>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Session</th>
                                <th>Date</th>
                                <th class="text-end">Variance value (Tk)</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($missingSessionJournals as $row)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.stock-take.show', $row['id']) }}" class="fw-semibold">
                                            {{ $row['session_code'] }}
                                        </a>
                                    </td>
                                    <td class="small">{{ $row['session_date'] }}</td>
                                    <td class="text-end small">{{ number_format($row['variance_value'], 2) }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.stock-take.show', $row['id']) }}" class="btn btn-outline-danger btn-sm">
                                            <i class="fas fa-magnifying-glass me-1"></i> Investigate
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
