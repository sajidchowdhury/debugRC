@extends('layouts.admin')

@section('content')
@php
    // Event → Bootstrap color mapping (matches rules view)
    $eventColors = [
        'sales_finalize'  => 'success',
        'challan_create'  => 'info',
        'godown_create'   => 'primary',
        'payment_receive' => 'success',
        'soft_delete'     => 'warning',
        'accounts_entry'  => 'primary',
        'user_login'      => 'secondary',
    ];

    // Map data.color (Bootstrap color name) → hex value for inline icon background.
    $colorHex = [
        'primary'   => '#6366f1',
        'secondary' => '#6c757d',
        'success'   => '#10b981',
        'danger'    => '#ef4444',
        'warning'   => '#f59e0b',
        'info'      => '#0ea5e9',
        'dark'      => '#1f2937',
        'light'     => '#e5e7eb',
    ];
    $validColors = array_keys($colorHex);

    // Friendly label map for event badges
    $eventLabels = [
        'sales_finalize'  => 'Sale',
        'challan_create'  => 'Challan',
        'godown_create'   => 'Godown',
        'payment_receive' => 'Payment',
        'soft_delete'     => 'Delete',
        'accounts_entry'  => 'Accounting',
        'user_login'      => 'Login',
    ];
@endphp

<div class="container-fluid py-2">

    {{-- =================== HERO HEADER =================== --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#6366f1,#8b5cf6);">
        <div class="d-flex align-items-center gap-3">
            <div class="rounded-3 d-flex align-items-center justify-content-center bg-white bg-opacity-25"
                 style="width:52px;height:52px;">
                <i class="fas fa-inbox fa-lg"></i>
            </div>
            <div>
                <h1 class="h4 mb-1">
                    {{ $title }}
                    @if ($unreadCount > 0)
                        <span class="badge bg-light text-danger ms-2" style="font-size:0.85rem;">
                            <i class="fas fa-circle me-1" style="font-size:0.55rem;vertical-align:middle;"></i>{{ $unreadCount }} unread
                        </span>
                    @endif
                </h1>
                <p class="mb-0 small opacity-75">
                    Your personal notification feed — sales events, payments, deletions and more, all in one place.
                </p>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if ($unreadCount > 0)
                <form method="POST" action="{{ route('admin.notifications.markAllRead') }}">
                    @csrf
                    <button type="submit" class="btn btn-light btn-sm">
                        <i class="fas fa-check-double me-1"></i> Mark All Read
                    </button>
                </form>
            @endif
            <a href="{{ route('admin.notifications.rules') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-list-check me-1"></i> Manage Rules
            </a>
        </div>
    </header>

    {{-- =================== FILTER TABS =================== --}}
    <ul class="nav nav-pills mb-3 gap-1" role="tablist">
        <li class="nav-item">
            <a class="nav-link @if ($filter === 'all') active @endif"
               href="{{ route('admin.notifications.inbox', ['filter' => 'all']) }}">
                <i class="fas fa-layer-group me-1"></i> All
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link @if ($filter === 'unread') active @endif"
               href="{{ route('admin.notifications.inbox', ['filter' => 'unread']) }}">
                <i class="fas fa-circle-dot me-1"></i> Unread
                @if ($unreadCount > 0)
                    <span class="badge bg-danger ms-1">{{ $unreadCount }}</span>
                @endif
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link @if ($filter === 'read') active @endif"
               href="{{ route('admin.notifications.inbox', ['filter' => 'read']) }}">
                <i class="fas fa-envelope-open me-1"></i> Read
            </a>
        </li>
    </ul>

    {{-- =================== NOTIFICATIONS LIST =================== --}}
    @forelse ($notifications as $n)
        @php
            $data       = is_array($n->data) ? $n->data : [];
            $title      = $data['title']       ?? 'Notification';
            $body       = $data['body']        ?? '';
            $event      = $data['event']       ?? null;
            $icon       = $data['icon']        ?? 'fa-bell';
            $color      = in_array($data['color'] ?? '', $validColors) ? $data['color'] : 'primary';
            $iconBgHex  = $colorHex[$color] ?? '#6366f1';
            $refType    = $data['reference_type'] ?? null;
            $refId      = $data['reference_id']   ?? null;
            $isUnread   = is_null($n->read_at);
            $evtColor   = $eventColors[$event] ?? 'secondary';
            $evtLabel   = $eventLabels[$event] ?? ucfirst((string) $event);
            $relTime    = $n->created_at?->diffForHumans();
            $exactTime  = $n->created_at?->format('M d, Y H:i');
        @endphp

        <div class="card border-0 shadow-sm mb-2 notification-card {{ $isUnread ? 'notification-unread' : '' }}"
             style="@if ($isUnread) border-left: 4px solid #6366f1 !important; @endif">
            <div class="card-body p-3">
                <div class="d-flex align-items-start gap-3">

                    {{-- Icon --}}
                    <div class="rounded-3 d-flex align-items-center justify-content-center text-white flex-shrink-0"
                         style="width:46px;height:46px;background:{{ $iconBgHex }};">
                        <i class="fas {{ $icon }} fa-lg"></i>
                    </div>

                    {{-- Body --}}
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="fw-semibold text-dark">{{ $title }}</span>
                            @if ($event)
                                <span class="badge bg-{{ $evtColor }}-subtle text-{{ $evtColor }}">{{ $evtLabel }}</span>
                            @endif
                            @if ($isUnread)
                                <span class="badge bg-success-subtle text-success" title="Unread">
                                    <i class="fas fa-circle" style="font-size:0.5rem;vertical-align:middle;"></i> New
                                </span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary" title="Read">
                                    <i class="far fa-circle" style="font-size:0.55rem;vertical-align:middle;"></i> Read
                                </span>
                            @endif
                        </div>

                        @if ($body)
                            <div class="text-muted small mb-2">{{ $body }}</div>
                        @endif

                        <div class="d-flex flex-wrap align-items-center gap-3 small text-muted">
                            <span title="{{ $exactTime }}">
                                <i class="far fa-clock me-1"></i>{{ $relTime }}
                            </span>
                            @if ($refType && $refId)
                                <span>
                                    <i class="fas fa-link me-1"></i>
                                    Reference: <span class="text-monospace">{{ $refType }} #{{ $refId }}</span>
                                </span>
                            @endif
                            <span class="text-muted" style="font-size:0.7rem;">
                                <i class="fas fa-fingerprint me-1"></i>{{ \Illuminate\Support\Str::limit($n->id, 8, '') }}
                            </span>
                        </div>
                    </div>

                    {{-- Actions --}}
                    <div class="flex-shrink-0 d-flex flex-column gap-1 align-items-end">
                        @if ($isUnread)
                            <form method="POST" action="{{ route('admin.notifications.markRead', $n->id) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-success" title="Mark as read">
                                    <i class="fas fa-check me-1"></i> Mark as Read
                                </button>
                            </form>
                        @endif

                        @php
                            // Try to build a smart reference URL for common types
                            $refUrl = null;
                            if ($refType && $refId) {
                                $map = [
                                    'sales-invoice'  => 'admin.sales-invoices.show',
                                    'SalesInvoice'   => 'admin.sales-invoices.show',
                                    'sales-challan'  => 'admin.sales-challans.show',
                                    'SalesChallan'   => 'admin.sales-challans.show',
                                    'customer-payment' => 'admin.customer-payments.show',
                                    'CustomerPayment'  => 'admin.customer-payments.show',
                                ];
                                foreach ($map as $needle => $routeName) {
                                    if (stripos($refType, $needle) !== false) {
                                        try { $refUrl = route($routeName, $refId); } catch (\Throwable $e) {}
                                        break;
                                    }
                                }
                            }
                        @endphp
                        @if ($refUrl)
                            <a href="{{ $refUrl }}" class="btn btn-sm btn-outline-primary" target="_blank" title="Open reference">
                                <i class="fas fa-arrow-up-right-from-square"></i> Open
                            </a>
                        @endif
                    </div>

                </div>
            </div>
        </div>
    @empty
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <div class="text-muted mb-3">
                    <i class="fas fa-bell-slash fa-3x opacity-50"></i>
                </div>
                <h5 class="fw-semibold">No notifications</h5>
                <p class="text-muted mb-0">
                    @if ($filter === 'unread')
                        You're all caught up! No unread notifications.
                    @elseif ($filter === 'read')
                        No read notifications yet. Unread items will move here after you mark them as read.
                    @else
                        When events happen across RC&nbsp;ERP (sales, payments, deletions…),<br>
                        notifications configured by your rules will appear here.
                    @endif
                </p>
                <a href="{{ route('admin.notifications.rules') }}" class="btn btn-sm text-white mt-2"
                   style="background:linear-gradient(135deg,#6366f1,#8b5cf6);">
                    <i class="fas fa-bell-concierge me-1"></i> Configure Rules
                </a>
            </div>
        </div>
    @endforelse

    {{-- Pagination --}}
    @if ($notifications->hasPages())
        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
            <div class="text-muted small">
                Showing {{ $notifications->firstItem() ?? 0 }}–{{ $notifications->lastItem() ?? 0 }} of {{ $notifications->total() }} notifications
            </div>
            {{ $notifications->withQueryString()->links() }}
        </div>
    @endif

</div>

@push('css')
<style>
    .notification-unread {
        background: linear-gradient(90deg, rgba(99,102,241,0.04), transparent 40%);
    }
    .notification-card { transition: box-shadow .15s ease, transform .15s ease; }
    .notification-card:hover { box-shadow: 0 .4rem 1rem rgba(0,0,0,.08) !important; }
    .nav-pills .nav-link { color: #6366f1; }
    .nav-pills .nav-link.active {
        background: linear-gradient(135deg,#6366f1,#8b5cf6);
        color: #fff;
    }
</style>
@endpush

@push('scripts')
<script>
    // Optional: live-refresh unread badge periodically (every 60s) via the AJAX endpoint.
    (function () {
        var badge = document.querySelector('header .badge.bg-light.text-danger');
        if (!badge) return; // nothing to refresh
        setInterval(function () {
            fetch('{{ route('admin.notifications.unreadCount') }}', {
                headers: { 'Accept': 'application/json' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var count = parseInt(data.count || 0, 10);
                if (count > 0) {
                    badge.innerHTML = '<i class="fas fa-circle me-1" style="font-size:0.55rem;vertical-align:middle;"></i>' + count + ' unread';
                    badge.style.display = '';
                } else {
                    badge.style.display = 'none';
                }
            })
            .catch(function () { /* silent */ });
        }, 60000);
    })();
</script>
@endpush
@endsection
