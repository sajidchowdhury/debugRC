@extends('layouts.admin')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1"><i class="fas fa-archive me-2 text-secondary"></i>Historical Archive Search</h2>
            <p class="text-muted mb-0">Search across PostgreSQL (current) + legacy MySQL (archive) — unified results</p>
        </div>
        @if (!$archiveAvailable)
        <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i>Archive MySQL offline</span>
        @else
        <span class="badge bg-success"><i class="fas fa-circle-check me-1"></i>Archive available</span>
        @endif
    </div>

    {{-- Search Form --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.archive.index') }}" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label small mb-0">Search Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="invoice" @selected($type === 'invoice')>Invoices</option>
                        <option value="customer" @selected($type === 'customer')>Customers</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Search Term</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="{{ $search }}"
                           placeholder="Invoice code, customer name, mobile..." style="min-width: 300px;">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>Search</button>
                </div>
            </form>
        </div>
    </div>

    @if ($results && $results->isNotEmpty())
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Results ({{ $results->count() }})
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            @if ($type === 'invoice')
                                <th>Code</th><th>Date</th><th>Customer</th><th>Branch</th>
                                <th class="text-end">Total</th><th class="text-end">Due</th>
                                <th>Status</th><th>Source</th>
                            @elseif ($type === 'customer')
                                <th>Code</th><th>Name</th><th>Mobile</th><th>Address</th>
                                <th class="text-end">Balance</th><th>Source</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($results as $r)
                        <tr>
                            @if ($type === 'invoice')
                                <td>
                                    {{ $r->invoiceCode }}
                                    @if ($r->source === 'archive_mysql')
                                        <i class="fas fa-archive text-muted ms-1" title="From legacy archive"></i>
                                    @endif
                                </td>
                                <td>{{ $r->invoiceDate }}</td>
                                <td>{{ $r->customerName ?? '—' }}</td>
                                <td>{{ $r->branchName ?? '—' }}</td>
                                <td class="text-end">{{ number_format($r->totalAmount, 2) }}</td>
                                <td class="text-end {{ $r->dueAmount > 0 ? 'text-danger' : '' }}">{{ number_format($r->dueAmount, 2) }}</td>
                                <td><span class="badge bg-secondary">{{ $r->status }}</span></td>
                                <td>
                                    @if ($r->source === 'archive_mysql')
                                        <span class="badge bg-warning text-dark">Archive</span>
                                    @else
                                        <span class="badge bg-success">Current</span>
                                    @endif
                                </td>
                            @elseif ($type === 'customer')
                                <td>{{ $r->customerCode }}</td>
                                <td>{{ $r->customerName }}</td>
                                <td>{{ $r->mobile ?? '—' }}</td>
                                <td class="small text-muted">{{ $r->address ?? '—' }}</td>
                                <td class="text-end">{{ number_format($r->balance, 2) }}</td>
                                <td>
                                    @if ($r->source === 'archive_mysql')
                                        <span class="badge bg-warning text-dark">Archive</span>
                                    @else
                                        <span class="badge bg-success">Current</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @elseif ($results && $results->isEmpty())
    <div class="text-center py-5 text-muted">
        <i class="fas fa-search fa-3x mb-3 opacity-25"></i>
        <p>No results found in PostgreSQL or legacy archive for "{{ $search }}"</p>
    </div>
    @else
    <div class="text-center py-5 text-muted">
        <i class="fas fa-archive fa-3x mb-3 opacity-25"></i>
        <p>Search for invoices or customers across both current and archived data.</p>
        <p class="small">Results from PostgreSQL (current) appear first. If none found, the system searches the legacy MySQL archive automatically.</p>
    </div>
    @endif
</div>
@endsection
