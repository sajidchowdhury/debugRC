@extends('layouts.admin')

@section('content')
@php
    $statusBadge = function (string $status, ?string $receivedAt = null): string {
        if ($status === 'received' && $receivedAt === null) {
            return '<span class="badge bg-warning-subtle text-warning"><i class="fas fa-clock me-1"></i>Awaiting Confirmation</span>';
        }
        if ($status === 'received' && $receivedAt !== null) {
            return '<span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i>Receipt Confirmed</span>';
        }
        return [
            'pending'  => '<span class="badge bg-info-subtle text-info"><i class="fas fa-hourglass-half me-1"></i>Pending</span>',
            'rejected' => '<span class="badge bg-danger-subtle text-danger"><i class="fas fa-ban me-1"></i>Rejected</span>',
            'reversed' => '<span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-rotate-left me-1"></i>Reversed</span>',
        ][$status] ?? '<span class="badge bg-light text-dark">' . e($status) . '</span>';
    };
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0d9488,#059669);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-clipboard-check me-2"></i>Pending Receipt Confirmation</h1>
            <p class="mb-0 small opacity-75">
                Goods have been sent to your branch — confirm receipt so the demand can be reversed if needed.
                Unconfirmed demands <strong>cannot be reversed</strong> until the receiving warehouse manager acknowledges receipt.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.branch-demands.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-list me-1"></i> All Demands
            </a>
            <a href="{{ route('admin.branch-demands.pending') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-inbox me-1"></i> Pending Demands
            </a>
        </div>
    </header>

    {{-- Info card --}}
    @if($demands->count() > 0)
    <div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <div>
            <strong>{{ $demands->total() }}</strong> demand(s) awaiting your receipt confirmation.
            Please verify that the goods have physically arrived at your warehouse before confirming.
        </div>
    </div>
    @else
    <div class="alert alert-success d-flex align-items-center mb-3" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <div>
            No pending receipt confirmations. All received goods have been acknowledged.
        </div>
    </div>
    @endif

    {{-- Demands table --}}
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Demand Code</th>
                            <th>Date</th>
                            <th>From Branch (Supplier)</th>
                            <th>Items</th>
                            <th>Total Value</th>
                            <th>Settlement</th>
                            <th>Status</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($demands as $demand)
                        <tr>
                            <td>
                                <a href="{{ route('admin.branch-demands.show', $demand->id) }}" class="fw-semibold text-decoration-none">
                                    {{ $demand->demand_code }}
                                </a>
                            </td>
                            <td>{{ $demand->demand_date ? $demand->demand_date->format('d M Y') : '-' }}</td>
                            <td>
                                <span class="text-muted small">Supplier:</span>
                                {{ $demand->toBranch->branch_name ?? 'N/A' }}
                            </td>
                            <td>
                                <span class="badge bg-light text-dark">{{ $demand->items->count() }} item(s)</span>
                            </td>
                            <td class="fw-semibold">{{ $demand->total_value ? number_format((float) $demand->total_value, 2) : '-' }}</td>
                            <td>
                                @if($demand->settlement_amount > 0)
                                    <span class="text-success">{{ number_format((float) $demand->settlement_amount, 2) }}</span>
                                @else
                                    <span class="text-muted">0.00</span>
                                @endif
                            </td>
                            <td>{!! $statusBadge($demand->status, $demand->received_at) !!}</td>
                            <td class="text-center">
                                @if($demand->status === 'received' && $demand->received_at === null)
                                <form method="POST" action="{{ route('admin.branch-demands.confirm-receipt', $demand->id) }}"
                                      onsubmit="return confirm('Confirm receipt of goods for {{ $demand->demand_code }}? This action cannot be undone.')">
                                    @csrf
                                    <button type="submit" class="btn btn-success btn-sm">
                                        <i class="fas fa-check me-1"></i> Confirm Receipt
                                    </button>
                                </form>
                                @else
                                <span class="text-muted small">-</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                <i class="fas fa-check-circle me-1"></i> No pending receipt confirmations.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Pagination --}}
    <div class="mt-3">
        {{ $demands->withQueryString()->links() }}
    </div>
</div>
@endsection
