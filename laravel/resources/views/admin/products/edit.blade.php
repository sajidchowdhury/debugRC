@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white" style="background:linear-gradient(135deg,#0f766e,#14b8a6);">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-pen-to-square me-2"></i>Edit product</h1>
            <p class="mb-0 opacity-75">{{ $item->product_name }} · <span class="badge bg-light text-dark">{{ $item->product_code }}</span></p>
        </div>
        <div class="d-flex flex-wrap gap-1">
            <a href="{{ route($routePrefix . '.priceHistory', $item->id) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-tag me-1"></i> Price history
            </a>
            <a href="{{ route($routePrefix . '.show', $item->id) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-eye me-1"></i> View
            </a>
            <a href="{{ route($routePrefix . '.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Catalog
            </a>
        </div>
    </header>

    <div class="row g-3">
        {{-- Form panel --}}
        <div class="col-12 col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="POST" action="{{ route($routePrefix . '.update', $item->id) }}" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')

                        {{-- Identity --}}
                        <h6 class="text-uppercase text-muted border-bottom pb-2 mb-3">
                            <i class="fas fa-box me-1"></i> Product identity
                        </h6>
                        <div class="row g-3 mb-4">
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="product_code">Product code <span class="text-danger">*</span></label>
                                <input type="text" id="product_code" name="product_code" class="form-control @error('product_code') is-invalid @enderror" required
                                       value="{{ old('product_code', $item->product_code) }}">
                                @error('product_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12 col-md-8">
                                <label class="form-label" for="product_name">Product name <span class="text-danger">*</span></label>
                                <input type="text" id="product_name" name="product_name" class="form-control @error('product_name') is-invalid @enderror" required
                                       value="{{ old('product_name', $item->product_name) }}">
                                @error('product_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="category_id">Category</label>
                                <select id="category_id" name="category_id" class="form-select select2 @error('category_id') is-invalid @enderror">
                                    <option value="">— No category —</option>
                                    @foreach ($categories as $cat)
                                        <option value="{{ $cat->id }}" @selected(old('category_id', $item->category_id) == $cat->id)>{{ $cat->category_name }}</option>
                                    @endforeach
                                </select>
                                @error('category_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="group_id">Group</label>
                                <select id="group_id" name="group_id" class="form-select select2 @error('group_id') is-invalid @enderror">
                                    <option value="">— No group —</option>
                                    @foreach ($groups as $grp)
                                        <option value="{{ $grp->id }}" @selected(old('group_id', $item->group_id) == $grp->id)>{{ $grp->group_name }}</option>
                                    @endforeach
                                </select>
                                @error('group_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        {{-- Unit & stock --}}
                        <h6 class="text-uppercase text-muted border-bottom pb-2 mb-3">
                            <i class="fas fa-scale-balanced me-1"></i> Unit &amp; stock levels
                        </h6>
                        <div class="row g-3 mb-4">
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="unit">Unit <span class="text-danger">*</span></label>
                                <select id="unit" name="unit" class="form-select @error('unit') is-invalid @enderror" required>
                                    @foreach ($units as $u)
                                        <option value="{{ $u }}" @selected(old('unit', $item->unit) === $u)>{{ $u }}</option>
                                    @endforeach
                                </select>
                                @error('unit') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="min_stock">Min stock</label>
                                <input type="number" id="min_stock" name="min_stock" class="form-control @error('min_stock') is-invalid @enderror"
                                       step="0.0001" min="0" value="{{ old('min_stock', $item->min_stock) }}">
                                @error('min_stock') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="max_stock">Max stock</label>
                                <input type="number" id="max_stock" name="max_stock" class="form-control @error('max_stock') is-invalid @enderror"
                                       step="0.0001" min="0" value="{{ old('max_stock', $item->max_stock) }}">
                                @error('max_stock') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="reorder_level">Reorder level</label>
                                <input type="number" id="reorder_level" name="reorder_level" class="form-control @error('reorder_level') is-invalid @enderror"
                                       step="0.0001" min="0" value="{{ old('reorder_level', $item->reorder_level) }}">
                                <div class="form-text">Reorder alert threshold.</div>
                                @error('reorder_level') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        {{-- Pricing --}}
                        <h6 class="text-uppercase text-muted border-bottom pb-2 mb-3">
                            <i class="fas fa-tag me-1"></i> Default pricing
                        </h6>
                        <div class="row g-3 mb-4">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="purchase_rate">Purchase rate (Tk)</label>
                                <input type="number" id="purchase_rate" name="purchase_rate" class="form-control @error('purchase_rate') is-invalid @enderror"
                                       step="0.01" min="0" value="{{ old('purchase_rate', $item->purchase_rate) }}">
                                @error('purchase_rate') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="sales_rate">Sales rate (Tk)</label>
                                <input type="number" id="sales_rate" name="sales_rate" class="form-control @error('sales_rate') is-invalid @enderror"
                                       step="0.01" min="0" value="{{ old('sales_rate', $item->sales_rate) }}">
                                <div class="form-text">Default — can be overridden by price history.</div>
                                @error('sales_rate') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        {{-- Condition & status --}}
                        <h6 class="text-uppercase text-muted border-bottom pb-2 mb-3">
                            <i class="fas fa-clipboard-check me-1"></i> Condition &amp; status
                        </h6>
                        <div class="row g-3 mb-4">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="condition_state">Condition</label>
                                <select id="condition_state" name="condition_state" class="form-select @error('condition_state') is-invalid @enderror">
                                    <option value="">— Select —</option>
                                    <option value="Good" @selected(old('condition_state', $item->condition_state) === 'Good')>Good</option>
                                    <option value="Damage" @selected(old('condition_state', $item->condition_state) === 'Damage')>Damage</option>
                                </select>
                                @error('condition_state') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12 col-md-6 d-flex align-items-end">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $item->is_active))>
                                    <label class="form-check-label" for="is_active">Active</label>
                                </div>
                            </div>
                        </div>

                        {{-- Image upload --}}
                        <h6 class="text-uppercase text-muted border-bottom pb-2 mb-3">
                            <i class="fas fa-image me-1"></i> Product image
                        </h6>
                        <div class="d-flex align-items-start gap-3 flex-wrap mb-2">
                            <div id="imagePreview" class="rounded bg-light d-flex align-items-center justify-content-center overflow-hidden"
                                 style="width:120px;height:120px;border:1px dashed #ccc;">
                                @if ($item->product_image)
                                    <img src="{{ asset('storage/' . $item->product_image) }}" style="width:100%;height:100%;object-fit:cover;">
                                @else
                                    <i class="fas fa-image fa-2x text-secondary"></i>
                                @endif
                            </div>
                            <div>
                                <input type="file" id="imageInput" name="image" accept="image/jpeg,image/png,image/webp,image/gif" class="d-none">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('imageInput').click()">
                                    <i class="fas fa-upload me-1"></i> {{ $item->product_image ? 'Replace image' : 'Choose image' }}
                                </button>
                                <button type="button" id="clearImage" class="btn btn-outline-secondary btn-sm ms-1 d-none">
                                    <i class="fas fa-times me-1"></i> Clear
                                </button>
                                <div class="small text-muted mt-2">JPG, PNG, WebP, GIF · max 2 MB</div>
                                @error('image') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        {{-- Footer actions --}}
                        <div class="d-flex gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-save me-1"></i> Save changes
                            </button>
                            <a href="{{ route($routePrefix . '.show', $item->id) }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- Aside: snapshot --}}
        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm sticky-top" style="top:80px;">
                <div class="card-body">
                    <h6 class="text-uppercase text-muted mb-3">Snapshot</h6>
                    @if ($item->product_image)
                    <div class="text-center mb-3">
                        <img src="{{ asset('storage/' . $item->product_image) }}" class="img-fluid rounded" style="max-height:180px;" alt="">
                    </div>
                    @endif
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small"><i class="fas fa-box me-1"></i> Status</span>
                        <span class="badge {{ $item->is_active ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">
                            {{ $item->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                    @if ($item->category)
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small"><i class="fas fa-tag me-1"></i> Category</span>
                        <span class="small fw-semibold">{{ $item->category->category_name }}</span>
                    </div>
                    @endif
                    @if ($item->group)
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small"><i class="fas fa-globe me-1"></i> Group</span>
                        <span class="small fw-semibold">{{ $item->group->group_name }}</span>
                    </div>
                    @endif
                    <hr>
                    <div class="d-grid gap-2">
                        <a href="{{ route($routePrefix . '.priceHistory', $item->id) }}" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-chart-line me-1"></i> Price history
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function() {
    const input  = document.getElementById('imageInput');
    const prev   = document.getElementById('imagePreview');
    const clearB = document.getElementById('clearImage');
    const origHTML = prev.innerHTML;
    input.addEventListener('change', function() {
        if (!this.files || !this.files[0]) return;
        const r = new FileReader();
        r.onload = e => {
            prev.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
            clearB.classList.remove('d-none');
        };
        r.readAsDataURL(this.files[0]);
    });
    clearB.addEventListener('click', () => {
        input.value = '';
        prev.innerHTML = origHTML;
        clearB.classList.add('d-none');
    });

    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });
})();
</script>
@endpush
