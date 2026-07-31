@extends('layouts.admin')

@section('content')
@php
    $filters = array_merge([
        'date_from' => '',
        'date_to'   => '',
        'status'    => 'all',
        'branch_id' => '',
        'search'    => '',
    ], is_array($filters ?? null) ? $filters : []);

    $stats = array_merge([
        'total'        => 0,
        'drafts'       => 0,
        'posted'       => 0,
        'reversed'     => 0,
        'total_debit'  => 0,
        'total_credit' => 0,
    ], $stats ?? []);

    $statusBadge = function (string $status): string {
        return [
            'draft'    => '<span class="badge bg-secondary"><i class="fas fa-pen me-1"></i>Draft</span>',
            'posted'   => '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Posted</span>',
            'reversed' => '<span class="badge bg-danger"><i class="fas fa-rotate-left me-1"></i>Reversed</span>',
        ][$status] ?? '<span class="badge bg-light text-dark">' . e($status) . '</span>';
    };
@endphp

<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#6366f1,#4f46e5);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-book me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Manual journal entries — accountants' custom Dr/Cr adjustments. Each posted entry hits the GL immediately.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.manual-journals.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New Journal
            </a>
            <a href="{{ route('admin.manual-journals.audit') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-history me-1"></i> Audit
            </a>
        </div>
    </header>

    {{-- Stats cards --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100"><div class="card-body text-center">
                <div class="h4 mb-0 text-primary">{{ number_format((int) $stats['total']) }}</div>
                <div class="text-muted small">Total</div>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100"><div class="card-body text-center">
                <div class="h4 mb-0 text-secondary">{{ number_format((int) $stats['drafts']) }}</div>
                <div class="text-muted small">Drafts</div>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100"><div class="card-body text-center">
                <div class="h4 mb-0 text-success">{{ number_format((int) $stats['posted']) }}</div>
                <div class="text-muted small">Posted</div>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100"><div class="card-body text-center">
                <div class="h4 mb-0 text-danger">{{ number_format((int) $stats['reversed']) }}</div>
                <div class="text-muted small">Reversed</div>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100"><div class="card-body text-center">
                <div class="h6 mb-0 text-success">Tk {{ number_format((float) $stats['total_debit'], 2) }}</div>
                <div class="text-muted small">Posted debits</div>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100"><div class="card-body text-center">
                <div class="h6 mb-0 text-danger">Tk {{ number_format((float) $stats['total_credit'], 2) }}</div>
                <div class="text-muted small">Posted credits</div>
            </div></div>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.manual-journals.index') }}" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="date_from">From date</label>
                    <input type="date" id="date_from" name="date_from" class="form-control form-control-sm" value="{{ $filters['date_from'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="date_to">To date</label>
                    <input type="date" id="date_to" name="date_to" class="form-control form-control-sm" value="{{ $filters['date_to'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="status">Status</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="all"      {{ $filters['status'] === 'all' ? 'selected' : '' }}>All</option>
                        <option value="draft"    {{ $filters['status'] === 'draft' ? 'selected' : '' }}>Draft</option>
                        <option value="posted"   {{ $filters['status'] === 'posted' ? 'selected' : '' }}>Posted</option>
                        <option value="reversed" {{ $filters['status'] === 'reversed' ? 'selected' : '' }}>Reversed</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="branch_id">Branch</label>
                    <select id="branch_id" name="branch_id" class="form-select form-select-sm select2">
                        <option value="">All branches</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" {{ (string) $filters['branch_id'] === (string) $b->id ? 'selected' : '' }}>
                                {{ $b->branch_code }} — {{ $b->branch_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="search">Search</label>
                    <input type="text" id="search" name="search" class="form-control form-control-sm" placeholder="Code or description" value="{{ $filters['search'] }}">
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i> Filter</button>
                    <a href="{{ route('admin.manual-journals.index') }}" class="btn btn-outline-secondary btn-sm"><i class="fas fa-eraser me-1"></i> Clear</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Journals table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0" id="dataTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th class="text-end">Debit (Tk)</th>
                            <th class="text-end">Credit (Tk)</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($journals as $j)
                            <tr class="{{ $j->status === 'reversed' ? 'table-danger' : '' }}">
                                <td>
                                    <a href="{{ route('admin.manual-journals.show', ['id' => $j->id]) }}" class="fw-semibold text-decoration-none">
                                        {{ $j->journal_code }}
                                    </a>
                                </td>
                                <td class="text-nowrap small">{{ \Carbon\Carbon::parse($j->journal_date)->format('d M Y') }}</td>
                                <td>{{ $j->branch ? $j->branch->branch_name : '—' }}</td>
                                <td class="small">{{ $j->description ? \Illuminate\Support\Str::limit($j->description, 60) : '—' }}</td>
                                <td>{!! $statusBadge($j->status) !!}</td>
                                <td class="text-end fw-semibold">Tk {{ number_format((float) $j->total_debit, 2) }}</td>
                                <td class="text-end fw-semibold">Tk {{ number_format((float) $j->total_credit, 2) }}</td>
                                <td class="text-center text-nowrap">
                                    <a href="{{ route('admin.manual-journals.show', ['id' => $j->id]) }}" class="btn btn-sm btn-outline-secondary" title="View details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No journals found. Try adjusting filters or
                                    <a href="{{ route('admin.manual-journals.create') }}">create a new journal</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">{{ $journals->links() }}</div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    var $dataTable = $('#dataTable');
    var hasDataRows = $dataTable.find('tbody tr').filter(function () {
        return $(this).find('td[colspan]').length === 0;
    }).length > 0;

    if (hasDataRows) {
        $dataTable.DataTable({
            paging: false, info: false, ordering: true,
            dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
            language: { search: 'Filter rows:', emptyTable: 'No journals on this page.' }
        });
    }
});
</script>
@endpush
@endsection
