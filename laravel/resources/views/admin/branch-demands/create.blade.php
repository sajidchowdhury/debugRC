@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    {{-- Header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-plus me-2"></i>Create Branch Demand</h1>
            <p class="mb-0 small opacity-75">
                Request products from another branch. Select the supplier branch and add items.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.branch-demands.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </header>

    {{-- Error display --}}
    @if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- Create form --}}
    <form method="POST" action="{{ route('admin.branch-demands.store') }}" id="demandForm">
        @csrf

        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light"><i class="fas fa-info-circle me-1"></i> Demand Details</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Supplier Branch <span class="text-danger">*</span></label>
                            <select name="to_branch_id" class="form-select" required>
                                <option value="">Select supplier branch...</option>
                                @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" {{ old('to_branch_id') == $branch->id ? 'selected' : '' }}>
                                    {{ $branch->branch_name }}
                                </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Demand Date <span class="text-danger">*</span></label>
                            <input type="date" name="demand_date" value="{{ old('demand_date', date('Y-m-d')) }}" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes...">{{ old('notes') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light"><i class="fas fa-boxes-stacked me-1"></i> Demand Items</div>
                    <div class="card-body">
                        <div id="items-container">
                            {{-- Items will be added dynamically --}}
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="addItemBtn">
                            <i class="fas fa-plus me-1"></i> Add Item
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-1"></i> Create Demand
            </button>
            <a href="{{ route('admin.branch-demands.index') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('items-container');
    const addBtn = document.getElementById('addItemBtn');
    let itemIndex = 0;

    function addItem() {
        const idx = itemIndex++;
        const div = document.createElement('div');
        div.className = 'row g-2 mb-2 align-items-end item-row';
        div.innerHTML = `
            <div class="col-5">
                <label class="form-label small">Product</label>
                <select name="items[${idx}][product_id]" class="form-select form-select-sm product-select" required>
                    <option value="">Select product...</option>
                </select>
            </div>
            <div class="col-3">
                <label class="form-label small">Quantity</label>
                <input type="number" name="items[${idx}][qty]" class="form-control form-control-sm" min="0.01" step="0.01" required>
            </div>
            <div class="col-3">
                <label class="form-label small">Notes</label>
                <input type="text" name="items[${idx}][notes]" class="form-control form-control-sm" placeholder="Optional">
            </div>
            <div class="col-1">
                <button type="button" class="btn btn-outline-danger btn-sm remove-item-btn" title="Remove">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        container.appendChild(div);

        // Load products for the new select
        const select = div.querySelector('.product-select');
        loadProducts(select);

        // Remove button
        div.querySelector('.remove-item-btn').addEventListener('click', function() {
            div.remove();
        });
    }

    function loadProducts(selectEl) {
        fetch('{{ route("admin.branch-demands.products") }}')
            .then(r => r.json())
            .then(data => {
                if (data.data) {
                    data.data.forEach(p => {
                        const opt = document.createElement('option');
                        opt.value = p.id;
                        opt.textContent = p.product_name + ' (' + p.product_code + ')';
                        selectEl.appendChild(opt);
                    });
                }
            })
            .catch(err => console.error('Failed to load products:', err));
    }

    addBtn.addEventListener('click', addItem);

    // Add first item by default
    addItem();
});
</script>
@endsection
@endsection
