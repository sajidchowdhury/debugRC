@extends('layouts.admin')

@section('title', $title)

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-percent me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                @if ($rule->is_active)
                    <span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i> Active</span>
                @else
                    <span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-circle me-1"></i> Inactive</span>
                @endif
                Salesman: {{ $rule->salesman?->name ?? '—' }} · Type: {{ ucfirst(str_replace('_', ' ', $rule->rule_type)) }}
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.commission-rules.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
            @if ($rule->is_active)
                <form method="POST" action="{{ route('admin.commission-rules.deactivate', $rule) }}"
                      onsubmit="return confirm('Deactivate commission rule #{{ $rule->id }}? This sets effective_to = today and is_active = false.');">
                    @csrf
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fas fa-power-off me-1"></i> Deactivate
                    </button>
                </form>
            @endif
        </div>
    </header>

    {{-- Flash success --}}
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-circle-check me-1"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    {{-- Primary details --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light">
            <i class="fas fa-info-circle me-1"></i> Rule details
        </div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Rule ID</dt>
                <dd class="col-sm-9">#{{ $rule->id }}</dd>

                <dt class="col-sm-3">Salesman</dt>
                <dd class="col-sm-9">
                    {{ $rule->salesman?->name ?? '—' }}
                    @if ($rule->salesman?->employee_code)
                        <span class="text-muted">({{ $rule->salesman->employee_code }})</span>
                    @endif
                </dd>

                <dt class="col-sm-3">Rule type</dt>
                <dd class="col-sm-9">
                    @php
                        $typeBadge = [
                            'flat'          => '<span class="badge bg-primary">Flat</span>',
                            'tiered'        => '<span class="badge bg-info text-dark">Tiered</span>',
                            'product_group' => '<span class="badge bg-warning text-dark">Product Group</span>',
                            'target_bonus'  => '<span class="badge bg-success">Target Bonus</span>',
                        ][$rule->rule_type] ?? '<span class="badge bg-secondary">' . e($rule->rule_type) . '</span>';
                    @endphp
                    {!! $typeBadge !!}
                </dd>

                <dt class="col-sm-3">Base rate</dt>
                <dd class="col-sm-9 font-monospace">{{ number_format((float) $rule->rate, 4) }}%</dd>

                <dt class="col-sm-3">Effective period</dt>
                <dd class="col-sm-9">
                    {{ $rule->effective_from?->format('Y-m-d') ?? '—' }}
                    &rarr;
                    {{ $rule->effective_to?->format('Y-m-d') ?? '<span class="text-muted">open-ended</span>' }}
                </dd>

                <dt class="col-sm-3">Branch scope</dt>
                <dd class="col-sm-9">
                    @if ($rule->branch_id)
                        <span class="badge bg-light text-dark border">{{ $rule->branch?->branch_name ?? "Branch #{$rule->branch_id}" }}</span>
                    @else
                        <span class="badge bg-light text-muted">All branches</span>
                    @endif
                </dd>

                <dt class="col-sm-3">Status</dt>
                <dd class="col-sm-9">
                    @if ($rule->is_active)
                        <span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i> Active</span>
                    @else
                        <span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-circle me-1"></i> Inactive</span>
                    @endif
                </dd>

                <dt class="col-sm-3">Created by</dt>
                <dd class="col-sm-9">
                    @if ($rule->created_by)
                        User #{{ $rule->created_by }}
                    @else
                        <span class="text-muted">—</span>
                    @endif
                    <span class="text-muted small">({{ $rule->created_at?->format('Y-m-d H:i') }})</span>
                </dd>

                @if ($rule->notes)
                    <dt class="col-sm-3">Notes</dt>
                    <dd class="col-sm-9">{{ $rule->notes }}</dd>
                @endif
            </dl>
        </div>
    </div>

    {{-- Tiers (if any) --}}
    @if ($rule->tiers->isNotEmpty())
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-light">
                <i class="fas fa-layer-group me-1"></i> Tiers ({{ $rule->tiers->count() }})
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Threshold (≥)</th>
                                <th>Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rule->tiers as $tier)
                                <tr>
                                    <td class="font-monospace">{{ number_format((float) $tier->threshold, 2) }}</td>
                                    <td class="font-monospace">{{ number_format((float) $tier->rate, 4) }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- Product groups (if any) --}}
    @if ($rule->productGroups->isNotEmpty())
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-light">
                <i class="fas fa-boxes-stacked me-1"></i> Product group rates ({{ $rule->productGroups->count() }})
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product group</th>
                                <th>Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rule->productGroups as $pg)
                                <tr>
                                    <td>{{ $pg->productGroup?->group_name ?? "Product Group #{$pg->product_group_id}" }}</td>
                                    <td class="font-monospace">{{ number_format((float) $pg->rate, 4) }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- Targets (if any) --}}
    @if ($rule->targets->isNotEmpty())
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-light">
                <i class="fas fa-bullseye me-1"></i> Sales targets ({{ $rule->targets->count() }})
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Target amount (≥)</th>
                                <th>Bonus rate</th>
                                <th>Period</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rule->targets as $target)
                                <tr>
                                    <td class="font-monospace">{{ number_format((float) $target->target_amount, 2) }}</td>
                                    <td class="font-monospace">{{ number_format((float) $target->bonus_rate, 4) }}%</td>
                                    <td><span class="badge bg-light text-dark">{{ ucfirst($target->period) }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- Recent entries (latest 20) --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list me-1"></i> Recent commission entries</span>
            <small class="text-muted">Latest {{ $rule->entries->count() }} entries</small>
        </div>
        <div class="card-body p-0">
            @if ($rule->entries->isEmpty())
                <div class="text-center text-muted py-4">
                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                    No commission entries have been generated for this rule yet.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Invoice</th>
                                <th>Period</th>
                                <th class="text-end">Amount</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rule->entries as $entry)
                                <tr>
                                    <td><small>{{ $entry->entry_date?->format('Y-m-d') ?? '—' }}</small></td>
                                    <td>
                                        @if ($entry->salesInvoice)
                                            <a href="{{ route('admin.sales-invoices.show', $entry->salesInvoice) }}">
                                                {{ $entry->salesInvoice->invoice_code ?? "Invoice #{$entry->sales_invoice_id}" }}
                                            </a>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td><small>{{ $entry->commission_period }}</small></td>
                                    <td class="text-end font-monospace">
                                        {{ number_format((float) $entry->commission_amount, 2) }}
                                    </td>
                                    <td class="text-center">
                                        @php
                                            $statusBadge = [
                                                'calculated' => '<span class="badge bg-warning text-dark">Pending</span>',
                                                'confirmed'  => '<span class="badge bg-info text-dark">Confirmed</span>',
                                                'paid'       => '<span class="badge bg-success">Paid</span>',
                                            ][$entry->status] ?? '<span class="badge bg-secondary">' . e($entry->status) . '</span>';
                                        @endphp
                                        {!! $statusBadge !!}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
