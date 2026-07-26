@extends('layouts.admin')

@push('css')
<link rel="stylesheet" href="/assets/css/sales-return-index.css">
<link rel="stylesheet" href="/assets/css/sales-return-create.css">
@endpush

@section('content')
@php
    // Phase 4 — workspace create page (typeahead find-invoice → return form).
    // The shared partial (admin.sales-returns.partials.create-workspace) holds
    // both steps; SalesReturn.js auto-binds to [data-srt-workspace].
    //
    // Pre-fill from URL: ?invoice_id=123 (resolved to invoice_code by the
    // controller) or ?q=INV-2026-0001 (raw search term).
    $branchName = auth()->user()?->branch?->branch_name ?? 'Branch';
    $csrf       = csrf_token();
    $prefill    = $prefill ?? '';

    // Pre-encode boot JSON (BUG-45: Blade's @json() can't safely encode
    // multi-key array literals, so we json_encode here).
    $createBoot = json_encode([
        'workspace_id' => 'salesReturnCreateRoot',
        'prefill'      => $prefill,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $mainBoot = json_encode([
        'csrf'      => $csrf,
        'endpoints' => [
            'datatables'      => route('admin.sales-returns.index'),
            'summary'         => route('admin.sales-returns.summary'),
            'search_invoices' => route('admin.sales-returns.search-invoices'),
            'invoice_details' => route('admin.sales-returns.invoice-details'),
            'store'           => route('admin.sales-returns.store'),
            'export'          => route('admin.sales-returns.export'),
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
@endphp

<div id="srt-create-page" class="srt-create-app container-fluid py-2">
    <header class="srt-create-hero">
        <div>
            <h1><i class="fas fa-undo-alt me-2"></i>Sales return</h1>
            <p>Search invoice once, enter quantities and warehouse, save</p>
            <span class="srt-create-branch-tag">
                <i class="fas fa-map-marker-alt me-1"></i>{{ e($branchName) }}
            </span>
        </div>
        <a href="{{ route('admin.sales-returns.index') }}" class="btn btn-light btn-sm flex-shrink-0">
            <i class="fas fa-list"></i> All returns
        </a>
    </header>

    {{-- Critical-info banner (PRESERVED from the pre-rewrite create page). --}}
    {{-- Documents Laravel's BETTER-than-legacy original_cost snapshot rule   --}}
    {{-- so users understand why the cost column is yellow-highlighted.       --}}
    <div class="alert alert-warning border-warning d-flex align-items-start mb-3" role="alert">
        <i class="fas fa-circle-exclamation me-2 fa-lg text-warning"></i>
        <div>
            <strong class="text-warning">Stock will be returned at the ORIGINAL avg_cost from the challan</strong>
            (NOT the current avg_cost). This preserves COGS integrity — the COGS reversal matches the original sale exactly,
            and the avg_cost is restored to its pre-sale value.
            <hr class="my-2">
            <span class="small">
                <strong>GL posts (on confirm):</strong>
                <span class="badge bg-secondary-subtle text-secondary me-1">Dr Sales Return</span>
                <span class="badge bg-secondary-subtle text-secondary me-1">Cr Accounts Receivable</span>
                (revenue reversal at sales rate) +
                <span class="badge bg-secondary-subtle text-secondary me-1">Dr Inventory</span>
                <span class="badge bg-secondary-subtle text-secondary me-1">Cr COGS</span>
                (at original avg_cost). Customer ledger is <em>credited</em> (customer owes less).
            </span>
        </div>
    </div>

    @if (session('error'))
        <div class="alert alert-danger mb-3">
            <i class="fas fa-triangle-exclamation me-1"></i> {{ session('error') }}
        </div>
    @endif

    <section class="srt-create-panel">
        @include('admin.sales-returns.partials.create-workspace', [
            'workspaceId' => 'salesReturnCreateRoot',
            'compact'     => false,
        ])
    </section>
</div>
@endsection

@push('scripts')
<script>window.CSRF_TOKEN = @json($csrf);</script>
<script>
window.SALES_RETURN_BASE = '{{ rtrim(route('admin.sales-returns.index'), '/') }}/';
window.SALES_RETURN_CREATE_BOOT = {!! $createBoot !!};
window.SALES_RETURN_BOOT = {!! $mainBoot !!};
</script>
<script src="/assets/js/SalesReturn.js"></script>
@endpush
