@extends('layouts.admin')

@section('content')
@php
    $t = $transfer; // alias for brevity
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#6d28d9,#7c3aed);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-magnifying-glass-chart me-2"></i>{{ $title }}
            </h1>
            <p class="mb-0 small opacity-75">
                Per-transfer audit checks and event history
            </p>
        </div>
        <div>
            <a href="{{ route('admin.warehouse-transfers.show', $t->id) }}" class="btn btn-sm btn-light">
                <i class="fas fa-arrow-left me-1"></i>Back to Transfer
            </a>
            <a href="{{ route('admin.warehouse-transfers.checklist') }}" class="btn btn-sm btn-outline-light ms-1">
                <i class="fas fa-clipboard-check me-1"></i>All Checks
            </a>
        </div>
    </header>

    {{-- Transfer summary --}}
    <div class="card mb-4">
        <div class="card-header bg-light">
            <strong>Transfer Summary</strong>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:140px">Transfer Code</td><td><strong>{{ $t->transfer_code }}</strong></td></tr>
                        <tr><td class="text-muted">Status</td><td>{{ $t->status }}</td></tr>
                        <tr><td class="text-muted">From Warehouse</td><td>{{ $t->fromWarehouse?->warehouse_name ?? 'N/A' }}</td></tr>
                        <tr><td class="text-muted">To Warehouse</td><td>{{ $t->toWarehouse?->warehouse_name ?? 'N/A' }}</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:140px">From Branch</td><td>{{ $t->fromBranch?->branch_name ?? 'N/A' }}</td></tr>
                        <tr><td class="text-muted">To Branch</td><td>{{ $t->toBranch?->branch_name ?? 'N/A' }}</td></tr>
                        <tr><td class="text-muted">Is Reversed</td><td>{{ $t->is_reversed ? 'Yes' : 'No' }}</td></tr>
                        <tr><td class="text-muted">Branch Demand</td><td>{{ $t->branch_demand_id ? 'Demand #' . $t->branch_demand_id : 'N/A' }}</td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Audit checks --}}
    <div class="card mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <strong><i class="fas fa-clipboard-check me-2"></i>Audit Checks</strong>
            <span class="badge bg-secondary">
                {{ $checks['summary']['pass'] ?? 0 }} pass ·
                {{ $checks['summary']['warn'] ?? 0 }} warn ·
                {{ $checks['summary']['fail'] ?? 0 }} fail ·
                {{ $checks['summary']['info'] ?? 0 }} info
            </span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr>
                    <th style="width:40px"></th>
                    <th>Check</th>
                    <th>Expected</th>
                    <th>Result</th>
                </tr></thead>
                <tbody>
                    @forelse ($checks['items'] ?? [] as $item)
                        <tr>
                            <td class="text-center">
                                @if ($item['status'] === 'pass')
                                    <i class="fas fa-check-circle text-success"></i>
                                @elseif ($item['status'] === 'warn')
                                    <i class="fas fa-exclamation-triangle text-warning"></i>
                                @elseif ($item['status'] === 'fail')
                                    <i class="fas fa-times-circle text-danger"></i>
                                @else
                                    <i class="fas fa-info-circle text-info"></i>
                                @endif
                            </td>
                            <td>
                                @if ($item['type'] === 'auto')
                                    <span class="badge bg-secondary-subtle text-secondary me-1">auto</span>
                                @else
                                    <span class="badge bg-light text-muted me-1">ref</span>
                                @endif
                                {{ $item['title'] }}
                            </td>
                            <td class="text-muted small">{{ $item['expected'] }}</td>
                            <td>{{ $item['detail'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted">No checks available.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Audit event history --}}
    <div class="card mb-4">
        <div class="card-header bg-light">
            <strong><i class="fas fa-clock-rotate-left me-2"></i>Audit Event History</strong>
        </div>
        <div class="card-body p-0">
            @forelse ($auditEvents as $event)
                <div class="d-flex align-items-center px-3 py-2 border-bottom">
                    <div class="me-3">
                        @if ($event->action === 'transfer_created')
                            <span class="badge bg-warning-subtle text-warning"><i class="fas fa-plus me-1"></i>Created</span>
                        @elseif ($event->action === 'transfer_confirmed')
                            <span class="badge bg-success-subtle text-success"><i class="fas fa-check me-1"></i>Confirmed</span>
                        @elseif ($event->action === 'transfer_cancelled')
                            <span class="badge bg-danger-subtle text-danger"><i class="fas fa-ban me-1"></i>Cancelled</span>
                        @else
                            <span class="badge bg-light text-dark">{{ $event->action }}</span>
                        @endif
                    </div>
                    <div class="flex-grow-1">
                        @php
                            $details = json_decode($event->details, true);
                        @endphp
                        <div class="small">
                            @if ($event->user_id)
                                By <strong>User #{{ $event->user_id }}</strong>
                            @else
                                By <strong>System</strong>
                            @endif
                            — {{ $event->created_at }}
                        </div>
                        @if ($details)
                            <div class="small text-muted">
                                @if (isset($details['previous_status']))
                                    {{ $details['previous_status'] }} → {{ $details['status'] ?? 'cancelled' }}
                                    @if (isset($details['reason']) && $details['reason'])
                                        · Reason: {{ $details['reason'] }}
                                    @endif
                                @elseif (isset($details['status']))
                                    Status: {{ $details['status'] }}
                                    @if (isset($details['total_amount']))
                                        · Amount: {{ number_format($details['total_amount'], 2) }}
                                    @endif
                                    @if (isset($details['items_count']))
                                        · Items: {{ $details['items_count'] }}
                                    @endif
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox me-1"></i>No audit events recorded for this transfer yet.
                </div>
            @endforelse
        </div>
    </div>
</div>
@endsection
