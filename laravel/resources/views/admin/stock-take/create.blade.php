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
                            @php($branchWhs = $warehousesByBranch->get($b->id, collect()))
                            @if ($branchWhs->isEmpty()) @continue @endif
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
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

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

    // Submit guard: require at least one warehouse.
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
        $('#submitBtn').prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-1"></i> Saving…');
    });
});
</script>
@endpush
@endsection
