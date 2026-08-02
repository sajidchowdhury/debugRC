@extends('layouts.admin')
@section('title', $title)

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">
            <i class="fas fa-university me-2"></i>
            {{ $reconciliation->reconciliation_code }}
            <span class="badge bg-{{ ['draft'=>'secondary','in_progress'=>'info','completed'=>'success','reversed'=>'warning'][$reconciliation->status] ?? 'secondary' }} ms-2">
                {{ ucfirst(str_replace('_', ' ', $reconciliation->status)) }}
            </span>
        </h4>
        <div>
            <a href="{{ route('admin.bank-reconciliation.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <small class="text-muted">Bank</small><br>
                    <strong>{{ $reconciliation->bank?->bank_name }}</strong><br>
                    <small class="text-muted">{{ $reconciliation->bank?->account_number }}</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <small class="text-muted">Period</small><br>
                    <strong>{{ $reconciliation->period_from->format('d M Y') }}</strong><br>
                    <small class="text-muted">to {{ $reconciliation->period_to->format('d M Y') }}</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <small class="text-muted">Statement Balance</small><br>
                    <strong>{{ number_format($reconciliation->statement_closing_balance, 2) }}</strong>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2 text-center">
                    <small class="text-muted">Difference</small><br>
                    @if(abs($reconciliation->difference) < 0.01)
                        <strong class="text-success">{{ number_format(0, 2) }}</strong>
                    @else
                        <strong class="text-danger">{{ number_format($reconciliation->difference, 2) }}</strong>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Progress Bar --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <small class="text-muted">Match Progress</small>
                <small class="text-muted">{{ $reconciliation->matched_lines }} / {{ $reconciliation->total_statement_lines }} lines matched</small>
            </div>
            <div class="progress" style="height: 20px;">
                <div class="progress-bar {{ $reconciliation->getMatchProgressPct() >= 100 ? 'bg-success' : 'bg-info' }}"
                     style="width: {{ $reconciliation->getMatchProgressPct() }}%">
                    {{ $reconciliation->getMatchProgressPct() }}%
                </div>
            </div>
        </div>
    </div>

    {{-- Import / Actions --}}
    @if($reconciliation->isEditable())
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <form method="POST" action="{{ route('admin.bank-reconciliation.import-statement', $reconciliation) }}" enctype="multipart/form-data" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-auto">
                            <label class="form-label small mb-1">Import CSV</label>
                            <input type="file" name="csv_file" class="form-control form-control-sm" accept=".csv,.txt" required>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-upload me-1"></i> Import
                            </button>
                        </div>
                    </form>
                </div>
                <div class="col-md-6 text-end">
                    <form method="POST" action="{{ route('admin.bank-reconciliation.auto-match', $reconciliation) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-magic me-1"></i> Auto-Match
                        </button>
                    </form>
                    @if($reconciliation->matched_lines > 0)
                    <form method="POST" action="{{ route('admin.bank-reconciliation.complete', $reconciliation) }}" class="d-inline"
                          onsubmit="return confirm('Complete this reconciliation? This action cannot be undone.')">
                        @csrf @method('PATCH')
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="fas fa-check me-1"></i> Complete
                        </button>
                    </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Reverse (completed only) --}}
    @if($reconciliation->isCompleted())
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#reverseForm">
                <i class="fas fa-undo me-1"></i> Reverse Reconciliation
            </button>
            <div class="collapse mt-2" id="reverseForm">
                <form method="POST" action="{{ route('admin.bank-reconciliation.reverse', $reconciliation) }}">
                    @csrf @method('PATCH')
                    <div class="row g-2 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label small">Reason for reversal <span class="text-danger">*</span></label>
                            <input type="text" name="reason" class="form-control form-control-sm" required maxlength="500" placeholder="Enter reason...">
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure? This will un-reconcile all matched entries.')">
                                <i class="fas fa-undo me-1"></i> Confirm Reverse
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif

    {{-- Statement Lines Table --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="fas fa-file-alt me-1"></i> Bank Statement Lines</h6>
            <div>
                <span class="badge bg-success">{{ $matchedLines->count() }} matched</span>
                <span class="badge bg-info">{{ $suggestedLines->count() }} suggested</span>
                <span class="badge bg-warning text-dark">{{ $unmatchedLines->count() }} unmatched</span>
                <span class="badge bg-secondary">{{ $excludedLines->count() }} excluded</span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:30px">#</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Reference</th>
                            <th class="text-end">Debit</th>
                            <th class="text-end">Credit</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($reconciliation->statementLines as $line)
                        <tr class="{{ $line->match_status === 'matched' ? 'table-success' : ($line->match_status === 'suggested' ? 'table-info' : ($line->match_status === 'unmatched' ? 'table-warning' : '')) }}">
                            <td class="text-muted">{{ $line->line_number }}</td>
                            <td>{{ $line->transaction_date?->format('d M Y') }}</td>
                            <td>{{ $line->description }}</td>
                            <td>{{ $line->reference }}</td>
                            <td class="text-end">{{ $line->debit > 0 ? number_format($line->debit, 2) : '' }}</td>
                            <td class="text-end">{{ $line->credit > 0 ? number_format($line->credit, 2) : '' }}</td>
                            <td>
                                <span class="badge bg-{{ ['unmatched'=>'warning text-dark','suggested'=>'info','matched'=>'success','excluded'=>'secondary'][$line->match_status] ?? 'secondary' }}">
                                    {{ ucfirst($line->match_status) }}
                                </span>
                            </td>
                            <td>
                                @if($reconciliation->isEditable())
                                    @if($line->match_status === 'matched' || $line->match_status === 'suggested')
                                        <form method="POST" action="{{ route('admin.bank-reconciliation.unmatch', $reconciliation) }}" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="statement_line_id" value="{{ $line->id }}">
                                            <button type="submit" class="btn btn-outline-warning btn-sm py-0 px-1" title="Unmatch" onclick="return confirm('Unmatch this line?')">
                                                <i class="fas fa-unlink"></i>
                                            </button>
                                        </form>
                                    @endif
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Unreconciled System Entries --}}
    @if($reconciliation->isEditable() && $unreconciledSystemEntries->count() > 0)
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light py-2">
            <h6 class="mb-0"><i class="fas fa-book me-1"></i> Unreconciled System Entries ({{ $unreconciledSystemEntries->count() }})</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Entry #</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Source</th>
                            <th class="text-end">Debit</th>
                            <th class="text-end">Credit</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($unreconciledSystemEntries as $entry)
                        <tr>
                            <td>{{ $entry->entry_no }}</td>
                            <td>{{ \Carbon\Carbon::parse($entry->entry_date)->format('d M Y') }}</td>
                            <td>{{ $entry->entry_description }}</td>
                            <td><span class="badge bg-secondary">{{ $entry->entry_source }}</span></td>
                            <td class="text-end">{{ $entry->debit > 0 ? number_format($entry->debit, 2) : '' }}</td>
                            <td class="text-end">{{ $entry->credit > 0 ? number_format($entry->credit, 2) : '' }}</td>
                            <td>
                                <button type="button" class="btn btn-outline-primary btn-sm py-0 px-1"
                                        onclick="openMatchModal({{ $entry->id }}, '{{ $entry->entry_no }}', {{ $entry->debit }}, {{ $entry->credit }})">
                                    <i class="fas fa-link"></i> Match
                                </button>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    {{-- Manual Match Modal --}}
    @if($reconciliation->isEditable())
    <div class="modal fade" id="matchModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('admin.bank-reconciliation.manual-match', $reconciliation) }}">
                    @csrf
                    <div class="modal-header py-2">
                        <h6 class="modal-title">Match System Entry</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-2">
                            <strong>System Entry:</strong> <span id="matchEntryNo"></span>
                            (<span id="matchEntryDebit"></span> Dr / <span id="matchEntryCredit"></span> Cr)
                        </div>
                        <input type="hidden" name="journal_line_id" id="matchJournalLineId">
                        <label class="form-label small">Select Statement Line to Match</label>
                        <select name="statement_line_id" class="form-select form-select-sm" required>
                            <option value="">— Select Statement Line —</option>
                            @foreach($unmatchedLines as $line)
                                <option value="{{ $line->id }}">
                                    #{{ $line->line_number }} — {{ $line->transaction_date?->format('d M') }} —
                                    {{ $line->description }} —
                                    @if($line->debit > 0) Dr {{ number_format($line->debit, 2) }} @endif
                                    @if($line->credit > 0) Cr {{ number_format($line->credit, 2) }} @endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-link me-1"></i> Match
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif
</div>

@section('scripts')
<script>
function openMatchModal(journalLineId, entryNo, debit, credit) {
    document.getElementById('matchJournalLineId').value = journalLineId;
    document.getElementById('matchEntryNo').textContent = entryNo;
    document.getElementById('matchEntryDebit').textContent = parseFloat(debit).toFixed(2);
    document.getElementById('matchEntryCredit').textContent = parseFloat(credit).toFixed(2);
    new bootstrap.Modal(document.getElementById('matchModal')).show();
}
</script>
@endsection
@endsection
