@extends('layouts.admin')

@section('title', $title)

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-plus-circle me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Configure a new commission rule. Conditional sections appear based on rule type.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.commission-rules.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Validation errors summary --}}
    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-circle-exclamation me-1"></i>
            <strong>Validation errors:</strong>
            <ul class="mb-0 mt-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.commission-rules.store') }}">
        @csrf

        {{-- Primary fields --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-light">
                <i class="fas fa-sliders me-1"></i> Primary fields
            </div>
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label for="salesman_id" class="form-label">Salesman <span class="text-danger">*</span></label>
                    <select id="salesman_id" name="salesman_id" class="form-select @error('salesman_id') is-invalid @enderror" required>
                        <option value="">— Select salesman —</option>
                        @foreach ($salesmen as $id => $name)
                            <option value="{{ $id }}" @selected(old('salesman_id') == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('salesman_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                    <label for="rule_type" class="form-label">Rule type <span class="text-danger">*</span></label>
                    <select id="rule_type" name="rule_type" class="form-select @error('rule_type') is-invalid @enderror" required>
                        <option value="">— Select rule type —</option>
                        <option value="flat"          @selected(old('rule_type') === 'flat')>Flat (single %)</option>
                        <option value="tiered"        @selected(old('rule_type') === 'tiered')>Tiered (progressive %)</option>
                        <option value="product_group" @selected(old('rule_type') === 'product_group')>Product Group (% per group)</option>
                        <option value="target_bonus"  @selected(old('rule_type') === 'target_bonus')>Target Bonus (base + bonus)</option>
                    </select>
                    @error('rule_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label for="rate" class="form-label">Base rate (%) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" step="0.01" min="0" max="100" id="rate" name="rate"
                               class="form-control @error('rate') is-invalid @enderror"
                               value="{{ old('rate', 0) }}" required>
                        <span class="input-group-text">%</span>
                    </div>
                    @error('rate') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <small class="text-muted">Default rate. Used directly for flat; as base for tiered/target_bonus; ignored for product_group.</small>
                </div>

                <div class="col-md-3">
                    <label for="effective_from" class="form-label">Effective from</label>
                    <input type="date" id="effective_from" name="effective_from"
                           class="form-control @error('effective_from') is-invalid @enderror"
                           value="{{ old('effective_from', now()->toDateString()) }}">
                    @error('effective_from') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label for="effective_to" class="form-label">Effective to <small class="text-muted">(optional)</small></label>
                    <input type="date" id="effective_to" name="effective_to"
                           class="form-control @error('effective_to') is-invalid @enderror"
                           value="{{ old('effective_to') }}">
                    @error('effective_to') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <small class="text-muted">Leave blank for open-ended rule.</small>
                </div>

                <div class="col-md-3">
                    <label for="branch_id" class="form-label">Branch scope</label>
                    <select id="branch_id" name="branch_id" class="form-select @error('branch_id') is-invalid @enderror">
                        <option value="" @selected(old('branch_id') === '')>All branches</option>
                        @foreach ($branches as $id => $name)
                            <option value="{{ $id }}" @selected(old('branch_id') == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-12">
                    <label for="notes" class="form-label">Notes</label>
                    <textarea id="notes" name="notes" rows="2" class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                    @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
        </div>

        {{-- Conditional: Tiers (rule_type=tiered) --}}
        <div class="card border-0 shadow-sm mb-3 conditional-section" data-rule-type="tiered" style="display:none;">
            <div class="card-header bg-light">
                <i class="fas fa-layer-group me-1"></i> Tiers (progressive rate thresholds)
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Define cumulative-sales thresholds and the rate that applies above each threshold.
                    E.g. threshold=50000 rate=1.0 means sales above 50,000 earn 1% commission.
                </p>
                <div id="tiers-container">
                    @php
                        $oldTiers = old('tiers', [['threshold' => '', 'rate' => '']]);
                    @endphp
                    @foreach ($oldTiers as $i => $tier)
                        <div class="row g-2 mb-2 tier-row">
                            <div class="col-md-5">
                                <div class="input-group">
                                    <span class="input-group-text">≥</span>
                                    <input type="number" step="0.01" min="0"
                                           name="tiers[{{ $i }}][threshold]"
                                           class="form-control"
                                           value="{{ $tier['threshold'] ?? '' }}"
                                           placeholder="Threshold amount">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="input-group">
                                    <input type="number" step="0.01" min="0" max="100"
                                           name="tiers[{{ $i }}][rate]"
                                           class="form-control"
                                           value="{{ $tier['rate'] ?? '' }}"
                                           placeholder="Rate %">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-md-3 d-flex align-items-center">
                                <button type="button" class="btn btn-sm btn-outline-danger remove-tier-btn">
                                    <i class="fas fa-trash"></i> Remove
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
                <button type="button" id="add-tier-btn" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-plus me-1"></i> Add tier
                </button>
            </div>
        </div>

        {{-- Conditional: Product groups (rule_type=product_group) --}}
        <div class="card border-0 shadow-sm mb-3 conditional-section" data-rule-type="product_group" style="display:none;">
            <div class="card-header bg-light">
                <i class="fas fa-boxes-stacked me-1"></i> Product group rates
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Specify a commission rate per product group. The base rate above is ignored.
                </p>
                <div id="product-groups-container">
                    @php
                        $oldGroups = old('product_groups', [['product_group_id' => '', 'rate' => '']]);
                    @endphp
                    @foreach ($oldGroups as $i => $pg)
                        <div class="row g-2 mb-2 product-group-row">
                            <div class="col-md-7">
                                <select name="product_groups[{{ $i }}][product_group_id]" class="form-select">
                                    <option value="">— Select product group —</option>
                                    @foreach ($productGroups as $id => $name)
                                        <option value="{{ $id }}" @selected(($pg['product_group_id'] ?? '') == $id)>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <div class="input-group">
                                    <input type="number" step="0.01" min="0" max="100"
                                           name="product_groups[{{ $i }}][rate]"
                                           class="form-control"
                                           value="{{ $pg['rate'] ?? '' }}"
                                           placeholder="Rate %">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-md-2 d-flex align-items-center">
                                <button type="button" class="btn btn-sm btn-outline-danger remove-product-group-btn">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
                <button type="button" id="add-product-group-btn" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-plus me-1"></i> Add product group
                </button>
            </div>
        </div>

        {{-- Conditional: Targets (rule_type=target_bonus) --}}
        <div class="card border-0 shadow-sm mb-3 conditional-section" data-rule-type="target_bonus" style="display:none;">
            <div class="card-header bg-light">
                <i class="fas fa-bullseye me-1"></i> Sales targets + bonus rates
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Define sales targets per period. When the salesman exceeds a target, the bonus rate applies.
                </p>
                <div id="targets-container">
                    @php
                        $oldTargets = old('targets', [['target_amount' => '', 'bonus_rate' => '', 'period' => 'monthly']]);
                    @endphp
                    @foreach ($oldTargets as $i => $target)
                        <div class="row g-2 mb-2 target-row">
                            <div class="col-md-4">
                                <div class="input-group">
                                    <span class="input-group-text">≥</span>
                                    <input type="number" step="0.01" min="0"
                                           name="targets[{{ $i }}][target_amount]"
                                           class="form-control"
                                           value="{{ $target['target_amount'] ?? '' }}"
                                           placeholder="Target amount">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="input-group">
                                    <input type="number" step="0.01" min="0" max="100"
                                           name="targets[{{ $i }}][bonus_rate]"
                                           class="form-control"
                                           value="{{ $target['bonus_rate'] ?? '' }}"
                                           placeholder="Bonus rate %">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <select name="targets[{{ $i }}][period]" class="form-select">
                                    <option value="monthly"   @selected(($target['period'] ?? 'monthly') === 'monthly')>Monthly</option>
                                    <option value="quarterly" @selected(($target['period'] ?? 'monthly') === 'quarterly')>Quarterly</option>
                                    <option value="yearly"    @selected(($target['period'] ?? 'monthly') === 'yearly')>Yearly</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-center">
                                <button type="button" class="btn btn-sm btn-outline-danger remove-target-btn">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
                <button type="button" id="add-target-btn" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-plus me-1"></i> Add target
                </button>
            </div>
        </div>

        {{-- Submit --}}
        <div class="d-flex justify-content-end gap-2 mb-4">
            <a href="{{ route('admin.commission-rules.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-times me-1"></i> Cancel
            </a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-check me-1"></i> Create Rule
            </button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
// ── Show/hide conditional sections based on rule_type ──────────────────────
(function () {
    const ruleTypeSelect = document.getElementById('rule_type');
    const sections = document.querySelectorAll('.conditional-section');

    function updateVisibility() {
        const selected = ruleTypeSelect.value;
        sections.forEach(function (s) {
            s.style.display = (s.dataset.ruleType === selected) ? '' : 'none';
        });
    }
    ruleTypeSelect.addEventListener('change', updateVisibility);
    updateVisibility(); // initial state (preserved on validation error)
})();

// ── Repeatable rows: tiers ─────────────────────────────────────────────────
(function () {
    const container = document.getElementById('tiers-container');
    if (!container) return;
    document.getElementById('add-tier-btn').addEventListener('click', function () {
        const idx = container.querySelectorAll('.tier-row').length;
        const html = `
            <div class="row g-2 mb-2 tier-row">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text">≥</span>
                        <input type="number" step="0.01" min="0"
                               name="tiers[${idx}][threshold]"
                               class="form-control"
                               placeholder="Threshold amount">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="input-group">
                        <input type="number" step="0.01" min="0" max="100"
                               name="tiers[${idx}][rate]"
                               class="form-control"
                               placeholder="Rate %">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
                <div class="col-md-3 d-flex align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-tier-btn">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                </div>
            </div>`;
        container.insertAdjacentHTML('beforeend', html);
    });
    container.addEventListener('click', function (e) {
        if (e.target.closest('.remove-tier-btn')) {
            e.target.closest('.tier-row').remove();
        }
    });
})();

// ── Repeatable rows: product groups ────────────────────────────────────────
(function () {
    const container = document.getElementById('product-groups-container');
    if (!container) return;
    const productGroups = @json($productGroups);
    document.getElementById('add-product-group-btn').addEventListener('click', function () {
        const idx = container.querySelectorAll('.product-group-row').length;
        const opts = Object.entries(productGroups).map(([id, name]) =>
            `<option value="${id}">${name}</option>`).join('');
        const html = `
            <div class="row g-2 mb-2 product-group-row">
                <div class="col-md-7">
                    <select name="product_groups[${idx}][product_group_id]" class="form-select">
                        <option value="">— Select product group —</option>
                        ${opts}
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="input-group">
                        <input type="number" step="0.01" min="0" max="100"
                               name="product_groups[${idx}][rate]"
                               class="form-control"
                               placeholder="Rate %">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
                <div class="col-md-2 d-flex align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-product-group-btn">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>`;
        container.insertAdjacentHTML('beforeend', html);
    });
    container.addEventListener('click', function (e) {
        if (e.target.closest('.remove-product-group-btn')) {
            e.target.closest('.product-group-row').remove();
        }
    });
})();

// ── Repeatable rows: targets ───────────────────────────────────────────────
(function () {
    const container = document.getElementById('targets-container');
    if (!container) return;
    document.getElementById('add-target-btn').addEventListener('click', function () {
        const idx = container.querySelectorAll('.target-row').length;
        const html = `
            <div class="row g-2 mb-2 target-row">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text">≥</span>
                        <input type="number" step="0.01" min="0"
                               name="targets[${idx}][target_amount]"
                               class="form-control"
                               placeholder="Target amount">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="input-group">
                        <input type="number" step="0.01" min="0" max="100"
                               name="targets[${idx}][bonus_rate]"
                               class="form-control"
                               placeholder="Bonus rate %">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="targets[${idx}][period]" class="form-select">
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="yearly">Yearly</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-target-btn">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>`;
        container.insertAdjacentHTML('beforeend', html);
    });
    container.addEventListener('click', function (e) {
        if (e.target.closest('.remove-target-btn')) {
            e.target.closest('.target-row').remove();
        }
    });
})();
</script>
@endpush
