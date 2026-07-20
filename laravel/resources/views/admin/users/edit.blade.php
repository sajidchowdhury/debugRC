@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#1e3a8a,#3b82f6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-pen-to-square me-2"></i>Edit user account</h1>
            <p class="mb-0 small opacity-75">
                <strong>{{ $item->username }}</strong>
                @if ($item->employee)
                    · {{ $item->employee->name }}
                @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.users.show', $item) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-circle-info me-1"></i> View
            </a>
            <a href="{{ route('admin.users.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </header>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-user me-1 text-primary"></i> Account details</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.users.update', $item) }}">
                        @csrf
                        @method('PUT')
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="username">Username <span class="text-danger">*</span></label>
                                <input type="text" id="username" name="username" class="form-control @error('username') is-invalid @enderror"
                                       required value="{{ old('username', $item->username) }}">
                                @error('username') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text small">Lowercased automatically on save.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="telegram_user_id">Telegram user ID</label>
                                <input type="number" id="telegram_user_id" name="telegram_user_id" class="form-control @error('telegram_user_id') is-invalid @enderror"
                                       value="{{ old('telegram_user_id', $item->telegram_user_id) }}">
                                @error('telegram_user_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="password">New password</label>
                                <div class="input-group">
                                    <input type="text" id="password" name="password" class="form-control @error('password') is-invalid @enderror"
                                           placeholder="Leave blank to keep current password"
                                           value="{{ old('password') }}"
                                           autocomplete="new-password">
                                    <button type="button" class="btn btn-outline-secondary" id="genPasswordBtn" title="Generate random">
                                        <i class="fas fa-dice"></i>
                                    </button>
                                </div>
                                @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text small">Setting a new password bumps <code>credential_version</code> and invalidates all active sessions.</div>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="hidden" name="is_active" value="0">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                                           {{ old('is_active', $item->is_active ? 1 : 0) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_active">Active</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-save me-1"></i> Save changes
                            </button>
                            <a href="{{ route('admin.users.show', $item) }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h3 class="h6"><i class="fas fa-shield-halved me-1 text-warning"></i> Snapshot</h3>
                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted">Status</dt>
                        <dd class="col-7">
                            @if ($item->is_active)
                                <span class="badge bg-success-subtle text-success">Active</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            @endif
                        </dd>
                        <dt class="col-5 text-muted">Employee</dt>
                        <dd class="col-7">{{ optional($item->employee)->name ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Branch</dt>
                        <dd class="col-7">{{ optional($item->employee)->branch?->branch_name ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Role</dt>
                        <dd class="col-7">{{ optional($item->employee)->role ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Cred version</dt>
                        <dd class="col-7">{{ (int) $item->credential_version }}</dd>
                        <dt class="col-5 text-muted">Last login</dt>
                        <dd class="col-7">{{ $item->last_login?->format('Y-m-d H:i') ?? 'never' }}</dd>
                        <dt class="col-5 text-muted">Created</dt>
                        <dd class="col-7">{{ optional($item->created_at)->format('Y-m-d') }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function() {
    $('#genPasswordBtn').on('click', function() {
        var chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        var pwd = '';
        for (var i = 0; i < 16; i++) {
            pwd += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        $('#password').val(pwd);
    });
});
</script>
@endpush
@endsection
