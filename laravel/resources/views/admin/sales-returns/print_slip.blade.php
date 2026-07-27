@extends('layouts.print')

@section('print_content')
@php
    $itemsPerPage = 17;
    $allItems = $salesReturn->items;
    $totalPages = max(1, ceil($allItems->count() / $itemsPerPage));
    $globalSl = 0;
    // Phase 7.2 — unique linked damage invoices for the write-off section.
    $linkedDamageInvoices = $allItems
        ->filter(fn ($i) => $i->isDamage() && $i->damageInvoice)
        ->map(fn ($i) => $i->damageInvoice)
        ->unique('id')
        ->values();
@endphp

@for ($page = 1; $page <= $totalPages; $page++)
    @php
        $pageItems = $allItems->slice(($page - 1) * $itemsPerPage, $itemsPerPage);
        $isLastPage = ($page === $totalPages);
    @endphp

    <div class="print-page position-relative">
        @if ($salesReturn->is_reversed)
            <div class="watermark">REVERSED</div>
        @elseif ($salesReturn->status === 'created')
            {{-- Phase 7.3 — pending-confirmation watermark (amber tint) --}}
            <div class="watermark" style="color: rgba(180, 83, 9, 0.13);">PENDING CONFIRMATION</div>
        @endif

        {{-- Company header --}}
        <div class="company-header d-flex justify-content-between align-items-start">
            <div>
                <div class="company-name">{{ config('app.name', 'Remote Center ERP') }}</div>
                @if ($salesReturn->branch)
                    <div class="small text-muted">{{ $salesReturn->branch->branch_name }}</div>
                @endif
            </div>
            <div class="text-end">
                <div class="doc-title">Sales Return Slip</div>
                <div class="small text-muted">Page {{ $page }} of {{ $totalPages }}</div>
            </div>
        </div>

        {{-- Return meta --}}
        <div class="meta-grid">
            <div>
                <div class="meta-label">Return No.</div>
                <div class="meta-value">{{ $salesReturn->return_code }}</div>
            </div>
            <div class="text-end">
                <div class="meta-label">Date</div>
                <div class="meta-value">{{ \Carbon\Carbon::parse($salesReturn->return_date)->format('d M Y') }}</div>
            </div>
            <div>
                <div class="meta-label">Original Invoice</div>
                <div class="meta-value">{{ $salesReturn->salesInvoice?->invoice_code ?? '—' }}</div>
            </div>
            <div class="text-end">
                <div class="meta-label">Customer</div>
                <div class="meta-value">{{ $salesReturn->salesInvoice?->customer?->customer_name ?? '—' }}</div>
            </div>
            <div>
                <div class="meta-label">Status</div>
                <div class="meta-value">
                    @if ($salesReturn->status === 'confirmed')
                        <span class="badge bg-success">Confirmed</span>
                        @if ($confirmedMeta)
                            <div class="small text-muted mt-1">
                                Confirmed {{ \Carbon\Carbon::parse($confirmedMeta->confirmed_at)->format('d M Y H:i') }}
                                @if ($confirmedMeta->employee_name || $confirmedMeta->username)
                                    by {{ $confirmedMeta->employee_name ?: $confirmedMeta->username }}
                                @endif
                            </div>
                        @endif
                    @elseif ($salesReturn->status === 'created')
                        <span class="badge bg-warning">Pending</span>
                        <div class="small text-muted mt-1">Awaiting warehouse confirmation</div>
                    @elseif ($salesReturn->status === 'reversed')
                        <span class="badge bg-danger">Reversed</span>
                        @if ($salesReturn->reversed_at)
                            <div class="small text-muted mt-1">
                                Reversed {{ \Carbon\Carbon::parse($salesReturn->reversed_at)->format('d M Y H:i') }}
                                @if ($salesReturn->reverse_reason)
                                    — {{ $salesReturn->reverse_reason }}
                                @endif
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        {{-- Items table --}}
        <table class="table table-sm table-bordered items-table">
            <thead>
                <tr>
                    <th style="width:5%;">#</th>
                    <th style="width:35%;">Product</th>
                    <th style="width:20%;">Warehouse</th>
                    <th class="text-end" style="width:12%;">Qty</th>
                    <th class="text-end" style="width:12%;">Rate (Tk)</th>
                    <th class="text-end" style="width:16%;">Amount (Tk)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pageItems as $item)
                    <tr>
                        <td>{{ ++$globalSl }}</td>
                        <td>
                            {{ $item->product?->product_name ?? 'Product #' . $item->product_id }}
                            @if ($item->isDamage())
                                <span class="badge bg-danger-subtle text-danger ms-1">{{ $item->conditionLabel() }}</span>
                            @endif
                        </td>
                        <td>{{ $item->warehouse?->warehouse_name ?? '—' }}</td>
                        <td class="text-end">{{ number_format((float) $item->qty, 4) }}</td>
                        <td class="text-end">{{ number_format((float) $item->rate, 2) }}</td>
                        <td class="text-end fw-semibold">{{ number_format((float) $item->qty * (float) $item->rate, 2) }}</td>
                    </tr>
                @endforeach
                @for ($i = $pageItems->count(); $i < $itemsPerPage && !$isLastPage; $i++)
                    <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>
                @endfor
            </tbody>
        </table>

        {{-- Totals on last page --}}
        @if ($isLastPage)
            <div class="totals-section">
                <dl class="row mb-0">
                    <dt class="col-7">Return Total</dt>
                    <dd class="col-5"><strong class="fs-5 text-danger">Tk {{ number_format((float) $salesReturn->total_amount, 2) }}</strong></dd>

                    @if ((float) $salesReturn->cogs_amount > 0)
                        <dt class="col-7 text-muted">COGS Reversed</dt>
                        <dd class="col-5 text-muted">Tk {{ number_format((float) $salesReturn->cogs_amount, 2) }}</dd>
                    @endif
                </dl>
            </div>

            {{-- Reason --}}
            @if ($salesReturn->reason)
                <div class="mt-3 p-2 bg-light rounded small">
                    <strong>Reason:</strong> {{ $salesReturn->reason }}
                </div>
            @endif

            {{-- Phase 7.2 — Linked damage write-offs (Damage-condition lines only) --}}
            @if ($linkedDamageInvoices->isNotEmpty())
                <div class="mt-3">
                    <div class="meta-label mb-1">Linked Damage Write-offs</div>
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.82rem;">
                        <thead>
                            <tr class="table-light">
                                <th style="width:28%;">Damage Code</th>
                                <th style="width:22%;">Warehouse</th>
                                <th style="width:18%;">Date</th>
                                <th class="text-end" style="width:16%;">Value (Tk)</th>
                                <th class="text-center" style="width:16%;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($linkedDamageInvoices as $di)
                                <tr>
                                    <td>{{ $di->damage_code }}</td>
                                    <td>{{ $di->warehouse?->warehouse_name ?? '—' }}</td>
                                    <td>{{ $di->damage_date ? \Carbon\Carbon::parse($di->damage_date)->format('d M Y') : '—' }}</td>
                                    <td class="text-end">{{ number_format((float) $di->total_value, 2) }}</td>
                                    <td class="text-center">
                                        @if ($di->is_reversed)
                                            <span class="badge bg-danger-subtle text-danger">Reversed</span>
                                        @else
                                            <span class="badge bg-success-subtle text-success">Active</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- Signatures --}}
            <div class="signature-section">
                <div class="signature-box">
                    <div class="small text-muted">Returned By (Customer)</div>
                </div>
                <div class="signature-box">
                    <div class="small text-muted">Received By (Warehouse)</div>
                </div>
                <div class="signature-box">
                    <div class="small text-muted">Approved By (Manager)</div>
                </div>
            </div>
        @endif
    </div>
@endfor
@endsection
