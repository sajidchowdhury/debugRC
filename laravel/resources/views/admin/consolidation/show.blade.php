@extends('layouts.admin')

@section('title', "Consolidation Run — Remote Center ERP")

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.consolidation.index') }}">Consolidation</a></li>
                    <li class="breadcrumb-item active">{{ $run->run_code }}</li>
                </ol>
            </nav>
            <div class="d-flex justify-content-between align-items-center">
                <h4 class="mb-0">
                    <i class="fas fa-layer-group me-2"></i>
                    {{ $run->run_code }} — {{ $run->name }}
                </h4>
                <div class="d-flex gap-2">
                    @php
                        $statusClass = match($run->status) {
                            'draft' => 'bg-warning text-dark',
                            'posted' => 'bg-success',
                            'reversed' => 'bg-secondary',
                            default => 'bg-light text-dark'
                        };
                    @endphp
                    <span class="badge {{ $statusClass }} fs-6">{{ ucfirst($run->status) }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Run Details --}}
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Run Details</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="text-muted small">Period</div>
                            <div>{{ $run->period_from->format('d M Y') }} — {{ $run->period_to->format('d M Y') }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Fiscal Year</div>
                            <div>{{ $run->fiscalYear?->fiscal_year_code ?? 'N/A' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Created</div>
                            <div>{{ $run->created_at->format('d M Y H:i') }} by {{ $run->creator?->name ?? 'System' }}</div>
                        </div>
                    </div>
                    @if($run->posted_at)
                    <div class="row mt-2">
                        <div class="col-md-4">
                            <div class="text-muted small">Posted</div>
                            <div>{{ $run->posted_at->format('d M Y H:i') }} by {{ $run->poster?->name ?? 'System' }}</div>
                        </div>
                    </div>
                    @endif
                    @if($run->notes)
                    <div class="row mt-2">
                        <div class="col-12">
                            <div class="text-muted small">Notes</div>
                            <div>{{ $run->notes }}</div>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Elimination Summary</h5>
                </div>
                <div class="card-body">
                    @php $summary = $run->elimination_summary ?? []; @endphp
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total Entries</span>
                        <span class="fw-bold">{{ $summary['total_entries'] ?? 0 }}</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total Elimination Amount</span>
                        <span class="fw-bold">{{ number_format($summary['total_amount'] ?? 0, 2) }}</span>
                    </div>
                    @foreach($summary as $key => $value)
                        @if(!in_array($key, ['total_entries', 'total_amount']) && is_array($value))
                        <div class="d-flex justify-content-between text-muted small mb-1">
                            <span>{{ ucfirst($key) }} ({{ $value['count'] ?? 0 }} entries)</span>
                            <span>{{ number_format($value['total_amount'] ?? 0, 2) }}</span>
                        </div>
                        @endif
                    @endforeach
                </div>
                <div class="card-footer">
                    @if($run->isDraft())
                    <form method="POST" action="{{ route('admin.consolidation.post', $run) }}">
                        @csrf
                        <button type="submit" class="btn btn-success w-100"
                                onclick="return confirm('Post this consolidation run? This will create elimination journal entries in the GL.')">
                            <i class="fas fa-check me-1"></i> Post Consolidation
                        </button>
                    </form>
                    @elseif($run->isPosted())
                    <button type="button" class="btn btn-outline-danger w-100" data-bs-toggle="modal" data-bs-target="#reverseModal">
                        <i class="fas fa-undo me-1"></i> Reverse Consolidation
                    </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Elimination Entries Table --}}
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Elimination Entries</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Rule</th>
                            <th>Branch Pair</th>
                            <th>Debit Ledger</th>
                            <th>Credit Ledger</th>
                            <th class="text-end">Amount</th>
                            <th>Description</th>
                            <th>JE</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($run->eliminationEntries as $i => $entry)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td>
                                <span class="badge bg-info">{{ $entry->eliminationRule?->rule_code ?? 'N/A' }}</span>
                                <span class="small text-muted">{{ $entry->eliminationRule?->rule_type ?? '' }}</span>
                            </td>
                            <td>
                                @if($entry->from_branch_id && $entry->to_branch_id)
                                {{ $entry->fromBranch?->branch_name ?? 'N/A' }}
                                <i class="fas fa-arrows-alt-h mx-1 text-muted"></i>
                                {{ $entry->toBranch?->branch_name ?? 'N/A' }}
                                @else
                                <span class="text-muted">Aggregate</span>
                                @endif
                            </td>
                            <td>
                                <span class="small">{{ $entry->debitLedger?->ledger_code }}</span>
                                {{ $entry->debitLedger?->ledger_name ?? 'N/A' }}
                            </td>
                            <td>
                                <span class="small">{{ $entry->creditLedger?->ledger_code }}</span>
                                {{ $entry->creditLedger?->ledger_name ?? 'N/A' }}
                            </td>
                            <td class="text-end fw-bold">{{ number_format($entry->elimination_amount, 2) }}</td>
                            <td class="small text-muted">{{ Str::limit($entry->description, 60) }}</td>
                            <td>
                                @if($entry->journal_entry_id)
                                <a href="{{ route('admin.manual-journals.index') }}" class="small">
                                    {{ $entry->journalEntry?->entry_no ?? 'N/A' }}
                                </a>
                                @else
                                <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                No elimination entries calculated for this run.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                    @if($run->eliminationEntries->count() > 0)
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="5" class="text-end">Total Elimination Amount:</th>
                            <th class="text-end">{{ number_format($run->getTotalEliminationAmount(), 2) }}</th>
                            <th colspan="2"></th>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Reverse Modal --}}
@if($run->isPosted())
<div class="modal fade" id="reverseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.consolidation.reverse', $run) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Reverse Consolidation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to reverse consolidation run <strong>{{ $run->run_code }}</strong>?</p>
                    <p class="text-muted">This will reverse all elimination journal entries.</p>
                    <div class="mb-3">
                        <label for="reason" class="form-label">Reason <span class="text-danger">*</span></label>
                        <textarea name="reason" id="reason" class="form-control" rows="3" required
                                  placeholder="Enter the reason for reversing this consolidation"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-undo me-1"></i> Reverse Consolidation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endsection
