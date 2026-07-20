@extends('layouts.app')

@section('content')
<div class="container d-flex align-items-center justify-content-center min-vh-100 py-5">
    <div class="erp-login-card card shadow-sm w-100">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <i class="fas fa-cubes fa-2x text-primary mb-2"></i>
                <h4 class="mb-1">Remote Center ERP</h4>
                <p class="text-muted small">Sign in to your account</p>
            </div>

            {{-- Flash messages --}}
            @if (session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if (session('warning'))
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    {{ session('warning') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if (session('error'))
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            {{-- Validation errors --}}
            @if ($errors->any())
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}">
                @csrf
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" class="form-control @error('username') is-invalid @enderror"
                               id="username" name="username"
                               value="{{ old('username') }}"
                               placeholder="Enter your username"
                               required autofocus autocomplete="username">
                        @error('username')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control @error('password') is-invalid @enderror"
                               id="password" name="password"
                               placeholder="Enter your password"
                               required autocomplete="current-password">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </button>
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me" value="1">
                    <label class="form-check-label" for="remember_me">Remember me on this device</label>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sign-in-alt me-2"></i>Sign In
                    </button>
                </div>
            </form>

            <div class="text-center mt-3">
                <a href="{{ route('password.request') }}" class="text-decoration-none small">
                    Forgot your password?
                </a>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    // Toggle password visibility
    document.getElementById('togglePassword')?.addEventListener('click', function() {
        const pwd = document.getElementById('password');
        const icon = this.querySelector('i');
        if (pwd.type === 'password') {
            pwd.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            pwd.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });
</script>
@endsection
