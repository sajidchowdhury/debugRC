@extends('layouts.admin')

@section('content')
@php
    $today  = now()->format('Y-m-d');
    $oldDate = old('session_date', $today);

    // Group warehouses by branch_id so we can render them as sections.
    $warehousesByBranch = $warehouses->groupBy('branch_id');
    $oldWhIds = (array) old('warehouse_ids', []);
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-plus me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Create a draft stock take session — pick the branch, date and the warehouses to count.
            </p>
        </div>
        <div>
            <a href="{{ route('admin.stock-take.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    <form method="POST" action="{{ route('admin.stock-take.store') }}" id="sessionForm">
        @csrf

        {{-- Header fields --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-primary"></i> Session header</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label" for="branch_id">
                            Branch <span class="text-danger">*</span>
                        </label>
                        <select id="branch_id" name="branch_id"
                                class="form-select select2 @error('branch_id') is-invalid @enderror" required>
                            <option value="">Select branch</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}"
                                    {{ (string) old('branch_id') === (string) $b->id ? 'selected' : '' }}>
                                    {{ $b->branch_code }} — {{ $b->branch_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text small">
                            <i class="fas fa-circle-info me-1"></i>
                            The branch scope for this session — typically your operating branch.
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="session_date">
                            Session date <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="session_date" name="session_date"
                               class="form-control @error('session_date') is-invalid @enderror"
                               required value="{{ $oldDate }}">
                        @error('session_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-3 d-flex align-items-end">
                        <div class="form-text small w-100">
                            <i class="fas fa-circle-info me-1 text-info"></i>
                            Status starts as <span class="badge bg-warning-subtle text-warning">Draft</span>.
                            Counts can be entered once the session is created.
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea id="notes" name="notes" rows="2" class="form-control"
                                  placeholder="Optional context — e.g. Q3 2024 annual stocktake.">{{ old('notes') }}</textarea>
                        @error('notes') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- Phase 3: Outbound freeze option --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0">
                    <i class="fas fa-snowflake me-1 text-info"></i> Stock integrity options
                </h2>
            </div>
            <div class="card-body">
                <div class="form-check form-switch">
                    @php
                        $freezeChecked = old('freeze_outbound', false) ? 'checked' : '';
                    @endphp
                    <input class="form-check-input" type="checkbox" role="switch"
                           name="freeze_outbound" value="1" id="freeze_outbound"
                           {{ $freezeChecked }}>
                    <label class="form-check-label" for="freeze_outbound">
                        <span class="fw-semibold">Freeze outbound movements during the count</span>
                    </label>
                </div>
                <div class="form-text small mt-2">
                    <i class="fas fa-circle-info me-1 text-info"></i>
                    When ON, sales, transfers out, stock adjustments out, and damages are
                    <strong>blocked</strong> for the selected warehouses while this session is active
                    (draft or counting). This guarantees the physical count is not corrupted by
                    concurrent stock movements — use for <em>full annual counts</em>.
                    Leave OFF for <em>cycle counts</em> where business must continue; in that case a
                    reconciliation warning is shown at post time if any stock drifted from the snapshot.
                    A historical <code>count_snapshot</code> (product list + system qty + avg cost) is
                    captured at setup either way, so the count can be reconstructed later.
                </div>
            </div>
        </div>

        {{-- Phase 5: Cycle count scope --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">
                    <i class="fas fa-bullseye me-1 text-success"></i> Count scope
                    <span class="badge bg-success-subtle text-success ms-1" id="scopeBadge">Full</span>
                </h2>
                <a href="{{ route('admin.stock-take.abc-report') }}" class="btn btn-outline-secondary btn-sm" target="_blank">
                    <i class="fas fa-chart-bar me-1"></i> ABC report
                </a>
            </div>
            <div class="card-body">
                <div class="form-text small mb-3">
                    <i class="fas fa-circle-info me-1 text-info"></i>
                    A <strong>full</strong> count loads every active product (the default — use for annual stocktaking).
                    A <strong>cycle count</strong> narrows the product set so high-value movers can be counted more
                    often than dead stock. The scope is applied when you click <em>Setup counts</em> per warehouse.
                </div>

                {{-- Scope selector: 7 radio cards in a responsive grid --}}
                @php
                    $scopes = [
                        'full'          => ['icon' => 'fa-warehouse',       'label' => 'Full warehouse',  'desc' => 'Every active product'],
                        'category'      => ['icon' => 'fa-layer-group',     'label' => 'By category',     'desc' => 'Products in chosen categories'],
                        'abc'           => ['icon' => 'fa-chart-line',      'label' => 'ABC class',       'desc' => 'High-value movers (A/B/C)'],
                        'group'         => ['icon' => 'fa-object-group',    'label' => 'By product group','desc' => 'Products in chosen groups'],
                        'ad_hoc'        => ['icon' => 'fa-list-check',      'label' => 'Ad-hoc products', 'desc' => 'An explicit product list'],
                        'negative_only' => ['icon' => 'fa-circle-exclamation','label' => 'Negative stock', 'desc' => 'Only products with qty &lt; 0'],
                        'zero_only'     => ['icon' => 'fa-infinity',        'label' => 'Zero stock',      'desc' => 'Only products with qty = 0'],
                    ];
                    $oldScope = old('count_scope', 'full');
                @endphp
                <div class="row g-2 mb-3">
                    @foreach ($scopes as $key => $meta)
                        <div class="col-6 col-md-3">
                            <label class="d-block">
                                <input type="radio" name="count_scope" value="{{ $key }}"
                                       class="btn-check scope-radio" id="scope_{{ $key }}"
                                       {{ $oldScope === $key ? 'checked' : '' }}>
                                <div class="border rounded-3 p-2 h-100 scope-card text-center" data-scope="{{ $key }}">
                                    <i class="fas {{ $meta['icon'] }} d-block mb-1"></i>
                                    <span class="fw-semibold small d-block">{{ $meta['label'] }}</span>
                                    <span class="text-muted" style="font-size:.7rem">{!! $meta['desc'] !!}</span>
                                </div>
                            </label>
                        </div>
                    @endforeach
                </div>
                @error('count_scope') <div class="text-danger small mb-2">{{ $message }}</div> @enderror

                {{-- Payload sections: shown only for the active scope --}}
                <div id="payloadContainer">

                    {{-- full / negative_only / zero_only : no payload, just a note --}}
                    <div class="scope-payload" data-for="full">
                        <div class="alert alert-light border small mb-0">
                            <i class="fas fa-circle-info me-1"></i> Every active product in each selected warehouse will be loaded for counting.
                        </div>
                    </div>
                    <div class="scope-payload" data-for="negative_only">
                        <div class="alert alert-warning border small mb-0">
                            <i class="fas fa-triangle-exclamation me-1"></i> Only products whose on-hand qty is <strong>negative</strong> (oversold / data errors) will be loaded. These are the items that most need a recount.
                        </div>
                    </div>
                    <div class="scope-payload" data-for="zero_only">
                        <div class="alert alert-secondary border small mb-0">
                            <i class="fas fa-infinity me-1"></i> Only products whose on-hand qty is <strong>zero</strong> (dead stock — no warehouse_stock row or qty = 0) will be loaded. Useful for periodic dead-stock clearance.
                        </div>
                    </div>

                    {{-- category --}}
                    <div class="scope-payload" data-for="category">
                        @php $oldCatIds = (array) old('count_scope_payload.category_ids', []); @endphp
                        <label class="form-label fw-semibold">Categories to count <span class="text-danger">*</span></label>
                        <div class="border rounded-3 p-2 mb-2" style="max-height:200px;overflow:auto">
                            @forelse ($categories as $c)
                                <div class="form-check">
                                    <input class="form-check-input cat-cb" type="checkbox"
                                           name="count_scope_payload[category_ids][]" value="{{ $c->id }}"
                                           id="cat_{{ $c->id }}"
                                           {{ in_array((string)$c->id, array_map('strval', $oldCatIds)) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="cat_{{ $c->id }}">{{ $c->category_name }}</label>
                                </div>
                            @empty
                                <div class="text-muted small p-2">No active categories. Add categories first.</div>
                            @endforelse
                        </div>
                        <div class="form-text small">Select one or more categories. Only products in these categories will be counted.</div>
                    </div>

                    {{-- group --}}
                    <div class="scope-payload" data-for="group">
                        @php $oldGroupIds = (array) old('count_scope_payload.group_ids', []); @endphp
                        <label class="form-label fw-semibold">Product groups to count <span class="text-danger">*</span></label>
                        <div class="border rounded-3 p-2 mb-2" style="max-height:200px;overflow:auto">
                            @forelse ($groups as $g)
                                <div class="form-check">
                                    <input class="form-check-input group-cb" type="checkbox"
                                           name="count_scope_payload[group_ids][]" value="{{ $g->id }}"
                                           id="grp_{{ $g->id }}"
                                           {{ in_array((string)$g->id, array_map('strval', $oldGroupIds)) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="grp_{{ $g->id }}">{{ $g->group_name }}</label>
                                </div>
                            @empty
                                <div class="text-muted small p-2">No active product groups. Add groups first.</div>
                            @endforelse
                        </div>
                        <div class="form-text small">Select one or more product groups.</div>
                    </div>

                    {{-- abc --}}
                    <div class="scope-payload" data-for="abc">
                        @php $oldClasses = (array) old('count_scope_payload.abc_classes', ['A']); @endphp
                        <label class="form-label fw-semibold">ABC classes to count <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3 mb-2">
                            @foreach (['A' => 'Top movers', 'B' => 'Medium', 'C' => 'Low/dead'] as $cls => $lbl)
                                <div class="form-check">
                                    <input class="form-check-input abc-cb" type="checkbox"
                                           name="count_scope_payload[abc_classes][]" value="{{ $cls }}"
                                           id="abc_{{ $cls }}"
                                           {{ in_array($cls, $oldClasses) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="abc_{{ $cls }}">
                                        <span class="badge bg-{{ $cls === 'A' ? 'success' : ($cls === 'B' ? 'warning' : 'secondary') }} me-1">{{ $cls }}</span>
                                        {{ $lbl }}
                                    </label>
                                </div>
                            @endforeach
                        </div>
                        {{-- ABC summary card --}}
                        @php $abc = $abcSummary['classes'] ?? []; @endphp
                        <div class="row g-2 mb-2">
                            @foreach (['A','B','C'] as $cls)
                                @php $row = $abc[$cls] ?? ['count'=>0,'total_usage_value'=>0,'share'=>0]; @endphp
                                <div class="col-4">
                                    <div class="border rounded-3 p-2 text-center">
                                        <div class="badge bg-{{ $cls === 'A' ? 'success' : ($cls === 'B' ? 'warning' : 'secondary') }} mb-1">Class {{ $cls }}</div>
                                        <div class="fw-bold">{{ number_format($row['count']) }}</div>
                                        <div class="text-muted" style="font-size:.7rem">products</div>
                                        <div class="small">{{ number_format($row['share'] * 100, 1) }}% of value</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="form-text small">
                            <i class="fas fa-clock me-1"></i>
                            Classification computed @if ($abcSummary['computed_at']) on {{ \Carbon\Carbon::parse($abcSummary['computed_at'])->format('d M Y H:i') }} @else (never — run a refresh) @endif.
                            Annual usage value = outbound consumption over the lookback window.
                            <a href="{{ route('admin.stock-take.abc-report') }}" target="_blank">View full report →</a>
                        </div>
                    </div>

                    {{-- ad_hoc --}}
                    <div class="scope-payload" data-for="ad_hoc">
                        <label class="form-label fw-semibold">Products to count <span class="text-danger">*</span></label>
                        <select id="adHocPicker" class="form-select select2" multiple
                                data-placeholder="Search by product code or name…"></select>
                        <div id="adHocHidden" class="mt-2"></div>
                        <div class="form-text small">
                            Pick one or more products. Each selected product gets a count line in every selected warehouse (system_qty from that warehouse's stock).
                        </div>
                    </div>
                </div>

                {{-- Live preview --}}
                <div class="mt-3 border-top pt-3">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <button type="button" class="btn btn-outline-success btn-sm" id="previewBtn">
                            <i class="fas fa-magnifying-glass me-1"></i> Preview product count
                        </button>
                        <span class="text-muted small">Checks how many products the current scope + first selected warehouse will load.</span>
                    </div>
                    <div id="previewResult" class="mt-2" style="display:none"></div>
                </div>
            </div>
        </div>

        {{-- Warehouses to count --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">
                    <i class="fas fa-warehouse me-1 text-primary"></i> Warehouses to count
                    <span class="badge bg-primary-subtle text-primary ms-1" id="whCount">0</span>
                </h2>
                <span class="text-muted small">
                    <i class="fas fa-hand-pointer me-1"></i>Select at least one warehouse.
                </span>
            </div>
            <div class="card-body">
                @error('warehouse_ids')
                    <div class="alert alert-danger py-2 small">
                        <i class="fas fa-exclamation-circle me-1"></i>{{ $message }}
                    </div>
                @enderror

                @if ($warehouses->isEmpty())
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                        No active warehouses available. Add warehouses first.
                    </div>
                @else
                    <div class="row g-3">
                        @foreach ($branches as $b)
                            @php
                                $branchWhs = $warehousesByBranch->get($b->id, collect());
                                if ($branchWhs->isEmpty()) {
                                    continue;
                                }
                            @endphp
                            <div class="col-md-6">
                                <div class="border rounded-3 p-3 h-100" data-branch-card="{{ $b->id }}">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h3 class="h6 mb-0">
                                            <i class="fas fa-building me-1 text-secondary"></i>{{ $b->branch_name }}
                                            <span class="small text-muted">({{ $b->branch_code }})</span>
                                        </h3>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input select-all-branch" type="checkbox"
                                                   role="switch" id="all_branch_{{ $b->id }}"
                                                   data-branch-id="{{ $b->id }}">
                                            <label class="form-check-label small" for="all_branch_{{ $b->id }}">
                                                Select all
                                            </label>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-column gap-2">
                                        @foreach ($branchWhs as $wh)
                                            @php
                                                $checked = in_array((string) $wh->id, array_map('strval', $oldWhIds));
                                            @endphp
                                            <div class="form-check">
                                                <input class="form-check-input wh-checkbox" type="checkbox"
                                                       name="warehouse_ids[]"
                                                       value="{{ $wh->id }}"
                                                       id="wh_{{ $wh->id }}"
                                                       data-branch-id="{{ $b->id }}"
                                                       {{ $checked ? 'checked' : '' }}>
                                                <label class="form-check-label" for="wh_{{ $wh->id }}">
                                                    <span class="fw-semibold">{{ $wh->warehouse_name }}</span>
                                                    <span class="small text-muted">({{ $wh->warehouse_code }})</span>
                                                    @if ($wh->location)
                                                        <span class="small text-muted">· {{ $wh->location }}</span>
                                                    @endif
                                                </label>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Submit --}}
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex gap-2 justify-content-end">
                <a href="{{ route('admin.stock-take.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-success" id="submitBtn">
                    <i class="fas fa-file-pen me-1"></i> Create Session
                </button>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
$(function () {
    // Init all .select2 EXCEPT the ad_hoc picker, which gets its own
    // AJAX-configured select2 below (Phase 5).
    $('.select2').not('#adHocPicker').select2({ theme: 'bootstrap-5', width: '100%' });

    var $form     = $('#sessionForm');
    var $whCount  = $('#whCount');
    var $whBoxes  = $('.wh-checkbox');

    function recount() {
        $whCount.text($whBoxes.filter(':checked').length);
    }

    // Wire per-warehouse checkboxes.
    $whBoxes.on('change', function () {
        var branchId = $(this).data('branch-id');
        var $branchBoxes = $('.wh-checkbox[data-branch-id="' + branchId + '"]');
        var $branchSwitch = $('#all_branch_' + branchId);
        $branchSwitch.prop('checked', $branchBoxes.length === $branchBoxes.filter(':checked').length);
        recount();
    });

    // "Select all" per branch.
    $('.select-all-branch').on('change', function () {
        var branchId = $(this).data('branch-id');
        var checked = $(this).prop('checked');
        $('.wh-checkbox[data-branch-id="' + branchId + '"]').prop('checked', checked);
        recount();
    });

    // When branch select changes, optionally highlight matching branch card.
    $('#branch_id').on('change', function () {
        var bid = $(this).val();
        $('[data-branch-card]').removeClass('border-primary bg-primary-subtle');
        if (bid) {
            var $card = $('[data-branch-card="' + bid + '"]');
            $card.addClass('border-primary');
        }
    });

    recount();

    // ====== Phase 5: Cycle-count scope wizard ======
    var $scopeRadios = $('.scope-radio');
    var $payloads    = $('.scope-payload');
    var $scopeBadge  = $('#scopeBadge');
    var scopeLabels  = {
        full:'Full', category:'Category', abc:'ABC', group:'Group',
        ad_hoc:'Ad-hoc', negative_only:'Negative', zero_only:'Zero'
    };

    function activeScope() {
        return $scopeRadios.filter(':checked').val() || 'full';
    }

    function renderScope() {
        var s = activeScope();
        $payloads.hide();
        $payloads.filter('[data-for="' + s + '"]').show();
        $scopeBadge.text(scopeLabels[s] || s);
        $('.scope-card').removeClass('border-success bg-success-subtle');
        $('.scope-card[data-scope="' + s + '"]').addClass('border-success bg-success-subtle');
        // Hide the preview result when the scope changes (it's stale).
        $('#previewResult').hide().empty();
    }
    $scopeRadios.on('change', renderScope);
    renderScope();

    // --- ad_hoc product picker (select2 AJAX) ---
    // Maintains a set of hidden inputs under count_scope_payload[product_ids][]
    // so the selected products submit with the form. The visible <select> is
    // just the picker UI; the hidden inputs are the source of truth for POST.
    var $picker = $('#adHocPicker');
    var $hidden = $('#adHocHidden');
    var selectedProducts = {}; // id -> {id, text, code, name, unit, stock}

    $picker.select2({
        theme: 'bootstrap-5',
        width: '100%',
        multiple: true,
        placeholder: $picker.data('placeholder'),
        minimumInputLength: 1,
        ajax: {
            url: '{{ route('admin.stock-take.products.search') }}',
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return { q: params.term, warehouse_id: firstWarehouseId(), limit: 30 };
            },
            processResults: function (data) {
                return { results: data.results };
            }
        },
        templateResult: function (r) {
            if (!r.id) return r.text;
            var stock = (r.stock !== null && r.stock !== undefined)
                ? '<span class="badge bg-secondary-subtle text-secondary ms-1">qty: ' + r.stock + '</span>' : '';
            return $('<span><strong>' + r.code + '</strong> — ' + r.name + stock + '</span>');
        },
        templateSelection: function (r) {
            return r.text || (r.code + ' — ' + r.name);
        }
    });

    $picker.on('select2:select', function (e) {
        var d = e.params.data;
        selectedProducts[d.id] = d;
        syncHidden();
    });
    $picker.on('select2:unselect', function (e) {
        var d = e.params.data;
        delete selectedProducts[d.id];
        syncHidden();
    });

    function syncHidden() {
        $hidden.empty();
        $.each(selectedProducts, function (id) {
            $hidden.append('<input type="hidden" name="count_scope_payload[product_ids][]" value="' + id + '">');
        });
    }

    function firstWarehouseId() {
        var v = $('.wh-checkbox:checked').first().val();
        return v || null;
    }

    // --- live preview ---
    $('#previewBtn').on('click', function () {
        var wid = firstWarehouseId();
        if (!wid) {
            Swal.fire({ icon:'warning', title:'Select a warehouse first',
                text:'Pick at least one warehouse so the preview can run the scope against it.' });
            return;
        }
        var payload = collectPayload();
        var $btn = $(this);
        $btn.prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-1"></i> Checking…');
        $.ajax({
            url: '{{ route('admin.stock-take.scope.preview') }}',
            method: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                warehouse_id: wid,
                count_scope: activeScope(),
                count_scope_payload: payload
            }
        }).done(function (res) {
            var html = '<div class="alert alert-success border small mb-0">'
                + '<i class="fas fa-circle-check me-1"></i> '
                + '<strong>' + res.count + '</strong> product' + (res.count === 1 ? '' : 's')
                + ' will be loaded for warehouse #' + wid + ' with this scope.</div>';
            if (res.sample && res.sample.length) {
                html += '<div class="mt-2 small"><span class="text-muted">Sample:</span> ';
                html += res.sample.map(function (p) {
                    return '<span class="badge bg-light text-dark border me-1">' + p.code + ' — ' + p.name
                        + (p.stock !== null ? ' (qty: ' + p.stock + ')' : '') + '</span>';
                }).join('');
                if (res.count > res.sample.length) {
                    html += '<span class="text-muted">… +' + (res.count - res.sample.length) + ' more</span>';
                }
                html += '</div>';
            }
            $('#previewResult').html(html).show();
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message) || xhr.statusText || 'Preview failed';
            $('#previewResult').html('<div class="alert alert-danger border small mb-0"><i class="fas fa-circle-exclamation me-1"></i> ' + msg + '</div>').show();
        }).always(function () {
            $btn.prop('disabled', false)
                .html('<i class="fas fa-magnifying-glass me-1"></i> Preview product count');
        });
    });

    // Collect the payload for the ACTIVE scope only (so we don't submit
    // stray fields from hidden sections).
    function collectPayload() {
        var s = activeScope();
        var p = {};
        if (s === 'category') {
            p.category_ids = $('.cat-cb:checked').map(function () { return $(this).val(); }).get();
        } else if (s === 'group') {
            p.group_ids = $('.group-cb:checked').map(function () { return $(this).val(); }).get();
        } else if (s === 'abc') {
            p.abc_classes = $('.abc-cb:checked').map(function () { return $(this).val(); }).get();
        } else if (s === 'ad_hoc') {
            p.product_ids = Object.keys(selectedProducts);
        }
        return p;
    }

    // Submit guard: warehouses + per-scope payload validation.
    $form.on('submit', function (e) {
        if ($whBoxes.filter(':checked').length === 0) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'No warehouses selected',
                text: 'Select at least one warehouse to count before creating the session.',
                confirmButtonText: 'OK'
            });
            return false;
        }

        // Per-scope payload check (mirrors the server-side validateCountScope).
        var s = activeScope();
        var payload = collectPayload();
        var missing = '';
        if (s === 'category' && (!payload.category_ids || !payload.category_ids.length)) {
            missing = 'Select at least one category for the Category scope.';
        } else if (s === 'group' && (!payload.group_ids || !payload.group_ids.length)) {
            missing = 'Select at least one product group for the Group scope.';
        } else if (s === 'abc' && (!payload.abc_classes || !payload.abc_classes.length)) {
            missing = 'Select at least one ABC class (A, B, and/or C) for the ABC scope.';
        } else if (s === 'ad_hoc' && (!payload.product_ids || !payload.product_ids.length)) {
            missing = 'Pick at least one product for the Ad-hoc scope.';
        }
        if (missing) {
            e.preventDefault();
            Swal.fire({ icon: 'error', title: 'Scope payload incomplete', text: missing, confirmButtonText: 'OK' });
            return false;
        }

        // Strip payload fields from non-active scopes so the server sees a
        // clean payload matching the chosen scope. (Hidden inputs from other
        // scopes would otherwise be submitted by the browser.)
        $('.scope-payload').not('[data-for="' + s + '"]').find('input[name^="count_scope_payload"]').prop('disabled', true);

        $('#submitBtn').prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-1"></i> Saving…');
    });
});
</script>
@endpush
@endsection
