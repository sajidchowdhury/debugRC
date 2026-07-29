@extends('layouts.admin')

@section('title', 'Branch Demand Reconciliation')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h4 class="mb-1"><i class="fas fa-balance-scale me-2"></i>Branch Demand — Reconciliation</h4>
            <p class="text-muted">Phase 8 — Compare demand outstanding vs branch_ledger running balance. Any discrepancy indicates a data integrity issue.</p>
        </div>
    </div>

    {{-- Date Filter --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('admin.branch-demands.reconcile') }}" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">From Date</label>
                            <input type="date" name="date_from" class="form-control" value="{{ $dateFrom }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">To Date</label>
                            <input type="date" name="date_to" class="form-control" value="{{ $dateTo }}">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Reconciliation Table --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header"><strong>Reconciliation by Branch Pair</strong></div>
                <div class="card-body">
                    @if(count($reconciliation) > 0)
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Partner Branch</th>
                                <th>My Role</th>
                                <th class="text-end">Demand Count</th>
                                <th class="text-end">Total Demand Value</th>
                                <th class="text-end">Total Settlement</th>
                                <th class="text-end">Demand Outstanding</th>
                                <th class="text-end">Ledger Outstanding</th>
                                <th class="text-end">Discrepancy</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($reconciliation as $row)
                            <tr class="{{ $row['status'] === 'discrepancy' ? 'table-danger' : '' }}">
                                <td>{{ $row['partner_branch'] }}</td>
                                <td><span class="badge bg-{{ $row['role'] === 'debtor' ? 'info' : 'success' }}">{{ $row['role'] }}</span></td>
                                <td class="text-end">{{ $row['demand_count'] }}</td>
                                <td class="text-end">{{ number_format($row['total_demand_value'], 2) }}</td>
                                <td class="text-end">{{ number_format($row['total_settlement'], 2) }}</td>
                                <td class="text-end">{{ number_format($row['demand_outstanding'], 2) }}</td>
                                <td class="text-end">{{ number_format($row['ledger_outstanding'], 2) }}</td>
                                <td class="text-end {{ abs($row['discrepancy']) > 0.01 ? 'text-danger fw-bold' : 'text-success' }}">{{ number_format($row['discrepancy'], 2) }}</td>
                                <td>
                                    <span class="badge bg-{{ $row['status'] === 'balanced' ? 'success' : 'danger' }}">
                                        {{ $row['status'] === 'balanced' ? 'Balanced' : 'Discrepancy' }}
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @else
                        <p class="text-muted">No open demands found for reconciliation.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Anti-Gaming Flags --}}
    <div class="row mb-4">
        <div class="col-12">
            <h5 class="mb-3"><i class="fas fa-flag me-1"></i>Anti-Gaming Flags</h5>
        </div>
    </div>

    {{-- Catalog Below Locked Rate --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><i class="fas fa-arrow-down text-warning me-1"></i>Catalog Below Locked Rate</strong>
                    <span class="badge bg-warning text-dark">{{ $antiGamingFlags['catalog_below_locked']->count() }} flags</span>
                </div>
                <div class="card-body">
                    @if($antiGamingFlags['catalog_below_locked']->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Demand</th><th>Date</th><th>Product</th><th>Qty</th>
                                    <th>Locked Rate</th><th>Current Default</th><th>Overcharge/Unit</th><th>Overcharge Total</th><th>Severity</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($antiGamingFlags['catalog_below_locked'] as $flag)
                                <tr>
                                    <td><a href="{{ route('admin.branch-demands.audit', $flag['demand_id']) }}">{{ $flag['demand_code'] }}</a></td>
                                    <td>{{ $flag['demand_date'] }}</td>
                                    <td>{{ $flag['product_id'] }}</td>
                                    <td>{{ $flag['qty'] }}</td>
                                    <td>{{ number_format($flag['locked_cost_rate'], 4) }}</td>
                                    <td>{{ number_format($flag['current_default_rate'], 4) }}</td>
                                    <td class="text-danger">{{ number_format($flag['overcharge_per_unit'], 4) }}</td>
                                    <td class="text-danger fw-bold">{{ number_format($flag['overcharge_total'], 2) }}</td>
                                    <td><span class="badge bg-{{ $flag['severity'] === 'high' ? 'danger' : ($flag['severity'] === 'medium' ? 'warning' : 'info') }}">{{ $flag['severity'] }}</span></td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @else
                        <p class="text-muted">No flags — current prices are at or above locked rates.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Sales Below Locked Cost --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><i class="fas fa-shopping-cart text-warning me-1"></i>Sales Below Locked Cost</strong>
                    <span class="badge bg-warning text-dark">{{ $antiGamingFlags['sales_below_cost']->count() }} flags</span>
                </div>
                <div class="card-body">
                    @if($antiGamingFlags['sales_below_cost']->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Demand</th><th>Sale</th><th>Product</th><th>Sale Qty</th>
                                    <th>Sale Rate</th><th>Locked Cost</th><th>Loss/Unit</th><th>Loss Total</th><th>Severity</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($antiGamingFlags['sales_below_cost'] as $flag)
                                <tr>
                                    <td>{{ $flag['demand_code'] }}</td>
                                    <td>{{ $flag['sale_code'] }}</td>
                                    <td>{{ $flag['product_id'] }}</td>
                                    <td>{{ $flag['sale_qty'] }}</td>
                                    <td>{{ number_format($flag['sale_rate'], 4) }}</td>
                                    <td>{{ number_format($flag['locked_cost_rate'], 4) }}</td>
                                    <td class="text-danger">{{ number_format($flag['loss_per_unit'], 4) }}</td>
                                    <td class="text-danger fw-bold">{{ number_format($flag['loss_total'], 2) }}</td>
                                    <td><span class="badge bg-{{ $flag['severity'] === 'high' ? 'danger' : ($flag['severity'] === 'medium' ? 'warning' : 'info') }}">{{ $flag['severity'] }}</span></td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @else
                        <p class="text-muted">No flags — all sales are at or above locked cost rates.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Stale Outstanding --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><i class="fas fa-clock text-warning me-1"></i>Stale Outstanding (> 30 days)</strong>
                    <span class="badge bg-warning text-dark">{{ $antiGamingFlags['stale_outstanding']->count() }} flags</span>
                </div>
                <div class="card-body">
                    @if($antiGamingFlags['stale_outstanding']->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Demand</th><th>Date</th><th>Requester</th><th>Supplier</th>
                                    <th>Total Value</th><th>Settled</th><th>Outstanding</th><th>Days</th><th>Severity</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($antiGamingFlags['stale_outstanding'] as $flag)
                                <tr>
                                    <td><a href="{{ route('admin.branch-demands.audit', $flag['demand_id']) }}">{{ $flag['demand_code'] }}</a></td>
                                    <td>{{ $flag['demand_date'] }}</td>
                                    <td>{{ $flag['from_branch'] }}</td>
                                    <td>{{ $flag['to_branch'] }}</td>
                                    <td>{{ number_format($flag['total_value'], 2) }}</td>
                                    <td>{{ number_format($flag['settlement_amount'], 2) }}</td>
                                    <td class="text-danger fw-bold">{{ number_format($flag['outstanding'], 2) }}</td>
                                    <td>{{ $flag['days_outstanding'] }}</td>
                                    <td><span class="badge bg-{{ $flag['severity'] === 'high' ? 'danger' : ($flag['severity'] === 'medium' ? 'warning' : 'info') }}">{{ $flag['severity'] }}</span></td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @else
                        <p class="text-muted">No flags — all outstanding balances are less than 30 days old.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <a href="{{ route('admin.branch-demands.checklist') }}" class="btn btn-outline-primary">
                <i class="fas fa-clipboard-check me-1"></i> Audit Checklist
            </a>
            <a href="{{ route('admin.branch-demands.index') }}" class="btn btn-outline-secondary ms-2">
                <i class="fas fa-arrow-left me-1"></i> Back to Demands
            </a>
        </div>
    </div>
</div>
@endsection
