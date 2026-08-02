@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-clipboard-check me-2 text-primary"></i> Approval Queue</h2>
            <p class="text-muted mb-0 small">Review and approve pending requests — segregation of duties enforced.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.approvals.workflows') }}" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-cogs me-1"></i> Workflows
            </a>
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    {{-- Filter --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.approvals.queue') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1">Entity Type</label>
                    <select name="entity_type" class="form-select form-select-sm">
                        <option value="">All types</option>
                        <option value="manual_journal" {{ $entityType === 'manual_journal' ? 'selected' : '' }}>Manual Journals</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter me-1"></i> Filter</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Pending approvals (my action required) --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-2">
            <i class="fas fa-inbox me-1 text-warning"></i> <strong>Pending My Action</strong>
            <span class="badge bg-warning text-dark ms-1">{{ $pendingRequests->count() }}</span>
        </div>
        <div class="card-body p-0">
            @if ($pendingRequests->isEmpty())
                <div class="text-center text-muted py-4">
                    <i class="fas fa-check-circle fa-2x mb-2 text-success"></i>
                    <p class="mb-0">No pending approvals requiring your action.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Type</th>
                                <th>Ref</th>
                                <th>Amount</th>
                                <th>Requested By</th>
                                <th>Requested At</th>
                                <th>Level</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pendingRequests as $req)
                                <tr>
                                    <td>
                                        <span class="badge bg-primary-subtle text-primary">{{ Str::headline($req->entity_type) }}</span>
                                    </td>
                                    <td>
                                        @if ($req->entity_type === 'manual_journal' && $req->entity)
                                            <a href="{{ route('admin.manual-journals.show', $req->entity_id) }}">
                                                {{ $req->entity->journal_code }}
                                            </a>
                                        @else
                                            #{{ $req->entity_id }}
                                        @endif
                                    </td>
                                    <td class="fw-semibold">Tk {{ number_format($req->entity ? $req->entity->total_debit : 0, 2) }}</td>
                                    <td>{{ $req->requester?->name ?? 'Unknown' }}</td>
                                    <td class="small">{{ $req->requested_at->format('d M Y H:i') }}</td>
                                    <td>
                                        <span class="badge bg-info">Level {{ $req->current_level }} / {{ $req->workflow?->maxLevel() }}</span>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('admin.approvals.approve', $req->id) }}" class="d-inline">
                                            @csrf
                                            <input type="text" name="comments" class="form-control form-control-sm d-none" placeholder="Optional comments">
                                            <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Approve this request?')">
                                                <i class="fas fa-check me-1"></i> Approve
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal{{ $req->id }}">
                                            <i class="fas fa-times me-1"></i> Reject
                                        </button>

                                        {{-- Reject Modal --}}
                                        <div class="modal fade" id="rejectModal{{ $req->id }}" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST" action="{{ route('admin.approvals.reject', $req->id) }}">
                                                        @csrf
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Reject Request</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                                                                <textarea name="reason" class="form-control" rows="3" required placeholder="Explain why this request is being rejected..."></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-danger btn-sm">Confirm Rejection</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- My submitted requests --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-2">
            <i class="fas fa-paper-plane me-1 text-info"></i> <strong>My Submitted Requests</strong>
            <span class="badge bg-info ms-1">{{ $myRequests->count() }}</span>
        </div>
        <div class="card-body p-0">
            @if ($myRequests->isEmpty())
                <div class="text-center text-muted py-3">
                    <p class="mb-0">You have no pending approval requests.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Type</th>
                                <th>Ref</th>
                                <th>Amount</th>
                                <th>Current Level</th>
                                <th>Submitted At</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($myRequests as $req)
                                <tr>
                                    <td><span class="badge bg-primary-subtle text-primary">{{ Str::headline($req->entity_type) }}</span></td>
                                    <td>
                                        @if ($req->entity_type === 'manual_journal' && $req->entity)
                                            <a href="{{ route('admin.manual-journals.show', $req->entity_id) }}">
                                                {{ $req->entity->journal_code }}
                                            </a>
                                        @else
                                            #{{ $req->entity_id }}
                                        @endif
                                    </td>
                                    <td>Tk {{ number_format($req->entity ? $req->entity->total_debit : 0, 2) }}</td>
                                    <td><span class="badge bg-info">Level {{ $req->current_level }} / {{ $req->workflow?->maxLevel() }}</span></td>
                                    <td class="small">{{ $req->requested_at->format('d M Y H:i') }}</td>
                                    <td>
                                        <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending</span>
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
