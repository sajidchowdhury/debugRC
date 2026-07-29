@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    {{-- Header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#a855f7);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-tags me-2"></i> Price Range Comparison</h1>
            <p class="mb-0 small opacity-75">
                Identify demand items where the current price range differs from the locked price range at send time
            </p>
        </div>
        <div>
            <a href="{{ route('admin.branch-demands.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to Demands
            </a>
        </div>
    </header>

    {{-- Date filters --}}
    <div class="card shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.branch-demands.price-range-comparison') }}" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label small mb-0">From Date</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="{{ $dateFrom ?? '' }}">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">To Date</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="{{ $dateTo ?? '' }}">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="{{ route('admin.branch-demands.price-range-comparison') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-times me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Summary cards --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#7c3aed;">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <div>
                        <div class="small text-muted">Price Changes</div>
                        <div class="h5 mb-0">{{ count($changes) }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#dc2626;">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <div>
                        <div class="small text-muted">Price Increases</div>
                        <div class="h5 mb-0">{{ collect($changes)->where('direction', 'increase')->count() }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#059669;">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <div>
                        <div class="small text-muted">Price Decreases</div>
                        <div class="h5 mb-0">{{ collect($changes)->where('direction', 'decrease')->count() }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#d97706;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div>
                        <div class="small text-muted">Out-of-Range Sales</div>
                        <div class="h5 mb-0">{{ count($outOfRangeSales) }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Price Range Changes Table --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light">
            <i class="fas fa-chart-line me-1"></i> Price Range Changes
            <span class="badge bg-info ms-1">{{ count($changes) }} items</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Demand</th>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Cost Rate</th>
                            <th>Locked Min</th>
                            <th>Locked Max</th>
                            <th>Locked Default</th>
                            <th>Current Default</th>
                            <th>Variance</th>
                            <th>Impact</th>
                            <th>Margin</th>
                            <th>Direction</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($changes as $change)
                        <tr class="{{ $change['direction'] === 'increase' ? 'table-danger-subtle' : 'table-success-subtle' }}">
                            <td>
                                <a href="{{ route('admin.branch-demands.show', $change['demand_id']) }}" class="fw-semibold">
                                    {{ $change['demand_code'] }}
                                </a>
                            </td>
                            <td class="small">{{ $change['demand_date'] }}</td>
                            <td>
                                <span class="fw-semibold">{{ $change['product_name'] }}</span>
                                <br><small class="text-muted">{{ $change['product_code'] }}</small>
                            </td>
                            <td>{{ number_format($change['qty'], 2) }}</td>
                            <td>{{ number_format($change['cost_rate'], 4) }}</td>
                            <td>{{ number_format($change['locked_min'], 2) }}</td>
                            <td>{{ number_format($change['locked_max'], 2) }}</td>
                            <td>{{ number_format($change['locked_default'], 2) }}</td>
                            <td class="fw-semibold">{{ number_format($change['current_default'], 2) }}</td>
                            <td>
                                @if($change['default_variance'] > 0)
                                    <span class="text-danger">+{{ number_format($change['default_variance'], 2) }}</span>
                                @else
                                    <span class="text-success">{{ number_format($change['default_variance'], 2) }}</span>
                                @endif
                            </td>
                            <td class="fw-semibold">{{ number_format($change['impact_amount'], 2) }}</td>
                            <td>
                                @if($change['margin_variance'] > 0)
                                    <span class="text-success">+{{ number_format($change['margin_variance'], 2) }}</span>
                                @elseif($change['margin_variance'] < 0)
                                    <span class="text-danger">{{ number_format($change['margin_variance'], 2) }}</span>
                                @else
                                    <span class="text-muted">0.00</span>
                                @endif
                            </td>
                            <td>
                                @if($change['direction'] === 'increase')
                                    <span class="badge bg-danger-subtle text-danger"><i class="fas fa-arrow-up me-1"></i>Up</span>
                                @else
                                    <span class="badge bg-success-subtle text-success"><i class="fas fa-arrow-down me-1"></i>Down</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="13" class="text-center text-muted py-4">
                                <i class="fas fa-check-circle me-1"></i> No price range changes detected. All locked prices match current prices.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Out-of-Range Sales Warnings --}}
    @if(count($outOfRangeSales) > 0)
    <div class="card border-warning shadow-sm mb-3">
        <div class="card-header bg-warning-subtle">
            <i class="fas fa-exclamation-triangle me-1"></i> Out-of-Range Sales Warnings
            <span class="badge bg-warning ms-1">{{ count($outOfRangeSales) }} warnings</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Demand</th>
                            <th>Product</th>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Sale Rate</th>
                            <th>Locked Min</th>
                            <th>Locked Max</th>
                            <th>Locked Default</th>
                            <th>Variance</th>
                            <th>Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($outOfRangeSales as $warning)
                        <tr class="table-warning-subtle">
                            <td>
                                <a href="{{ route('admin.branch-demands.show', $warning['demand_id']) }}" class="fw-semibold">
                                    {{ $warning['demand_code'] }}
                                </a>
                            </td>
                            <td>
                                <span class="fw-semibold">{{ $warning['product_name'] }}</span>
                                <br><small class="text-muted">{{ $warning['product_code'] }}</small>
                            </td>
                            <td>{{ $warning['invoice_code'] }}</td>
                            <td class="small">{{ $warning['invoice_date'] }}</td>
                            <td class="fw-semibold">{{ number_format($warning['sale_rate'], 2) }}</td>
                            <td>{{ number_format($warning['locked_min'], 2) }}</td>
                            <td>{{ number_format($warning['locked_max'], 2) }}</td>
                            <td>{{ number_format($warning['locked_default'], 2) }}</td>
                            <td>
                                @if($warning['variance'] > 0)
                                    <span class="text-danger">+{{ number_format($warning['variance'], 2) }}</span>
                                @else
                                    <span class="text-success">{{ number_format($warning['variance'], 2) }}</span>
                                @endif
                            </td>
                            <td>
                                @if($warning['type'] === 'below_min')
                                    <span class="badge bg-danger-subtle text-danger">
                                        <i class="fas fa-arrow-down me-1"></i>Below Min
                                    </span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning">
                                        <i class="fas fa-arrow-up me-1"></i>Above Max
                                    </span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    {{-- Info section --}}
    <div class="card shadow-sm">
        <div class="card-body">
            <h6><i class="fas fa-info-circle me-1"></i> About Price Range Comparison</h6>
            <p class="small text-muted mb-1">
                This view shows demand items where the current product price range differs from the
                price range that was locked at the time goods were sent. This helps identify:
            </p>
            <ul class="small text-muted mb-0">
                <li><strong>Price variance:</strong> The difference between the current default rate and the locked default rate at send time</li>
                <li><strong>Impact amount:</strong> The financial impact on the outstanding balance (variance x quantity)</li>
                <li><strong>Margin variance:</strong> The difference between the current default rate and the locked cost rate</li>
                <li><strong>Out-of-range sales:</strong> Sales where the price was below the locked minimum or above the locked maximum</li>
            </ul>
            <p class="small text-muted mt-2 mb-0">
                <strong>Note:</strong> Out-of-range sales are flagged as warnings for visibility and accountability — they do NOT prevent the sale.
                Consider creating a repricing adjustment if the price change is significant and both branches agree.
            </p>
        </div>
    </div>
</div>
@endsection
