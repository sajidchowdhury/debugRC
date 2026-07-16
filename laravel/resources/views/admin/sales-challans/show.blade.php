@extends('layouts.admin')

@section('content')
@php
    $challan = $challan ?? null;
    $stockMovements = $stockMovements ?? collect();

    // Status badge helper
    $statusBadge = function (bool $large = false) use ($challan): string {
        $cls = $large ? ' fs-5' : ' fs-6';
        if ($challan->is_reversed) {
            return '<span class="badge bg-danger-subtle text-danger' . $cls . '">'
                . '<i class="fas fa-rotate-left me-1"></i>Reversed</span>';
        }
        return '<span class="badge bg-success-subtle text-success' . $cls . '">'
            . '<i class="fas fa-circle-check me-1"></i>Active</span>';
    };

    // GL journal lines totals
    $glDebitTotal = 0.0;
    $glCreditTotal = 0.0;
    if ($challan->journalEntry && $challan->journalEntry->lines) {
        foreach ($challan->journalEntry->lines as $line) {
            $glDebitTotal  += (float) $line->debit;
            $glCreditTotal += (float) $line->credit;
        }
    }

    // Reverse-by user name lookup (best effort)
    $reversedByName = null;
    if ($challan->reversed_by) {
        $u = \App\Models\Employee::find($challan->reversed_by);
        if ($u) {
            $reversedByName = $u->name ?? ('Employee #' . $challan->reversed_by);
        } else {
            $reversedByName = 'User #' . $challan->reversed_by;
        }
    }

    // Created-by user name lookup (best effort)
    $createdByName = null;
    if ($challan->created_by) {
        $u = \App\Models\Employee::find($challan->created_by);
        if ($u) {
            $createdByName = $u->name ?? ('Employee #' . $challan->created_by);
        } else {
            $createdByName = 'User #' . $challan->created_by;
        }
    }

    $inv = $challan->salesInvoice;
    $cogsTotal = (float) ($challan->issue_cost ?? 0);
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-truck me-2"></i>Challan {{ $challan->challan_code }}
                {!! $statusBadge() !!}
            </h1>
            <p class="mb-0 small opacity-75">
                @if ($inv)<a href="{{ route('admin.sales-invoices.show', $inv) }}" class="text-white text-decoration-underline">{{ $inv->invoice_code }}</a>@endif
                @if ($inv && $inv->customer) · {{ $inv->customer->customer_name }}@endif
                @if ($challan->branch) · {{ $challan->branch->branch_name }}@endif
                · {{ \Carbon\Carbon::parse($challan->challan_date)->format('d M Y') }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-light btn-sm" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print
            </button>
            <a href="{{ route('admin.sales-challans.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Reversal alert --}}
    @if ($challan->is_reversed)
        <div class="alert alert-danger d-flex align-items-start mb-3" role="alert">
            <i class="fas fa-rotate-left me-2 fa-lg text-danger"></i>
            <div class="w-100">
                <strong>This challan has been reversed.</strong>
                Stock movements and the GL journal entry have been reversed.
                @if ($challan->reversed_at)
                    <div class="small text-muted mt-1">
                        <i class="fas fa-clock me-1"></i>
                        {{ \Carbon\Carbon::parse($challan->reversed_at)->format('d M Y, H:i') }}
                        @if ($reversedByName)
                            · <i class="fas fa-user me-1"></i>{{ $reversedByName }}
                        @endif
                    </div>
                @endif
                @if (!empty($challan->reverse_reason))
                    <div class="mt-1">
                        <span class="text-muted">Reason:</span>
                        <em>{{ $challan->reverse_reason }}</em>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="row g-3">
        {{-- Left column --}}
        <div class="col-lg-8">
            {{-- Challan details card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-circle-info me-1" style="color:#7c3aed;"></i> Challan details
                    </h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Challan code</dt>
                        <dd class="col-sm-9">
                            <span class="badge bg-secondary-subtle text-secondary">{{ $challan->challan_code }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Challan date</dt>
                        <dd class="col-sm-9">
                            {{ \Carbon\Carbon::parse($challan->challan_date)->format('d M Y') }}
                        </dd>

                        <dt class="col-sm-3 text-muted">Invoice</dt>
                        <dd class="col-sm-9">
                            @if ($inv)
                                <a href="{{ route('admin.sales-invoices.show', $inv) }}"
                                   class="text-decoration-none fw-semibold">
                                    {{ $inv->invoice_code }}
                                </a>
                                <span class="text-muted small">({{ \Carbon\Carbon::parse($inv->invoice_date)->format('d M Y') }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Customer</dt>
                        <dd class="col-sm-9">
                            @if ($inv && $inv->customer)
                                <strong>{{ $inv->customer->customer_name }}</strong>
                                <span class="text-muted">({{ $inv->customer->customer_code }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Branch</dt>
                        <dd class="col-sm-9">
                            @if ($challan->branch)
                                {{ $challan->branch->branch_name }}
                                <span class="text-muted small">({{ $challan->branch->branch_code }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Transport name</dt>
                        <dd class="col-sm-9">{{ $challan->transport_name ?: '—' }}</dd>

                        <dt class="col-sm-3 text-muted">Transport phone</dt>
                        <dd class="col-sm-9">{{ $challan->transport_phone ?: '—' }}</dd>

                        <dt class="col-sm-3 text-muted">Vehicle number</dt>
                        <dd class="col-sm-9">{{ $challan->vehicle_number ?: '—' }}</dd>

                        <dt class="col-sm-3 text-muted">Driver name</dt>
                        <dd class="col-sm-9">{{ $challan->driver_name ?: '—' }}</dd>

                        <dt class="col-sm-3 text-muted">Transport cost</dt>
                        <dd class="col-sm-9">
                            Tk {{ number_format((float) ($challan->transport_cost ?? 0), 2) }}
                        </dd>

                        <dt class="col-sm-3 text-muted">COGS total</dt>
                        <dd class="col-sm-9">
                            <strong class="fs-5" style="color:#7c3aed;">
                                Tk {{ number_format($cogsTotal, 2) }}
                            </strong>
                        </dd>

                        @if ($createdByName)
                            <dt class="col-sm-3 text-muted">Issued by</dt>
                            <dd class="col-sm-9">{{ $createdByName }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- Invoice items table --}}
            @if ($inv && $inv->items && $inv->items->isNotEmpty())
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-list me-1" style="color:#7c3aed;"></i> Invoice items
                        </h2>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product</th>
                                        <th>Warehouse</th>
                                        <th class="text-end">Qty</th>
                                        <th class="text-end">Rate (Tk)</th>
                                        <th class="text-end">Amount (Tk)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($inv->items as $item)
                                        <tr>
                                            <td>
                                                @if ($item->product)
                                                    <div class="fw-semibold">{{ $item->product->product_name }}</div>
                                                    <div class="small text-muted">{{ $item->product->product_code }}</div>
                                                @else
                                                    <span class="text-muted">Product #{{ $item->product_id }}</span>
                                                @endif
                                            </td>
                                            <td class="small">
                                                @if ($item->warehouse)
                                                    {{ $item->warehouse->warehouse_name }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end">{{ number_format((float) $item->qty, 2) }}</td>
                                            <td class="text-end">{{ number_format((float) $item->rate, 2) }}</td>
                                            <td class="text-end fw-semibold">
                                                {{ number_format((float) $item->qty * (float) $item->rate, 2) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Stock movements card --}}
            @if ($stockMovements->isNotEmpty())
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex align-items-center justify-content-between">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-boxes-stacked me-1" style="color:#7c3aed;"></i> Stock movements
                        </h2>
                        <span class="badge bg-primary-subtle text-primary">
                            {{ $stockMovements->count() }} tx(s)
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>TX #</th>
                                        <th>Product</th>
                                        <th>Warehouse</th>
                                        <th class="text-end">Qty (OUT)</th>
                                        <th class="text-end">Rate (avg_cost)</th>
                                        <th class="text-end">Value (Tk)</th>
                                        <th class="text-center">Reversed?</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($stockMovements as $mv)
                                        @php
                                            $mvQty = (float) ($mv->qty ?? 0);
                                            $mvCost = (float) ($mv->avg_cost ?? $mv->unit_cost ?? 0);
                                            $mvValue = abs($mvQty) * $mvCost;
                                            $mvReversed = !empty($mv->is_reversed);
                                        @endphp
                                        <tr class="{{ $mvReversed ? 'table-danger' : '' }}">
                                            <td class="small text-nowrap">
                                                @if (!empty($mv->transaction_date))
                                                    {{ \Carbon\Carbon::parse($mv->transaction_date)->format('d M Y') }}
                                                @elseif (!empty($mv->created_at))
                                                    {{ \Carbon\Carbon::parse($mv->created_at)->format('d M Y') }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="small">
                                                @if (!empty($mv->transaction_no))
                                                    <span class="badge bg-secondary-subtle text-secondary">
                                                        {{ $mv->transaction_no }}
                                                    </span>
                                                @else
                                                    <span class="text-muted">#{{ $mv->id }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="fw-semibold">{{ $mv->product_name }}</span>
                                                <div class="small text-muted">{{ $mv->product_code }}</div>
                                            </td>
                                            <td class="small">{{ $mv->warehouse_name }}</td>
                                            <td class="text-end text-danger fw-semibold">
                                                − {{ number_format(abs($mvQty), 2) }}
                                            </td>
                                            <td class="text-end">{{ number_format($mvCost, 2) }}</td>
                                            <td class="text-end fw-semibold">
                                                {{ number_format($mvValue, 2) }}
                                            </td>
                                            <td class="text-center">
                                                @if ($mvReversed)
                                                    <span class="badge bg-danger-subtle text-danger">
                                                        <i class="fas fa-rotate-left me-1"></i>Yes
                                                    </span>
                                                @else
                                                    <span class="text-muted">No</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- GL Journal Entry card --}}
            @if ($challan->journalEntry)
                @php $je = $challan->journalEntry; @endphp
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex align-items-center justify-content-between">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-book me-1" style="color:#7c3aed;"></i> GL Journal Entry
                        </h2>
                        @if (!empty($je->is_reversed))
                            <span class="badge bg-danger-subtle text-danger">
                                <i class="fas fa-rotate-left me-1"></i>Reversed
                            </span>
                        @endif
                    </div>
                    <div class="card-body">
                        <dl class="row mb-3">
                            <dt class="col-sm-3 text-muted">JE #</dt>
                            <dd class="col-sm-9">
                                <span class="badge bg-secondary-subtle text-secondary">{{ $je->entry_no }}</span>
                            </dd>
                            <dt class="col-sm-3 text-muted">Entry date</dt>
                            <dd class="col-sm-9">
                                @if ($je->entry_date)
                                    {{ \Carbon\Carbon::parse($je->entry_date)->format('d M Y') }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </dd>
                            <dt class="col-sm-3 text-muted">Description</dt>
                            <dd class="col-sm-9">{!! nl2br(e($je->description ?: '—')) !!}</dd>
                        </dl>

                        @if ($je->lines && $je->lines->isNotEmpty())
                            <div class="table-responsive">
                                <table class="table table-sm table-striped table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Ledger</th>
                                            <th class="text-end">Debit (Tk)</th>
                                            <th class="text-end">Credit (Tk)</th>
                                            <th>Memo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($je->lines as $line)
                                            <tr>
                                                <td>
                                                    @if ($line->ledger)
                                                        <span class="fw-semibold">{{ $line->ledger->ledger_name }}</span>
                                                        <div class="small text-muted">{{ $line->ledger->ledger_code }}</div>
                                                    @else
                                                        <span class="text-muted">Ledger #{{ $line->ledger_id }}</span>
                                                    @endif
                                                </td>
                                                <td class="text-end">
                                                    @if ((float) $line->debit > 0)
                                                        {{ number_format((float) $line->debit, 2) }}
                                                    @else
                                                        <span class="text-muted">—</span>
                                                    @endif
                                                </td>
                                                <td class="text-end">
                                                    @if ((float) $line->credit > 0)
                                                        {{ number_format((float) $line->credit, 2) }}
                                                    @else
                                                        <span class="text-muted">—</span>
                                                    @endif
                                                </td>
                                                <td class="small">{{ $line->memo ?: '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-light fw-bold">
                                            <td class="text-end">Totals</td>
                                            <td class="text-end">Tk {{ number_format($glDebitTotal, 2) }}</td>
                                            <td class="text-end">Tk {{ number_format($glCreditTotal, 2) }}</td>
                                            <td>
                                                @if (abs($glDebitTotal - $glCreditTotal) < 0.01)
                                                    <span class="badge bg-success-subtle text-success">
                                                        <i class="fas fa-check me-1"></i>Balanced
                                                    </span>
                                                @else
                                                    <span class="badge bg-danger-subtle text-danger">
                                                        <i class="fas fa-triangle-exclamation me-1"></i>Unbalanced
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        @else
                            <p class="text-muted small mb-0">No journal lines.</p>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        {{-- Right column: status + actions --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm sticky-top" style="top:80px;">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-gauge-high me-1" style="color:#7c3aed;"></i> Status &amp; actions
                    </h2>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-column align-items-start gap-2 mb-3">
                        <div class="text-muted small">Current status</div>
                        <div>{!! $statusBadge(true) !!}</div>
                    </div>

                    <hr>

                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                            <i class="fas fa-print me-1"></i> Print challan
                        </button>
                        <a href="{{ route('admin.sales-challans.index') }}" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-list me-1"></i> All challans
                        </a>
                        @if ($inv)
                            <a href="{{ route('admin.sales-invoices.show', $inv) }}" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-file-invoice-dollar me-1"></i> View invoice
                            </a>
                        @endif
                    </div>

                    @if (! $challan->is_reversed)
                        <hr>
                        <div class="alert alert-warning small mb-2">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Cancelling this challan will reverse the stock OUT movements and reverse the GL journal
                            entry. A reason is required.
                        </div>
                        <form method="POST" action="{{ route('admin.sales-challans.cancel', $challan) }}" id="cancelForm">
                            @csrf
                            <input type="hidden" name="cancel_reason" id="cancelReasonInput" value="">
                            <button type="button" class="btn btn-danger w-100" id="cancelBtn">
                                <i class="fas fa-ban me-1"></i> Cancel Challan
                            </button>
                        </form>
                    @else
                        <hr>
                        <div class="alert alert-secondary small mb-0">
                            <i class="fas fa-lock me-1"></i>
                            This challan is reversed — no further actions available.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    $('#cancelBtn').on('click', function () {
        Swal.fire({
            icon: 'warning',
            title: 'Cancel this challan?',
            html: '<p class="mb-2">This will <strong>reverse stock movements</strong> and <strong>reverse the GL journal entry</strong>.</p>' +
                  '<p class="mb-2 text-muted small">A reason is required (max 500 chars).</p>' +
                  '<textarea id="cancelReason" class="form-control" placeholder="Enter cancellation reason..." maxlength="500" rows="3"></textarea>',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-ban"></i> Yes, cancel challan',
            cancelButtonText: 'Keep challan',
            preConfirm: function () {
                var reason = $('#cancelReason').val().trim();
                if (!reason) {
                    Swal.showValidationMessage('A cancellation reason is required.');
                    return false;
                }
                return reason;
            }
        }).then(function (res) {
            if (res.isConfirmed) {
                $('#cancelReasonInput').val(res.value);
                $('#cancelForm').submit();
            }
        });
    });
});
</script>
@endpush
@endsection
