@extends('layouts.admin')

@section('content')
@php
    $saveUrl      = route('admin.stock-take.saveCounts', [$session->id, $warehouse->id]);
    $scanUrl      = route('admin.stock-take.scanCount', [$session->id, $warehouse->id]);
    $bulkPasteUrl = route('admin.stock-take.bulkPaste', [$session->id, $warehouse->id]);
    $importUrl    = route('admin.stock-take.importCounts', [$session->id, $warehouse->id]);
    $autosaveUrl  = route('admin.stock-take.autosave', [$session->id, $warehouse->id]);
    $backUrl      = route('admin.stock-take.show', $session->id);

    // Phase 7: a recount is "in progress" when any line in this warehouse has
    // a recounted_at stamp (set by recountWarehouse). The banner reminds the
    // counter they are re-entering values, not counting fresh.
    $recountInProgress = $items->contains(fn($i) => !empty($i->recounted_at));

    // Is the session editable? Scan / bulk paste / autosave are disabled when
    // the session is in a terminal state (the service re-checks, but the UI
    // hides the inputs to avoid dead clicks).
    $editable = in_array($session->status, ['draft', 'counting', 'submitted', 'approved'], true);
@endphp

<div class="container-fluid py-2">
    {{-- Hero header (sticky on mobile so the barcode scan stays reachable) --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white st-hero"
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
                @if ($recountInProgress)
                    · <span class="badge bg-warning text-dark ms-1"><i class="fas fa-rotate me-1"></i>Recount</span>
                @endif
            </p>
        </div>
        <div>
            <a href="{{ $backUrl }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to session
            </a>
        </div>
    </header>

    {{-- Recount banner (only when a recount is in progress) --}}
    @if ($recountInProgress)
        <div class="alert alert-warning d-flex align-items-start mb-3" role="alert">
            <i class="fas fa-rotate me-2 mt-1"></i>
            <div>
                <strong>Recount in progress.</strong>
                The previous physical quantities were preserved (or reset per policy) — review each line and adjust as needed.
                A recount audit entry has been recorded with the pre-recount values.
            </div>
        </div>
    @endif

    {{-- Info banner --}}
    <div class="alert alert-info d-flex align-items-start mb-3" role="alert">
        <i class="fas fa-circle-info me-2 mt-1"></i>
        <div>
            <strong>Enter the physically counted quantity for each product.</strong>
            Products with <em>no variance</em> (physical = system) will be skipped during posting —
            only rows where physical ≠ system will create stock movements + GL lines.
            <span class="d-block small mt-1 text-muted">
                <i class="fas fa-lightbulb me-1"></i>
                Tip: scan a barcode, paste a list, or import a CSV — lines auto-save as you type.
            </span>
        </div>
    </div>

    {{-- Phase 7 toolbar: barcode scan (auto-focus) + bulk paste + CSV import --}}
    @if ($editable)
    <div class="card border-0 shadow-sm mb-3 st-toolbar">
        <div class="card-body p-2 p-md-3">
            <div class="row g-2 align-items-end">
                {{-- Barcode scan column --}}
                <div class="col-12 col-md-5">
                    <label for="barcodeInput" class="form-label small fw-semibold mb-1">
                        <i class="fas fa-barcode me-1 text-primary"></i>Barcode / product code scan
                    </label>
                    <div class="input-group input-group-lg">
                        <input type="text"
                               id="barcodeInput"
                               class="form-control"
                               placeholder="Scan or type a product code…"
                               autocomplete="off"
                               autofocus>
                        <button class="btn btn-primary" type="button" id="barcodeQtyBtn" title="Enter qty then save">
                            <i class="fas fa-keyboard me-1"></i> Qty
                        </button>
                    </div>
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <input type="number"
                               id="barcodeQty"
                               class="form-control form-control-sm"
                               style="max-width:120px;"
                               step="any"
                               min="0"
                               placeholder="qty"
                               value="0">
                        <input type="text"
                               id="barcodeReason"
                               class="form-control form-control-sm"
                               placeholder="optional reason"
                               maxlength="500">
                        <button class="btn btn-success btn-sm" type="button" id="barcodeSubmit">
                            <i class="fas fa-check me-1"></i>Save line
                        </button>
                    </div>
                    <div class="small text-muted mt-1" id="barcodeHint">
                        Scan a code → the product row highlights + qty is saved instantly.
                    </div>
                </div>

                {{-- Bulk paste column --}}
                <div class="col-6 col-md-3">
                    <button type="button" class="btn btn-outline-primary w-100 st-touch-btn" data-bs-toggle="modal" data-bs-target="#bulkPasteModal">
                        <i class="fas fa-paste me-1"></i> Bulk paste
                    </button>
                    <div class="small text-muted text-center mt-1">code,qty per line</div>
                </div>

                {{-- CSV import column --}}
                <div class="col-6 col-md-4">
                    <button type="button" class="btn btn-outline-success w-100 st-touch-btn" data-bs-toggle="modal" data-bs-target="#csvImportModal">
                        <i class="fas fa-file-csv me-1"></i> Import CSV
                    </button>
                    <div class="small text-muted text-center mt-1">product_code, physical_qty</div>
                </div>
            </div>
        </div>
    </div>
    @endif

    <form method="POST" action="{{ $saveUrl }}" id="countForm">
        @csrf

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center sticky-top" style="z-index:1020;">
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
                                    $updatedAt = $item->updated_at ? \Illuminate\Support\Carbon::parse($item->updated_at)->toDateTimeString() : '';
                                @endphp
                                <tr data-row
                                    data-system-qty="{{ $sysQty }}"
                                    data-rate="{{ $rate }}"
                                    data-product-id="{{ $item->product_id }}"
                                    data-product-code="{{ $item->product_code }}"
                                    data-updated-at="{{ $updatedAt }}"
                                    @if (!empty($item->recounted_at)) data-recounted="1" @endif>
                                    <td class="small">
                                        {{ $item->category_name ?: '—' }}
                                        @if (!empty($item->recounted_at))
                                            <span class="badge bg-warning-subtle text-warning ms-1" title="Recounted"><i class="fas fa-rotate"></i></span>
                                        @endif
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
                                               min="0"
                                               value="{{ $physical }}"
                                               placeholder="0.0000"
                                               @if (!$editable) readonly @endif>
                                    </td>
                                    <td class="text-end difference-cell text-muted">—</td>
                                    <td class="text-end value-cell text-muted">—</td>
                                    <td>
                                        <input type="text"
                                               name="reasons[{{ $item->product_id }}]"
                                               class="form-control form-control-sm reason-input"
                                               maxlength="500"
                                               value="{{ $reason }}"
                                               placeholder="optional"
                                               @if (!$editable) readonly @endif>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-5">
                                        <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                        <p class="mb-2">No products loaded for this warehouse yet.</p>
                                        <p class="small text-muted mb-3">
            Products are loaded when you click <strong>Setup Counts</strong> on the session page.
            If you added a new product after setup, re-run <strong>Setup Counts</strong> to include it.
                                        </p>
                                        <a href="{{ route('admin.stock-take.setup', [$session->id, $warehouse->id]) }}"
                                           class="btn btn-sm btn-primary">
                                            <i class="fas fa-wand-magic-sparkles me-1"></i> Setup Counts Now
                                        </a>
                                        <a href="{{ route('admin.stock-take.show', $session->id) }}"
                                           class="btn btn-sm btn-outline-secondary ms-1">
                                            <i class="fas fa-arrow-left me-1"></i> Back to Session
                                        </a>
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

{{-- Phase 7: Bulk paste modal --}}
<div class="modal fade" id="bulkPasteModal" tabindex="-1" aria-labelledby="bulkPasteLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkPasteLabel">
                    <i class="fas fa-paste me-1 text-primary"></i> Bulk paste counts
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    Paste rows from a spreadsheet or text file. One product per line, format:
                    <code>code,qty</code> or <code>code,qty,reason</code> (comma or tab separated).
                    Lines starting with <code>#</code> are ignored. Unknown codes are skipped and reported.
                </p>
                <textarea id="bulkPasteText" class="form-control font-monospace" rows="12" placeholder="SKU-001,42&#10;SKU-002,17,damaged&#10;SKU-003,0&#10;# comment line ignored"></textarea>
                <div id="bulkPasteResult" class="mt-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="bulkPasteSubmit">
                    <i class="fas fa-upload me-1"></i> Upsert lines
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Phase 7: CSV import modal --}}
<div class="modal fade" id="csvImportModal" tabindex="-1" aria-labelledby="csvImportLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="csvImportLabel">
                    <i class="fas fa-file-csv me-1 text-success"></i> Import counts from CSV
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="{{ $importUrl }}" enctype="multipart/form-data" id="csvImportForm">
                @csrf
                <div class="modal-body">
                    <p class="small text-muted">
                        Upload a CSV with a header row containing at least
                        <code>product_code</code> and <code>physical_qty</code>.
                        An optional <code>reason</code> column is honoured. Unknown codes are skipped and reported.
                    </p>
                    <div class="mb-3">
                        <label for="csvFile" class="form-label">CSV file</label>
                        <input class="form-control" type="file" id="csvFile" name="csv_file" accept=".csv,.txt" required>
                    </div>
                    <div class="small text-muted">
                        <i class="fas fa-lightbulb me-1"></i>
                        Max 2 MB. The first row must be the header.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success" id="csvImportSubmit">
                        <i class="fas fa-upload me-1"></i> Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    var $form       = $('#countForm');
    var $saveBtn    = $('#saveBtn');
    var $rows       = $('#countTable tbody tr[data-row]');

    var $vLines     = $('#totalVarianceLines');
    var $absQty     = $('#totalAbsQty');
    var $gain       = $('#totalGain');
    var $loss       = $('#totalLoss');

    var editable    = {{ $editable ? 'true' : 'false' }};
    var scanUrl     = '{{ $scanUrl }}';
    var bulkPasteUrl= '{{ $bulkPasteUrl }}';
    var autosaveUrl = '{{ $autosaveUrl }}';

    // ---- numeric helpers -------------------------------------------------
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
    function escapeHtml(s) {
        return $('<div>').text(String(s == null ? '' : s)).html();
    }

    // ---- row + totals recompute -----------------------------------------
    function recomputeRow($tr) {
        var sysQty = parseFloat($tr.data('system-qty')) || 0;
        var rate   = parseFloat($tr.data('rate'))       || 0;
        var phys   = parseFloat($tr.find('.physical-qty').val());

        var $diff  = $tr.find('.difference-cell');
        var $val   = $tr.find('.value-cell');

        if (isNaN(phys)) {
            $diff.text('—').removeClass('text-success text-danger text-muted fw-bold').addClass('text-muted');
            $val.text('—').removeClass('text-success text-danger text-muted fw-bold').addClass('text-muted');
            return { diff: 0, value: 0 };
        }

        var diff  = phys - sysQty;
        var value = diff * rate;

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
        var varianceLines = 0, absQty = 0, gain = 0, loss = 0;
        $rows.each(function () {
            var r = recomputeRow($(this));
            if (r.diff !== 0) { varianceLines++; absQty += Math.abs(r.diff); }
            if (r.value > 0) { gain += r.value; } else if (r.value < 0) { loss += Math.abs(r.value); }
        });
        $vLines.text(varianceLines);
        $absQty.text(fmt4(absQty));
        $gain.text('+' + fmt2(gain));
        $loss.text('−' + fmt2(loss));
    }

    $rows.find('.physical-qty').on('input change', recomputeTotals);
    recomputeTotals();

    // ---- highlight a row (barcode scan target) --------------------------
    function highlightRow($tr) {
        $('.st-row-flash').removeClass('st-row-flash');
        $tr.addClass('st-row-flash');
        $('html, body').animate({ scrollTop: $tr.offset().top - 120 }, 200);
        // Auto-focus the physical-qty input for immediate correction.
        $tr.find('.physical-qty').focus().select();
    }

    // ---- Phase 7: barcode scan flow -------------------------------------
    // Scan a code → resolve + save via AJAX → highlight the row + flash the
    // saved value. The qty + reason inputs let the counter enter the count
    // before pressing Save (or Enter on the barcode field).
    var $barcodeInput  = $('#barcodeInput');
    var $barcodeQty    = $('#barcodeQty');
    var $barcodeReason = $('#barcodeReason');
    var $barcodeHint   = $('#barcodeHint');

    function submitBarcode() {
        var code = $.trim($barcodeInput.val());
        var qty  = parseFloat($barcodeQty.val());
        var reason = $.trim($barcodeReason.val());
        if (!code) { return; }
        if (isNaN(qty) || qty < 0) {
            Swal.fire({ icon:'error', title:'Invalid qty', text:'Enter a non-negative number for the quantity.' });
            return;
        }
        $barcodeHint.html('<i class="fas fa-spinner fa-spin me-1"></i>Saving…');
        $.ajax({
            url: scanUrl,
            method: 'POST',
            data: { code: code, qty: qty, reason: reason || undefined, _token: $('meta[name="csrf-token"]').attr('content') || $('input[name="_token"]').val() },
            dataType: 'json'
        }).done(function (resp) {
            if (resp && resp.status === 'success' && resp.line) {
                var line = resp.line;
                // Update the matching grid row in-place (no full reload).
                var $tr = $('tr[data-product-code="' + escapeHtml(line.product_code) + '"]');
                if ($tr.length) {
                    $tr.find('.physical-qty').val(line.physical_qty);
                    if (reason) { $tr.find('.reason-input').val(reason); }
                    $tr.data('updated-at', line.updated_at);
                    recomputeRow($tr); recomputeTotals();
                    highlightRow($tr);
                }
                $barcodeHint.html('<i class="fas fa-check text-success me-1"></i>Saved <strong>' + escapeHtml(line.product_code) + '</strong> = ' + line.physical_qty + '. Diff: ' + signed4(line.difference) + '.');
                $barcodeInput.val('').focus();
                $barcodeQty.val(0);
                $barcodeReason.val('');
            } else {
                $barcodeHint.html('<span class="text-danger">Unexpected response.</span>');
            }
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Scan failed.';
            $barcodeHint.html('<span class="text-danger"><i class="fas fa-triangle-exclamation me-1"></i>' + escapeHtml(msg) + '</span>');
        });
    }

    $('#barcodeSubmit').on('click', submitBarcode);
    // Enter on the barcode field → jump to qty (common scan-gun behaviour:
    // the scanner types the code then sends Enter).
    $barcodeInput.on('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); $barcodeQty.focus().select(); }
    });
    // Enter on qty → save immediately.
    $barcodeQty.on('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); submitBarcode(); }
    });
    $barcodeReason.on('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); submitBarcode(); }
    });

    // ---- Phase 7: bulk paste --------------------------------------------
    var $bulkText   = $('#bulkPasteText');
    var $bulkResult = $('#bulkPasteResult');

    $('#bulkPasteSubmit').on('click', function () {
        var text = $bulkText.val();
        if (!$.trim(text)) {
            Swal.fire({ icon:'info', title:'Nothing to paste', text:'Enter one or more code,qty lines first.' });
            return;
        }
        var $btn = $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Upserting…');
        $.ajax({
            url: bulkPasteUrl,
            method: 'POST',
            data: { lines: text, _token: $('meta[name="csrf-token"]').attr('content') || $('input[name="_token"]').val() },
            dataType: 'json'
        }).done(function (resp) {
            if (resp && resp.status === 'success') {
                var html = '<div class="alert alert-success py-2 mb-2"><i class="fas fa-check me-1"></i>'
                    + '<strong>' + resp.updated + '</strong> line(s) updated, <strong>' + resp.skipped + '</strong> skipped.</div>';
                if (resp.errors && resp.errors.length) {
                    html += '<div class="alert alert-warning py-2 mb-0"><strong>Skipped rows:</strong><ul class="mb-0 small">';
                    $.each(resp.errors, function (i, err) {
                        html += '<li>Line ' + err.line + ' (' + escapeHtml(err.code) + '): ' + escapeHtml(err.error) + '</li>';
                    });
                    html += '</ul></div>';
                }
                $bulkResult.html(html);
                // Reload the page so the grid reflects the batch upsert.
                setTimeout(function () { location.reload(); }, 1800);
            } else {
                $bulkResult.html('<div class="alert alert-danger py-2 mb-0">' + escapeHtml((resp && resp.message) || 'Unknown error') + '</div>');
            }
        }).fail(function (xhr) {
            $bulkResult.html('<div class="alert alert-danger py-2 mb-0">' + escapeHtml((xhr.responseJSON && xhr.responseJSON.message) || 'Request failed.') + '</div>');
        }).always(function () {
            $btn.prop('disabled', false).html('<i class="fas fa-upload me-1"></i> Upsert lines');
        });
    });

    // ---- Phase 7: CSV import --------------------------------------------
    $('#csvImportForm').on('submit', function () {
        $('#csvImportSubmit').prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-1"></i> Importing…');
        // Let the normal form submit proceed (multipart POST → redirect back).
        return true;
    });

    // ---- Phase 7: autosave (debounced, optimistic concurrency) ----------
    // Each physical-qty / reason input auto-saves 800ms after the user stops
    // typing. The row's data-updated-at is the optimistic-lock token: the
    // server rejects (409) if the row moved since the caller last saw it.
    if (editable) {
        var saveTimers = {};
        function scheduleAutosave($input) {
            var $tr = $input.closest('tr[data-row]');
            if (!$tr.length) return;
            var pid = $tr.data('product-id');
            clearTimeout(saveTimers[pid]);
            saveTimers[pid] = setTimeout(function () { doAutosave($tr); }, 800);
        }
        function doAutosave($tr) {
            var pid = $tr.data('product-id');
            var qty = parseFloat($tr.find('.physical-qty').val());
            var reason = $tr.find('.reason-input').val();
            var expectedAt = $tr.data('updated-at') || null;
            if (isNaN(qty) || qty < 0) return; // skip invalid — the submit guard handles it

            var $indicator = $tr.find('.difference-cell').first();
            $.ajax({
                url: autosaveUrl,
                method: 'POST',
                data: {
                    product_id: pid,
                    qty: qty,
                    reason: reason || undefined,
                    expected_updated_at: expectedAt,
                    _token: $('meta[name="csrf-token"]').attr('content') || $('input[name="_token"]').val()
                },
                dataType: 'json'
            }).done(function (resp) {
                if (resp && resp.status === 'saved' && resp.line) {
                    $tr.data('updated-at', resp.current_updated_at);
                    recomputeRow($tr); recomputeTotals();
                } else if (resp && resp.status === 'conflict') {
                    // Someone else saved this line — show the fresh value + prompt.
                    var line = resp.line;
                    Swal.fire({
                        icon: 'warning',
                        title: 'Line was updated elsewhere',
                        html: '<p class="text-start">Product <strong>' + escapeHtml(line.product_code) + '</strong> was saved by another user.</p>' +
                              '<p class="text-start">Their value: <strong>' + line.physical_qty + '</strong>. Reload to see the latest, or overwrite it by typing again.</p>',
                        confirmButtonText: 'Reload page'
                    }).then(function () { location.reload(); });
                }
            }).fail(function () {
                // Silent fail on autosave — the explicit Save button is the
                // authoritative path. The user will see the error on submit.
            });
        }
        $rows.find('.physical-qty, .reason-input').on('input change', function () {
            scheduleAutosave($(this));
        });
    }

    // ---- submit guard ----------------------------------------------------
    $form.on('submit', function (e) {
        var missing = 0;
        $rows.each(function () {
            var v = $(this).find('.physical-qty').val();
            if (v === '' || v === null || isNaN(parseFloat(v))) { missing++; }
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

@push('css')
<style>
/* Phase 7: mobile-friendly + scan-flash highlight */
.st-row-flash { animation: st-flash 1.2s ease-out; }
@keyframes st-flash {
    0%   { background-color: #fef08a; }
    100% { background-color: transparent; }
}
/* Touch-target sizing: 44px minimum on the toolbar buttons (mobile). */
@media (max-width: 575.98px) {
    .st-touch-btn { min-height: 44px; font-size: 1rem; }
    .st-hero { position: sticky; top: 0; z-index: 1030; }
    .st-toolbar .form-control { font-size: 16px; } /* prevents iOS zoom-on-focus */
}
/* Make the count table inputs comfortably tappable on mobile. */
@media (max-width: 575.98px) {
    #countTable .physical-qty,
    #countTable .reason-input { min-height: 40px; font-size: 16px; }
}
</style>
@endpush
@endsection
