@extends('layouts.admin')

@section('title', 'Branch Demand Audit Trail — ' . $auditData['demand']->demand_code)

@push('css')
<link rel="stylesheet" href="/assets/css/branch-demand.css">
@endpush

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h4 class="mb-1">
                <i class="fas fa-search me-2"></i>Audit Trail — {{ $auditData['demand']->demand_code }}
            </h4>
            <p class="text-muted">Phase 8 — Full traceability for this demand.</p>
        </div>
    </div>

    {{-- Demand Summary --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header"><strong>Demand Summary</strong></div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted" style="width:200px">Demand Code</td>
                            <td><strong>{{ $auditData['demand']->demand_code }}</strong></td>
                            <td class="text-muted" style="width:200px">Status</td>
                            <td><span class="badge bg-{{ $auditData['demand']->status === 'received' ? 'success' : ($auditData['demand']->status === 'pending' ? 'warning' : ($auditData['demand']->is_reversed ? 'danger' : 'secondary')) }}">{{ $auditData['demand']->status }}</span></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Requester (Debtor)</td>
                            <td>{{ $auditData['branch_names'][$auditData['demand']->from_branch_id] ?? 'Unknown' }}</td>
                            <td class="text-muted">Supplier (Creditor)</td>
                            <td>{{ $auditData['branch_names'][$auditData['demand']->to_branch_id] ?? 'Unknown' }}</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Total Value</td>
                            <td>{{ number_format((float) $auditData['demand']->total_value, 2) }}</td>
                            <td class="text-muted">Settlement Amount</td>
                            <td>{{ number_format((float) $auditData['demand']->settlement_amount, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Demand Date</td>
                            <td>{{ $auditData['demand']->demand_date }}</td>
                            <td class="text-muted">Is Reversed</td>
                            <td>{{ $auditData['demand']->is_reversed ? 'Yes' : 'No' }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Anti-Gaming Flags --}}
    @if($auditData['anti_gaming_flags']->count() > 0)
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-warning">
                <div class="card-header bg-warning text-white"><strong><i class="fas fa-exclamation-triangle me-1"></i>Anti-Gaming Flags</strong></div>
                <div class="card-body">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Type</th><th>Details</th></tr></thead>
                        <tbody>
                            @foreach($auditData['anti_gaming_flags'] as $flag)
                            <tr>
                                <td><span class="badge bg-warning text-dark">{{ $flag['type'] }}</span></td>
                                <td>
                                    @if($flag['type'] === 'catalog_below_locked')
                                        Product {{ $flag['product_id'] }}: locked rate {{ $flag['locked_rate'] }} vs current default {{ $flag['current_default'] }} (variance: {{ $flag['variance'] }})
                                    @elseif($flag['type'] === 'stale_outstanding')
                                        Outstanding {{ number_format($flag['outstanding'], 2) }} for {{ $flag['days_outstanding'] }} days
                                    @else
                                        {{ json_encode($flag) }}
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- GL Journal Blocks --}}
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><strong>Creditor Journal</strong></div>
                <div class="card-body">
                    @if($auditData['gl_journal_blocks']['creditor'])
                        <p class="small text-muted mb-2">Journal: {{ $auditData['gl_journal_blocks']['creditor']->journal_code ?? 'N/A' }}</p>
                        @if(isset($auditData['gl_journal_blocks']['creditor']->items))
                        <table class="table table-sm table-hover mb-0">
                            <thead><tr><th>Ledger</th><th>Debit</th><th>Credit</th></tr></thead>
                            <tbody>
                                @foreach($auditData['gl_journal_blocks']['creditor']->items as $item)
                                <tr>
                                    <td>{{ $item->ledger_id }}</td>
                                    <td>{{ number_format((float) $item->debit, 2) }}</td>
                                    <td>{{ number_format((float) $item->credit, 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @endif
                    @else
                        <p class="text-muted">No creditor journal entry found.</p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><strong>Debtor Journal</strong></div>
                <div class="card-body">
                    @if($auditData['gl_journal_blocks']['debtor'])
                        <p class="small text-muted mb-2">Journal: {{ $auditData['gl_journal_blocks']['debtor']->journal_code ?? 'N/A' }}</p>
                        @if(isset($auditData['gl_journal_blocks']['debtor']->items))
                        <table class="table table-sm table-hover mb-0">
                            <thead><tr><th>Ledger</th><th>Debit</th><th>Credit</th></tr></thead>
                            <tbody>
                                @foreach($auditData['gl_journal_blocks']['debtor']->items as $item)
                                <tr>
                                    <td>{{ $item->ledger_id }}</td>
                                    <td>{{ number_format((float) $item->debit, 2) }}</td>
                                    <td>{{ number_format((float) $item->credit, 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @endif
                    @else
                        <p class="text-muted">No debtor journal entry found.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Repricing History --}}
    @if($auditData['repricing_history']->count() > 0)
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header"><strong>Repricing History</strong></div>
                <div class="card-body">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Date</th><th>Original</th><th>New</th><th>Adjustment</th><th>Reason</th><th>Approved By</th></tr></thead>
                        <tbody>
                            @foreach($auditData['repricing_history'] as $rh)
                            <tr>
                                <td>{{ $rh->created_at }}</td>
                                <td>{{ number_format((float) $rh->original_total_value, 2) }}</td>
                                <td>{{ number_format((float) $rh->new_total_value, 2) }}</td>
                                <td class="{{ (float) $rh->adjustment_amount < 0 ? 'text-danger' : 'text-success' }}">{{ number_format((float) $rh->adjustment_amount, 2) }}</td>
                                <td>{{ $rh->reason }}</td>
                                <td>{{ $rh->approved_by ?? 'N/A' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Settlement Trace --}}
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><strong>Money Transfer Settlements</strong></div>
                <div class="card-body">
                    @if($auditData['settlement_trace']['money_transfer']->count() > 0)
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Transfer ID</th><th>Settled</th></tr></thead>
                        <tbody>
                            @foreach($auditData['settlement_trace']['money_transfer'] as $st)
                            <tr>
                                <td>{{ $st->transfer_id }}</td>
                                <td>{{ number_format((float) $st->settled_amount, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @else
                        <p class="text-muted">No money transfer settlements.</p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><strong>Customer Payment Settlements</strong></div>
                <div class="card-body">
                    @if($auditData['settlement_trace']['customer_payment']->count() > 0)
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Payment ID</th><th>Settled</th></tr></thead>
                        <tbody>
                            @foreach($auditData['settlement_trace']['customer_payment'] as $st)
                            <tr>
                                <td>{{ $st->payment_id }}</td>
                                <td>{{ number_format((float) $st->settled_amount, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @else
                        <p class="text-muted">No customer payment settlements.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Audit Log (Chronological) --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header"><strong>Audit Log (Chronological)</strong></div>
                <div class="card-body">
                    @if($auditData['audit_log']->count() > 0)
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Time</th><th>Action</th><th>Actor</th><th>Role</th><th>IP</th><th>Payload</th></tr></thead>
                        <tbody>
                            @foreach($auditData['audit_log'] as $log)
                            <tr>
                                <td class="small">{{ $log->created_at }}</td>
                                <td><span class="badge bg-{{ in_array($log->action, ['reverse','delete','reprice','settlement_reverse']) ? 'danger' : (in_array($log->action, ['create','send']) ? 'success' : 'info') }}">{{ $log->action }}</span></td>
                                <td>{{ $log->actor_id ?? 'System' }}</td>
                                <td>{{ $log->actor_role ?? '-' }}</td>
                                <td class="small">{{ $log->ip_address ?? '-' }}</td>
                                <td>
                                    @php $payload = json_decode($log->payload, true) ?? []; @endphp
                                    @if(!empty($payload))
                                    <button class="btn btn-sm btn-outline-secondary py-0" type="button" data-bs-toggle="collapse" data-bs-target="#payload-{{ $log->id }}">Show</button>
                                    <div class="collapse mt-1" id="payload-{{ $log->id }}">
                                        <pre class="bg-light p-1 rounded small mb-0" style="max-height:150px;overflow-y:auto;">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </div>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @else
                        <p class="text-muted">No audit log entries found for this demand.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <a href="{{ route('admin.branch-demands.show', $auditData['demand']->id) }}" class="btn btn-outline-primary">
                <i class="fas fa-eye me-1"></i> View Demand
            </a>
            <a href="{{ route('admin.branch-demands.checklist') }}" class="btn btn-outline-secondary ms-2">
                <i class="fas fa-clipboard-check me-1"></i> Audit Checklist
            </a>
        </div>
    </div>
</div>
@endsection
