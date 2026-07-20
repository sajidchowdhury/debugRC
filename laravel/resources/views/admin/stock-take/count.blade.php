@extends('layouts.admin')

@section('content')
@php
    $saveUrl = route('admin.stock-take.saveCounts', [$session->id, $warehouse->id]);
    $backUrl = route('admin.stock-take.show', $session->id);
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-clipboard-list me-2"></i>{{ $title }}
            </h1>
            <p class="mb-0 small opacity-75">
                Session
                <a href="{{ $backUrl }}" class="text-white text-decoration-underline">{{ $session->session_code }}</a>
                @if ($session->branch)
                    · <i class="fas fa-building me-1"></i>{{ $session->branch->branch_name }}
                @endif
                · {{ $items->count() }} product(s)
            </p>
        </div>
        <div>
            <a href="{{ $backUrl }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to session
            </a>
        </div>
    </header>

    {{-- Info banner --}}
    <div class="alert alert-info d-flex align-items-start mb-3" role="alert">
        <i class="fas fa-circle-info me-2 mt-1"></i>
        <div>
            <strong>Enter the physically counted quantity for each product.</strong>
            Products with <em>no variance</em> (physical = system) will be skipped during posting —
            only rows where physical ≠ system will create stock movements + GL lines.
            <span class="d-block small mt-1 text-muted">
                <i class="fas fa-lightbulb me-1"></i>
                Tip: leave a per-line reason for any variances (e.g. "damaged", "lost", "found in back room").
            </span>
        </div>
    </div>

    <form method="POST" action="{{ $saveUrl }}" id="countForm">
        @csrf

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">
                    <i class="fas fa-table-list me-1 text-primary"></i> Physical count
                </h2>
                <span class="text-muted small">
                    <i class="fas fa-warehouse me-1"></i>{{ $warehouse->warehouse_name }}
                    ({{ $warehouse->warehouse_code }})
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle mb-0" id="countTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width:13%;">Category</th>
                                <th style="width:10%;">Product Code</th>
                                <th style="width:20%;">Product Name</th>
                                <th class="text-center" style="width:5%;">Unit</th>
                                <th class="text-end" style="width:10%;">System Qty</th>
                                <th class="text-end" style="width:12%;">Physical Qty</th>
                                <th class="text-end" style="width:10%;">Difference</th>
                                <th class="text-end" style="width:12%;">Value Impact (Tk)</th>
                                <th style="width:18%;">Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($items as $item)
                                @php
                                    $physical = old("counts.{$item->product_id}", $item->physical_qty ?? '');
                                    $reason   = old("reasons.{$item->product_id}", $item->reason ?? '');
                                    $sysQty   = (float) $item->system_qty;
                                    $rate     = (float) $item->rate;
                                @endphp
                                <tr data-row data-system-qty="{{ $sysQty }}" data-rate="{{ $rate }}">
                                    <td class="small">
                                        {{ $item->category_name ?: '—' }}
                                    </td>
                                    <td>
                                        <span class="fw-semibold">{{ $item->product_code }}</span>
                                    </td>
                                    <td>
                                        <span class="fw-semibold">{{ $item->product_name }}</span>
                                    </td>
                                    <td class="text-center small">{{ $item->unit ?: '—' }}</td>
                                    <td class="text-end system-qty-cell">{{ number_format($sysQty, 4) }}</td>
                                    <td class="text-end">
                                        <input type="number"
                                               name="counts[{{ $item->product_id }}]"
                                               class="form-control form-control-sm text-end physical-qty"
                                               step="any"
                                               value="{{ $physical }}"
                                               placeholder="0.0000">
                                    </td>
                                    <td class="text-end difference-cell text-muted">—</td>
                                    <td class="text-end value-cell text-muted">—</td>
                                    <td>
                                        <input type="text"
                                               name="reasons[{{ $item->product_id }}]"
                                               class="form-control form-control-sm reason-input"
                                               maxlength="500"
                                               value="{{ $reason }}"
                                               placeholder="optional">
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-5">
                                        <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                        No products loaded for this warehouse. Click <strong>Setup Counts</strong> first.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="5" class="text-end">Totals</td>
                                <td class="text-end">
                                    <span class="badge bg-warning-subtle text-warning" id="totalVarianceLines">0</span>
                                    <span class="small text-muted ms-1">variance line(s)</span>
                                </td>
                                <td class="text-end" id="totalAbsQty">0.0000</td>
                                <td class="text-end">
                                    <span class="text-success me-2" id="totalGain">+0.00</span>
                                    <span class="text-danger" id="totalLoss">-0.00</span>
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white d-flex gap-2 justify-content-end">
                <a href="{{ $backUrl }}" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-success" id="saveBtn">
                    <i class="fas fa-floppy-disk me-1"></i> Save Counts
                </button>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
$(function () {
    var $form     = $('#countForm');
    var $saveBtn  = $('#saveBtn');
    var $rows     = $('#countTable tbody tr[data-row]');

    var $vLines   = $('#totalVarianceLines');
    var $absQty   = $('#totalAbsQty');
    var $gain     = $('#totalGain');
    var $loss     = $('#totalLoss');

    function fmt4(n)  { return Number(n).toFixed(4); }
    function fmt2(n)  { return Number(n).toFixed(2); }
    function signed2(n) {
        var v = Number(n);
        return (v > 0 ? '+' : (v < 0 ? '−' : '')) + fmt2(Math.abs(v));
    }
    function signed4(n) {
        var v = Number(n);
        return (v > 0 ? '+' : (v < 0 ? '−' : '')) + fmt4(Math.abs(v));
    }

    function recomputeRow($tr) {
        var sysQty = parseFloat($tr.data('system-qty')) || 0;
        var rate   = parseFloat($tr.data('rate'))       || 0;
        var phys   = parseFloat($tr.find('.physical-qty').val());

        var $diff  = $tr.find('.difference-cell');
        var $val   = $tr.find('.value-cell');

        // If physical is empty / NaN, treat as "no count" → no variance.
        if (isNaN(phys)) {
            $diff.text('—').removeClass('text-success text-danger text-muted fw-bold').addClass('text-muted');
            $val.text('—').removeClass('text-success text-danger text-muted fw-bold').addClass('text-muted');
            return { diff: 0, value: 0 };
        }

        var diff  = phys - sysQty;
        var value = diff * rate;

        // Color-code the difference + value cells.
        $diff.removeClass('text-success text-danger text-muted fw-bold');
        $val.removeClass('text-success text-danger text-muted fw-bold');
        if (diff > 0) {
            $diff.html(signed4(diff)).addClass('text-success fw-bold');
            $val.html(signed2(value)).addClass('text-success fw-bold');
        } else if (diff < 0) {
            $diff.html(signed4(diff)).addClass('text-danger fw-bold');
            $val.html(signed2(value)).addClass('text-danger fw-bold');
        } else {
            $diff.text('0.0000').addClass('text-muted');
            $val.text('0.00').addClass('text-muted');
        }

        return { diff: diff, value: value };
    }

    function recomputeTotals() {
        var varianceLines = 0;
        var absQty        = 0;
        var gain          = 0;
        var loss          = 0;

        $rows.each(function () {
            var r = recomputeRow($(this));
            if (r.diff !== 0) {
                varianceLines++;
                absQty += Math.abs(r.diff);
            }
            if (r.value > 0) {
                gain += r.value;
            } else if (r.value < 0) {
                loss += Math.abs(r.value);
            }
        });

        $vLines.text(varianceLines);
        $absQty.text(fmt4(absQty));
        $gain.text('+' + fmt2(gain));
        $loss.text('−' + fmt2(loss));
    }

    // Initial compute (server-side pre-fill values).
    $rows.find('.physical-qty').on('input change', recomputeTotals);
    recomputeTotals();

    // Submit guard: ensure every physical-qty is filled with a valid number.
    $form.on('submit', function (e) {
        var missing = 0;
        $rows.each(function () {
            var v = $(this).find('.physical-qty').val();
            if (v === '' || v === null || isNaN(parseFloat(v))) {
                missing++;
            }
        });

        if (missing > 0) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Incomplete count',
                html: '<p class="text-start">' + missing + ' row(s) are missing a physical quantity.<br>' +
                      'Enter <code>0</code> for products with zero stock on hand.</p>',
                confirmButtonText: 'OK'
            });
            return false;
        }

        $saveBtn.prop('disabled', true)
                .html('<i class="fas fa-spinner fa-spin me-1"></i> Saving…');
    });
});
</script>
@endpush
@endsection
