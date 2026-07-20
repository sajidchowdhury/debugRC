@php
    /**
     * Unlock button partial — shown on the user show page.
     * Only renders the unlock form when the user is currently locked.
     *
     * Phase 14.
     *
     * @var \App\Models\User $item
     */
@endphp

@if ($item->isLocked())
    <form method="POST" action="{{ route('admin.users.unlock', $item) }}"
          onsubmit="return confirm('Unlock this user account? Failed login counters will be reset.');">
        @csrf
        <button type="submit" class="btn btn-warning w-100">
            <i class="fas fa-lock-open me-1"></i> Unlock account
        </button>
    </form>
@else
    <button type="button" class="btn btn-outline-secondary w-100" disabled title="Account is not locked">
        <i class="fas fa-lock-open me-1"></i> Unlock (not locked)
    </button>
@endif
