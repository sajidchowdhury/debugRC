@extends('layouts.admin')

@section('content')
@php
    $expense      = $expense ?? null;
    $canReverse   = $canReverse ?? false;
    $glLines      = $expense->journalEntry->lines ?? collect();
    $journalEntry = $expense->journalEntry ?? null;

    $statusBadge = function (bool $large = false) use ($expense): string {
        $cls = $large ? ' fs-5' : ' fs-6';
        if ($expense->is_reversed) {
            return '<span class="badge bg-danger' . $cls . '"><i class="fas fa-rotate-left me-1"></i>Reversed</span>';
        }
        return '<span class="badge bg-success' . $cls . '"><i class="fas fa-check me-1"></i>Active</span>';
    };

    $modeBadge = function (string $mode): string {
        return [
            'cash'            => '<span class="badge bg-secondary fs-6"><i class="fas fa-money-bill me-1"></i>Cash</span>',
            'bank'            => '<span class="badge bg-primary fs-6"><i class="fas fa-university me-1"></i>Bank</span>',
            'mobile_banking' => '<span class="badge bg-info fs-6"><i class="fas fa-mobile-screen me-1"></i>Mobile</span>',
            'cheque'          => '<span class="badge bg-warning text-dark fs-6"><i class="fas fa-money-check me-1"></i>Cheque</span>',
        ][$mode] ?? '<span class="badge bg-light text-dark fs-6">' . e($mode) . '</span>';
    };

    $heroGradient = 'linear-gradient(135deg,#b91c1c,#991b1b)';
    $heroIcon = 'fa-arrow-down';
@endphp

<style>
    .oe-show-page { --oe-primary: #b91c1c; --oe-primary-dark: #991b1b; }

    .oe-show-hero {
        background: linear-gradient(135deg, var(--oe-primary), var(--oe-primary-dark));
        border-radius: 1rem;
        padding: 1.5rem 1.75rem;
        color: #fff;
        box-shadow: 0 8px 32px rgba(185,28,28,0.18);
        margin-bottom: 1.5rem;
    }
    .oe-show-hero h1 { font-size: 1.35rem; font-weight: 700; margin-bottom: 0.15rem; }
    .oe-show-hero .oe-hero-subtitle { font-size: 0.82rem; opacity: 0.85; }
    .oe-show-hero .oe-hero-amount {
        font-size: 2rem; font-weight: 800; font-variant-numeric: tabular-nums;
        line-height: 1.1;
    }

    .oe-section-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 0.875rem;
        box-shadow: 0 1px 4px rgba(15,23,42,0.04);
        margin-bottom: 1.25rem;
        overflow: hidden;
        transition: box-shadow 0.2s;
    }
    .oe-section-card:hover { box-shadow: 0 4px 16px rgba(15,23,42,0.07); }
    .oe-section-header {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 0.75rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .oe-section-header h2 { font-size: 0.88rem; font-weight: 700; margin: 0; color: #0f172a; }
    .oe-section-header .oe-section-icon {
        width: 32px; height: 32px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.85rem; color: #fff;
    }
    .oe-section-body { padding: 1.25rem; }

    .oe-detail-item {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 0.75rem 1rem;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .oe-detail-item:hover { border-color: #cbd5e1; box-shadow: 0 2px 8px rgba(15,23,42,0.04); }
    .oe-detail-label {
        font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em;
        color: #64748b; margin-bottom: 0.2rem; font-weight: 600;
    }
    .oe-detail-value { font-weight: 600; color: #0f172a; font-size: 0.92rem; }

    .oe-detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 1rem;
    }

    .oe-amount-card {
        background: linear-gradient(135deg, var(--oe-primary), var(--oe-primary-dark));
        border-radius: 0.875rem;
        padding: 1.5rem;
        color: #fff;
        text-align: center;
        box-shadow: 0 8px 24px rgba(185,28,28,0.2);
    }
    .oe-amount-card .oe-amount-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.85; }
    .oe-amount-card .oe-amount-value { font-size: 2rem; font-weight: 800; font-variant-numeric: tabular-nums; }
    .oe-amount-card .oe-amount-meta { font-size: 0.78rem; opacity: 0.8; margin-top: 0.25rem; }

    .oe-reversal-banner {
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-left: 4px solid #dc2626;
        border-radius: 0.875rem;
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
    }

    .oe-gl-table th {
        font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.03em;
        color: #64748b; font-weight: 600;
    }
    .oe-gl-table td { font-size: 0.88rem; }
    .oe-gl-table .debit-col { color: #b91c1c; font-weight: 600; }
    .oe-gl-table .credit-col { color: #15803d; font-weight: 600; }

    .oe-reverse-modal-overlay {
        display: none; position: fixed; inset: 0; z-index: 9999;
        background: rgba(0,0,0,0.5); backdrop-filter: blur(4px);
        align-items: center; justify-content: center;
    }
    .oe-reverse-modal-overlay.active { display: flex; }
    .oe-reverse-modal-box {
        background: #fff; border-radius: 1rem; padding: 1.75rem;
        max-width: 480px; width: 90%;
        box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        animation: oe-slideUp 0.25s ease;
    }
    @keyframes oe-slideUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<div class="oe-show-page">

    {{-- ─── Hero ──────────────────────────────────── --}}
    <div class="oe-show-hero d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1><i class="fas {{ $heroIcon }} me-2"></i>Other Expense Details</h1>
            <div class="oe-hero-subtitle">
                {{ $expense->expense_code }} &middot;
                {{ $expense->expense_date ? $expense->expense_date->format('d M Y') : '—' }}
            </div>
            <div class="oe-hero-amount mt-2">Tk {{ number_format((float) $expense->amount, 2) }}</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if($canReverse)
                <button class="btn btn-outline-light btn-sm" onclick="openReverseModal()">
                    <i class="fas fa-undo-alt me-1"></i> Reverse
                </button>
            @endif
            <a href="{{ route('admin.other-expenses.slip', $expense->id) }}" target="_blank" class="btn btn-outline-light btn-sm">
                <i class="fas fa-print me-1"></i> Print Slip
            </a>
            <a href="{{ route('admin.other-expenses.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    {{-- ─── Reversal Banner ──────────────────────────── --}}
    @if($expense->is_reversed)
    <div class="oe-reversal-banner">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-exclamation-triangle text-danger"></i>
            <div>
                <strong class="text-danger">This expense has been reversed</strong>
                <div class="small text-muted">
                    Reversed on {{ $expense->reversed_at ? $expense->reversed_at->format('d M Y, h:i A') : '—' }}
                    @if($expense->reverse_reason)
                        &middot; Reason: {{ $expense->reverse_reason }}
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endif

    <div class="row g-4">
        {{-- ─── Left Column ──────────────────────────── --}}
        <div class="col-lg-8">

            {{-- Expense Details --}}
            <div class="oe-section-card">
                <div class="oe-section-header">
                    <span class="oe-section-icon" style="background:#b91c1c;"><i class="fas fa-file-invoice-dollar"></i></span>
                    <h2>Expense Information</h2>
                    {!! $statusBadge(true) !!}
                </div>
                <div class="oe-section-body">
                    <div class="oe-detail-grid">
                        <div class="oe-detail-item">
                            <div class="oe-detail-label">Expense Code</div>
                            <div class="oe-detail-value" style="font-family:ui-monospace,monospace;">{{ $expense->expense_code }}</div>
                        </div>
                        <div class="oe-detail-item">
                            <div class="oe-detail-label">Expense Type</div>
                            <div class="oe-detail-value">{{ $expense->expense_type ?? 'General' }}</div>
                        </div>
                        <div class="oe-detail-item">
                            <div class="oe-detail-label">Branch</div>
                            <div class="oe-detail-value">{{ $expense->branch->branch_name ?? '—' }}</div>
                        </div>
                        <div class="oe-detail-item">
                            <div class="oe-detail-label">Date</div>
                            <div class="oe-detail-value">{{ $expense->expense_date ? $expense->expense_date->format('d M Y') : '—' }}</div>
                        </div>
                        <div class="oe-detail-item">
                            <div class="oe-detail-label">Payment Mode</div>
                            <div class="oe-detail-value">{!! $modeBadge($expense->payment_mode) !!}</div>
                        </div>
                        @if($expense->bank)
                        <div class="oe-detail-item">
                            <div class="oe-detail-label">Bank</div>
                            <div class="oe-detail-value">{{ $expense->bank->bank_name }}</div>
                        </div>
                        @endif
                        @if($expense->description)
                        <div class="oe-detail-item">
                            <div class="oe-detail-label">Description</div>
                            <div class="oe-detail-value">{{ $expense->description }}</div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- GL Journal Entry --}}
            @if($journalEntry)
            <div class="oe-section-card">
                <div class="oe-section-header">
                    <span class="oe-section-icon" style="background:#1d4ed8;"><i class="fas fa-book"></i></span>
                    <h2>GL Journal Entry</h2>
                    <span class="badge bg-success-subtle text-success ms-auto">
                        <i class="fas fa-check me-1"></i>Balanced
                    </span>
                </div>
                <div class="oe-section-body">
                    <div class="alert alert-info py-2 px-3 mb-3" style="font-size:0.82rem;">
                        <i class="fas fa-info-circle me-1"></i>
                        {{ $expense->getGlDescription() }}
                        &middot; Journal #{{ $journalEntry->entry_no ?? $journalEntry->id }}
                    </div>

                    <table class="table table-sm table-bordered mb-0 oe-gl-table">
                        <thead class="table-light">
                            <tr>
                                <th>Ledger</th>
                                <th>Code</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $totalDr = 0; $totalCr = 0; @endphp
                            @foreach($glLines as $line)
                            <tr>
                                <td>{{ $line->ledger->ledger_name ?? '—' }}</td>
                                <td style="font-family:ui-monospace,monospace; font-size:0.82rem; color:#64748b;">{{ $line->ledger->ledger_code ?? '—' }}</td>
                                <td class="text-end">
                                    @if($line->debit_amount > 0)
                                        <span class="debit-col">{{ number_format($line->debit_amount, 2) }}</span>
                                        @php $totalDr += $line->debit_amount; @endphp
                                    @else
                                        <span class="text-muted">&mdash;</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if($line->credit_amount > 0)
                                        <span class="credit-col">{{ number_format($line->credit_amount, 2) }}</span>
                                        @php $totalCr += $line->credit_amount; @endphp
                                    @else
                                        <span class="text-muted">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                            <tr class="table-light fw-bold">
                                <td colspan="2" class="text-end">Total</td>
                                <td class="text-end debit-col">{{ number_format($totalDr, 2) }}</td>
                                <td class="text-end credit-col">{{ number_format($totalCr, 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            @endif
        </div>

        {{-- ─── Right Column ──────────────────────────── --}}
        <div class="col-lg-4">
            <div class="oe-amount-card mb-4">
                <div class="oe-amount-label">Expense Amount</div>
                <div class="oe-amount-value">Tk {{ number_format((float) $expense->amount, 2) }}</div>
                <div class="oe-amount-meta">
                    @if($expense->payment_mode === 'cash') Paid in Cash
                    @elseif($expense->payment_mode === 'bank') Via Bank Transfer
                    @elseif($expense->payment_mode === 'mobile_banking') Via Mobile Banking
                    @elseif($expense->payment_mode === 'cheque') Via Cheque
                    @else {{ ucfirst($expense->payment_mode) }}
                    @endif
                </div>
            </div>

            {{-- Quick Info --}}
            <div class="oe-section-card">
                <div class="oe-section-header">
                    <span class="oe-section-icon" style="background:#7c3aed;"><i class="fas fa-clock"></i></span>
                    <h2>Quick Info</h2>
                </div>
                <div class="oe-section-body">
                    <div class="oe-detail-grid" style="gap:0.75rem;">
                        <div class="oe-detail-item">
                            <div class="oe-detail-label">Created</div>
                            <div class="oe-detail-value" style="font-size:0.85rem;">
                                {{ $expense->created_at ? $expense->created_at->format('d M Y, h:i A') : '—' }}
                            </div>
                        </div>
                        <div class="oe-detail-item">
                            <div class="oe-detail-label">Created By</div>
                            <div class="oe-detail-value" style="font-size:0.85rem;">
                                {{ $expense->createdBy->name ?? 'System' }}
                            </div>
                        </div>
                        @if($expense->is_reversed)
                        <div class="oe-detail-item">
                            <div class="oe-detail-label">Reversed By</div>
                            <div class="oe-detail-value" style="font-size:0.85rem;">
                                {{ $expense->reversedBy->name ?? 'System' }}
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ─── Reverse Modal ──────────────────────────── --}}
<div class="oe-reverse-modal-overlay" id="oeReverseModal">
    <div class="oe-reverse-modal-box">
        <h5 class="fw-bold mb-2"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Reverse Expense</h5>
        <p class="text-muted small mb-3">This will reverse the GL journal entry for <strong>{{ $expense->expense_code }}</strong>. This action cannot be undone.</p>
        <textarea id="oeReverseReason" class="form-control" rows="3" placeholder="Enter reason for reversal (required)..."></textarea>
        <div class="d-flex gap-2 justify-content-end mt-3">
            <button onclick="closeReverseModal()" class="btn btn-outline-secondary btn-sm">Cancel</button>
            <button onclick="submitReverse()" class="btn btn-danger btn-sm">Confirm Reverse</button>
        </div>
    </div>
</div>

@push('scripts')
<script>
window.OE_BOOT = {
    expenseId: {{ $expense->id }},
    expenseCode: '{{ $expense->expense_code }}',
    routes: {
        reverse: '{{ route("admin.other-expenses.reverse", ["id" => "__ID__"]) }}',
        index: '{{ route("admin.other-expenses.index") }}',
    }
};

function openReverseModal() {
    document.getElementById('oeReverseModal').classList.add('active');
    document.getElementById('oeReverseReason').value = '';
    document.getElementById('oeReverseReason').focus();
}

function closeReverseModal() {
    document.getElementById('oeReverseModal').classList.remove('active');
}

function submitReverse() {
    const reason = document.getElementById('oeReverseReason').value.trim();
    if (!reason || reason.length < 3) {
        Swal.fire('Validation Error', 'Please provide a reason (at least 3 characters).', 'warning');
        return;
    }

    const url = OE_BOOT.routes.reverse.replace('__ID__', OE_BOOT.expenseId);

    Swal.fire({
        title: 'Confirm Reversal?',
        html: 'Reverse expense <strong>{{ $expense->expense_code }}</strong>?<br>This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#b91c1c',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Reverse',
    }).then((result) => {
        if (!result.isConfirmed) return;

        $.ajax({
            url: url,
            method: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                reverse_reason: reason,
            },
            dataType: 'json',
            success: function(resp) {
                closeReverseModal();
                Swal.fire({
                    icon: 'success',
                    title: 'Reversed!',
                    text: resp.message || 'Expense reversed successfully.',
                    confirmButtonColor: '#b91c1c',
                }).then(() => window.location.reload());
            },
            error: function(xhr) {
                const msg = xhr.responseJSON?.message || 'Something went wrong.';
                Swal.fire('Error', msg, 'error');
            }
        });
    });
}

document.getElementById('oeReverseModal').addEventListener('click', function(e) {
    if (e.target === this) closeReverseModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeReverseModal();
});
</script>
@endpush
@endsection
