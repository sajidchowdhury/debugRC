@extends('layouts.admin')
@section('title', $title)

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="fas fa-file-upload me-2"></i> Import Bank Statement</h4>
        <a href="{{ route('admin.bank-reconciliation.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>

    {{-- Instructions --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light py-2">
            <h6 class="mb-0"><i class="fas fa-info-circle me-1"></i> CSV Format</h6>
        </div>
        <div class="card-body">
            <p class="mb-1">Upload a CSV file with the following columns. The first row must be a header row.</p>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Column</th>
                            <th>Required</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><code>Date</code></td><td>Yes</td><td>Transaction date (YYYY-MM-DD or DD/MM/YYYY)</td></tr>
                        <tr><td><code>Description</code></td><td>No</td><td>Transaction description / particulars</td></tr>
                        <tr><td><code>Reference</code></td><td>No</td><td>Cheque number or reference</td></tr>
                        <tr><td><code>Debit</code></td><td>No*</td><td>Withdrawal amount (money out)</td></tr>
                        <tr><td><code>Credit</code></td><td>No*</td><td>Deposit amount (money in)</td></tr>
                        <tr><td><code>Balance</code></td><td>No</td><td>Running balance from statement</td></tr>
                    </tbody>
                </table>
            </div>
            <small class="text-muted mt-2 d-block">* Alternatively, use a single <code>Amount</code> column (positive = deposit, negative = withdrawal).</small>
        </div>
    </div>

    {{-- Import to existing reconciliation --}}
    @if($reconciliations->count() > 0)
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light py-2">
            <h6 class="mb-0"><i class="fas fa-upload me-1"></i> Import to Existing Reconciliation</h6>
        </div>
        <div class="card-body">
            @foreach($reconciliations as $recon)
            <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                <div>
                    <strong>{{ $recon->reconciliation_code }}</strong> —
                    {{ $recon->bank?->bank_name }} ({{ $recon->bank?->account_number }})
                    <br>
                    <small class="text-muted">{{ $recon->period_from->format('d M Y') }} — {{ $recon->period_to->format('d M Y') }}</small>
                </div>
                <form method="POST" action="{{ route('admin.bank-reconciliation.import-statement', $recon) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="input-group input-group-sm" style="width: 320px;">
                        <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-upload me-1"></i> Import
                        </button>
                    </div>
                </form>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Create new reconciliation + import --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-light py-2">
            <h6 class="mb-0"><i class="fas fa-plus me-1"></i> Or Create New Reconciliation First</h6>
        </div>
        <div class="card-body">
            <a href="{{ route('admin.bank-reconciliation.create') }}" class="btn btn-primary btn-sm">
                <i class="fas fa-plus me-1"></i> New Reconciliation
            </a>
            <small class="text-muted ms-2">Create a reconciliation first, then import your bank statement from the reconciliation page.</small>
        </div>
    </div>
</div>
@endsection
