@extends('layouts.admin')

@section('content')
@php
    $today   = now()->format('Y-m-d');
    $oldDate = old('damage_date', $today);

    // Phase 1 — reason taxonomy grouped by damage_type (from DamageReason::groupedByType()).
    $reasonsByType = $damageReasons ?? [];
    $typeLabels    = $damageTypeLabels ?? [];
    $types         = $damageTypes ?? \App\Models\DamageInvoice::DAMAGE_TYPES;

    // Pre-select old damage_type (sticky on validation error).
    $oldType = old('damage_type', 'real_damage');
    $oldReasonCode = old('reason_code', '');

    // Phase 4 — employees for the witness / accountable dropdowns. Grouped
    // by branch_id so the JS can cascade by the selected warehouse's branch
    // (an admin picking a cross-branch warehouse needs that branch's staff).
    $employees = $employees ?? collect();
    $oldWitness = old('witness_employee_id', '');
    $oldAccountable = old('accountable_employee_id', '');

    // Clean array for @json (avoids Eloquent serializing extra keys).
    $employeeOptions = $employees->map(fn ($e) => [
        'id'        => (int) $e->id,
        'code'      => $e->employee_code,
        'name'      => $e->name,
        'role'      => $e->role,
        'branch_id' => (int) $e->branch_id,
    ])->values();
@endphp

<div class="container-fluid py-2">
    {{-- Hero header (red = loss) --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#dc2626,#b91c1c);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-triangle-exclamation me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Create a draft damage invoice — no stock movement or GL posting until you confirm.
            </p>
        </div>
        <div>
            <a href="{{ route('admin.damages.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Warning banner --}}
    <div class="alert alert-danger d-flex align-items-start mb-3" role="alert">
        <i class="fas fa-triangle-exclamation me-2 fa-lg mt-1"></i>
        <div>
            <strong>Damaged stock will be written off at current average cost.</strong>
            GL posts <span class="font-monospace">Dr &lt;loss ledger&gt; / Cr Inventory</span> — the loss ledger is chosen by
            <strong>damage type</strong> (real damage → <code>damage_loss</code>; missing/theft → <code>inventory_shrinkage</code>),
            so the P&amp;L splits damage by type. Only confirmed damage invoices move stock and post to the ledger.
        </div>
    </div>

    <form method="POST" action="{{ route('admin.damages.store') }}" id="damageForm">
        @csrf

        {{-- Top: header fields --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-file-pen me-1 text-danger"></i> Damage header</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="warehouse_id">
                            Warehouse <span class="text-danger">*</span>
                        </label>
                        <select id="warehouse_id" name="warehouse_id"
                                class="form-select select2 @error('warehouse_id') is-invalid @enderror" required>
                            <option value="">Select warehouse</option>
                            @foreach ($warehouses as $wh)
                                <option value="{{ $wh->id }}"
                                    data-branch-id="{{ $wh->branch_id }}"
                                    {{ (string) old('warehouse_id') === (string) $wh->id ? 'selected' : '' }}>
                                    {{ $wh->warehouse_code }} — {{ $wh->warehouse_name }}
                                    @if ($wh->branch) ({{ $wh->branch->branch_name }}) @endif
                                </option>
                            @endforeach
                        </select>
                        @error('warehouse_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text small">
                            <i class="fas fa-circle-info me-1"></i>
                            Rate is auto-fetched from this warehouse's average cost.
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="damage_date">
                            Damage date <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="damage_date" name="damage_date"
                               class="form-control @error('damage_date') is-invalid @enderror"
                               required value="{{ $oldDate }}">
                        @error('damage_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Phase 1 — Damage type (required enum) --}}
                    <div class="col-md-4">
                        <label class="form-label" for="damage_type">
                            Damage type <span class="text-danger">*</span>
                        </label>
                        <select id="damage_type" name="damage_type"
                                class="form-select select2 @error('damage_type') is-invalid @enderror" required>
                            @foreach ($types as $t)
                                <option value="{{ $t }}" {{ $oldType === $t ? 'selected' : '' }}>
                                    {{ $typeLabels[$t] ?? $t }}
                                </option>
                            @endforeach
                        </select>
                        @error('damage_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text small">
                            <i class="fas fa-circle-info me-1"></i>
                            <strong>Missing / Theft</strong> are accountability flags — they hit
                            <code>inventory_shrinkage</code> and will require a witness / accountable employee (Phase 4).
                        </div>
                    </div>

                    {{-- Phase 1 — structured reason (filtered by damage_type via JS) --}}
                    <div class="col-md-6">
                        <label class="form-label" for="reason_code">
                            Reason
                            <span class="text-muted small">(structured — recommended)</span>
                        </label>
                        <select id="reason_code" name="reason_code"
                                class="form-select select2 @error('reason_code') is-invalid @enderror">
                            <option value="">— Select a reason —</option>
                        </select>
                        @error('reason_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text small">
                            Dropdown filters by the selected damage type. Leave blank only if none fits.
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="reason_detail">
                            Reason details
                            <span class="text-muted small">(optional)</span>
                        </label>
                        <textarea id="reason_detail" name="reason_detail" rows="2" class="form-control"
                                  placeholder="Extra context for the chosen reason (e.g. where / how it happened)">{{ old('reason_detail') }}</textarea>
                        @error('reason_detail') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>

                    {{-- Phase 1 — accountability warning (shown for missing / theft).
                         Phase 4 made the witness / accountable fields live
                         (below), so the warning now points at them instead
                         of promising a future release. --}}
                    <div class="col-12">
                        <div class="alert alert-warning d-flex align-items-start d-none mb-0" id="accountabilityWarning" role="alert">
                            <i class="fas fa-user-shield me-2 fa-lg mt-1"></i>
                            <div>
                                <strong>Accountability action.</strong>
                                Declaring stock as <span id="accountabilityTypeLabel">missing</span> is a serious
                                classification — it means the goods are <em>unaccounted for</em>, not physically damaged.
                                <span id="accountabilityRequirementText">A witness / accountable employee is required.</span>
                                Select the responsible party below before creating the draft.
                            </div>
                        </div>
                    </div>

                    {{-- Phase 4 — Witness & Accountable employee dropdowns.
                         Both are cascaded by the selected warehouse's branch
                         (an admin picking a cross-branch warehouse needs that
                         branch's employees). The required-ness is driven by
                         damage_type: missing → accountable required, theft →
                         witness required. The server (DamageService) is the
                         real gate; this JS only hints + marks the label. --}}
                    <div class="col-md-6">
                        <label class="form-label" for="witness_employee_id">
                            Witness (employee)
                            <span class="text-danger d-none" id="witnessRequiredMark">*</span>
                            <span class="text-muted small">(required for theft)</span>
                        </label>
                        <select id="witness_employee_id" name="witness_employee_id"
                                class="form-select select2 @error('witness_employee_id') is-invalid @enderror">
                            <option value="">— Select witness —</option>
                        </select>
                        @error('witness_employee_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text small">
                            <i class="fas fa-circle-info me-1"></i>
                            The employee who corroborates a theft / sensitive write-off.
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="accountable_employee_id">
                            Accountable employee
                            <span class="text-danger d-none" id="accountableRequiredMark">*</span>
                            <span class="text-muted small">(required for missing)</span>
                        </label>
                        <select id="accountable_employee_id" name="accountable_employee_id"
                                class="form-select select2 @error('accountable_employee_id') is-invalid @enderror">
                            <option value="">— Select accountable —</option>
                        </select>
                        @error('accountable_employee_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text small">
                            <i class="fas fa-circle-info me-1"></i>
                            The employee responsible for the loss. Recovery (salary deduction) is possible when set.
                        </div>
                    </div>

                    {{-- Legacy free-text reason (kept for back-compat / extra notes) --}}
                    <div class="col-12">
                        <label class="form-label" for="reason">
                            Additional notes
                            <span class="text-muted small">(optional, free text)</span>
                        </label>
                        <textarea id="reason" name="reason" rows="2" class="form-control"
                                  placeholder="Any extra note not covered by the structured reason">{{ old('reason') }}</textarea>
                        @error('reason') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- Items table --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">
                    <i class="fas fa-table-list me-1 text-danger"></i> Damaged items
                    <span class="badge bg-danger-subtle text-danger ms-1" id="itemCount">0</span>
                </h2>
                <button type="button" class="btn btn-sm btn-danger" id="addItemBtn">
                    <i class="fas fa-plus me-1"></i> Add item
                </button>
            </div>
            <div class="card-body p-0">
                {{-- Phase 7 — barcode / product-code scan input. Type or scan a --}}
                {{-- product code and press Enter: the matching product is looked --}}
                {{-- up via the AJAX search endpoint and a row is added with qty  --}}
                {{-- focused for fast entry. Manual fallback: click "Add item"   --}}
                {{-- to search by name.                                          --}}
                <div class="px-3 pt-3 pb-2 border-bottom bg-light-subtle">
                    <div class="input-group input-group-sm" style="max-width:460px;">
                        <span class="input-group-text"><i class="fas fa-barcode"></i></span>
                        <input type="text" id="barcodeScan" class="form-control"
                               placeholder="Scan / type product code, then Enter"
                               autocomplete="off">
                        <button class="btn btn-outline-danger" type="button" id="barcodeScanBtn" title="Look up this product code">
                            <i class="fas fa-magnifying-glass"></i>
                        </button>
                    </div>
                    <div class="small text-muted mt-1">
                        <i class="fas fa-circle-info me-1"></i>
                        Or click <em>Add item</em> to search products by name.
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0" id="itemsTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width:40%;">Product</th>
                                <th class="text-end" style="width:12%;">Qty</th>
                                <th class="text-end" style="width:13%;">Rate (Tk)</th>
                                <th class="text-end" style="width:13%;">Available</th>
                                <th class="text-end" style="width:15%;">Amount (Tk)</th>
                                <th class="text-center" style="width:7%;"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            {{-- Rows injected by JS --}}
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="4" class="text-end">Total damage value</td>
                                <td class="text-end" id="totalAmount">0.00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="p-3">
                    <div class="text-danger small d-none" id="itemsError">
                        <i class="fas fa-exclamation-circle me-1"></i> Add at least one item with a product and qty &gt; 0.
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit --}}
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex gap-2 justify-content-end">
                <a href="{{ route('admin.damages.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-danger" id="submitBtn">
                    <i class="fas fa-file-pen me-1"></i> Create Draft Damage
                </button>
            </div>
        </div>
    </form>
</div>

{{--
  Phase 7 — product label map (hidden template).

  No longer the dropdown source (the product picker is now AJAX-driven, with
  no 500-row cap). This template survives ONLY to resolve the human-readable
  label for a sticky row after a validation error (old('items') carries just
  the product_id; we look up "code — name" here so the AJAX Select2 can render
  the pre-selected option's text). The 500-row cap only affects this fallback
  label resolution, not live search.
--}}
<template id="productOptionsTpl">
    <option value="">Select product</option>
    @foreach ($products as $p)
        <option value="{{ $p->id }}">{{ $p->product_code }} — {{ $p->product_name }}</option>
    @endforeach
</template>

@push('css')
<style>
    {{-- Phase 7 — mobile responsive item table. On small screens the table    --}}
    {{-- collapses into stacked cards (one per row) using data-label attrs    --}}
    {{-- set in buildRow(). The desktop table is untouched above the sm/md    --}}
    {{-- breakpoint.                                                          --}}
    @@media (max-width: 767.98px) {
        /* Hide the table headers — each cell renders its own label via ::before */
        #itemsTable thead { display: none; }

        /* Table → stacked-card layout (block-level, full width) */
        #itemsTable,
        #itemsTable tbody,
        #itemsTable tr,
        #itemsTable td { display: block; width: 100%; }

        /* Each row becomes a card */
        #itemsTable tbody tr {
            margin-bottom: 12px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 4px 8px;
            background: #fff;
        }

        /* Each body cell becomes a flex row: label on the left, control on the right */
        #itemsTable tbody td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            text-align: right;
            border: 0;
            border-bottom: 1px solid #f1f5f9;
            padding: 8px 4px;
            min-height: 44px;
        }
        #itemsTable tbody td:last-child { border-bottom: 0; }

        /* Cell label from data-label attribute (left side, fixed width) */
        #itemsTable tbody td::before {
            content: attr(data-label);
            font-weight: 600;
            color: #475569;
            text-align: left;
            flex: 0 0 38%;
        }

        /* Cells with empty data-label (the remove-button column) get no
           label at all — the button takes the full row, right-aligned. */
        #itemsTable tbody td[data-label=""]::before { display: none; }
        #itemsTable tbody td[data-label=""] { justify-content: flex-end; }

        /* Form controls inside body cells take the remaining width */
        #itemsTable tbody td .form-control,
        #itemsTable tbody td .form-select,
        #itemsTable tbody td .select2-container {
            width: 100% !important;
            flex: 1 1 auto;
            min-width: 0;
        }
        /* The hidden original <select> must not steal flex space */
        #itemsTable tbody td .select2-hidden-accessible {
            position: absolute !important;
            width: 1px !important;
        }

        /* Total row (tfoot) — render as a single horizontal bar:
           "Total damage value" on the left, amount on the right.
           The colspan="4" is ignored in flex/block layout, so we explicitly
           lay the cells out side-by-side and hide the empty placeholder cell. */
        #itemsTable tfoot {
            display: block;
            width: 100%;
            margin-top: 8px;
        }
        #itemsTable tfoot tr {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #dee2e6;
            background: #f8fafc;
            padding: 10px 14px;
            border-radius: 8px;
            font-weight: 600;
        }
        /* tfoot cells: block (not flex), no ::before label */
        #itemsTable tfoot td {
            display: block;
            border: 0;
            padding: 0;
            min-height: 0;
            background: transparent;
        }
        #itemsTable tfoot td::before { display: none; }
        /* Hide the empty placeholder <td></td> after the total */
        #itemsTable tfoot td:empty { display: none; }
        /* "Total damage value" label takes the left side; amount stays right */
        #itemsTable tfoot td:first-child { flex: 1 1 auto; text-align: left; }
        #itemsTable tfoot td#totalAmount { flex: 0 0 auto; text-align: right; }

        /* The barcode scan input should be full-width on mobile (the 460px
           cap is for desktop). The input-group's text + button stay glued. */
        .card-body .input-group { max-width: 100% !important; }

        /* Let the cards breathe inside the table-responsive wrapper — the
           stacked layout never overflows horizontally. */
        .table-responsive { overflow-x: visible; }
    }
</style>
@endpush

@push('scripts')
<script>
$(function () {
    var productStockUrl  = '{{ route("admin.damages.product-stock") }}';
    // Phase 7 — AJAX product search endpoint (replaces the 500-cap dropdown).
    var productsSearchUrl = '{{ route("admin.damages.products.search") }}';
    var $form        = $('#damageForm');
    var $warehouse   = $('#warehouse_id');
    var $tbody       = $('#itemsBody');
    var $totalAmount = $('#totalAmount');
    var $itemCount   = $('#itemCount');
    var $itemsError  = $('#itemsError');
    var rowIndex     = 0;

    // Phase 7 — product label map (id -> "code — name") for sticky rows.
    // Populated from the hidden #productOptionsTpl template (server-rendered
    // 500-cap list). Used ONLY to render the text of a pre-selected option on
    // a validation-error reload; live search is AJAX (no cap).
    var productLabelMap = {};
    $('#productOptionsTpl option[value]').each(function () {
        var v = $(this).val();
        if (v) productLabelMap[v] = $(this).text();
    });

    // Minimal HTML escaper for Select2 templateResult/templateSelection.
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Init select2 on header selects
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    // ====== Phase 1 — Damage type & reason taxonomy ======
    // reasonsByType: { 'real_damage': [{code,label},...], 'missing': [...], ... }
    var reasonsByType = @json($reasonsByType);
    var typeLabels    = @json($typeLabels);
    var $damageType   = $('#damage_type');
    var $reasonCode   = $('#reason_code');
    var $warn         = $('#accountabilityWarning');
    var $warnLabel    = $('#accountabilityTypeLabel');
    var $warnReqText  = $('#accountabilityRequirementText');
    var $witnessMark  = $('#witnessRequiredMark');
    var $acctMark     = $('#accountableRequiredMark');
    // Sticky value on validation error.
    var oldReasonCode = @json($oldReasonCode);

    // ====== Phase 4 — Witness & Accountable employee ======
    // employees: [{id, code, name, role, branch_id}, ...]
    var employees     = @json($employeeOptions);
    var oldWitness    = @json($oldWitness ? (string) $oldWitness : '');
    var oldAccountable = @json($oldAccountable ? (string) $oldAccountable : '');

    // Build warehouse→branch map from the data-branch-id attributes the blade
    // rendered on each warehouse <option>. Used to cascade the employee
    // dropdowns by the selected warehouse's branch.
    var warehouseBranchMap = {};
    $('#warehouse_id option[data-branch-id]').each(function () {
        var wid = $(this).val();
        if (wid) {
            warehouseBranchMap[wid] = parseInt($(this).attr('data-branch-id'), 10);
        }
    });

    var $witnessSel  = $('#witness_employee_id');
    var $acctSel     = $('#accountable_employee_id');

    // damage_type → required party. Must stay in sync with
    // config/damage.php 'accountability' and DamageService::validateAccountability.
    var ACCOUNTABLE_REQUIRED_TYPES = ['missing'];
    var WITNESS_REQUIRED_TYPES     = ['theft'];
    var ACCOUNTABILITY_TYPES = ACCOUNTABLE_REQUIRED_TYPES.concat(WITNESS_REQUIRED_TYPES);

    /**
     * Repopulate an employee <select> with the active employees belonging to
     * the given branch. Preserves the selected value if it's still in the
     * filtered set (sticky on validation error). Pass branchId=0/null to
     * show all (used before a warehouse is picked, as a fallback).
     */
    function populateEmployees($select, branchId, selectedId) {
        var list = employees.filter(function (e) {
            return !branchId || e.branch_id === branchId;
        });
        var placeholder = $select.attr('id') === 'witness_employee_id'
            ? '— Select witness —' : '— Select accountable —';
        $select.empty().append($('<option>').val('').text(placeholder));
        list.forEach(function (e) {
            var label = e.code + ' — ' + e.name + ' (' + e.role + ')';
            var $opt = $('<option>').val(e.id).text(label);
            if (selectedId && String(e.id) === String(selectedId)) {
                $opt.prop('selected', true);
            }
            $select.append($opt);
        });
        if ($select.hasClass('select2-hidden-accessible')) {
            $select.trigger('change.select2');
        }
    }

    /**
     * Cascade both employee dropdowns by the selected warehouse's branch.
     */
    function refreshEmployeesByWarehouse() {
        var wid = $warehouse.val();
        var branchId = wid ? (warehouseBranchMap[wid] || 0) : 0;
        populateEmployees($witnessSel, branchId, oldWitness);
        populateEmployees($acctSel,    branchId, oldAccountable);
    }

    /**
     * Repopulate the reason dropdown with only the reasons belonging to the
     * given damage_type. Preserves the selected value if still valid.
     */
    function populateReasons(type, selectedCode) {
        var list = (reasonsByType[type] || []);
        $reasonCode.empty().append($('<option>').val('').text('— Select a reason —'));
        list.forEach(function (r) {
            var $opt = $('<option>').val(r.code).text(r.label);
            if (selectedCode && r.code === selectedCode) {
                $opt.prop('selected', true);
            }
            $reasonCode.append($opt);
        });
        // Re-sync select2 if it was already initialized on this element.
        if ($reasonCode.hasClass('select2-hidden-accessible')) {
            $reasonCode.trigger('change.select2');
        }
    }

    /**
     * Show / hide the accountability warning + required marks based on type.
     * Phase 4: the warning now points at the live witness/accountable fields
     * below it (no longer "arrives in Phase 4"), and the requirement text +
     * red asterisks update to match which party is mandatory.
     */
    function toggleAccountabilityWarning(type) {
        var needsAccountable = ACCOUNTABLE_REQUIRED_TYPES.indexOf(type) !== -1;
        var needsWitness     = WITNESS_REQUIRED_TYPES.indexOf(type) !== -1;

        // Required marks (red asterisks) on the labels.
        $witnessMark.toggleClass('d-none', !needsWitness);
        $acctMark.toggleClass('d-none', !needsAccountable);

        if (ACCOUNTABILITY_TYPES.indexOf(type) !== -1) {
            $warnLabel.text(typeLabels[type] || type);
            // Tailor the requirement sentence to the type.
            if (needsAccountable) {
                $warnReqText.html('An <strong>accountable employee</strong> is required — someone must be named responsible for the unaccounted-for stock.');
            } else if (needsWitness) {
                $warnReqText.html('A <strong>witness employee</strong> is required — a theft write-off must be corroborated by a second person.');
            }
            $warn.removeClass('d-none');
        } else {
            $warn.addClass('d-none');
        }
    }

    // When damage_type changes: refresh the reason dropdown + warning + marks.
    $damageType.on('change', function () {
        var type = $(this).val();
        populateReasons(type, '');
        toggleAccountabilityWarning(type);
    });

    // When warehouse changes: cascade the employee dropdowns to the new
    // branch (so the witness/accountable options always match the warehouse).
    $warehouse.on('change', refreshEmployeesByWarehouse);

    // Initial population (on first load + sticky on validation error).
    (function init() {
        var type = $damageType.val();
        if (type) {
            populateReasons(type, oldReasonCode);
            toggleAccountabilityWarning(type);
        }
        // Seed the employee dropdowns for the pre-selected warehouse (or all
        // if none selected yet — the user can still pick one before choosing
        // a warehouse, though the server re-validates branch on submit).
        refreshEmployeesByWarehouse();
    })();

    // ====== Item row helpers ======

    /**
     * Build a new item row.
     *
     * Phase 7 — the product picker is now an AJAX Select2 (no 500-cap). The
     * optional `preset` ({id, text}) pre-selects an option — used for barcode
     * scan results and for sticky rows after a validation error (the label is
     * resolved from #productOptionsTpl via productLabelMap).
     *
     * Each <td> carries a data-label so the responsive CSS block above
     * can collapse the table into stacked cards on mobile.
     */
    function buildRow(idx, preset) {
        preset = preset || null;
        var $tr = $('<tr>').attr('data-row', idx);

        // Product select — AJAX-driven.
        var $sel = $('<select>').attr({
            name: 'items[' + idx + '][product_id]',
            class: 'form-select form-select-sm select2-row product-select',
            required: true
        });
        if (preset && preset.id) {
            $('<option>').val(preset.id).text(preset.text).prop('selected', true).appendTo($sel);
        } else {
            $('<option>').val('').text('Type to search product…').appendTo($sel);
        }

        var $tdProduct = $('<td>').attr('data-label', 'Product').append($sel);

        // Qty input
        var $qty = $('<input>').attr({
            type: 'number',
            name: 'items[' + idx + '][qty]',
            class: 'form-control form-control-sm text-end qty-input',
            min: '0.001',
            step: '0.001',
            required: true,
            placeholder: '0.000'
        });

        // Rate input (auto-filled from AJAX; READONLY — avg cost is the only
        // valid rate for a damage write-off, matching legacy behavior. The
        // server re-validates and falls back to avg cost if rate <= 0.)
        var $rate = $('<input>').attr({
            type: 'number',
            name: 'items[' + idx + '][rate]',
            class: 'form-control form-control-sm text-end rate-input bg-light',
            min: '0',
            step: '0.01',
            readonly: true,
            placeholder: '0.00'
        });

        // Available (display only, from AJAX)
        var $avail = $('<input>').attr({
            type: 'text',
            class: 'form-control form-control-sm text-end available-input bg-light',
            readonly: true,
            placeholder: '—'
        });

        // Amount (display only)
        var $amt = $('<input>').attr({
            type: 'text',
            class: 'form-control form-control-sm text-end amount-input bg-light',
            readonly: true
        });
        $amt.val('0.00');

        // Remove button
        var $rm = $('<button>').attr({
            type: 'button',
            class: 'btn btn-sm btn-outline-danger remove-row',
            title: 'Remove item'
        }).html('<i class="fas fa-trash"></i>');

        $tr.append($tdProduct)
           .append($('<td class="text-end">').attr('data-label', 'Qty').append($qty))
           .append($('<td class="text-end">').attr('data-label', 'Rate (Tk)').append($rate))
           .append($('<td class="text-end">').attr('data-label', 'Available').append($avail))
           .append($('<td class="text-end">').attr('data-label', 'Amount (Tk)').append($amt))
           .append($('<td class="text-center">').attr('data-label', '').append($rm));

        $tbody.append($tr);

        // Initialize the AJAX Select2 on the new product select.
        $sel.select2({
            theme: 'bootstrap-5',
            width: '100%',
            minimumInputLength: 1,
            placeholder: 'Type to search product…',
            allowClear: true,
            ajax: {
                url: productsSearchUrl,
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return { q: params.term || '', warehouse_id: $warehouse.val() || '' };
                },
                processResults: function (data) {
                    return { results: data.results || [] };
                }
            },
            templateResult: function (r) {
                if (!r.id) return r.text;
                return $('<span><strong>' + escapeHtml(r.product_code || '') + '</strong> — '
                    + escapeHtml(r.product_name || '') + '</span>');
            },
            templateSelection: function (r) {
                if (!r.id) return r.text || 'Type to search product…';
                // For a pre-selected option, r.text is already "code — name".
                return r.text || (r.product_code + ' — ' + r.product_name);
            }
        });

        // Wire events
        $sel.on('select2:select', function () { onProductChange($tr); });
        $qty.on('input',  function () { recomputeRow($tr); });
        // Note: rate input is readonly (auto-fetched avg cost) — no input
        // listener needed.
        $rm.on('click',   function () {
            $tr.remove();
            recomputeTotal();
        });

        recomputeTotal();
        return $tr;
    }

    /**
     * Phase 7 — barcode / product-code scan. Looks up the scanned code via the
     * AJAX search endpoint; prefers an exact product_code match, else takes a
     * single-result match. Adds a row with the product pre-selected and qty
     * focused for fast entry. Clears the scan input on success.
     */
    function scanBarcode(code) {
        code = (code || '').trim();
        if (!code) return;
        $.ajax({
            url: productsSearchUrl,
            type: 'GET',
            data: { q: code, warehouse_id: $warehouse.val() || '' },
            dataType: 'json'
        }).done(function (data) {
            var results = data.results || [];
            var match = null;
            for (var i = 0; i < results.length; i++) {
                if (results[i].product_code
                    && results[i].product_code.toLowerCase() === code.toLowerCase()) {
                    match = results[i];
                    break;
                }
            }
            if (!match && results.length === 1) match = results[0];

            if (!match) {
                Swal.fire({
                    icon: 'warning',
                    title: 'No exact match',
                    html: 'No product code exactly matches <strong>' + escapeHtml(code) + '</strong>.<br>Use the search box in a row to pick by name.',
                    confirmButtonText: 'OK'
                });
                return;
            }
            var $tr = buildRow(rowIndex++, { id: match.id, text: match.text });
            // Focus qty for fast entry after a scan.
            $tr.find('.qty-input').focus();
            // Fetch rate/available if a warehouse is selected.
            if ($warehouse.val()) onProductChange($tr);
            $('#barcodeScan').val('').focus();
        }).fail(function () {
            Swal.fire({
                icon: 'error',
                title: 'Search failed',
                text: 'Could not search products. Check your connection and retry.',
                confirmButtonText: 'OK'
            });
        });
    }

    function onProductChange($tr) {
        var pid = parseInt($tr.find('.product-select').val(), 10);
        var wid = parseInt($warehouse.val(), 10);
        var $rate  = $tr.find('.rate-input');
        var $avail = $tr.find('.available-input');
        var $amt   = $tr.find('.amount-input');

        $avail.val('loading…');

        if (!pid || !wid) {
            $rate.val('');
            $avail.val('—');
            $amt.val('0.00');
            recomputeTotal();
            return;
        }

        $.ajax({
            url: productStockUrl,
            type: 'GET',
            data: { product_id: pid, warehouse_id: wid },
            dataType: 'json'
        }).done(function (data) {
            // Rate is readonly — just set the value (avg cost).
            $rate.val(Number(data.rate).toFixed(2));
            $avail.val(Number(data.available_qty).toFixed(4));
            recomputeRow($tr);
        }).fail(function () {
            $rate.val('');
            $avail.val('error');
            $amt.val('0.00');
            recomputeTotal();
        });
    }

    function recomputeRow($tr) {
        var qty  = parseFloat($tr.find('.qty-input').val())  || 0;
        var rate = parseFloat($tr.find('.rate-input').val()) || 0;
        var amt  = qty * rate;
        $tr.find('.amount-input').val(amt.toFixed(2));
        recomputeTotal();
    }

    function recomputeTotal() {
        var total = 0;
        var rows  = 0;
        $tbody.find('tr').each(function () {
            var amt = parseFloat($(this).find('.amount-input').val()) || 0;
            total += amt;
            rows++;
        });
        // #totalAmount is a <td>, so use .text() not .val()
        $totalAmount.text(total.toFixed(2));
        $itemCount.text(rows);
        $itemsError.toggleClass('d-none', rows > 0);
    }

    // ====== Add item button ======
    $('#addItemBtn').on('click', function () {
        buildRow(rowIndex++);
    });

    // ====== Phase 7 — barcode scan wiring ======
    // Enter (or the barcode scanner's auto-Enter) triggers a lookup; the
    // magnifier button does the same for manual entry.
    $('#barcodeScan').on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            scanBarcode($(this).val());
        }
    });
    $('#barcodeScanBtn').on('click', function () {
        scanBarcode($('#barcodeScan').val());
    });

    // ====== When warehouse changes: refresh rates for all rows ======
    $warehouse.on('change', function () {
        $tbody.find('tr').each(function () {
            var $tr = $(this);
            if ($tr.find('.product-select').val()) {
                onProductChange($tr);
            } else {
                // No product yet — clear rate/available
                $tr.find('.rate-input').val('');
                $tr.find('.available-input').val('—');
                $tr.find('.amount-input').val('0.00');
            }
        });
        recomputeTotal();
    });

    // ====== Pre-populate old input on validation errors ======
    @if (old('items'))
        var oldItems = @json(old('items'));
        oldItems.forEach(function (item) {
            // Phase 7 — an AJAX Select2 can't render a pre-selected option
            // without its text, so resolve the label from productLabelMap
            // (the hidden #productOptionsTpl fallback). Falls back to
            // "Product #id" if the product isn't in the 500-cap map.
            var preset = item.product_id
                ? { id: item.product_id, text: productLabelMap[item.product_id] || ('Product #' + item.product_id) }
                : null;
            var $tr = buildRow(rowIndex++, preset);
            if (item.qty)  $tr.find('.qty-input').val(item.qty);
            if (item.rate) $tr.find('.rate-input').val(item.rate);
            // Re-fetch rate/available if both product & warehouse are set
            if (item.product_id && $warehouse.val()) {
                onProductChange($tr);
            } else {
                recomputeRow($tr);
            }
        });
    @else
        // Seed with one empty row by default
        buildRow(rowIndex++);
    @endif

    // ====== Submit guard ======
    $form.on('submit', function (e) {
        // Phase 4 — client-side accountability hint. The server
        // (DamageService::validateAccountability) is the real gate; this
        // just saves a round-trip when the user forgot the required party.
        var type = $damageType.val();
        if (ACCOUNTABLE_REQUIRED_TYPES.indexOf(type) !== -1 && !$acctSel.val()) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Accountable employee required',
                html: 'A <strong>' + (typeLabels[type] || type) + '</strong> damage requires an accountable employee — someone must be named responsible for the unaccounted-for stock.',
                confirmButtonText: 'OK'
            });
            return false;
        }
        if (WITNESS_REQUIRED_TYPES.indexOf(type) !== -1 && !$witnessSel.val()) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Witness required',
                html: 'A <strong>' + (typeLabels[type] || type) + '</strong> damage requires a witness employee — a theft write-off must be corroborated by a second person.',
                confirmButtonText: 'OK'
            });
            return false;
        }

        var rows = $tbody.find('tr').length;
        if (rows === 0) {
            e.preventDefault();
            $itemsError.removeClass('d-none');
            Swal.fire({
                icon: 'error',
                title: 'No items',
                text: 'Add at least one product line before creating the damage invoice.',
                confirmButtonText: 'OK'
            });
            return false;
        }
        // Validate each row has product + qty
        var invalid = 0;
        $tbody.find('tr').each(function () {
            var pid = $(this).find('.product-select').val();
            var qty = parseFloat($(this).find('.qty-input').val());
            if (!pid || !qty || qty <= 0) invalid++;
        });
        if (invalid > 0) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Incomplete items',
                text: invalid + ' row(s) are missing a product or have qty ≤ 0. Please fix or remove them.',
                confirmButtonText: 'OK'
            });
            return false;
        }
        $('#submitBtn').prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-1"></i> Saving…');
    });
});
</script>
@endpush
@endsection
