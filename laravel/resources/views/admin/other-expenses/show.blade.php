@extends('layouts.admin')

@section('title', $title)

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/css/other-expense-theme.css') }}">
<style>
/* ─────────────────────────────────────────────
   Other Expense Detail — Premium Show Page
   ───────────────────────────────────────────── */

/* Hero Banner */
.oe-hero {
    background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 40%, #b91c1c 100%);
    border-radius: 16px;
    padding: 2rem 2.5rem;
    color: #fff;
    margin-bottom: 1.75rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(185, 28, 28, 0.25);
}
.oe-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 320px;
    height: 320px;
    border-radius: 50%;
    background: rgba(255,255,255,0.06);
    pointer-events: none;
}
.oe-hero::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: 15%;
    width: 200px;
    height: 200px;
    border-radius: 50%;
    background: rgba(255,255,255,0.04);
    pointer-events: none;
}
.oe-hero-content {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1.5rem;
    flex-wrap: wrap;
}
.oe-hero-left { flex: 1; min-width: 250px; }
.oe-hero-right { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-start; }

.oe-hero-icon {
    width: 52px;
    height: 52px;
    background: rgba(255,255,255,0.15);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1rem;
    font-size: 1.5rem;
    backdrop-filter: blur(4px);
}
.oe-hero h1 {
    font-size: 1.6rem;
    font-weight: 700;
    margin: 0 0 0.25rem;
    letter-spacing: -0.02em;
}
.oe-hero .oe-code {
    font-family: 'SF Mono', ui-monospace, monospace;
    font-size: 0.95rem;
    opacity: 0.85;
    background: rgba(255,255,255,0.12);
    padding: 0.2rem 0.75rem;
    border-radius: 6px;
    display: inline-block;
    margin-bottom: 0.5rem;
}
.oe-hero .oe-date {
    font-size: 0.85rem;
    opacity: 0.75;
}
.oe-hero .oe-date i { margin-right: 0.35rem; }

/* Hero action buttons */
.oe-hero-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.55rem 1.15rem;
    border-radius: 10px;
    font-size: 0.82rem;
    font-weight: 600;
    border: 1.5px solid rgba(255,255,255,0.3);
    background: rgba(255,255,255,0.1);
    color: #fff;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    backdrop-filter: blur(4px);
}
.oe-hero-btn:hover {
    background: rgba(255,255,255,0.22);
    border-color: rgba(255,255,255,0.5);
    transform: translateY(-1px);
}
.oe-hero-btn.oe-btn-danger {
    background: rgba(220, 38, 38, 0.6);
    border-color: rgba(248, 113, 113, 0.5);
}
.oe-hero-btn.oe-btn-danger:hover {
    background: rgba(220, 38, 38, 0.8);
    border-color: rgba(248, 113, 113, 0.7);
}
.oe-hero-btn.oe-btn-success {
    background: rgba(22, 163, 74, 0.6);
    border-color: rgba(74, 222, 128, 0.5);
}
.oe-hero-btn.oe-btn-success:hover {
    background: rgba(22, 163, 74, 0.8);
    border-color: rgba(74, 222, 128, 0.7);
}

/* Reversed banner */
.oe-reversed-banner {
    background: linear-gradient(135deg, #fef2f2, #fee2e2);
    border: 1.5px solid #fca5a5;
    border-radius: 12px;
    padding: 1rem 1.5rem;
    margin-bottom: 1.75rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: #991b1b;
}
.oe-reversed-banner i { font-size: 1.3rem; color: #dc2626; }
.oe-reversed-banner .oe-rev-label {
    font-weight: 700;
    font-size: 0.9rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}
.oe-reversed-banner .oe-rev-meta {
    font-size: 0.82rem;
    color: #7f1d1d;
    margin-top: 0.15rem;
}

/* Section Card */
.oe-section-card {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06), 0 4px 16px rgba(0,0,0,0.04);
    margin-bottom: 1.5rem;
    overflow: hidden;
    border: 1px solid #f1f5f9;
    transition: box-shadow 0.2s;
}
.oe-section-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.08), 0 8px 24px rgba(0,0,0,0.06);
}
.oe-section-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1.15rem 1.5rem;
    border-bottom: 1px solid #f1f5f9;
    font-weight: 700;
    font-size: 0.95rem;
    color: #1e293b;
}
.oe-section-header .oe-header-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}
.oe-section-header .oe-header-icon.red {
    background: linear-gradient(135deg, #fef2f2, #fee2e2);
    color: #b91c1c;
}
.oe-section-header .oe-header-icon.amber {
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    color: #b45309;
}
.oe-section-header .oe-header-icon.blue {
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    color: #1d4ed8;
}
.oe-section-header .oe-header-icon.purple {
    background: linear-gradient(135deg, #faf5ff, #f3e8ff);
    color: #7c3aed;
}
.oe-section-body {
    padding: 1.5rem;
}

/* Detail Grid */
.oe-detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 1.25rem;
}
.oe-detail-item {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}
.oe-detail-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #94a3b8;
}
.oe-detail-value {
    font-size: 0.95rem;
    font-weight: 600;
    color: #1e293b;
    word-break: break-word;
}
.oe-detail-value.mono {
    font-family: 'SF Mono', ui-monospace, monospace;
}
.oe-detail-value.amount {
    font-size: 1.35rem;
    font-weight: 800;
    color: #b91c1c;
}

/* Payment mode badge */
.oe-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.3rem 0.85rem;
    border-radius: 8px;
    font-size: 0.78rem;
    font-weight: 600;
    text-transform: capitalize;
}
.oe-badge.cash {
    background: #f0fdf4;
    color: #15803d;
    border: 1px solid #bbf7d0;
}
.oe-badge.bank {
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}
.oe-badge.mobile_banking {
    background: #faf5ff;
    color: #7c3aed;
    border: 1px solid #e9d5ff;
}
.oe-badge.cheque {
    background: #fffbeb;
    color: #b45309;
    border: 1px solid #fde68a;
}

/* Amount highlight card */
.oe-amount-card {
    background: linear-gradient(135deg, #fef2f2 0%, #fff1f2 100%);
    border: 1.5px solid #fecaca;
    border-radius: 14px;
    padding: 1.5rem;
    text-align: center;
    position: relative;
    overflow: hidden;
}
.oe-amount-card::before {
    content: '';
    position: absolute;
    top: -20px;
    right: -20px;
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: rgba(185, 28, 28, 0.06);
}
.oe-amount-card .oe-amount-label {
    font-size: 0.78rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #b91c1c;
    margin-bottom: 0.5rem;
}
.oe-amount-card .oe-amount-value {
    font-size: 2rem;
    font-weight: 800;
    color: #991b1b;
    letter-spacing: -0.02em;
}
.oe-amount-card .oe-amount-sub {
    font-size: 0.78rem;
    color: #dc2626;
    margin-top: 0.35rem;
}

/* Layout grid for side-by-side cards */
.oe-layout-row {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 1.5rem;
}
@media (max-width: 900px) {
    .oe-layout-row { grid-template-columns: 1fr; }
}

/* GL Journal Table */
.oe-gl-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid #e2e8f0;
}
.oe-gl-table thead th {
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    padding: 0.75rem 1rem;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #64748b;
    border-bottom: 2px solid #e2e8f0;
    text-align: left;
}
.oe-gl-table tbody td {
    padding: 0.75rem 1rem;
    font-size: 0.88rem;
    color: #334155;
    border-bottom: 1px solid #f1f5f9;
}
.oe-gl-table tbody tr:last-child td { border-bottom: none; }
.oe-gl-table tbody tr:hover { background: #fafafa; }
.oe-gl-table .dr { color: #b91c1c; font-weight: 700; }
.oe-gl-table .cr { color: #15803d; font-weight: 700; }
.oe-gl-table .total-row td {
    border-top: 2px solid #e2e8f0;
    font-weight: 700;
    background: #fafafa;
}

/* GL description */
.oe-gl-desc {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    border-radius: 10px;
    margin-bottom: 1rem;
    font-size: 0.82rem;
    color: #92400e;
    font-weight: 500;
}
.oe-gl-desc i { color: #b45309; }

/* Sticky Action Bar */
.oe-action-bar {
    position: sticky;
    bottom: 0;
    background: rgba(255,255,255,0.95);
    backdrop-filter: blur(12px);
    border-top: 1px solid #e2e8f0;
    padding: 1rem 1.5rem;
    margin: 1.5rem -1.5rem -1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    z-index: 10;
}
.oe-action-bar .oe-action-left {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}
.oe-action-bar .oe-action-right {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

/* Reverse Modal */
.oe-reverse-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(4px);
}
.oe-reverse-modal.active { display: flex; }
.oe-reverse-modal-content {
    background: #fff;
    border-radius: 16px;
    padding: 2rem;
    max-width: 480px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    animation: oe-slideUp 0.25s ease;
}
@keyframes oe-slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
.oe-reverse-modal-content h3 {
    font-size: 1.15rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.5rem;
}
.oe-reverse-modal-content p {
    font-size: 0.88rem;
    color: #64748b;
    margin-bottom: 1.25rem;
}
.oe-reverse-modal-content textarea {
    width: 100%;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    padding: 0.75rem 1rem;
    font-size: 0.88rem;
    resize: vertical;
    min-height: 80px;
    transition: border-color 0.2s;
}
.oe-reverse-modal-content textarea:focus {
    outline: none;
    border-color: #b91c1c;
    box-shadow: 0 0 0 3px rgba(185, 28, 28, 0.1);
}
.oe-reverse-modal-actions {
    display: flex;
    gap: 0.75rem;
    justify-content: flex-end;
    margin-top: 1.25rem;
}

/* Responsive */
@media (max-width: 768px) {
    .oe-hero { padding: 1.5rem; }
    .oe-hero h1 { font-size: 1.3rem; }
    .oe-hero-content { flex-direction: column; }
    .oe-detail-grid { grid-template-columns: 1fr; }
    .oe-layout-row { grid-template-columns: 1fr; }
    .oe-amount-card .oe-amount-value { font-size: 1.6rem; }
}
</style>
@endpush

@section('content')
<?php
$expense      = $expense ?? null;
$canReverse   = $canReverse ?? false;
$glLines      = $expense->journalEntry->lines ?? collect();
$journalEntry = $expense->journalEntry ?? null;
?>

<div class="oe-page-wrapper">

    {{-- ─── Hero Banner ──────────────────────────── --}}
    <div class="oe-hero">
        <div class="oe-hero-content">
            <div class="oe-hero-left">
                <div class="oe-hero-icon">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <h1>Other Expense Details</h1>
                <div class="oe-code">{{ $expense->expense_code }}</div>
                <div class="oe-date">
                    <i class="far fa-calendar-alt"></i>
                    {{ $expense->expense_date ? $expense->expense_date->format('d M Y') : '—' }}
                </div>
            </div>
            <div class="oe-hero-right">
                @if($canReverse)
                    <button class="oe-hero-btn oe-btn-danger" onclick="openReverseModal()">
                        <i class="fas fa-undo-alt"></i> Reverse
                    </button>
                @endif
                <a href="{{ route('admin.other-expenses.slip', $expense->id) }}" target="_blank" class="oe-hero-btn oe-btn-success">
                    <i class="fas fa-print"></i> Print Slip
                </a>
                <a href="{{ route('admin.other-expenses.index') }}" class="oe-hero-btn">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>
    </div>

    {{-- ─── Reversed Banner ──────────────────────── --}}
    @if($expense->is_reversed)
    <div class="oe-reversed-banner">
        <i class="fas fa-exclamation-triangle"></i>
        <div>
            <div class="oe-rev-label">This expense has been reversed</div>
            <div class="oe-rev-meta">
                Reversed on {{ $expense->reversed_at ? $expense->reversed_at->format('d M Y, h:i A') : '—' }}
                @if($expense->reverse_reason)
                    &nbsp;·&nbsp; Reason: {{ $expense->reverse_reason }}
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- ─── Main Content Row ─────────────────────── --}}
    <div class="oe-layout-row">

        {{-- ─── Left Column: Details ──────────────── --}}
        <div>

            {{-- Expense Details Card --}}
            <div class="oe-section-card">
                <div class="oe-section-header">
                    <span class="oe-header-icon red"><i class="fas fa-file-invoice-dollar"></i></span>
                    Expense Information
                </div>
                <div class="oe-section-body">
                    <div class="oe-detail-grid">
                        <div class="oe-detail-item">
                            <span class="oe-detail-label">Expense Code</span>
                            <span class="oe-detail-value mono">{{ $expense->expense_code }}</span>
                        </div>
                        <div class="oe-detail-item">
                            <span class="oe-detail-label">Expense Type</span>
                            <span class="oe-detail-value">{{ $expense->expense_type ?? 'General' }}</span>
                        </div>
                        <div class="oe-detail-item">
                            <span class="oe-detail-label">Branch</span>
                            <span class="oe-detail-value">{{ $expense->branch->branch_name ?? '—' }}</span>
                        </div>
                        <div class="oe-detail-item">
                            <span class="oe-detail-label">Date</span>
                            <span class="oe-detail-value">{{ $expense->expense_date ? $expense->expense_date->format('d M Y') : '—' }}</span>
                        </div>
                        <div class="oe-detail-item">
                            <span class="oe-detail-label">Payment Mode</span>
                            <span class="oe-detail-value">
                                <span class="oe-badge {{ $expense->payment_mode }}">
                                    @if($expense->payment_mode === 'cash')
                                        <i class="fas fa-money-bill-wave"></i>
                                    @elseif($expense->payment_mode === 'bank')
                                        <i class="fas fa-university"></i>
                                    @elseif($expense->payment_mode === 'mobile_banking')
                                        <i class="fas fa-mobile-alt"></i>
                                    @elseif($expense->payment_mode === 'cheque')
                                        <i class="fas fa-check-circle"></i>
                                    @endif
                                    {{ ucfirst(str_replace('_', ' ', $expense->payment_mode)) }}
                                </span>
                            </span>
                        </div>
                        @if($expense->bank)
                        <div class="oe-detail-item">
                            <span class="oe-detail-label">Bank</span>
                            <span class="oe-detail-value">{{ $expense->bank->bank_name ?? '—' }}</span>
                        </div>
                        @endif
                        <div class="oe-detail-item">
                            <span class="oe-detail-label">Status</span>
                            <span class="oe-detail-value">
                                @if($expense->is_reversed)
                                    <span class="oe-badge" style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;">
                                        <i class="fas fa-ban"></i> Reversed
                                    </span>
                                @else
                                    <span class="oe-badge" style="background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;">
                                        <i class="fas fa-check-circle"></i> Active
                                    </span>
                                @endif
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Description Card --}}
            @if($expense->description)
            <div class="oe-section-card">
                <div class="oe-section-header">
                    <span class="oe-header-icon amber"><i class="fas fa-align-left"></i></span>
                    Description
                </div>
                <div class="oe-section-body">
                    <p style="margin:0; color:#475569; font-size:0.92rem; line-height:1.65;">{{ $expense->description }}</p>
                </div>
            </div>
            @endif

            {{-- GL Journal Card --}}
            @if($journalEntry)
            <div class="oe-section-card">
                <div class="oe-section-header">
                    <span class="oe-header-icon blue"><i class="fas fa-book"></i></span>
                    GL Journal Entry
                </div>
                <div class="oe-section-body">
                    <div class="oe-gl-desc">
                        <i class="fas fa-info-circle"></i>
                        {{ $expense->getGlDescription() }}
                    </div>

                    <div style="margin-bottom:0.75rem; display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap;">
                        <span class="oe-detail-label" style="margin:0;">
                            Journal #{{ $journalEntry->entry_no ?? $journalEntry->id }}
                        </span>
                        @if($journalEntry->entry_date)
                        <span style="font-size:0.78rem; color:#94a3b8;">
                            <i class="far fa-calendar-alt" style="margin-right:0.25rem;"></i>
                            {{ $journalEntry->entry_date->format('d M Y') }}
                        </span>
                        @endif
                    </div>

                    <table class="oe-gl-table">
                        <thead>
                            <tr>
                                <th>Ledger</th>
                                <th>Code</th>
                                <th style="text-align:right;">Debit</th>
                                <th style="text-align:right;">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $totalDr = 0;
                                $totalCr = 0;
                            @endphp
                            @foreach($glLines as $line)
                            <tr>
                                <td>{{ $line->ledger->ledger_name ?? '—' }}</td>
                                <td class="mono" style="font-family:'SF Mono',ui-monospace,monospace; font-size:0.82rem; color:#64748b;">{{ $line->ledger->ledger_code ?? '—' }}</td>
                                <td style="text-align:right;">
                                    @if($line->debit_amount > 0)
                                        <span class="dr">{{ number_format($line->debit_amount, 2) }}</span>
                                        @php $totalDr += $line->debit_amount; @endphp
                                    @else
                                        —
                                    @endif
                                </td>
                                <td style="text-align:right;">
                                    @if($line->credit_amount > 0)
                                        <span class="cr">{{ number_format($line->credit_amount, 2) }}</span>
                                        @php $totalCr += $line->credit_amount; @endphp
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                            <tr class="total-row">
                                <td colspan="2" style="text-align:right; font-weight:700; color:#475569;">Total</td>
                                <td style="text-align:right; font-weight:700; color:#b91c1c;">{{ number_format($totalDr, 2) }}</td>
                                <td style="text-align:right; font-weight:700; color:#15803d;">{{ number_format($totalCr, 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            @endif
        </div>

        {{-- ─── Right Column: Amount Card ──────────── --}}
        <div>
            <div class="oe-amount-card">
                <div class="oe-amount-label">Expense Amount</div>
                <div class="oe-amount-value">{{ number_format($expense->amount, 2) }}</div>
                <div class="oe-amount-sub">
                    @if($expense->payment_mode === 'cash')
                        Paid in Cash
                    @elseif($expense->payment_mode === 'bank')
                        Via Bank Transfer
                    @elseif($expense->payment_mode === 'mobile_banking')
                        Via Mobile Banking
                    @elseif($expense->payment_mode === 'cheque')
                        Via Cheque
                    @else
                        {{ ucfirst($expense->payment_mode) }}
                    @endif
                </div>
            </div>

            {{-- Quick Info Card --}}
            <div class="oe-section-card" style="margin-top:1.5rem;">
                <div class="oe-section-header">
                    <span class="oe-header-icon purple"><i class="fas fa-clock"></i></span>
                    Quick Info
                </div>
                <div class="oe-section-body">
                    <div class="oe-detail-grid" style="gap:1rem;">
                        <div class="oe-detail-item">
                            <span class="oe-detail-label">Created</span>
                            <span class="oe-detail-value" style="font-size:0.85rem;">
                                {{ $expense->created_at ? $expense->created_at->format('d M Y, h:i A') : '—' }}
                            </span>
                        </div>
                        <div class="oe-detail-item">
                            <span class="oe-detail-label">Created By</span>
                            <span class="oe-detail-value" style="font-size:0.85rem;">
                                {{ $expense->createdBy->name ?? 'System' }}
                            </span>
                        </div>
                        @if($expense->is_reversed)
                        <div class="oe-detail-item">
                            <span class="oe-detail-label">Reversed By</span>
                            <span class="oe-detail-value" style="font-size:0.85rem;">
                                {{ $expense->reversedBy->name ?? 'System' }}
                            </span>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

{{-- ─── Reverse Modal ──────────────────────────── --}}
<div class="oe-reverse-modal" id="oeReverseModal">
    <div class="oe-reverse-modal-content">
        <h3><i class="fas fa-exclamation-triangle" style="color:#b91c1c; margin-right:0.5rem;"></i>Reverse Expense</h3>
        <p>This will reverse the GL journal entry for <strong>{{ $expense->expense_code }}</strong>. This action cannot be undone.</p>
        <textarea id="oeReverseReason" placeholder="Enter reason for reversal (required)..."></textarea>
        <div class="oe-reverse-modal-actions">
            <button onclick="closeReverseModal()" style="padding:0.5rem 1.25rem; border:1.5px solid #e2e8f0; border-radius:10px; background:#fff; color:#475569; font-weight:600; cursor:pointer; font-size:0.85rem;">Cancel</button>
            <button onclick="submitReverse()" style="padding:0.5rem 1.25rem; border:none; border-radius:10px; background:linear-gradient(135deg,#b91c1c,#dc2626); color:#fff; font-weight:600; cursor:pointer; font-size:0.85rem;">Confirm Reverse</button>
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
        show: '{{ route("admin.other-expenses.show", ["id" => $expense->id]) }}',
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
        html: `Reverse expense <strong>${OE_BOOT.expenseCode}</strong>?<br>This cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#b91c1c',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Reverse',
        cancelButtonText: 'Cancel',
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
                }).then(() => {
                    window.location.reload();
                });
            },
            error: function(xhr) {
                const msg = xhr.responseJSON?.message || 'Something went wrong.';
                Swal.fire('Error', msg, 'error');
            }
        });
    });
}

// Close modal on backdrop click
document.getElementById('oeReverseModal').addEventListener('click', function(e) {
    if (e.target === this) closeReverseModal();
});

// Close modal on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeReverseModal();
});
</script>
@endpush
@endsection
