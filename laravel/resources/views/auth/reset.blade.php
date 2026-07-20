@extends('layouts.app')

@section('content')
<div class="container d-flex align-items-center justify-content-center min-vh-100 py-5">
    <div class="erp-login-card card shadow-sm w-100">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <i class="fas fa-shield-alt fa-2x text-success mb-2"></i>
                <h4 class="mb-1">Reset Password</h4>
                <p class="text-muted small">Choose a new password for your account</p>
            </div>

            @if (session('error'))
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div class="mb-3">
                    <label for="password" class="form-label">New Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="Enter new password"
                               required autocomplete="new-password" minlength="8">
                    </div>
                    <div class="form-text">
                        Min 8 chars, at least 1 letter, 1 number, 1 special character.
                    </div>
                </div>

                <div class="mb-3">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                               placeholder="Re-enter new password"
                               required autocomplete="new-password">
                    </div>
                </div>

                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-2"></i>Reset Password
                    </button>
                </div>

                <div class="text-center">
                    <a href="{{ route('login') }}" class="text-decoration-none small">
                        <i class="fas fa-arrow-left me-1"></i>Back to login
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
