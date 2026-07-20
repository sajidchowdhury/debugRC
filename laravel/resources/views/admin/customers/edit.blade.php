@extends('layouts.admin')

@section('content')

@push('css')
<style>
    .md-hero {
        background: linear-gradient(135deg, #1f6f8b 0%, #2c8a6e 100%);
        color: #fff; border-radius: .75rem; padding: 1.25rem 1.5rem;
        display: flex; justify-content: space-between; flex-wrap: wrap;
        gap: 1rem; align-items: center; margin-bottom: 1rem;
    }
    .md-hero h1 { font-size: 1.5rem; margin: 0 0 .25rem; font-weight: 700; }
    .md-hero p  { margin: 0; opacity: .9; font-size: .9rem; }
    .md-hero .hero-badge {
        display: inline-flex; align-items: center; gap: .35rem;
        background: rgba(255,255,255,.15); padding: .25rem .6rem;
        border-radius: 1rem; font-size: .8rem; margin-top: .35rem;
    }
    .md-hero-actions { display: flex; gap: .5rem; flex-wrap: wrap; }

    .md-form-layout { display: grid; grid-template-columns: 1fr; gap: 1rem; }
    @media (min-width: 992px) { .md-form-layout.has-aside { grid-template-columns: 2fr 1fr; } }

    .md-form-panel, .md-aside {
        background: #fff; border: 1px solid #e7eaf0; border-radius: .65rem;
        box-shadow: 0 1px 2px rgba(15,23,42,.04); padding: 1.25rem 1.5rem;
    }
    .md-form-section { padding: 1rem 0; border-bottom: 1px solid #f1f5f9; }
    .md-form-section:last-of-type { border-bottom: 0; }
    .md-form-section:first-child { padding-top: 0; }
    .md-form-section-head { display: flex; align-items: center; gap: .5rem; font-weight: 600; margin-bottom: .85rem; color: #0f172a; }
    .md-form-section-head .icon-wrap { width: 32px; height: 32px; border-radius: .45rem; display: grid; place-items: center; color: #fff; font-size: .9rem; }
    .icon-wrap.teal   { background: #2c8a6e; }
    .icon-wrap.indigo { background: #4f46e5; }
    .icon-wrap.amber  { background: #d97706; }
    .md-form-footer { margin-top: 1rem; display: flex; gap: .5rem; flex-wrap: wrap; }

    .md-aside-title { font-size: .8rem; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; margin-bottom: .65rem; }
    .md-preview-card { background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: .65rem; padding: 1rem; text-align: center; }
    .md-preview-avatar { width: 56px; height: 56px; border-radius: 50%; background: #2c8a6e; color: #fff; display: grid; place-items: center; margin: 0 auto .6rem; font-size: 1.4rem; font-weight: 700; }
    .md-preview-name { font-weight: 700; font-size: 1.05rem; color: #0f172a; }
    .md-preview-contact { font-size: .85rem; color: #475569; margin-top: .15rem; }
    .md-preview-meta { font-size: .8rem; color: #6b7280; margin-top: .35rem; }
    .md-aside-stat { display: flex; justify-content: space-between; align-items: center; padding: .5rem 0; border-bottom: 1px solid #f1f5f9; font-size: .85rem; }
    .md-aside-stat:last-of-type { border-bottom: 0; }
    .md-aside-tip { background: #fef3c7; border: 1px solid #fde68a; color: #92400e; padding: .65rem .8rem; border-radius: .5rem; font-size: .82rem; margin-top: 1rem; }
    .status-pill { display: inline-flex; align-items: center; gap: .35rem; padding: .15rem .55rem; border-radius: 1rem; font-size: .75rem; font-weight: 600; }
    .status-pill.active   { background: #dcfce7; color: #166534; }
    .status-pill.inactive { background: #fee2e2; color: #991b1b; }
    .status-pill .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .select2-container--default .select2-selection--single { height: 38px; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 36px; }
</style>
@endpush

@php
    $isActive = (bool) ($item->is_active ?? false);
@endphp

<div class="md-hero">
    <div>
        <h1><i class="fas fa-pen-to-square me-2"></i>Edit customer</h1>
        <p>Update <strong>{{ $item->customer_name ?? '' }}</strong> — contact, credit, and status.</p>
        <span class="hero-badge">
            <i class="fas {{ $isActive ? 'fa-circle-check' : 'fa-circle-xmark' }}"></i>
            {{ $isActive ? 'Active' : 'Inactive' }} · {{ $item->customer_code ?? '—' }}
        </span>
    </div>
    <div class="md-hero-actions">
        <a href="{{ route("{$routePrefix}.show", $item) }}" class="btn btn-outline-light btn-sm">
            <i class="fas fa-circle-info me-1"></i> View
        </a>
        <a href="{{ route("{$routePrefix}.index") }}" class="btn btn-outline-light btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<div class="md-form-layout has-aside">
    <div class="md-form-panel">
        <form method="POST" action="{{ route("{$routePrefix}.update", $item) }}" id="customerForm">
            @csrf
            @method('PUT')

            <div class="md-form-section">
                <div class="md-form-section-head">
                    <span class="icon-wrap teal"><i class="fas fa-store"></i></span>
                    Shop &amp; contact
                </div>
                <div class="row g-3">
                    <div class="col-12 col-md-8">
                        <label class="form-label" for="customer_name">Customer name <span class="text-danger">*</span></label>
                        <input type="text" id="customer_name" name="customer_name"
                               class="form-control @error('customer_name') is-invalid @enderror" required
                               value="{{ old('customer_name', $item->customer_name ?? '') }}">
                        @error('customer_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="customer_code">Customer code</label>
                        <input type="text" id="customer_code" name="customer_code"
                               class="form-control @error('customer_code') is-invalid @enderror"
                               value="{{ old('customer_code', $item->customer_code ?? '') }}">
                        @error('customer_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="mobile">Mobile</label>
                        <input type="text" id="mobile" name="mobile"
                               class="form-control @error('mobile') is-invalid @enderror"
                               value="{{ old('mobile', $item->mobile ?? '') }}">
                        @error('mobile')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="phone">Phone</label>
                        <input type="text" id="phone" name="phone"
                               class="form-control @error('phone') is-invalid @enderror"
                               value="{{ old('phone', $item->phone ?? '') }}">
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" id="email" name="email"
                               class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email', $item->email ?? '') }}">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="branch_id">Branch</label>
                        <select id="branch_id" name="branch_id" class="form-select select2 @error('branch_id') is-invalid @enderror">
                            <option value="">— Not assigned —</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}"
                                    @selected((int) old('branch_id', $item->branch_id) === (int) $branch->id)>
                                    {{ $branch->branch_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="sales_person_id">Sales person</label>
                        <select id="sales_person_id" name="sales_person_id" class="form-select select2 @error('sales_person_id') is-invalid @enderror">
                            <option value="">— Not assigned —</option>
                            @foreach ($salesPersons as $emp)
                                <option value="{{ $emp->id }}"
                                    @selected((int) old('sales_person_id', $item->sales_person_id) === (int) $emp->id)>
                                    {{ $emp->name }} ({{ $emp->role }})
                                </option>
                            @endforeach
                        </select>
                        @error('sales_person_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            <div class="md-form-section">
                <div class="md-form-section-head">
                    <span class="icon-wrap indigo"><i class="fas fa-location-dot"></i></span>
                    Address
                </div>
                <textarea id="address" name="address" class="form-control @error('address') is-invalid @enderror" rows="3">{{ old('address', $item->address ?? '') }}</textarea>
                @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="md-form-section">
                <div class="md-form-section-head">
                    <span class="icon-wrap amber"><i class="fas fa-credit-card"></i></span>
                    Credit &amp; opening balance
                </div>
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="credit_limit">Credit limit (Tk)</label>
                        <input type="number" step="0.01" min="0" id="credit_limit" name="credit_limit"
                               class="form-control @error('credit_limit') is-invalid @enderror"
                               value="{{ old('credit_limit', $item->credit_limit ?? '') }}">
                        @error('credit_limit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="opening_balance">Opening balance (Tk)</label>
                        <input type="number" step="0.01" id="opening_balance" name="opening_balance"
                               class="form-control @error('opening_balance') is-invalid @enderror"
                               value="{{ old('opening_balance', $item->opening_balance ?? '') }}">
                        @error('opening_balance')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="balance_type">Balance type</label>
                        <select id="balance_type" name="balance_type" class="form-select @error('balance_type') is-invalid @enderror">
                            <option value="">— None —</option>
                            <option value="debit"  @selected(old('balance_type', $item->balance_type) === 'debit')>Debit (customer owes)</option>
                            <option value="credit" @selected(old('balance_type', $item->balance_type) === 'credit')>Credit (advance paid)</option>
                        </select>
                        @error('balance_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            <div class="md-form-section">
                <div class="md-form-section-head">
                    <span class="icon-wrap teal"><i class="fas fa-power-off"></i></span>
                    Status
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
                           @checked((bool) old('is_active', $item->is_active))
                    <label class="form-check-label" for="is_active">Active (visible in dropdowns and sales)</label>
                </div>
            </div>

            <div class="md-form-footer">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="fas fa-save me-1"></i> Save changes
                </button>
                <a href="{{ route("{$routePrefix}.show", $item) }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <aside class="md-aside">
        <div class="md-aside-title">Customer snapshot</div>
        <div class="md-preview-card">
            <div class="md-preview-avatar" id="previewAvatar">{{ strtoupper(mb_substr($item->customer_name ?? '?', 0, 1)) }}</div>
            <div class="md-preview-name" id="previewName">{{ $item->customer_name ?? '—' }}</div>
            <div class="md-preview-contact" id="previewContact">{{ $item->customer_code ?? '—' }}</div>
            <div class="md-preview-meta" id="previewMobile">{{ $item->mobile ?? '—' }}</div>
            <div class="mt-2">
                @if ($isActive)
                    <span class="status-pill active"><span class="dot"></span> Active</span>
                @else
                    <span class="status-pill inactive"><span class="dot"></span> Inactive</span>
                @endif
            </div>
        </div>

        <div class="md-aside-title mt-3">Accounts receivable</div>
        <div class="md-aside-stat">
            <span><i class="fas fa-hand-holding-dollar text-muted me-1"></i> Credit limit</span>
            <strong>Tk {{ number_format((float) ($item->credit_limit ?? 0), 2) }}</strong>
        </div>
        <div class="md-aside-stat">
            <span><i class="fas fa-coins text-muted me-1"></i> Opening balance</span>
            <strong>Tk {{ number_format((float) ($item->opening_balance ?? 0), 2) }}</strong>
        </div>
        <div class="md-aside-stat">
            <span><i class="fas fa-user-tie text-muted me-1"></i> Sales person</span>
            <strong>{{ $item->salesPerson?->name ?? '—' }}</strong>
        </div>

        <div class="md-aside-tip">
            <i class="fas fa-lightbulb me-1"></i>
            Deactivation hides this customer from new transactions but preserves historical invoices and ledger entries.
        </div>
    </aside>
</div>

@push('scripts')
<script>
(function () {
    const nameEl    = document.getElementById('customer_name');
    const mobileEl  = document.getElementById('mobile');
    const previewName    = document.getElementById('previewName');
    const previewContact = document.getElementById('previewContact');
    const previewMobile  = document.getElementById('previewMobile');
    const previewAvatar  = document.getElementById('previewAvatar');

    function updatePreview() {
        const name   = (nameEl?.value || '').trim();
        const mobile = (mobileEl?.value || '').trim();
        if (previewName)    previewName.textContent    = name   || 'Customer';
        if (previewMobile)  previewMobile.textContent  = mobile || '—';
        if (previewAvatar)  previewAvatar.textContent  = name   ? name.charAt(0).toUpperCase() : '?';
    }

    nameEl?.addEventListener('input', updatePreview);
    mobileEl?.addEventListener('input', updatePreview);

    $('.select2').select2({ theme: 'default', width: '100%' });
})();
</script>
@endpush

@endsection
