@php
    /**
     * Reset password partial — shown on the user show page.
     * Generates a new random password, hashes it, and bumps
     * credential_version. The plain password is flashed back
     * to the admin via session('new_password').
     *
     * Phase 14.
     *
     * @var \App\Models\User $item
     */
@endphp

<form method="POST" action="{{ route('admin.users.resetPassword', $item) }}"
      onsubmit="return confirm('Reset this user\\'s password? A new random password will be generated and shown once. All active sessions will be invalidated.');">
    @csrf
    <button type="submit" class="btn btn-outline-warning w-100">
        <i class="fas fa-key me-1"></i> Reset password
    </button>
</form>
