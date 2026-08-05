@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-cogs me-2 text-primary"></i> Approval Workflows</h2>
            <p class="text-muted mb-0 small">Configure multi-level approval workflows for each entity type.</p>
        </div>
        <a href="{{ route('admin.approvals.queue') }}" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to Queue
        </a>
    </div>

    @foreach ($workflows as $workflow)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                <div>
                    <strong>{{ $workflow->name }}</strong>
                    <span class="badge {{ $workflow->is_active ? 'bg-success' : 'bg-secondary' }} ms-2">
                        {{ $workflow->is_active ? 'Active' : 'Inactive' }}
                    </span>
                    <span class="badge bg-primary-subtle text-primary ms-1">{{ Str::headline($workflow->entity_type) }}</span>
                </div>
                <form method="POST" action="{{ route('admin.approvals.workflows.update', $workflow->id) }}" class="d-flex gap-2 align-items-center">
                    @csrf
                    @method('PUT')
                    <div class="form-check form-switch">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input" id="active{{ $workflow->id }}"
                               {{ $workflow->is_active ? 'checked' : '' }} onchange="this.form.submit()">
                        <label class="form-check-label small" for="active{{ $workflow->id }}">Enabled</label>
                    </div>
                    <div class="input-group input-group-sm" style="width: 200px;">
                        <span class="input-group-text">Min Tk</span>
                        <input type="number" name="min_amount" class="form-control" value="{{ $workflow->min_amount }}" step="0.01" min="0">
                        <button type="submit" class="btn btn-outline-primary"><i class="fas fa-save"></i></button>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Level</th>
                            <th>Required Role</th>
                            <th>Parallel</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($workflow->steps as $step)
                            <tr>
                                <td><span class="badge bg-primary">{{ $step->level }}</span></td>
                                <td><span class="badge bg-warning text-dark">{{ $step->role }}</span></td>
                                <td>
                                    @if ($step->is_parallel)
                                        <span class="badge bg-secondary" title="Configured for all-must-approve, but parallel enforcement is not yet implemented. Currently a single approver advances the level.">
                                            Parallel (reserved)
                                        </span>
                                    @else
                                        <span class="badge bg-light text-dark">Single approver</span>
                                    @endif
                                </td>
                                <td class="text-muted small">{{ $step->description }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($workflow->description)
                <div class="card-footer bg-white py-2">
                    <small class="text-muted"><i class="fas fa-info-circle me-1"></i> {{ $workflow->description }}</small>
                </div>
            @endif
        </div>
    @endforeach

    @if ($workflows->isEmpty())
        <div class="text-center text-muted py-4">
            <i class="fas fa-inbox fa-2x mb-2"></i>
            <p>No approval workflows configured. Run the migration to seed defaults.</p>
        </div>
    @endif

</div>
@endsection
