@php
/**
 * Branch Demand Status Badge Component.
 *
 * Renders a consistent status badge across all Branch Demand views.
 * Replaces the duplicated $statusBadge closure in index, show, pending, and pending-receipt views.
 *
 * @param string $status     The demand status (pending, received, rejected, reversed)
 * @param string|null $receivedAt  Receipt confirmation timestamp (for received status differentiation)
 */
@endphp

@if($status === 'received' && !$receivedAt)
    <span class="badge bg-warning-subtle text-warning"><i class="fas fa-clock me-1"></i>Awaiting Confirmation</span>
@elseif($status === 'received' && $receivedAt)
    <span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i>Confirmed</span>
@elseif($status === 'pending')
    <span class="badge bg-info-subtle text-info"><i class="fas fa-hourglass-half me-1"></i>Pending</span>
@elseif($status === 'rejected')
    <span class="badge bg-danger-subtle text-danger"><i class="fas fa-ban me-1"></i>Rejected</span>
@elseif($status === 'reversed')
    <span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-rotate-left me-1"></i>Reversed</span>
@else
    <span class="badge bg-light text-dark">{{ $status }}</span>
@endif
