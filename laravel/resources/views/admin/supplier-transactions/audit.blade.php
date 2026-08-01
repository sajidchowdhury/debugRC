@extends('layouts.admin')

@section('content')

@push('css')
<style>
    .md-hero {
        background: linear-gradient(135deg, #0d9488 0%, #059669 100%);
        color: #fff; border-radius: .75rem; padding: 1.25rem 1.5rem;
        display: flex; justify-content: space-between; flex-wrap: wrap;
        gap: 1rem; align-items: center; margin-bottom: 1rem;
    }
    .md-hero h1 { font-size: 1.5rem; margin: 0 0 .25rem; font-weight: 700; }
    .md-hero p  { margin: 0; opacity: .9; font-size: .9rem; }
    .md-hero-actions { display: flex; gap: .5rem; flex-wrap: wrap; }

    .md-audit-summary { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .md-audit-chip {
        display: inline-flex; align-items: center; gap: .35rem;
        padding: .3rem .7rem; border-radius: 1rem; font-size: .8rem; font-weight: 600;
        background: #f1f5f9; color: #475569;
    }
    .md-audit-chip.created { background: #dcfce7; color: #166534; }
    .md-audit-chip.reversed { background: #fee2e2; color: #991b1b; }
    .md-audit-chip.other { background: #fef3c7; color: #92400e; }

    .md-panel { background: #fff; border: 1px solid #e7eaf0; border-radius: .65rem; box-shadow: 0 1px 2px rgba(15,23,42,.04); overflow: hidden; }
    .md-panel-body { padding: 0; }
    .md-table th { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: #64748b; padding: .75rem 1rem; }
    .md-table td { padding: .65rem 1rem; vertical-align: middle; }
    .md-audit-action { display: inline-block; padding: .15rem .55rem; border-radius: 1rem; font-size: .75rem; font-weight: 600; }
    .md-audit-action.created   { background: #dcfce7; color: #166534; }
    .md-audit-action.reversed  { background: #fee2e2; color: #991b1b; }
    .md-audit-action.other     { background: #fef3c7; color: #92400e; }
    .md-audit-meta-foot { padding: .65rem 1rem; border-top: 1px solid #eef2f6; background: #fafbfd; font-size: .8rem; color: #6b7280; }
</style>
@endpush

@php
    $countCreated = $countReversed = $countOther = 0;
    foreach ($logs as $log) {
        $action = (string) ($log->action ?? '');
        if (str_contains($action, 'created'))       $countCreated++;
        elseif (str_contains($action, 'reversed'))  $countReversed++;
        else                                        $countOther++;
    }
@endphp

<div class="md-hero">
    <div>
        <h1><i class="fas fa-clock-rotate-left me-2"></i>Supplier payment audit trail</h1>
        <p>Creates, reversals, and other events — recent supplier payment activity from <code>user_audit_log</code>.</p>
    </div>
    <div class="md-hero-actions">
        <a href="{{ route('admin.supplier-transactions.create', ['transaction_type' => 'payment']) }}" class="btn btn-outline-light btn-sm">
            <i class="fas fa-plus me-1"></i> New payment
        </a>
        <a href="{{ route('admin.supplier-transactions.index') }}" class="btn btn-light btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Payments list
        </a>
    </div>
</div>

<div class="md-audit-summary">
    <span class="md-audit-chip"><i class="fas fa-list"></i> {{ $logs->count() }} entries</span>
    <span class="md-audit-chip created"><i class="fas fa-plus"></i> {{ $countCreated }} created</span>
    <span class="md-audit-chip reversed"><i class="fas fa-rotate-left"></i> {{ $countReversed }} reversed</span>
    <span class="md-audit-chip other"><i class="fas fa-ellipsis"></i> {{ $countOther }} other</span>
</div>

<div class="md-panel">
    <div class="md-panel-body">
        <div class="table-responsive">
            <table class="table table-borderless align-middle mb-0 md-table" id="auditTable">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Payment ID</th>
                        <th>Details</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        @php
                            $action     = (string) ($log->action ?? '');
                            $actionCls  = str_contains($action, 'created')  ? 'created'
                                        : (str_contains($action, 'reversed') ? 'reversed'
                                        : 'other');
                            $details    = json_decode($log->details ?? '{}', true) ?: [];
                            $recordId   = $details['record_id'] ?? $details['payment_id'] ?? null;
                            $paymentCode = $details['payment_code'] ?? null;
                            $transactionType = $details['transaction_type'] ?? null;
                            $amount     = $details['amount'] ?? null;
                            $reason     = $details['reason'] ?? null;
                            $journalEntryId = $details['journal_entry_id'] ?? null;
                        @endphp
                        <tr>
                            <td><small class="text-nowrap">{{ $log->created_at ?? '' }}</small></td>
                            <td>{{ $log->user_id ?? '—' }}</td>
                            <td><span class="md-audit-action {{ $actionCls }}">{{ $action }}</span></td>
                            <td>
                                @if (is_numeric($recordId) && $recordId > 0)
                                    <a href="{{ route('admin.supplier-transactions.show', ['id' => (int) $recordId]) }}" class="text-decoration-none">
                                        #{{ (int) $recordId }}
                                    </a>
                                    @if ($paymentCode)
                                        <div class="small text-muted">{{ $paymentCode }}</div>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if ($transactionType || $amount || $reason || $journalEntryId)
                                    <details>
                                        <summary class="text-muted small">
                                            @if ($paymentCode){{ $paymentCode }}@endif
                                            @if ($transactionType)<span class="badge bg-light text-dark ms-1">{{ $transactionType }}</span>@endif
                                        </summary>
                                        <div class="mt-1">
                                            @if ($amount)
                                                <div class="small"><strong>Amount:</strong> Tk {{ number_format((float) $amount, 2) }}</div>
                                            @endif
                                            @if ($journalEntryId)
                                                <div class="small"><strong>JE #:</strong> {{ $journalEntryId }}</div>
                                            @endif
                                            @if ($reason)
                                                <div class="small"><strong>Reason:</strong> {{ $reason }}</div>
                                            @endif
                                            <div class="mt-1"><code class="d-block small">{{ json_encode($details, JSON_PRETTY_PRINT) }}</code></div>
                                        </div>
                                    </details>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td><small class="text-muted">{{ $log->ip_address ?? 'unknown' }}</small></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="fas fa-inbox fa-2x mb-2 opacity-50 d-block"></i>
                                No supplier payment audit logs yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="md-audit-meta-foot">
        <i class="fas fa-file-lines me-1"></i>
        Stored in <code>user_audit_log</code> · Filtered by action prefix <code>supplier_payment_%</code> · {{ $logs->count() }} entries shown.
    </div>
</div>

@push('scripts')
<script>
$(function () {
    const hasRows = $('#auditTable tbody tr').length > 0
                 && $('#auditTable tbody tr td').length > 1;
    if (! hasRows) return;

    $('#auditTable').DataTable({
        pageLength: 50,
        order: [[0, 'desc']],
        dom: '<"row mb-2"<"col-sm-6"l><"col-sm-6 text-end"f>>rtip',
        language: {
            emptyTable: 'No supplier payment audit logs found yet.',
            search: 'Filter logs:'
        }
    });
});
</script>
@endpush

@endsection
