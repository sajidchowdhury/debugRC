@extends('layouts.admin')

@section('title', 'Pending Receipt Confirmation')

@push('css')
<link rel="stylesheet" href="/assets/css/branch-demand.css">
@endpush

@section('content')
<div class="bd-demand-app container-fluid py-2">
    {{-- Flash messages --}}
    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-1"></i> {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    @endif
    @if(session('warning'))
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-1"></i> {{ session('warning') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    @endif
    @if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-1"></i>
        <ul class="mb-0">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    @endif

    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0d9488,#059669);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-clipboard-check me-2"></i>Pending Receipt Confirmation</h1>
            <p class="mb-0 small opacity-75">
                Demands where goods have been sent but receipt is not yet confirmed.
                As the <strong>requester</strong>, confirm receipt of goods. As the <strong>supplier</strong>, track which demands are awaiting confirmation.
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

    @php
        $myBranchId = (int) session('branch_id', 0);
    @endphp

    {{-- Info card --}}
    @if($demands->count() > 0)
    <div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <div>
            <strong>{{ $demands->total() }}</strong> demand(s) pending receipt confirmation.
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
                <table class="bd-index-table table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Demand Code</th>
                            <th>Date</th>
                            <th>Requester</th>
                            <th>Supplier</th>
                            <th>Your Role</th>
                            <th>Items</th>
                            <th>Total Value</th>
                            <th>Settlement</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($demands as $demand)
                        @php
                            $isRequester = (int) $demand->from_branch_id === $myBranchId;
                            $isSupplier = (int) $demand->to_branch_id === $myBranchId;
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('admin.branch-demands.show', $demand->id) }}" class="fw-semibold text-decoration-none">
                                    {{ $demand->demand_code }}
                                </a>
                            </td>
                            <td>{{ $demand->demand_date ? $demand->demand_date->format('d M Y') : '-' }}</td>
                            <td>{{ $demand->fromBranch->branch_name ?? 'N/A' }}</td>
                            <td>{{ $demand->toBranch->branch_name ?? 'N/A' }}</td>
                            <td>
                                @if($isRequester)
                                    <span class="badge bg-primary"><i class="fas fa-arrow-down me-1"></i>Requester</span>
                                @elseif($isSupplier)
                                    <span class="badge bg-info"><i class="fas fa-arrow-up me-1"></i>Supplier</span>
                                @endif
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
                            <td class="text-center">
                                @if($demand->status === 'received' && $demand->received_at === null && $isRequester)
                                <form method="POST" action="{{ route('admin.branch-demands.confirm-receipt', $demand->id) }}"
                                      onsubmit="return confirm('Confirm receipt of goods for {{ $demand->demand_code }}? This action cannot be undone.')">
                                    @csrf
                                    <button type="submit" class="btn btn-success btn-sm">
                                        <i class="fas fa-check me-1"></i> Confirm Receipt
                                    </button>
                                </form>
                                @elseif($demand->status === 'received' && $demand->received_at === null && $isSupplier)
                                <span class="badge bg-warning text-dark">
                                    <i class="fas fa-clock me-1"></i> Waiting for Confirmation
                                </span>
                                @else
                                <span class="text-muted small">-</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="bd-empty-state text-center text-muted py-4">
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
