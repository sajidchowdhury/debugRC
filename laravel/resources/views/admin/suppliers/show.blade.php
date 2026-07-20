@extends('layouts.admin')

@section('content')

@push('css')
<style>
    .md-hero {
        background: linear-gradient(135deg, #b45309 0%, #d97706 100%);
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

    .md-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: .75rem; margin-bottom: 1rem; }
    .md-stat-card { background: #fff; border: 1px solid #e7eaf0; border-radius: .65rem; padding: .9rem 1rem; display: flex; gap: .75rem; align-items: center; box-shadow: 0 1px 2px rgba(15,23,42,.04); }
    .md-stat-icon { width: 42px; height: 42px; border-radius: .5rem; display: grid; place-items: center; color: #fff; font-size: 1.05rem; }
    .md-stat-icon.amber  { background: #d97706; }
    .md-stat-icon.indigo { background: #4f46e5; }
    .md-stat-icon.slate  { background: #64748b; }
    .md-stat-icon.teal   { background: #2c8a6e; }
    .md-stat-value { font-size: 1.15rem; font-weight: 700; line-height: 1.1; }
    .md-stat-label { color: #6b7280; font-size: .8rem; }

    .md-detail-grid {
        background: #fff; border: 1px solid #e7eaf0; border-radius: .65rem;
        box-shadow: 0 1px 2px rgba(15,23,42,.04); padding: 1.25rem 1.5rem; margin-bottom: 1rem;
    }
    .md-detail-row { display: grid; grid-template-columns: 200px 1fr; gap: .75rem; padding: .55rem 0; border-bottom: 1px solid #f1f5f9; }
    .md-detail-row:last-child { border-bottom: 0; }
    .md-detail-label { color: #6b7280; font-size: .85rem; }
    .md-detail-value { font-weight: 600; color: #0f172a; word-break: break-word; }
    .code-pill { display: inline-block; background: #fef3c7; color: #92400e; padding: .15rem .55rem; border-radius: 1rem; font-size: .78rem; font-weight: 600; }
    .status-pill { display: inline-flex; align-items: center; gap: .35rem; padding: .15rem .55rem; border-radius: 1rem; font-size: .75rem; font-weight: 600; }
    .status-pill.active   { background: #dcfce7; color: #166534; }
    .status-pill.inactive { background: #fee2e2; color: #991b1b; }
    .status-pill .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
</style>
@endpush

@php
    $isActive = (bool) ($item->is_active ?? false);
@endphp

<div class="md-hero">
    <div>
        <h1>
            <i class="fas fa-truck me-2"></i>
            {{ $item->supplier_name ?? 'Supplier' }}
        </h1>
        <p>Supplier hub — master data, contact, AP opening balance.</p>
        <span class="hero-badge">
            <i class="fas {{ $isActive ? 'fa-circle-check' : 'fa-circle-xmark' }}"></i>
            {{ $isActive ? 'Active' : 'Inactive' }} · {{ $item->supplier_code ?? '—' }}
        </span>
    </div>
    <div class="md-hero-actions">
        <a href="{{ route("{$routePrefix}.edit", $item) }}" class="btn btn-light btn-sm">
            <i class="fas fa-pen me-1"></i> Edit
        </a>
        <a href="{{ route("{$routePrefix}.index") }}" class="btn btn-outline-light btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Directory
        </a>
    </div>
</div>

<div class="md-stats">
    <div class="md-stat-card">
        <div class="md-stat-icon amber"><i class="fas fa-coins"></i></div>
        <div>
            <div class="md-stat-value">Tk {{ number_format((float) ($item->opening_balance ?? 0), 0) }}</div>
            <div class="md-stat-label">Opening balance</div>
        </div>
    </div>
    <div class="md-stat-card">
        <div class="md-stat-icon indigo"><i class="fas fa-building"></i></div>
        <div>
            <div class="md-stat-value">{{ $item->branch?->branch_name ?? '—' }}</div>
            <div class="md-stat-label">Branch</div>
        </div>
    </div>
    <div class="md-stat-card">
        <div class="md-stat-icon slate"><i class="fas fa-user"></i></div>
        <div>
            <div class="md-stat-value">{{ $item->contact_person ?? '—' }}</div>
            <div class="md-stat-label">Contact person</div>
        </div>
    </div>
    <div class="md-stat-card">
        <div class="md-stat-icon teal"><i class="fas fa-phone"></i></div>
        <div>
            <div class="md-stat-value">{{ $item->mobile ?? '—' }}</div>
            <div class="md-stat-label">Mobile</div>
        </div>
    </div>
</div>

<div class="md-detail-grid">
    <div class="md-detail-row">
        <div class="md-detail-label">Supplier code</div>
        <div class="md-detail-value">
            @if (! empty($item->supplier_code))
                <span class="code-pill">{{ $item->supplier_code }}</span>
            @else
                <span class="text-muted">—</span>
            @endif
        </div>
    </div>
    <div class="md-detail-row">
        <div class="md-detail-label">Supplier name</div>
        <div class="md-detail-value">{{ $item->supplier_name ?? '—' }}</div>
    </div>
    <div class="md-detail-row">
        <div class="md-detail-label">Contact person</div>
        <div class="md-detail-value">{{ $item->contact_person ?? '—' }}</div>
    </div>
    <div class="md-detail-row">
        <div class="md-detail-label">Mobile</div>
        <div class="md-detail-value">
            @if (! empty($item->mobile))
                <a href="tel:{{ $item->mobile }}" class="text-decoration-none"><i class="fas fa-phone me-1 text-muted"></i>{{ $item->mobile }}</a>
            @else
                <span class="text-muted">—</span>
            @endif
        </div>
    </div>
    <div class="md-detail-row">
        <div class="md-detail-label">Phone</div>
        <div class="md-detail-value">{{ $item->phone ?? '—' }}</div>
    </div>
    <div class="md-detail-row">
        <div class="md-detail-label">Email</div>
        <div class="md-detail-value">
            @if (! empty($item->email))
                <a href="mailto:{{ $item->email }}" class="text-decoration-none">{{ $item->email }}</a>
            @else
                <span class="text-muted">—</span>
            @endif
        </div>
    </div>
    <div class="md-detail-row">
        <div class="md-detail-label">Address</div>
        <div class="md-detail-value">{!! nl2br(e($item->address ?? '')) !!}</div>
    </div>
    <div class="md-detail-row">
        <div class="md-detail-label">Branch</div>
        <div class="md-detail-value">{{ $item->branch?->branch_name ?? '—' }}</div>
    </div>
    <div class="md-detail-row">
        <div class="md-detail-label">Opening balance</div>
        <div class="md-detail-value">
            Tk {{ number_format((float) ($item->opening_balance ?? 0), 2) }}
            @if (! empty($item->balance_type))
                <span class="badge bg-secondary ms-1">{{ ucfirst($item->balance_type) }}</span>
            @endif
        </div>
    </div>
    <div class="md-detail-row">
        <div class="md-detail-label">Status</div>
        <div class="md-detail-value">
            @if ($isActive)
                <span class="status-pill active"><span class="dot"></span> Active</span>
            @else
                <span class="status-pill inactive"><span class="dot"></span> Inactive</span>
            @endif
        </div>
    </div>
</div>

<div class="d-flex gap-2 flex-wrap">
    <a href="{{ route("{$routePrefix}.edit", $item) }}" class="btn btn-primary">
        <i class="fas fa-pen me-1"></i> Edit supplier
    </a>
    <a href="{{ route("{$routePrefix}.index") }}" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to directory
    </a>
</div>

@push('scripts')
<script>
/* No DataTables on show view; placeholder for future supplier-ledger widget. */
</script>
@endpush

@endsection
