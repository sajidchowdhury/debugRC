@extends('layouts.app')

@section('content')
<div class="container d-flex align-items-center justify-content-center min-vh-100 py-5">
    <div class="erp-login-card card shadow-sm w-100">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <i class="fas fa-key fa-2x text-warning mb-2"></i>
                <h4 class="mb-1">Forgot Password</h4>
                <p class="text-muted small">Enter your username to receive reset instructions</p>
            </div>

            @if (session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('success') }}
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

            <form method="POST" action="{{ route('password.email') }}">
                @csrf
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" class="form-control" id="username" name="username"
                               value="{{ old('username') }}"
                               placeholder="Enter your username"
                               required autofocus>
                    </div>
                </div>

                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-paper-plane me-2"></i>Send Reset Link
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
