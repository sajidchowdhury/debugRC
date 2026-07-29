@extends('layouts.admin')

@section('title', $demand->demand_code)

@push('css')
<link rel="stylesheet" href="/assets/css/branch-demand.css">
@endpush

@section('content')
<div class="bd-demand-app container-fluid py-2">
    {{-- Header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-file-invoice me-2"></i>{{ $demand->demand_code }}</h1>
            <p class="mb-0 small opacity-75">
                {{ $demand->fromBranch->branch_name ?? 'N/A' }} &rarr; {{ $demand->toBranch->branch_name ?? 'N/A' }}
                &bull; {{ $demand->demand_date ? $demand->demand_date->format('d M Y') : '-' }}
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.branch-demands.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
            @if($demand->isPending() && $isSupplier)
            <a href="#send-section" class="btn btn-success btn-sm">
                <i class="fas fa-paper-plane me-1"></i> Send Goods
            </a>
            @endif
            @if($demand->status === 'received' && $demand->received_at === null && $isRequester)
            <form method="POST" action="{{ route('admin.branch-demands.confirm-receipt', $demand->id) }}"
                  onsubmit="return confirm('Confirm receipt of goods for {{ $demand->demand_code }}? This action cannot be undone.')">
                @csrf
                <button type="submit" class="btn btn-success btn-sm">
                    <i class="fas fa-check me-1"></i> Confirm Receipt
                </button>
            </form>
            @endif
            @if($demand->isReceived() && $demand->received_at !== null)
            <form method="POST" action="{{ route('admin.branch-demands.reverse', $demand->id) }}"
                  onsubmit="return confirm('Are you sure you want to reverse demand {{ $demand->demand_code }}? This will restore all stock movements.')">
                @csrf
                <div class="d-flex gap-1">
                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reversal reason..." required style="width: 200px;">
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fas fa-rotate-left me-1"></i> Reverse
                    </button>
                </div>
            </form>
            @endif
            @if($demand->isPending() && $isSupplier)
            <form method="POST" action="{{ route('admin.branch-demands.reject', $demand->id) }}"
                  onsubmit="return confirm('Reject demand {{ $demand->demand_code }}?')">
                @csrf
                <div class="d-flex gap-1">
                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="Rejection reason..." required style="width: 200px;">
                    <button type="submit" class="btn btn-outline-warning btn-sm">
                        <i class="fas fa-ban me-1"></i> Reject
                    </button>
                </div>
            </form>
            @endif
            @if($demand->isPending() && $isRequester)
            <form method="POST" action="{{ route('admin.branch-demands.destroy', $demand->id) }}"
                  onsubmit="return confirm('Delete demand {{ $demand->demand_code }}? This cannot be undone.')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="fas fa-trash me-1"></i> Delete
                </button>
            </form>
            @endif
        </div>
    </header>

    {{-- Status cards row --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#4f46e5;">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <div>
                        <div class="small text-muted">Status</div>
                        <div><x-branch-demand.status-badge :status="$demand->status" :received-at="$demand->received_at" /></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#059669;">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div>
                        <div class="small text-muted">Total Value</div>
                        <div class="h5 mb-0">{{ $demand->total_value ? number_format((float) $demand->total_value, 2) : '-' }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0284c7;">
                        <i class="fas fa-hand-holding-dollar"></i>
                    </div>
                    <div>
                        <div class="small text-muted">Settlement</div>
                        <div class="h5 mb-0">{{ number_format((float) ($demand->settlement_amount ?? 0), 2) }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:{{ $demand->received_at ? '#059669' : '#d97706' }};">
                        <i class="fas fa-{{ $demand->received_at ? 'check-double' : 'clock' }}"></i>
                    </div>
                    <div>
                        <div class="small text-muted">Receipt</div>
                        <div class="h6 mb-0">
                            @if($demand->received_at)
                                <span class="text-success">Confirmed {{ $demand->received_at->format('d M Y H:i') }}</span>
                                <br><small class="text-muted">by {{ $demand->receivedBy->name ?? 'N/A' }}</small>
                            @elseif($demand->status === 'received')
                                <span class="text-warning">Awaiting Confirmation</span>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Demand details --}}
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-light"><i class="fas fa-info-circle me-1"></i> Demand Info</div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:35%">Demand Code</td><td class="fw-semibold">{{ $demand->demand_code }}</td></tr>
                        <tr><td class="text-muted">Demand Date</td><td>{{ $demand->demand_date ? $demand->demand_date->format('d M Y') : '-' }}</td></tr>
                        <tr><td class="text-muted">From Branch (Requester)</td><td>{{ $demand->fromBranch->branch_name ?? 'N/A' }}</td></tr>
                        <tr><td class="text-muted">To Branch (Supplier)</td><td>{{ $demand->toBranch->branch_name ?? 'N/A' }}</td></tr>
                        <tr><td class="text-muted">Created By</td><td>{{ $demand->createdBy->name ?? 'N/A' }}</td></tr>
                        <tr><td class="text-muted">Created At</td><td>{{ $demand->created_at ? $demand->created_at->format('d M Y H:i') : '-' }}</td></tr>
                        @if($demand->notes)
                        <tr><td class="text-muted">Notes</td><td>{{ $demand->notes }}</td></tr>
                        @endif
                        @if($demand->is_reversed)
                        <tr><td class="text-muted">Reversed By</td><td>{{ $demand->reversedBy->name ?? 'N/A' }}</td></tr>
                        <tr><td class="text-muted">Reversed At</td><td>{{ $demand->reversed_at ? $demand->reversed_at->format('d M Y H:i') : '-' }}</td></tr>
                        <tr><td class="text-muted">Reverse Reason</td><td class="text-danger">{{ $demand->reverse_reason }}</td></tr>
                        @endif
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-light"><i class="fas fa-chart-pie me-1"></i> Settlement Progress</div>
                <div class="card-body">
                    @php
                        $total = (float) ($demand->total_value ?? 0);
                        $settled = (float) ($demand->settlement_amount ?? 0);
                        $outstanding = max(0, $total - $settled);
                        $progress = $total > 0 ? min(100, round(($settled / $total) * 100, 1)) : 0;
                    @endphp
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span>Settlement Progress</span>
                            <span class="fw-semibold">{{ $progress }}%</span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-success" style="width: {{ $progress }}%"></div>
                        </div>
                    </div>
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted">Total Value</td><td class="fw-semibold">{{ number_format($total, 2) }}</td></tr>
                        <tr><td class="text-muted">Settled</td><td class="text-success fw-semibold">{{ number_format($settled, 2) }}</td></tr>
                        <tr><td class="text-muted">Outstanding</td><td class="text-danger fw-semibold">{{ number_format($outstanding, 2) }}</td></tr>
                    </table>
                    @if($demand->moneyTransferSettlements->count() > 0)
                    <hr>
                    <div class="small text-muted mb-1">Money Transfer Settlements</div>
                    @foreach($demand->moneyTransferSettlements as $mts)
                    <div class="small">Transfer #{{ $mts->transfer_id }}: {{ number_format((float) $mts->settled_amount, 2) }}</div>
                    @endforeach
                    @endif
                    @if($demand->customerPaymentSettlements->count() > 0)
                    <hr>
                    <div class="small text-muted mb-1">Customer Payment Settlements</div>
                    @foreach($demand->customerPaymentSettlements as $cps)
                    <div class="small">Payment #{{ $cps->payment_id }}: {{ number_format((float) $cps->settled_amount, 2) }}</div>
                    @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Items table --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light"><i class="fas fa-boxes-stacked me-1"></i> Demand Items</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>From Warehouse</th>
                            <th>To Warehouse</th>
                            <th>Cost Rate</th>
                            <th>Line Total</th>
                            <th>Price Range</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($demand->items as $idx => $item)
                        <tr>
                            <td>{{ $idx + 1 }}</td>
                            <td>
                                <span class="fw-semibold">{{ $item->product->product_name ?? 'N/A' }}</span>
                                <br><small class="text-muted">{{ $item->product->product_code ?? '' }}</small>
                            </td>
                            <td>{{ number_format((float) $item->qty, 2) }}</td>
                            <td>{{ $item->fromWarehouse->warehouse_name ?? '-' }}</td>
                            <td>{{ $item->toWarehouse->warehouse_name ?? '-' }}</td>
                            <td>{{ $item->cost_rate ? number_format((float) $item->cost_rate, 4) : '-' }}</td>
                            <td class="fw-semibold">{{ $item->isSent() ? number_format($item->lineTotal(), 2) : '-' }}</td>
                            <td>
                                @if($item->isSent() && (float) $item->price_min > 0)
                                    <span class="small">
                                        {{ number_format((float) $item->price_min, 2) }}
                                        &ndash;
                                        {{ number_format((float) $item->price_max, 2) }}
                                        <br><span class="text-muted">Default: {{ number_format((float) $item->price_default, 2) }}</span>
                                    </span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Send goods section (only for supplier viewing a pending demand) --}}
    @if($demand->isPending() && $isSupplier && count($supplierWarehouses) > 0)
    <div class="card shadow-sm mb-3" id="send-section">
        <div class="card-header bg-success text-white"><i class="fas fa-paper-plane me-1"></i> Send Goods — Select Warehouses</div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.branch-demands.send', $demand->id) }}" id="sendForm">
                @csrf
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>From Warehouse (Supplier)</th>
                                <th>To Warehouse (Requester)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($demand->items as $idx => $item)
                            <tr>
                                <td>{{ $item->product->product_name ?? 'N/A' }}</td>
                                <td>{{ number_format((float) $item->qty, 2) }}</td>
                                <td>
                                    <select name="items[{{ $idx }}][from_warehouse_id]" class="form-select form-select-sm" required>
                                        <option value="">Select supplier warehouse...</option>
                                        @foreach($supplierWarehouses as $wh)
                                        <option value="{{ $wh->id }}">{{ $wh->warehouse_name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="items[{{ $idx }}][to_warehouse_id]" class="form-select form-select-sm" required>
                                        <option value="">Select requester warehouse...</option>
                                        @foreach($requesterWarehouses as $wh)
                                        <option value="{{ $wh->id }}">{{ $wh->warehouse_name }}</option>
                                        @endforeach
                                    </select>
                                    <input type="hidden" name="items[{{ $idx }}][id]" value="{{ $item->id }}">
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-success" onclick="return confirm('Send goods for demand {{ $demand->demand_code }}? This will move stock and create GL entries.')">
                        <i class="fas fa-paper-plane me-1"></i> Send Goods
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    {{-- Receipt confirmation notice (Phase 5) --}}
    @if($demand->status === 'received' && $demand->received_at === null && $isRequester)
    <div class="card border-warning mb-3">
        <div class="card-header bg-warning-subtle"><i class="fas fa-exclamation-triangle me-1"></i> Receipt Confirmation Required</div>
        <div class="card-body">
            <p class="mb-2">
                Goods have been sent by the supplier branch. Please confirm that you have physically received the products at your warehouse.
            </p>
            <p class="mb-3 small text-muted">
                <strong>Important:</strong> This demand cannot be reversed until you confirm receipt. This ensures accountability for the transferred goods.
            </p>
            <form method="POST" action="{{ route('admin.branch-demands.confirm-receipt', $demand->id) }}"
                  onsubmit="return confirm('Confirm receipt of goods for {{ $demand->demand_code }}? This action cannot be undone.')">
                @csrf
                <div class="d-flex gap-2 align-items-center">
                    <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Optional remarks about receipt..." style="max-width:300px;">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="fas fa-check me-1"></i> Confirm Receipt
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    {{-- Reversal blocked notice (Phase 5) --}}
    @if($demand->status === 'received' && $demand->received_at === null && $isSupplier)
    <div class="card border-info mb-3">
        <div class="card-header bg-info-subtle"><i class="fas fa-info-circle me-1"></i> Receipt Not Yet Confirmed</div>
        <div class="card-body">
            <p class="mb-0">
                The requesting branch has not yet confirmed receipt of the goods. This demand <strong>cannot be reversed</strong> until the receiving warehouse manager acknowledges receipt. This is a Phase 5 accountability control.
            </p>
        </div>
    </div>
    @endif

    {{-- Stock Transactions trace --}}
    @if($demand->status === 'received' || $demand->status === 'reversed')
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light"><i class="fas fa-truck me-1"></i> Stock Movement Trace</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Type</th>
                            <th>Product</th>
                            <th>Warehouse</th>
                            <th>Qty</th>
                            <th>Rate</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($stockTransactions as $st)
                        <tr>
                            <td>
                                @if($st->reference_type === 'demand_send')
                                    <span class="badge bg-danger-subtle text-danger">OUT</span>
                                @elseif($st->reference_type === 'demand_receive')
                                    <span class="badge bg-success-subtle text-success">IN</span>
                                @elseif($st->reference_type === 'demand_reversal')
                                    <span class="badge bg-secondary-subtle text-secondary">REVERSAL</span>
                                @else
                                    <span class="badge bg-light text-dark">{{ $st->reference_type }}</span>
                                @endif
                            </td>
                            <td>{{ $st->product->product_name ?? 'N/A' }}</td>
                            <td>{{ $st->warehouse->warehouse_name ?? 'N/A' }}</td>
                            <td class="fw-semibold">{{ number_format((float) $st->qty, 2) }}</td>
                            <td>{{ number_format((float) $st->rate, 4) }}</td>
                            <td>{{ $st->transaction_date ?? '-' }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-3">No stock transactions found.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    {{-- GL / Journal Entries --}}
    @if($demand->journal_entry_id || $demand->journal_entry_id_debtor)
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light"><i class="fas fa-book me-1"></i> GL Journal Entries</div>
        <div class="card-body">
            @if($demand->journalEntry)
            <div class="mb-2">
                <strong>Creditor (Supplier) Journal:</strong> JE #{{ $demand->journal_entry_id }}
                @if($demand->journalEntry->is_reversed)
                <span class="badge bg-secondary">Reversed</span>
                @endif
            </div>
            @endif
            @if($demand->debtorJournalEntry)
            <div>
                <strong>Debtor (Requester) Journal:</strong> JE #{{ $demand->journal_entry_id_debtor }}
                @if($demand->debtorJournalEntry->is_reversed)
                <span class="badge bg-secondary">Reversed</span>
                @endif
            </div>
            @endif
        </div>
    </div>
    @endif

    {{-- Repricing History (Phase 7) --}}
    @php
        $repricingHistory = $demand->repricingAdjustments ?? collect();
    @endphp
    @if($demand->isReceived() && !$demand->is_reversed)
    <div class="card shadow-sm mb-3" id="repricing-section">
        <div class="card-header bg-light">
            <i class="fas fa-tags me-1"></i> Repricing History
            @if($repricingHistory->count() > 0)
            <span class="badge bg-info ms-1">{{ $repricingHistory->count() }} adjustment(s)</span>
            @endif
        </div>
        <div class="card-body">
            @if($repricingHistory->count() > 0)
            <div class="table-responsive mb-3">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Original Value</th>
                            <th>New Value</th>
                            <th>Adjustment</th>
                            <th>Reason</th>
                            <th>GL Journal</th>
                            <th>Approved By</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($repricingHistory as $idx => $ra)
                        <tr>
                            <td>{{ $idx + 1 }}</td>
                            <td>{{ number_format((float) $ra->original_total_value, 2) }}</td>
                            <td class="fw-semibold">{{ number_format((float) $ra->new_total_value, 2) }}</td>
                            <td>
                                @if((float) $ra->adjustment_amount > 0)
                                    <span class="text-danger">+{{ number_format((float) $ra->adjustment_amount, 2) }}</span>
                                @else
                                    <span class="text-success">{{ number_format((float) $ra->adjustment_amount, 2) }}</span>
                                @endif
                            </td>
                            <td class="small">{{ $ra->reason }}</td>
                            <td>
                                @if($ra->journal_entry_id)
                                    JE #{{ $ra->journal_entry_id }}
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td>
                                @if($ra->approved_by)
                                    {{ App\Models\User::find($ra->approved_by)?->name ?? 'N/A' }}
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="small text-muted">{{ $ra->created_at ? \Carbon\Carbon::parse($ra->created_at)->format('d M Y H:i') : '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            {{-- Repricing form --}}
            @if($demand->isReceived() && $demand->received_at !== null)
            <div class="border rounded p-3 bg-light-subtle">
                <h6 class="mb-2"><i class="fas fa-tags me-1"></i> Create Repricing Adjustment</h6>
                <p class="small text-muted mb-3">
                    Adjust the total value of this demand. This will create a GL adjustment journal and update the branch ledger.
                    Current total value: <strong>{{ number_format((float) ($demand->total_value ?? 0), 2) }}</strong>
                </p>
                <form method="POST" action="{{ route('admin.branch-demands.reprice', $demand->id) }}"
                      onsubmit="return confirm('Create repricing adjustment for demand {{ $demand->demand_code }}? This will update the total value and create GL entries.')">
                    @csrf
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small">New Total Value</label>
                            <input type="number" name="new_total_value" class="form-control form-control-sm"
                                   step="0.01" min="0" required
                                   value="{{ number_format((float) ($demand->total_value ?? 0), 2) }}"
                                   placeholder="Enter new total value">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Reason <span class="text-muted">(min 10 chars)</span></label>
                            <input type="text" name="reason" class="form-control form-control-sm"
                                   required minlength="10" maxlength="1000"
                                   placeholder="Reason for repricing adjustment...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Approved By <span class="text-muted">(optional)</span></label>
                            <input type="number" name="approved_by" class="form-control form-control-sm"
                                   placeholder="User ID (optional)">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-warning btn-sm w-100">
                                <i class="fas fa-tags me-1"></i> Reprice
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            @endif
        </div>
    </div>
    @endif

    {{-- Price Range Audit Section (Phase 7) --}}
    @if($demand->isReceived() && !$demand->is_reversed && $demand->items->where('price_min', '>', 0)->count() > 0)
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light"><i class="fas fa-chart-line me-1"></i> Price Range Audit (Phase 7)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Cost Rate</th>
                            <th>Locked Min</th>
                            <th>Locked Max</th>
                            <th>Locked Default</th>
                            <th>Current Default</th>
                            <th>Variance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($demand->items->where('price_min', '>', 0) as $item)
                        @php
                            $currentPrice = \Illuminate\Support\Facades\DB::table('product_price_history')
                                ->where('product_id', $item->product_id)
                                ->where('effective_from', '<=', now()->format('Y-m-d'))
                                ->where(function($q) { $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()->format('Y-m-d')); })
                                ->orderByDesc('effective_from')
                                ->value('default_rate') ?? 0;
                            $variance = round((float) $currentPrice - (float) $item->price_default, 2);
                            $hasChanged = abs($variance) > 0.01;
                        @endphp
                        <tr class="{{ $hasChanged ? 'table-warning' : '' }}">
                            <td>
                                <span class="fw-semibold">{{ $item->product->product_name ?? 'N/A' }}</span>
                                <br><small class="text-muted">{{ $item->product->product_code ?? '' }}</small>
                            </td>
                            <td>{{ number_format((float) $item->qty, 2) }}</td>
                            <td>{{ number_format((float) $item->cost_rate, 4) }}</td>
                            <td>{{ number_format((float) $item->price_min, 2) }}</td>
                            <td>{{ number_format((float) $item->price_max, 2) }}</td>
                            <td>{{ number_format((float) $item->price_default, 2) }}</td>
                            <td class="fw-semibold">{{ number_format((float) $currentPrice, 2) }}</td>
                            <td>
                                @if($hasChanged)
                                    @if($variance > 0)
                                        <span class="text-danger">+{{ number_format($variance, 2) }}</span>
                                    @else
                                        <span class="text-success">{{ number_format($variance, 2) }}</span>
                                    @endif
                                @else
                                    <span class="text-muted">0.00</span>
                                @endif
                            </td>
                            <td>
                                @if($hasChanged)
                                    <span class="badge bg-warning-subtle text-warning">
                                        <i class="fas fa-exclamation-triangle me-1"></i>Price Changed
                                    </span>
                                @else
                                    <span class="badge bg-success-subtle text-success">
                                        <i class="fas fa-check me-1"></i>Unchanged
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
</div>
@endsection
