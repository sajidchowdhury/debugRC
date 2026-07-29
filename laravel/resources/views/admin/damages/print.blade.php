{{--
  admin/damages/print.blade.php — Phase 7.

  Printable damage slip (A5-ish) rendered inside layouts/print.blade.php
  (branch-colored toolbar + auto-print). Opens in a new tab from the show
  page's "Print slip" button.

  Renders: company header + meta grid (code/date/branch/warehouse/type/
  status/reason) + items table + totals + accountability (witness/accountable)
  + approval timeline + evidence thumbnails + GL journal summary + remarks +
  signature lines (Prepared / Approved / Received by).

  Authorization mirrors show(): DamagePolicy::view + branch.isolation on the
  route. RLS is the DB backstop.
--}}
@extends('layouts.print')

@section('print_content')
@php
    $dmg       = $damage;
    $je        = $dmg->journalEntry;
    $jeLines   = $je ? $je->lines : collect();
    $debitTotal  = $jeLines->sum(fn ($l) => (float) $l->debit);
    $creditTotal = $jeLines->sum(fn ($l) => (float) $l->credit);

    $typeLabels = \App\Models\DamageInvoice::DAMAGE_TYPE_LABELS;
    $typeLabel  = $typeLabels[$dmg->damage_type] ?? ucfirst(str_replace('_', ' ', $dmg->damage_type));

    $statusLabel = [
        'draft'     => 'Draft',
        'submitted' => 'Submitted (awaiting approval)',
        'approved'  => 'Approved',
        'confirmed' => 'Confirmed (posted)',
        'cancelled' => 'Cancelled',
        'rejected'  => 'Rejected',
    ][$dmg->status] ?? ucfirst($dmg->status);

    $recovered   = (float) ($dmg->recovery_amount ?? 0);
    $totalValue  = (float) $dmg->total_value;
    $netLoss     = $totalValue - $recovered;

    $fmtDate = fn ($d) => $d
        ? \Carbon\Carbon::parse($d)->format('d M Y H:i')
        : '—';
@endphp

<div class="print-page">
    {{-- Company header --}}
    <div class="company-header d-flex justify-content-between align-items-start">
        <div>
            <div class="company-name">{{ config('app.name', 'ERP') }}</div>
            <div class="text-muted small">
                @if ($dmg->warehouse && $dmg->warehouse->branch)
                    {{ $dmg->warehouse->branch->branch_name }}
                    @if ($dmg->warehouse->branch->branch_code) · {{ $dmg->warehouse->branch->branch_code }} @endif
                @elseif ($dmg->branch)
                    {{ $dmg->branch->branch_name }}
                @endif
            </div>
        </div>
        <div class="text-end">
            <div class="doc-title">Damage Slip</div>
            <div class="text-muted small">Stock write-off record</div>
        </div>
    </div>

    {{-- Meta grid --}}
    <div class="meta-grid">
        <div>
            <div class="meta-label">Damage code</div>
            <div class="meta-value">{{ $dmg->damage_code }}</div>
        </div>
        <div>
            <div class="meta-label">Date</div>
            <div class="meta-value">{{ \Carbon\Carbon::parse($dmg->damage_date)->format('d M Y') }}</div>
        </div>
        <div>
            <div class="meta-label">Warehouse</div>
            <div class="meta-value">
                @if ($dmg->warehouse)
                    {{ $dmg->warehouse->warehouse_name }}
                    @if ($dmg->warehouse->warehouse_code) · {{ $dmg->warehouse->warehouse_code }} @endif
                @else
                    —
                @endif
            </div>
        </div>
        <div>
            <div class="meta-label">Status</div>
            <div class="meta-value">{{ $statusLabel }}</div>
        </div>
        <div>
            <div class="meta-label">Damage type</div>
            <div class="meta-value">{{ $typeLabel }}</div>
        </div>
        <div>
            <div class="meta-label">Total value</div>
            <div class="meta-value text-danger">Tk {{ number_format($totalValue, 2) }}</div>
        </div>
        @if ($dmg->reason_code || $dmg->reasonTaxonomy)
            <div>
                <div class="meta-label">Reason code</div>
                <div class="meta-value">
                    {{ $dmg->reasonTaxonomy?->reason_label ?? $dmg->reason_code ?? '—' }}
                </div>
            </div>
        @endif
        @if ($recovered > 0)
            <div>
                <div class="meta-label">Recovered</div>
                <div class="meta-value text-success">Tk {{ number_format($recovered, 2) }}</div>
            </div>
        @endif
    </div>

    {{-- Items table --}}
    <table class="table table-sm table-bordered items-table align-middle mb-0">
        <thead>
            <tr>
                <th style="width:5%;">#</th>
                <th>Product</th>
                <th class="text-end" style="width:12%;">Qty</th>
                <th class="text-end" style="width:15%;">Rate (Tk)</th>
                <th class="text-end" style="width:17%;">Amount (Tk)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($dmg->items as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>
                        @if ($item->product)
                            <span class="fw-semibold">{{ $item->product->product_name }}</span>
                            @if ($item->product->product_code)
                                <div class="small text-muted">{{ $item->product->product_code }}</div>
                            @endif
                        @else
                            <span class="text-muted">Product #{{ $item->product_id }}</span>
                        @endif
                    </td>
                    <td class="text-end">{{ number_format((float) $item->qty, 3) }}</td>
                    <td class="text-end">{{ number_format((float) $item->rate, 2) }}</td>
                    <td class="text-end">{{ number_format((float) $item->qty * (float) $item->rate, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="fw-bold">
                <td colspan="4" class="text-end">Total damage value</td>
                <td class="text-end">Tk {{ number_format($totalValue, 2) }}</td>
            </tr>
            @if ($recovered > 0)
                <tr>
                    <td colspan="4" class="text-end text-success">Less: recovered from employee</td>
                    <td class="text-end text-success">Tk {{ number_format($recovered, 2) }}</td>
                </tr>
                <tr class="fw-bold">
                    <td colspan="4" class="text-end">Net loss to P&amp;L</td>
                    <td class="text-end">Tk {{ number_format($netLoss, 2) }}</td>
                </tr>
            @endif
        </tfoot>
    </table>

    {{-- Accountability + remarks --}}
    <div class="row mt-3 g-3">
        <div class="col-6">
            <div class="meta-label">Witness</div>
            <div class="meta-value">
                @if ($dmg->witnessEmployee)
                    {{ $dmg->witnessEmployee->name }}
                    @if ($dmg->witnessEmployee->employee_code)
                        <span class="text-muted small">({{ $dmg->witnessEmployee->employee_code }})</span>
                    @endif
                @else
                    <span class="text-muted">—</span>
                @endif
            </div>
        </div>
        <div class="col-6">
            <div class="meta-label">Accountable employee</div>
            <div class="meta-value">
                @if ($dmg->accountableEmployee)
                    {{ $dmg->accountableEmployee->name }}
                    @if ($dmg->accountableEmployee->employee_code)
                        <span class="text-muted small">({{ $dmg->accountableEmployee->employee_code }})</span>
                    @endif
                @else
                    <span class="text-muted">—</span>
                @endif
            </div>
        </div>
        @php
            // Prefer the longer reason_detail; fall back to the short reason.
            $remarks = trim((string) ($dmg->reason_detail ?? $dmg->reason ?? ''));
        @endphp
        @if ($remarks !== '')
            <div class="col-12">
                <div class="meta-label">Remarks</div>
                <div>{{ $remarks }}</div>
            </div>
        @endif
    </div>

    {{-- GL journal summary (only when posted) --}}
    @if ($je && $jeLines->isNotEmpty())
        <div class="mt-3">
            <div class="meta-label mb-1">GL posting — {{ $je->entry_no }}
                @if ($je->is_reversed) <span class="text-danger">(reversed)</span> @endif
            </div>
            <table class="table table-sm table-bordered items-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Ledger</th>
                        <th class="text-end" style="width:20%;">Debit (Tk)</th>
                        <th class="text-end" style="width:20%;">Credit (Tk)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($jeLines as $line)
                        <tr>
                            <td>
                                @if ($line->ledger)
                                    {{ $line->ledger->ledger_name }}
                                    <span class="text-muted small">{{ $line->ledger->ledger_code }}</span>
                                @else
                                    Ledger #{{ $line->ledger_id }}
                                @endif
                            </td>
                            <td class="text-end">{{ (float) $line->debit > 0 ? number_format((float) $line->debit, 2) : '—' }}</td>
                            <td class="text-end">{{ (float) $line->credit > 0 ? number_format((float) $line->credit, 2) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td class="text-end">Total</td>
                        <td class="text-end">{{ number_format($debitTotal, 2) }}</td>
                        <td class="text-end">{{ number_format($creditTotal, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    {{-- Evidence thumbnails (if any) --}}
    @if ($dmg->attachments && $dmg->attachments->isNotEmpty())
        <div class="mt-3">
            <div class="meta-label mb-1">Evidence ({{ $dmg->attachments->count() }})</div>
            <div class="d-flex flex-wrap gap-2">
                @foreach ($dmg->attachments as $att)
                    @php
                        $isImg = in_array($att->mime_type, ['image/jpeg','image/png','image/webp','image/gif'], true);
                        $viewUrl = route('admin.damages.attachments.view', [$dmg->id, $att->id]);
                    @endphp
                    @if ($isImg)
                        <img src="{{ $viewUrl }}" alt="{{ e($att->file_name) }}"
                             style="width:90px;height:90px;object-fit:cover;border:1px solid #dee2e6;border-radius:4px;">
                    @else
                        <div style="width:90px;height:90px;border:1px solid #dee2e6;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#6b7280;">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                    @endif
                @endforeach
            </div>
            <div class="text-muted small mt-1">Attached files are stored privately; thumbnails render inline only when viewing this slip online.</div>
        </div>
    @endif

    {{-- Approval timeline (compact) --}}
    @if ($dmg->submitted_at || $dmg->approved_at || $dmg->approval_rejected_at)
        <div class="mt-3">
            <div class="meta-label mb-1">Approval timeline</div>
            <div class="small">
                @if ($dmg->submitted_at)
                    Submitted by <strong>{{ $dmg->submitter?->username ?? ('user #' . $dmg->submitted_by) }}</strong>
                    on {{ $fmtDate($dmg->submitted_at) }}.<br>
                @endif
                @if ($dmg->approved_at)
                    Approved by <strong>{{ $dmg->approver?->username ?? ('user #' . $dmg->approved_by) }}</strong>
                    on {{ $fmtDate($dmg->approved_at) }}.
                    @if ($dmg->wasAutoApproved()) <span class="text-muted">(auto-approved — within threshold)</span> @endif
                    <br>
                @endif
                @if ($dmg->approval_rejected_at)
                    Rejected by <strong>{{ $dmg->rejecter?->username ?? ('user #' . $dmg->approval_rejected_by) }}</strong>
                    on {{ $fmtDate($dmg->approval_rejected_at) }}.<br>
                @endif
                @if ($dmg->approval_notes)
                    <em>Notes: {{ $dmg->approval_notes }}</em>
                @endif
            </div>
        </div>
    @endif

    {{-- Signature lines --}}
    <div class="signature-section">
        <div class="signature-box">
            <div class="small text-muted mb-4">Prepared by</div>
            <div class="small">{{ $dmg->submitter?->username ?? ('user #' . $dmg->created_by) }}</div>
        </div>
        <div class="signature-box">
            <div class="small text-muted mb-4">Approved by</div>
            <div class="small">{{ $dmg->approver?->username ?? '________________' }}</div>
        </div>
        <div class="signature-box">
            <div class="small text-muted mb-4">Received / Witnessed by</div>
            <div class="small">{{ $dmg->witnessEmployee?->name ?? '________________' }}</div>
        </div>
    </div>

    <div class="text-muted small mt-4 text-center">
        Generated {{ \Carbon\Carbon::now()->format('d M Y H:i') }} · {{ $dmg->damage_code }}
        @if ($dmg->is_reversed) · REVERSED @endif
    </div>
</div>
@endsection
