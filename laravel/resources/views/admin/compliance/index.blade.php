@extends('layouts.admin')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">
                <i class="fas fa-shield-halved me-2" style="color: {{ $isInvestigation ? '#dc2626' : '#16a34a' }};"></i>
                System Policy & Compliance
            </h2>
            <p class="text-muted mb-0">Centralized compliance framework — no scattered checks, no per-controller logic</p>
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
        {{-- Left: Current Status + Activate/Deactivate --}}
        <div class="col-lg-7">
            {{-- Current Mode Banner --}}
            <div class="card border-0 shadow-sm mb-4 {{ $isInvestigation ? 'border-danger' : 'border-success' }}"
                 style="border-width: 2px !important; border-style: solid !important;">
                <div class="card-body text-center py-4">
                    @if ($isInvestigation)
                        <i class="fas fa-user-secret fa-3x text-danger mb-3"></i>
                        <h3 class="text-danger">INVESTIGATION MODE ACTIVE</h3>
                        <p class="text-muted">
                            All users (including Super Admin) can only access data within the current fiscal year
                            ({{ $currentPolicy?->getFiscalYearStart() }} → {{ $currentPolicy?->getFiscalYearEnd() }}).
                            Old records are inaccessible. Reports auto-clamp. This is system-wide.
                        </p>
                        @if ($currentPolicy?->expires_at)
                            <p class="text-warning">
                                <i class="fas fa-clock me-1"></i>
                                Auto-expires: {{ \Carbon\Carbon::parse($currentPolicy->expires_at)->format('d M Y H:i') }}
                            </p>
                        @endif
                    @else
                        <i class="fas fa-circle-check fa-3x text-success mb-3"></i>
                        <h3 class="text-success">NORMAL OPERATION</h3>
                        <p class="text-muted">No restrictions. All data is accessible to authorized users.</p>
                    @endif
                </div>
            </div>

            {{-- Activate Investigation --}}
            @if (!$isInvestigation)
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-user-secret me-2 text-danger"></i>Activate Investigation Mode</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> Activating Investigation Mode restricts ALL users (including Super Admin)
                        to current fiscal year data only. This is irreversible until manually deactivated.
                    </div>
                    <form method="POST" action="{{ route('admin.compliance.activate') }}">
                        @csrf
                        <input type="hidden" name="mode" value="INVESTIGATION">
                        <div class="mb-3">
                            <label class="form-label">Reason (min 10 chars) <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="3" required minlength="10"
                                      placeholder="e.g. Government investigation — restrict access to current fiscal year per compliance order"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Auto-Expire (optional)</label>
                            <input type="datetime-local" name="expires_at" class="form-control">
                            <div class="form-text">Policy auto-deactivates at this time. Leave empty for manual deactivation only.</div>
                        </div>
                        <button type="submit" class="btn btn-danger"
                                onclick="return confirm('Activate INVESTIGATION MODE? All users will be restricted to current fiscal year data.')">
                            <i class="fas fa-user-secret me-1"></i> Activate Investigation Mode
                        </button>
                    </form>
                </div>
            </div>
            @endif

            {{-- Deactivate Investigation --}}
            @if ($isInvestigation)
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-unlock me-2 text-success"></i>Deactivate Investigation Mode</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.compliance.deactivate') }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">Reason for Deactivation (min 10 chars) <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="3" required minlength="10"
                                      placeholder="e.g. Investigation concluded — restoring normal access"></textarea>
                        </div>
                        <button type="submit" class="btn btn-success"
                                onclick="return confirm('Deactivate Investigation Mode? Normal access will be restored for all users.')">
                            <i class="fas fa-unlock me-1"></i> Deactivate & Restore Normal Operation
                        </button>
                    </form>
                </div>
            </div>
            @endif
        </div>

        {{-- Right: History + Architecture --}}
        <div class="col-lg-5">
            {{-- Policy History --}}
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-clock-rotate-left me-2"></i>Policy History (last 30)</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="table-light sticky-top">
                                <tr><th>Mode</th><th>Activated By</th><th>At</th><th>Reason</th></tr>
                            </thead>
                            <tbody>
                                @forelse ($history as $h)
                                <tr>
                                    <td>
                                        <span class="badge {{ $h->mode === 'INVESTIGATION' ? 'bg-danger' : 'bg-success' }}">
                                            {{ $h->mode }}
                                        </span>
                                        @if ($h->is_active)
                                            <span class="badge bg-warning text-dark">ACTIVE</span>
                                        @endif
                                    </td>
                                    <td class="small">{{ $h->activatedBy?->username ?? '—' }}</td>
                                    <td class="small">{{ $h->activated_at?->format('d M Y H:i') }}</td>
                                    <td class="small text-muted" style="max-width: 200px; overflow: hidden; text-overflow: ellipsis;">
                                        {{ $h->reason }}
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">No policy changes yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Architecture Info --}}
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6><i class="fas fa-diagram-project text-info me-2"></i>How It Works</h6>
                    <ol class="small text-muted ps-3">
                        <li><strong>SystemPolicyService</strong> — cached policy lookup (O(1) per request)</li>
                        <li><strong>CheckSystemPolicy middleware</strong> — loads policy, shares with app + views</li>
                        <li><strong>ApplySystemPolicyScope</strong> — Eloquent global scope auto-clamps dates</li>
                        <li><strong>SystemPolicyChanged event</strong> — dispatches on every change</li>
                        <li><strong>Gate</strong> — only superadmin can activate/deactivate</li>
                        <li><strong>Audit log</strong> — every change recorded (immutable)</li>
                    </ol>
                    <div class="alert alert-info small mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        <strong>No scattered checks.</strong> Controllers don't know about investigation mode.
                        The Eloquent scope handles it transparently. Adding a new mode requires zero
                        changes to existing business logic.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
