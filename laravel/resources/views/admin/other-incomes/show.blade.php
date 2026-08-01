@extends('layouts.admin')

@section('content')
@php
    $statusBadge = function (bool $large = false) use ($income): string {
        $cls = $large ? ' fs-5' : ' fs-6';
        if ($income->is_reversed) {
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
            'adjustment'      => '<span class="badge bg-dark fs-6"><i class="fas fa-sliders me-1"></i>Adjustment</span>',
        ][$mode] ?? '<span class="badge bg-light text-dark fs-6">' . e($mode) . '</span>';
    };

    $heroGradient = 'linear-gradient(135deg,#16a34a,#15803d)';
    $heroIcon = 'fa-arrow-trend-up';
@endphp

<style>
    .oi-show-page { --oi-primary: #16a34a; --oi-primary-dark: #15803d; }

    .oi-show-hero {
        background: linear-gradient(135deg, var(--oi-primary), var(--oi-primary-dark));
        border-radius: 1rem;
        padding: 1.5rem 1.75rem;
        color: #fff;
        box-shadow: 0 8px 32px rgba(22,163,74,0.18);
        margin-bottom: 1.5rem;
    }
    .oi-show-hero h1 { font-size: 1.35rem; font-weight: 700; margin-bottom: 0.15rem; }
    .oi-show-hero .oi-hero-subtitle { font-size: 0.82rem; opacity: 0.85; }
    .oi-show-hero .oi-hero-amount {
        font-size: 2rem; font-weight: 800; font-variant-numeric: tabular-nums;
        line-height: 1.1;
    }

    .oi-section-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 0.875rem;
        box-shadow: 0 1px 4px rgba(15,23,42,0.04);
        margin-bottom: 1.25rem;
        overflow: hidden;
        transition: box-shadow 0.2s;
    }
    .oi-section-card:hover { box-shadow: 0 4px 16px rgba(15,23,42,0.07); }
    .oi-section-header {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 0.75rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .oi-section-header h2 { font-size: 0.88rem; font-weight: 700; margin: 0; color: #0f172a; }
    .oi-section-header .oi-section-icon {
        width: 32px; height: 32px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.85rem; color: #fff;
    }
    .oi-section-body { padding: 1.25rem; }

    .oi-detail-item {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 0.75rem 1rem;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .oi-detail-item:hover { border-color: #cbd5e1; box-shadow: 0 2px 8px rgba(15,23,42,0.04); }
    .oi-detail-label {
        font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em;
        color: #64748b; margin-bottom: 0.2rem; font-weight: 600;
    }
    .oi-detail-value { font-weight: 600; color: #0f172a; font-size: 0.92rem; }

    .oi-detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 1rem;
    }

    .oi-amount-card {
        background: linear-gradient(135deg, var(--oi-primary), var(--oi-primary-dark));
        border-radius: 0.875rem;
        padding: 1.5rem;
        color: #fff;
        text-align: center;
        box-shadow: 0 8px 24px rgba(22,163,74,0.2);
    }
    .oi-amount-card .oi-amount-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.85; }
    .oi-amount-card .oi-amount-value { font-size: 2rem; font-weight: 800; font-variant-numeric: tabular-nums; }
    .oi-amount-card .oi-amount-meta { font-size: 0.78rem; opacity: 0.8; margin-top: 0.25rem; }

    .oi-reversal-banner {
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-left: 4px solid #dc2626;
        border-radius: 0.875rem;
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
    }

    .oi-gl-table th {
        font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.03em;
        color: #64748b; font-weight: 600;
    }
    .oi-gl-table td { font-size: 0.88rem; }
    .oi-gl-table .debit-col { color: #0d9488; font-weight: 600; }
    .oi-gl-table .credit-col { color: #dc2626; font-weight: 600; }

    .oi-gl-mini-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 0.75rem;
        text-align: center;
    }
    .oi-gl-mini-card .oi-gl-mini-label { font-size: 0.7rem; text-transform: uppercase; color: #64748b; letter-spacing: 0.04em; }
    .oi-gl-mini-card .oi-gl-mini-value { font-weight: 700; font-size: 0.82rem; color: #0f172a; }

    @media (max-width: 768px) {
        .oi-show-hero { padding: 1rem 1.15rem; border-radius: 0.75rem; }
        .oi-show-hero h1 { font-size: 1.1rem; }
        .oi-show-hero .oi-hero-amount { font-size: 1.5rem; }
        .oi-section-body { padding: 0.85rem; }
        .oi-amount-card .oi-amount-value { font-size: 1.5rem; }
    }
</style>

<div class="container-fluid py-3 oi-show-page">
    {{-- Hero header --}}
    <header class="oi-show-hero">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1>
                    <i class="fas {{ $heroIcon }} me-2"></i>Other Income {{ $income->income_code }}
                </h1>
                <p class="oi-hero-subtitle mb-2">
                    @if ($income->branch)<i class="fas fa-code-branch me-1"></i> {{ $income->branch->branch_name }}@endif
                    · <i class="fas fa-calendar me-1"></i> {{ \Carbon\Carbon::parse($income->income_date)->format('d M Y') }}
                </p>
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    {!! $statusBadge() !!}
                    @if ($income->income_type)
                        <span class="badge bg-success-subtle text-success fs-6"><i class="fas fa-tag me-1"></i>{{ $income->income_type }}</span>
                    @endif
                </div>
            </div>
            <div class="text-end">
                <div class="oi-hero-amount">Tk {{ number_format((float) $income->amount, 2) }}</div>
                <div class="oi-hero-subtitle">Income received</div>
                <div class="d-flex gap-2 mt-2 justify-content-end">
                    <a href="{{ route('admin.other-incomes.slip', ['id' => $income->id]) }}" class="btn btn-outline-light btn-sm" target="_blank">
                        <i class="fas fa-print me-1"></i> Print
                    </a>
                    <a href="{{ route('admin.other-incomes.index') }}" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-arrow-left me-1"></i> Back
                    </a>
                </div>
            </div>
        </div>
    </header>

    {{-- Reversal alert --}}
    @if ($income->is_reversed)
        <div class="oi-reversal-banner">
            <div class="d-flex align-items-start gap-2">
                <i class="fas fa-rotate-left fa-lg text-danger mt-1"></i>
                <div class="flex-grow-1">
                    <strong class="text-danger">This income has been reversed.</strong>
                    <div class="mt-1 small">
                        @if ($income->reversed_at)
                            <span class="me-3"><i class="fas fa-calendar me-1"></i>
                                Reversed at: {{ \Carbon\Carbon::parse($income->reversed_at)->format('d M Y H:i') }}
                            </span>
                        @endif
                        @if ($income->reversed_by)
                            <span class="me-3"><i class="fas fa-user me-1"></i>
                                By: User #{{ $income->reversed_by }}
                            </span>
                        @endif
                    </div>
                    @if (!empty($income->reverse_reason))
                        <div class="mt-1">
                            <span class="text-muted">Reason:</span>
                            <em>{{ $income->reverse_reason }}</em>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3">
        {{-- Left: main details --}}
        <div class="col-lg-8">
            {{-- Income details section card --}}
            <div class="oi-section-card">
                <div class="oi-section-header">
                    <div class="oi-section-icon" style="background:linear-gradient(135deg,#16a34a,#15803d);">
                        <i class="fas fa-circle-info"></i>
                    </div>
                    <h2>Income Details</h2>
                </div>
                <div class="oi-section-body">
                    <div class="oi-detail-grid">
                        <div class="oi-detail-item">
                            <div class="oi-detail-label">Income Code</div>
                            <div class="oi-detail-value">
                                <span class="badge bg-secondary-subtle text-secondary" style="font-size:0.85rem;">{{ $income->income_code }}</span>
                            </div>
                        </div>
                        <div class="oi-detail-item">
                            <div class="oi-detail-label">Income Date</div>
                            <div class="oi-detail-value">
                                <i class="fas fa-calendar me-1 text-muted"></i>
                                {{ \Carbon\Carbon::parse($income->income_date)->format('d M Y') }}
                            </div>
                        </div>
                        <div class="oi-detail-item">
                            <div class="oi-detail-label">Branch</div>
                            <div class="oi-detail-value">
                                @if ($income->branch)
                                    <i class="fas fa-code-branch me-1 text-muted"></i>
                                    {{ $income->branch->branch_name }}
                                    <span class="text-muted small">({{ $income->branch->branch_code }})</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </div>
                        </div>
                        <div class="oi-detail-item">
                            <div class="oi-detail-label">Income Type</div>
                            <div class="oi-detail-value">
                                @if ($income->income_type)
                                    <span class="badge bg-success-subtle text-success">{{ $income->income_type }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </div>
                        </div>
                        <div class="oi-detail-item">
                            <div class="oi-detail-label">Payment Mode</div>
                            <div class="oi-detail-value">{!! $modeBadge($income->payment_mode) !!}</div>
                        </div>
                        @if ($income->payment_mode === 'bank' || $income->bank)
                            <div class="oi-detail-item">
                                <div class="oi-detail-label">Bank</div>
                                <div class="oi-detail-value">
                                    @if ($income->bank)
                                        <i class="fas fa-university me-1 text-muted"></i>
                                        {{ $income->bank->bank_name }}
                                        @if (!empty($income->bank->bank_code))
                                            <span class="text-muted small">({{ $income->bank->bank_code }})</span>
                                        @endif
                                        @if (!empty($income->bank->account_no))
                                            <div class="small text-muted mt-1"><i class="fas fa-hashtag me-1"></i>A/C: {{ $income->bank->account_no }}</div>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </div>
                            </div>
                        @endif
                        <div class="oi-detail-item">
                            <div class="oi-detail-label">Amount</div>
                            <div class="oi-detail-value">
                                <span class="text-success">Tk {{ number_format((float) $income->amount, 2) }}</span>
                                <span class="text-muted small ms-1">(income)</span>
                            </div>
                        </div>
                        <div class="oi-detail-item">
                            <div class="oi-detail-label">Description</div>
                            <div class="oi-detail-value">{!! nl2br(e($income->description ?: '—')) !!}</div>
                        </div>
                        <div class="oi-detail-item">
                            <div class="oi-detail-label">Created By</div>
                            <div class="oi-detail-value small text-muted">
                                @if ($income->created_by) User #{{ $income->created_by }} @else — @endif
                                @if ($income->created_at) · {{ $income->created_at->format('d M Y H:i') }} @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- GL Journal Entry card --}}
            @if ($income->journalEntry)
                @php
                    $je           = $income->journalEntry;
                    $jeTotalDr    = 0;
                    $jeTotalCr    = 0;
                    foreach ($je->lines as $line) {
                        $jeTotalDr += (float) $line->debit;
                        $jeTotalCr += (float) $line->credit;
                    }
                @endphp
                <div class="oi-section-card">
                    <div class="oi-section-header">
                        <div class="oi-section-icon" style="background:linear-gradient(135deg,#0f172a,#334155);">
                            <i class="fas fa-book"></i>
                        </div>
                        <h2>GL Journal Entry</h2>
                        @if ($je->is_reversed)
                            <span class="badge bg-danger-subtle text-danger ms-auto">
                                <i class="fas fa-rotate-left me-1"></i>Reversed
                            </span>
                        @endif
                    </div>
                    <div class="oi-section-body">
                        <div class="oi-detail-grid mb-3">
                            <div class="oi-detail-item">
                                <div class="oi-detail-label">JE #</div>
                                <div class="oi-detail-value">
                                    <span class="badge bg-secondary-subtle text-secondary">{{ $je->entry_no }}</span>
                                </div>
                            </div>
                            <div class="oi-detail-item">
                                <div class="oi-detail-label">Date</div>
                                <div class="oi-detail-value">
                                    {{ \Carbon\Carbon::parse($je->entry_date)->format('d M Y') }}
                                </div>
                            </div>
                            <div class="oi-detail-item">
                                <div class="oi-detail-label">Description</div>
                                <div class="oi-detail-value">{{ $je->description ?: '—' }}</div>
                            </div>
                            @if (!empty($je->source))
                                <div class="oi-detail-item">
                                    <div class="oi-detail-label">Source</div>
                                    <div class="oi-detail-value">{{ $je->source }}</div>
                                </div>
                            @endif
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0 oi-gl-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>Ledger</th>
                                        <th class="text-end">Debit (Tk)</th>
                                        <th class="text-end">Credit (Tk)</th>
                                        <th>Memo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($je->lines as $line)
                                        <tr>
                                            <td>
                                                @if ($line->ledger)
                                                    <span class="fw-semibold">{{ $line->ledger->ledger_name }}</span>
                                                    @if (!empty($line->ledger->ledger_code))
                                                        <div class="small text-muted">{{ $line->ledger->ledger_code }}</div>
                                                    @endif
                                                @else
                                                    <span class="text-muted">Ledger #{{ $line->ledger_id }}</span>
                                                @endif
                                            </td>
                                            <td class="text-end debit-col">
                                                @if ((float) $line->debit > 0)
                                                    {{ number_format((float) $line->debit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end credit-col">
                                                @if ((float) $line->credit > 0)
                                                    {{ number_format((float) $line->credit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="small text-muted">{{ $line->memo ?: '—' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-3">No lines.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td class="text-end">Total</td>
                                        <td class="text-end text-teal">{{ number_format($jeTotalDr, 2) }}</td>
                                        <td class="text-end text-danger">{{ number_format($jeTotalCr, 2) }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Right: aside --}}
        <div class="col-lg-4">
            {{-- Amount highlight card --}}
            <div class="oi-amount-card mb-3">
                <div class="oi-amount-label">Income Amount</div>
                <div class="oi-amount-value">Tk {{ number_format((float) $income->amount, 2) }}</div>
                <div class="oi-amount-meta">
                    <i class="fas fa-calendar me-1"></i>
                    {{ \Carbon\Carbon::parse($income->income_date)->format('d M Y') }}
                </div>
            </div>

            {{-- Status card --}}
            <div class="oi-section-card">
                <div class="oi-section-header">
                    <div class="oi-section-icon" style="background:linear-gradient(135deg,{{ $income->is_reversed ? '#dc2626,#b91c1c' : '#059669,#047857' }});">
                        <i class="fas fa-flag"></i>
                    </div>
                    <h2>Status</h2>
                </div>
                <div class="oi-section-body text-center">
                    <div class="mb-2">{!! $statusBadge(true) !!}</div>
                    <div class="small text-muted">
                        @if ($income->is_reversed)
                            Reversed — GL journal entry has been backed out.
                        @else
                            Active — GL posted to Chart of Accounts.
                        @endif
                    </div>
                </div>
            </div>

            {{-- GL Summary card --}}
            <div class="oi-section-card">
                <div class="oi-section-header">
                    <div class="oi-section-icon" style="background:linear-gradient(135deg,#0f172a,#334155);">
                        <i class="fas fa-calculator"></i>
                    </div>
                    <h2>GL Summary</h2>
                </div>
                <div class="oi-section-body">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="oi-gl-mini-card">
                                <div class="oi-gl-mini-label">GL Rule</div>
                                <div class="oi-gl-mini-value">Dr Cash/Bank · Cr Income</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="oi-gl-mini-card" style="background:#fff7ed;border-color:#fed7aa;">
                                <div class="oi-gl-mini-label">Sub-Ledger</div>
                                <div class="oi-gl-mini-value">None — CoA only</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="oi-gl-mini-card" style="background:#eff6ff;border-color:#bfdbfe;">
                                <div class="oi-gl-mini-label">Bank Book</div>
                                <div class="oi-gl-mini-value">
                                    @if ($income->payment_mode === 'bank')
                                        Increase
                                    @else
                                        No change
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                    @if ($income->journalEntry)
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-2" style="border-top:1px solid #e2e8f0;">
                            <span class="text-muted small">JE #</span>
                            <span class="badge bg-secondary-subtle text-secondary">{{ $income->journalEntry->entry_no }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Actions card --}}
            <div class="oi-section-card">
                <div class="oi-section-header">
                    <div class="oi-section-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <h2>Actions</h2>
                </div>
                <div class="oi-section-body d-grid gap-2">
                    <a href="{{ route('admin.other-incomes.slip', ['id' => $income->id]) }}" class="btn btn-outline-primary w-100" target="_blank">
                        <i class="fas fa-print me-1"></i> Print Slip
                    </a>

                    @if ($canReverse && ! $income->is_reversed)
                        <button type="button" class="btn btn-outline-danger w-100" id="reverseBtn">
                            <i class="fas fa-rotate-left me-1"></i> Reverse Income
                        </button>
                        <div class="alert alert-warning small mb-0" style="border-radius:0.5rem;">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Reversing backs out the GL journal entry and restores cash/bank balance. This action cannot be undone.
                        </div>
                    @elseif ($income->is_reversed)
                        @if (!empty($income->reverse_reason))
                            <div class="alert alert-secondary small mb-0" style="border-radius:0.5rem;">
                                <i class="fas fa-comment me-1"></i>
                                <strong>Reversal reason:</strong> {{ $income->reverse_reason }}
                            </div>
                        @endif
                        <div class="alert alert-secondary small mb-0" style="border-radius:0.5rem;">
                            <i class="fas fa-ban me-1"></i>
                            This income is already reversed. No further actions available.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Boot config for reversal JS --}}
<script>
    window.OI_BOOT = {
        baseUrl: '{{ url("/") }}/',
        csrf_token: '{{ csrf_token() }}',
        incomeId: {{ $income->id }},
        incomeCode: '{{ $income->income_code }}',
        routes: {
            'index': '{{ route("admin.other-incomes.index") }}',
            'show': '{{ route("admin.other-incomes.show", ["id" => "__ID__"]) }}'.replace('__ID__', ''),
            'reverse': '{{ route("admin.other-incomes.reverse", ["id" => $income->id]) }}',
            'slip': '{{ route("admin.other-incomes.slip", ["id" => "__ID__"]) }}'.replace('__ID__', ''),
        },
    };
</script>

@push('scripts')
<script src="/assets/js/OtherIncome.js?v={{ filemtime(public_path('assets/js/OtherIncome.js')) }}"></script>
<script>
$(function () {
    // ====== Reverse income (SweetAlert2) ======
    var $reverseBtn = $('#reverseBtn');
    if (!$reverseBtn.length) return;

    $reverseBtn.on('click', function (e) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Reverse this income?',
            html: '<p class="text-muted small mb-2">This will reverse the GL journal entry and restore cash/bank balance. This action cannot be undone. Please provide a reason:</p>' +
                  '<textarea id="swalReverseReason" class="form-control" rows="3" placeholder="Reason for reversal" maxlength="500"></textarea>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-rotate-left"></i> Confirm Reversal',
            cancelButtonText: 'Keep Income',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
            focusConfirm: false,
            preConfirm: function () {
                var reason = $('#swalReverseReason').val();
                if (!reason || !reason.trim()) {
                    Swal.showValidationMessage('A reversal reason is required.');
                    return false;
                }
                return reason;
            }
        }).then(function (result) {
            if (result.isConfirmed && result.value) {
                $.ajax({
                    url: window.OI_BOOT.routes.reverse,
                    method: 'POST',
                    data: {
                        _token: window.OI_BOOT.csrf_token,
                        reverse_reason: result.value
                    },
                    success: function (resp) {
                        if (resp.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Reversed!',
                                text: resp.message || 'Income reversed successfully.',
                                timer: 2000,
                                showConfirmButton: false
                            }).then(function () {
                                location.reload();
                            });
                        } else {
                            Swal.fire('Error', resp.message || 'Failed to reverse.', 'error');
                        }
                    },
                    error: function (xhr) {
                        var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'An error occurred.';
                        Swal.fire('Error', msg, 'error');
                    }
                });
            }
        });
    });
});
</script>
@endpush
@endsection