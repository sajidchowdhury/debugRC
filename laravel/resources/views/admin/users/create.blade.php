@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#1e3a8a,#3b82f6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-plus me-2"></i>New user account</h1>
            <p class="mb-0 small opacity-75">Assign a login account to an employee. Username is lowercased automatically.</p>
        </div>
        <div>
            <a href="{{ route('admin.users.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-user-plus me-1 text-primary"></i> Account details</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.users.store') }}">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label" for="employee_id">Employee <span class="text-danger">*</span></label>
                                <select id="employee_id" name="employee_id" class="form-select select2 @error('employee_id') is-invalid @enderror" required>
                                    <option value="">— Select employee without account —</option>
                                    @foreach ($employees as $employee)
                                        <option value="{{ $employee->id }}"
                                            {{ (int) old('employee_id') === (int) $employee->id ? 'selected' : '' }}>
                                            {{ $employee->name }} ({{ $employee->employee_code ?? '—' }})
                                            @if ($employee->branch)
                                                · {{ $employee->branch->branch_name }}
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                                @error('employee_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text small">Only employees without an existing login account are listed (users.employee_id is UNIQUE).</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="username">Username <span class="text-danger">*</span></label>
                                <input type="text" id="username" name="username" class="form-control @error('username') is-invalid @enderror"
                                       required placeholder="e.g. johndoe"
                                       value="{{ old('username') }}">
                                @error('username') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text small">Lowercased automatically — case-insensitive unique.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="password">Initial password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" id="password" name="password" class="form-control @error('password') is-invalid @enderror"
                                           required placeholder="Min 6 characters"
                                           value="{{ old('password') }}"
                                           autocomplete="new-password">
                                    <button type="button" class="btn btn-outline-secondary" id="genPasswordBtn" title="Generate random">
                                        <i class="fas fa-dice"></i>
                                    </button>
                                </div>
                                @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="hidden" name="is_active" value="0">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" {{ old('is_active', 1) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_active">Active</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-check me-1"></i> Create account
                            </button>
                            <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h3 class="h6"><i class="fas fa-lightbulb me-1 text-warning"></i> Tips</h3>
                    <ul class="small text-muted mb-0 ps-3">
                        <li>Usernames are lowercased + trimmed on save.</li>
                        <li>Each employee can only have ONE login account (UNIQUE).</li>
                        <li>The initial password is hashed with bcrypt before storage.</li>
                        <li>New accounts are <strong>active</strong> by default.</li>
                        <li>Use the dice button to generate a random password.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function() {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

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
