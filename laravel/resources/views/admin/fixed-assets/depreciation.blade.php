@extends('layouts.admin')

@section('title', $title)

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-chart-line me-2"></i>Asset Depreciation</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.fixed-assets.index') }}">Fixed Assets</a></li>
                    <li class="breadcrumb-item active">Depreciation</li>
                </ol>
            </nav>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-muted small">Pending Schedules</div>
                    <h4 class="mb-0 text-warning">{{ $pendingCount }}</h4>
                    <div class="text-muted small">৳ {{ number_format($pendingAmount, 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-muted small">Posted Entries</div>
                    <h4 class="mb-0 text-success">{{ $postedCount }}</h4>
                </div>
            </div>
        </div>
    </div>

    {{-- Generate & Post Form --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small">Period From</label>
                    <input type="date" id="periodFrom" class="form-control form-control-sm" value="{{ now()->startOfMonth()->format('Y-m-d') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Period To</label>
                    <input type="date" id="periodTo" class="form-control form-control-sm" value="{{ now()->endOfMonth()->format('Y-m-d') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Branch</label>
                    <select id="branchFilter" class="form-select form-select-sm">
                        <option value="">All Branches</option>
                        @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->branch_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <form method="POST" action="{{ route('admin.fixed-assets.generate-depreciation') }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="period_from" id="genPeriodFrom">
                        <input type="hidden" name="period_to" id="genPeriodTo">
                        <input type="hidden" name="branch_id" id="genBranchId">
                        <button type="submit" class="btn btn-sm btn-outline-primary" onclick="populateGenForm()">
                            <i class="fas fa-cog me-1"></i> Generate Schedules
                        </button>
                    </form>
                    <form method="POST" action="{{ route('admin.fixed-assets.post-depreciation') }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="period_from" id="postPeriodFrom">
                        <input type="hidden" name="period_to" id="postPeriodTo">
                        <input type="hidden" name="branch_id" id="postBranchId">
                        <button type="submit" class="btn btn-sm btn-success" onclick="populatePostForm()" id="postBtn">
                            <i class="fas fa-check me-1"></i> Post All Pending
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.fixed-assets.depreciation') }}" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="posted" {{ request('status') === 'posted' ? 'selected' : '' }}>Posted</option>
                        <option value="reversed" {{ request('status') === 'reversed' ? 'selected' : '' }}>Reversed</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Period From</label>
                    <input type="date" name="period_from" class="form-control form-control-sm" value="{{ request('period_from') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Period To</label>
                    <input type="date" name="period_to" class="form-control form-control-sm" value="{{ request('period_to') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" {{ request('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->branch_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-search me-1"></i>Filter</button>
                    <a href="{{ route('admin.fixed-assets.depreciation') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Schedules Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Asset Code</th>
                            <th>Period</th>
                            <th>Method</th>
                            <th class="text-end">Opening BV</th>
                            <th class="text-end">Depreciation</th>
                            <th class="text-end">Closing BV</th>
                            <th>Status</th>
                            <th>Branch</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($schedules as $schedule)
                        <tr>
                            <td>
                                <a href="{{ route('admin.fixed-assets.show', $schedule->fixedAsset) }}">{{ $schedule->fixedAsset?->asset_code }}</a>
                            </td>
                            <td>{{ $schedule->period_from->format('M Y') }}</td>
                            <td><span class="badge bg-light text-dark">{{ ucfirst(str_replace('_', ' ', $schedule->depreciation_method)) }}</span></td>
                            <td class="text-end">৳ {{ number_format($schedule->opening_book_value, 2) }}</td>
                            <td class="text-end fw-semibold">৳ {{ number_format($schedule->depreciation_amount, 2) }}</td>
                            <td class="text-end">৳ {{ number_format($schedule->closing_book_value, 2) }}</td>
                            <td>
                                @if ($schedule->isPosted())
                                <span class="badge bg-success">Posted</span>
                                @elseif ($schedule->isPending())
                                <span class="badge bg-warning text-dark">Pending</span>
                                @else
                                <span class="badge bg-danger">Reversed</span>
                                @endif
                            </td>
                            <td>{{ $schedule->fixedAsset?->branch?->branch_name ?? '-' }}</td>
                            <td class="text-center">
                                @if ($schedule->isPending())
                                <form method="POST" action="{{ route('admin.fixed-assets.post-single-depreciation', $schedule) }}" class="d-inline">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Post" onclick="return confirm('Post this entry?')">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </form>
                                @elseif ($schedule->isPosted())
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#revModal{{ $schedule->id }}">
                                    <i class="fas fa-undo"></i>
                                </button>
                                @endif
                            </td>
                        </tr>

                        @if ($schedule->isPosted())
                        <div class="modal fade" id="revModal{{ $schedule->id }}" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST" action="{{ route('admin.fixed-assets.reverse-depreciation', $schedule) }}">
                                        @csrf @method('PATCH')
                                        <div class="modal-header">
                                            <h5 class="modal-title">Reverse Depreciation</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p>Reverse depreciation for {{ $schedule->fixedAsset?->asset_code }} ({{ $schedule->period_from->format('M Y') }})?</p>
                                            <label class="form-label">Reason <span class="text-danger">*</span></label>
                                            <input type="text" name="reason" class="form-control" required maxlength="500">
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger">Reverse</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        @endif
                        @empty
                        <tr><td colspan="9" class="text-center text-muted py-4">No depreciation schedules found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            {{ $schedules->withQueryString()->links() }}
        </div>
    </div>
</div>

@push('scripts')
<script>
function populateGenForm() {
    document.getElementById('genPeriodFrom').value = document.getElementById('periodFrom').value;
    document.getElementById('genPeriodTo').value = document.getElementById('periodTo').value;
    document.getElementById('genBranchId').value = document.getElementById('branchFilter').value;
}
function populatePostForm() {
    document.getElementById('postPeriodFrom').value = document.getElementById('periodFrom').value;
    document.getElementById('postPeriodTo').value = document.getElementById('periodTo').value;
    document.getElementById('postBranchId').value = document.getElementById('branchFilter').value;
}
</script>
@endpush
@endsection
