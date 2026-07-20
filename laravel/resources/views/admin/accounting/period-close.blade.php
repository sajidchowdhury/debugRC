@extends('layouts.admin')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1"><i class="fas fa-lock me-2 text-primary"></i>Accounting Period Close</h2>
            <p class="text-muted mb-0">Close accounting periods + execute year-end close</p>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle me-2"></i>{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row g-4">
        {{-- Left column: Period Close + Pre-close Gate --}}
        <div class="col-lg-8">
            {{-- Current Period Status --}}
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-calendar-check me-2"></i>Current Period Status</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="text-muted small">Branch</div>
                            <div class="fw-bold">{{ collect($branches)->firstWhere('id', $selectedBranchId)?->branch_name ?? '—' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Closed Through</div>
                            <div class="fw-bold {{ $closedThrough ? 'text-danger' : 'text-success' }}">
                                {{ $closedThrough ? \Carbon\Carbon::parse($closedThrough)->format('d M Y') : 'Open (no close)' }}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Earliest Open Date</div>
                            <div class="fw-bold text-success">{{ $earliestOpen ? \Carbon\Carbon::parse($earliestOpen)->format('d M Y') : 'Any date' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Pre-Close Gate --}}
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Pre-Close Gate Checks</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small">All checks must pass before the period can be closed.</p>
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Check</th><th>Status</th><th>Detail</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($preCloseChecks as $check)
                            <tr class="{{ $check['passed'] ? 'table-success' : 'table-danger' }}">
                                <td>{{ $check['label'] }}</td>
                                <td>
                                    @if ($check['passed'])
                                        <span class="badge bg-success"><i class="fas fa-check"></i> Pass</span>
                                    @else
                                        <span class="badge bg-danger"><i class="fas fa-times"></i> Fail</span>
                                    @endif
                                </td>
                                <td class="small text-muted">{{ $check['detail'] }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Close Period Form --}}
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-lock me-2"></i>Close Period</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.accounting.period-close.store') }}">
                        @csrf
                        <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">
                        <div class="mb-3">
                            <label class="form-label">Close Through Date</label>
                            <input type="date" name="close_through_date" class="form-control" required
                                   value="{{ $closedThrough ? \Carbon\Carbon::parse($closedThrough)->addMonth()->format('Y-m-t') : now()->endOfMonth()->format('Y-m-d') }}">
                            <div class="form-text">No new journal entries can be posted before this date after close.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes (optional)</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Reason for close..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary" {{ !$canClose ? 'disabled' : '' }}>
                            <i class="fas fa-lock me-1"></i> Close Period
                        </button>
                        @if (!$canClose)
                            <span class="text-danger small ms-2">Fix failing checks above first.</span>
                        @endif
                    </form>
                </div>
            </div>

            {{-- Reopen Period (superadmin only) --}}
            @if (auth()->user()?->isSuperadmin())
            <div class="card border-danger border-0 shadow-sm mb-4">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="fas fa-unlock me-2"></i>Reopen Period (Superadmin Only)</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Reopening a closed period is a sensitive action. All changes will be audit-logged.
                    </div>
                    <form method="POST" action="{{ route('admin.accounting.period-reopen') }}">
                        @csrf
                        <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">
                        <div class="mb-3">
                            <label class="form-label">Reason (min 10 chars)</label>
                            <textarea name="reason" class="form-control" rows="2" required minlength="10"
                                      placeholder="Why is this period being reopened?"></textarea>
                        </div>
                        <button type="submit" class="btn btn-outline-danger" {{ !$closedThrough ? 'disabled' : '' }}>
                            <i class="fas fa-unlock me-1"></i> Reopen Period
                        </button>
                    </form>
                </div>
            </div>
            @endif
        </div>

        {{-- Right column: Year-End Close --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-calendar-xmark me-2"></i>Year-End Close</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small">
                        Zeroes all Income + Expense ledgers and transfers net P&L to Retained Earnings.
                        Balance-sheet ledgers carry forward.
                    </p>

                    <h6 class="text-muted">Year-End Checklist</h6>
                    <table class="table table-sm">
                        <tbody>
                            @foreach ($yearEndChecks as $check)
                            <tr class="{{ $check['passed'] ? '' : 'table-danger' }}">
                                <td>
                                    @if ($check['passed'])
                                        <i class="fas fa-check text-success"></i>
                                    @else
                                        <i class="fas fa-times text-danger"></i>
                                    @endif
                                    {{ $check['label'] }}
                                </td>
                                <td class="small text-muted">{{ $check['detail'] }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <form method="POST" action="{{ route('admin.accounting.year-end-close') }}">
                        @csrf
                        <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">
                        <input type="hidden" name="year_end_date" value="{{ $yearEndDate }}">
                        <button type="submit" class="btn btn-warning w-100" {{ !$canYearEndClose ? 'disabled' : '' }}
                                onclick="return confirm('Execute year-end close? This will zero all Income/Expense ledgers and transfer net P&L to Retained Earnings.')">
                            <i class="fas fa-calendar-xmark me-1"></i> Execute Year-End Close ({{ $yearEndDate }})
                        </button>
                        @if (!$canYearEndClose)
                            <div class="text-danger small mt-2">Complete all checklist items first.</div>
                        @endif
                    </form>
                </div>
            </div>

            {{-- Info Card --}}
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6><i class="fas fa-info-circle text-info me-2"></i>How Period Close Works</h6>
                    <ol class="small text-muted ps-3">
                        <li>Pre-close gate checks: TB balanced, sub-ledgers reconciled, no unbalanced entries</li>
                        <li>Close sets <code>closed_through_date</code> on <code>accounting_periods</code></li>
                        <li><code>JournalPostingService::validatePeriod()</code> rejects postings before this date</li>
                        <li>Reversals can still post to closed periods (using <code>skip_period_check</code>)</li>
                        <li>Year-end close zeroes Income/Expense → Retained Earnings</li>
                        <li>Reopen requires superadmin + audit log</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
