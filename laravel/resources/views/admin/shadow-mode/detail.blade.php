@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0f766e,#14b8a6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-search me-2"></i>Comparison Detail #{{ $comparison->id }}</h1>
            <p class="mb-0 small opacity-75">Detailed diff breakdown for transfer {{ $comparison->laravel_transfer_code }}</p>
        </div>
        <div>
            <a href="{{ route('admin.shadow-mode.comparisons') }}" class="btn btn-sm btn-light">
                <i class="fas fa-arrow-left me-1"></i>All Comparisons
            </a>
            <a href="{{ route('admin.warehouse-transfers.show', $comparison->laravel_transfer_id) }}" class="btn btn-sm btn-outline-light ms-1">
                <i class="fas fa-warehouse me-1"></i>View Transfer
            </a>
        </div>
    </header>

    {{-- Status banner --}}
    <div class="card mb-3 border-{{ $comparison->diff_status === 'match' ? 'success' : ($comparison->diff_status === 'diff' ? 'danger' : 'warning') }}">
        <div class="card-body">
            <div class="d-flex align-items-center gap-3">
                <div class="flex-shrink-0">
                    @switch($comparison->diff_status)
                        @case('match')
                            <i class="fas fa-check-circle fa-3x text-success"></i>
                            @break
                        @case('diff')
                            <i class="fas fa-times-circle fa-3x text-danger"></i>
                            @break
                        @case('missing_legacy')
                            <i class="fas fa-question-circle fa-3x text-warning"></i>
                            @break
                        @default
                            <i class="fas fa-exclamation-circle fa-3x text-secondary"></i>
                    @endswitch
                </div>
                <div class="flex-grow-1">
                    <h5>{{ ucfirst($comparison->diff_status) }}</h5>
                    <p class="text-muted mb-0">
                        Transfer <strong>{{ $comparison->laravel_transfer_code }}</strong>
                        &middot; Operation: <strong>{{ $comparison->operation }}</strong>
                        &middot; Mode: <strong>{{ $comparison->mode }}</strong>
                        &middot; Checks: {{ $comparison->match_count }} match / {{ $comparison->diff_count }} diff out of {{ $comparison->total_checks }}
                        &middot; Compared: {{ $comparison->compared_at }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- Comparison metadata --}}
    <div class="card mb-3">
        <div class="card-header"><i class="fas fa-info-circle me-2"></i>Transfer Metadata</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>Laravel Transfer</h6>
                    @if($laravelTransfer)
                    <table class="table table-sm">
                        <tr><td>ID</td><td>{{ $laravelTransfer->id }}</td></tr>
                        <tr><td>Code</td><td>{{ $laravelTransfer->transfer_code }}</td></tr>
                        <tr><td>Date</td><td>{{ $laravelTransfer->transfer_date }}</td></tr>
                        <tr><td>From WH</td><td>{{ $laravelTransfer->from_warehouse_id }}</td></tr>
                        <tr><td>To WH</td><td>{{ $laravelTransfer->to_warehouse_id }}</td></tr>
                        <tr><td>From Branch</td><td>{{ $laravelTransfer->from_branch_id }}</td></tr>
                        <tr><td>To Branch</td><td>{{ $laravelTransfer->to_branch_id }}</td></tr>
                        <tr><td>Status</td><td>{{ $laravelTransfer->status }}</td></tr>
                        <tr><td>Is Reversed</td><td>{{ $laravelTransfer->is_reversed ? 'Yes' : 'No' }}</td></tr>
                    </table>
                    @else
                    <p class="text-muted">Transfer not found in Laravel database.</p>
                    @endif
                </div>
                <div class="col-md-6">
                    <h6>Legacy Transfer</h6>
                    @if($comparison->legacy_transfer_code)
                    <table class="table table-sm">
                        <tr><td>ID</td><td>{{ $comparison->legacy_transfer_id ?? '—' }}</td></tr>
                        <tr><td>Code</td><td>{{ $comparison->legacy_transfer_code }}</td></tr>
                    </table>
                    @else
                    <p class="text-warning"><i class="fas fa-question me-1"></i>No matching legacy transfer found.</p>
                    <p class="text-muted small">This could mean the transfer was created in Laravel but not yet replicated to the legacy system, or the legacy database connection is unavailable.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Diff details --}}
    @if(!empty($diffDetails))
    <div class="card mb-3">
        <div class="card-header"><i class="fas fa-bug me-2"></i>Diff Breakdown</div>
        <div class="card-body">
            @foreach($diffDetails as $scope => $detail)
            <div class="card mb-2 border-{{ (isset($detail['match']) && $detail['match']) || (isset($detail['status']) && $detail['status'] === 'match') ? 'success' : 'danger' }}">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <strong>
                        @if((isset($detail['match']) && $detail['match']) || (isset($detail['status']) && $detail['status'] === 'match'))
                            <i class="fas fa-check text-success me-1"></i>
                        @else
                            <i class="fas fa-times text-danger me-1"></i>
                        @endif
                        {{ ucfirst(str_replace('_', ' ', $scope)) }}
                    </strong>
                    <span class="badge bg-{{ (isset($detail['match']) && $detail['match']) || (isset($detail['status']) && $detail['status'] === 'match') ? 'success' : 'danger' }}">
                        {{ isset($detail['status']) ? $detail['status'] : (isset($detail['match']) ? ($detail['match'] ? 'match' : 'diff') : 'unknown') }}
                    </span>
                </div>
                <div class="card-body py-2">
                    <p class="small mb-1">{{ $detail['detail'] ?? ($detail['details'] ?? 'No detail available.') }}</p>

                    @if(isset($detail['laravel']) && is_string($detail['laravel']))
                    <p class="small">Laravel: <strong>{{ $detail['laravel'] }}</strong> &middot; Legacy: <strong>{{ $detail['legacy'] ?? 'N/A' }}</strong></p>
                    @endif

                    @if(isset($detail['diffs']) && !empty($detail['diffs']))
                    <table class="table table-sm table-bordered mt-2">
                        <thead class="table-light">
                            <tr>
                                @foreach(array_keys($detail['diffs'][0]) as $col)
                                <th>{{ ucfirst(str_replace('_', ' ', $col)) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($detail['diffs'] as $diffRow)
                            <tr class="{{ isset($diffRow['type']) && str_contains($diffRow['type'], 'diff') ? 'table-danger' : 'table-warning' }}">
                                @foreach($diffRow as $val)
                                <td>{{ is_null($val) ? '—' : $val }}</td>
                                @endforeach
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @else
    <div class="card mb-3 border-success">
        <div class="card-body text-center">
            <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
            <h5>No Diffs Detected</h5>
            <p class="text-muted">All comparison checks passed. Laravel and Legacy results match within tolerance thresholds.</p>
        </div>
    </div>
    @endif
</div>
@endsection
