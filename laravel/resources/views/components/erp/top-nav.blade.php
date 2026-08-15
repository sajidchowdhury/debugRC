{{--
  x-erp.top-nav — Minimal clean top navigation bar

  Simple & clean design:
    - White background with subtle bottom border
    - Left: hamburger (mobile only)
    - Right: role badge | branch | notification bell | user dropdown
    - Brand name lives in sidebar only — removed from top bar

  Usage:
    <x-erp.top-nav />
    <x-erp.top-nav :tabs="$tabs" />
--}}
@props([
    'tabs' => null,
])

@php
    $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
    $currentBranchId = session('branch_id');
    $currentBranchCode = session('branch_code');

    $role = auth()->user()?->getRole() ?? 'user';
    $roleMap = [
        'admin'            => ['label' => 'Admin',            'color' => 'rose'],
        'superadmin'       => ['label' => 'Super Admin',      'color' => 'pink'],
        'manager'          => ['label' => 'Manager',          'color' => 'cyan'],
        'sales_manager'    => ['label' => 'Sales Manager',    'color' => 'amber'],
        'warehouse_manager'=> ['label' => 'Warehouse Manager', 'color' => 'orange'],
        'dispatcher'       => ['label' => 'Dispatcher',       'color' => 'violet'],
        'accountant'       => ['label' => 'Accountant',       'color' => 'emerald'],
    ];
    $roleCfg = $roleMap[$role] ?? ['label' => ucfirst($role), 'color' => 'slate'];

    $canSwitchBranch = in_array($role, ['admin', 'superadmin', 'manager']);

    $userName = auth()->user()?->username ?? 'U';
    $employeeName = auth()->user()?->employee?->name ?? '';
    $initials = $employeeName
        ? strtoupper(mb_substr($employeeName, 0, 1) . (mb_substr($employeeName, 1, 1) ?: ''))
        : strtoupper(mb_substr($userName, 0, 1));
@endphp

<style>
    /* ── Notification badge pulse ── */
    @keyframes notifPulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.5); }
        50%      { box-shadow: 0 0 0 5px rgba(239,68,68,0); }
    }
    .notif-pulse { animation: notifPulse 2s ease-in-out infinite; }

    /* ── Top nav bar: clean white ── */
    .rc-topnav {
        background: #ffffff;
        border-bottom: 1px solid #e2e8f0;
    }

    /* ── Hamburger: mobile-only, clean circle button ── */
    .rc-hamburger {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        border: none;
        background: transparent;
        color: #475569;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .rc-hamburger:hover {
        background: #f1f5f9;
        color: #1e293b;
    }
    .rc-hamburger:active {
        background: #e2e8f0;
        transform: scale(0.92);
    }

    /* ── Notification bell: refined circle with border ── */
    .rc-notif-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        border-radius: 50%;
        border: 1.5px solid #e2e8f0;
        background: #ffffff;
        color: #64748b;
        cursor: pointer;
        transition: all 0.2s ease;
        position: relative;
    }
    .rc-notif-btn:hover {
        border-color: #c7d2fe;
        background: #f5f3ff;
        color: #6366f1;
    }
    .rc-notif-btn:active {
        background: #ede9fe;
        transform: scale(0.92);
    }

    /* ── User avatar button: clean pill shape ── */
    .rc-user-btn {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 4px 12px 4px 4px;
        border-radius: 100px;
        border: 1.5px solid #e2e8f0;
        background: #ffffff;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .rc-user-btn:hover {
        border-color: #c7d2fe;
        background: #f5f3ff;
    }
    .rc-user-btn:active {
        background: #ede9fe;
    }
    .rc-user-avatar {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: #6366f1;
        color: #ffffff;
        font-size: 0.7rem;
        font-weight: 700;
        flex-shrink: 0;
    }

    /* ── Dropdown theme: light, crisp ── */
    .rc-topnav .dropdown-menu {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.08), 0 2px 6px rgba(0,0,0,0.04);
        color: #334155;
        padding: 6px;
    }
    .rc-topnav .dropdown-menu .dropdown-item {
        color: #475569;
        border-radius: 8px;
        padding: 8px 12px;
        font-size: 0.875rem;
        transition: all 0.15s ease;
    }
    .rc-topnav .dropdown-menu .dropdown-item:hover,
    .rc-topnav .dropdown-menu .dropdown-item:focus {
        background: #f1f5f9;
        color: #1e293b;
    }
    .rc-topnav .dropdown-menu .dropdown-item-text { color: #334155; }
    .rc-topnav .dropdown-menu .dropdown-divider { border-color: #e2e8f0; margin: 4px 8px; }
    .rc-topnav .dropdown-menu .dropdown-header { color: #64748b; }
    .rc-topnav .dropdown-menu .text-muted { color: #94a3b8 !important; }
    .rc-topnav .dropdown-menu small { color: #94a3b8; }

    .rc-topnav select option { background: #ffffff; color: #1e293b; }

    /* ── Role badge colors (light mode) ── */
    .rc-role-rose    { background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; }
    .rc-role-pink    { background: #fdf2f8; color: #db2777; border: 1px solid #fbcfe8; }
    .rc-role-cyan    { background: #ecfeff; color: #0891b2; border: 1px solid #cffafe; }
    .rc-role-amber   { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
    .rc-role-orange  { background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; }
    .rc-role-violet  { background: #f5f3ff; color: #7c3aed; border: 1px solid #ddd6fe; }
    .rc-role-emerald { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
    .rc-role-slate   { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }

    /* ── Mobile only ── */
    @media (min-width: 992px) {
        .rc-mobile-only { display: none !important; }
    }
    @media (max-width: 991.98px) {
        .rc-mobile-only { display: flex !important; }
    }
</style>

{{-- ==================== STICKY TOP NAV ==================== --}}
<div class="rc-topnav sticky top-0 z-50 no-print">
    <div class="px-4 sm:px-6 h-[56px] flex items-center justify-between">

        {{-- LEFT: hamburger (mobile ONLY) --}}
        <div class="flex items-center">
            <button type="button"
                    class="rc-hamburger rc-mobile-only"
                    onclick="toggleSidebar()"
                    aria-label="Toggle menu">
                <i class="fas fa-bars" style="font-size:1.05rem;"></i>
            </button>
        </div>

        {{-- RIGHT: role → branch → notification → user --}}
        <div class="flex items-center gap-2.5 sm:gap-3 ml-auto">

            {{-- Role badge --}}
            <span class="rc-role-{{ $roleCfg['color'] }} inline-flex items-center gap-1.5 font-semibold text-xs rounded-full px-2.5 py-1 transition-colors select-none">
                <span class="w-1.5 h-1.5 rounded-full bg-current opacity-60"></span>
                {{ $roleCfg['label'] }}
            </span>

            {{-- Branch selector --}}
            @if ($canSwitchBranch && $branches->isNotEmpty())
                <form method="POST" action="{{ route('branch.switch') }}"
                      class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 bg-slate-50 border border-slate-200 hover:border-slate-300 transition-colors">
                    @csrf
                    <i class="fas fa-location-dot text-[0.65rem] text-slate-400"></i>
                    <select name="branch_id" onchange="this.form.submit()"
                            class="bg-transparent border-0 text-xs font-medium text-slate-600 outline-none cursor-pointer focus:ring-0 hover:text-slate-800 transition-colors max-w-[80px] sm:max-w-[180px] truncate"
                            aria-label="Switch branch">
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" {{ (string) $currentBranchId === (string) $branch->id ? 'selected' : '' }}>
                                {{ $branch->branch_name }} ({{ $branch->branch_code }})
                            </option>
                        @endforeach
                    </select>
                </form>
            @elseif ($branches->isNotEmpty())
                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 bg-slate-50 border border-slate-200 text-xs font-medium text-slate-600">
                    <i class="fas fa-location-dot text-[0.65rem] text-slate-400"></i>
                    <span class="max-w-[80px] sm:max-w-[180px] truncate">{{ session('branch_name', 'No Branch') }}</span>
                </span>
            @endif

            {{-- Notification bell — refined circle button --}}
            <div class="dropdown">
                <button type="button"
                        class="rc-notif-btn"
                        title="Notifications"
                        id="notifDropdownBtn"
                        data-bs-toggle="dropdown"
                        aria-expanded="false">
                    <i class="fas fa-bell" style="font-size:0.9rem;"></i>
                    <span id="notifBadge"
                          class="absolute -top-1 -right-1 flex items-center justify-center w-[18px] h-[18px] rounded-full bg-red-500 text-white font-bold leading-none notif-pulse"
                          style="display:none; font-size:0.55rem;">0</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end"
                    aria-labelledby="notifDropdownBtn"
                    style="min-width: 320px; max-height: 400px; overflow-y: auto;">
                    <li class="dropdown-header d-flex justify-content-between align-items-center">
                        <strong class="text-slate-700"><i class="fas fa-bell me-1.5 text-indigo-500"></i>Notifications</strong>
                        <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-indigo-500 hover:text-indigo-700"
                                id="notifMarkAllRead" title="Mark all as read">
                            <i class="fas fa-check-double me-1"></i><small>Mark all read</small>
                        </button>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li id="notifList">
                        <span class="dropdown-item-text text-muted small">Loading…</span>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item" href="{{ route('admin.notifications.inbox') }}">
                            <i class="fas fa-inbox me-2 text-indigo-400"></i>View all notifications
                        </a>
                    </li>
                    @can('view-notification-rules')
                    <li>
                        <a class="dropdown-item" href="{{ route('admin.notifications.rules') }}">
                            <i class="fas fa-sliders me-2 text-indigo-400"></i>Notification settings
                        </a>
                    </li>
                    @endcan
                </ul>
            </div>

            {{-- User avatar + dropdown — clean pill button --}}
            <div class="dropdown">
                <button type="button"
                        class="rc-user-btn"
                        data-bs-toggle="dropdown"
                        aria-expanded="false">
                    <span class="rc-user-avatar">{{ $initials }}</span>
                    <span class="hidden sm:inline text-sm font-medium text-slate-700 max-w-[90px] truncate">{{ $userName }}</span>
                    <i class="fas fa-chevron-down text-[0.5rem] text-slate-400 hidden sm:inline"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" style="min-width: 220px;">
                    <li class="dropdown-item-text px-3 py-2.5">
                        <div class="flex items-center gap-3">
                            <span class="rc-user-avatar" style="width:38px;height:38px;font-size:0.8rem;">{{ $initials }}</span>
                            <div class="min-w-0">
                                <div class="font-semibold text-sm text-slate-800 truncate">{{ $employeeName ?: $userName }}</div>
                                <div class="text-xs text-slate-500 mt-0.5">{{ $roleCfg['label'] }}</div>
                            </div>
                        </div>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item" href="{{ route('dashboard') }}">
                            <i class="fas fa-gauge-high me-2 text-indigo-400"></i> Dashboard
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item !text-red-500 hover:!bg-red-50 hover:!text-red-600">
                                <i class="fas fa-right-from-bracket me-2"></i> Logout
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Optional tab strip --}}
    @isset($tabs)
        <div class="flex flex-wrap gap-1.5 overflow-x-auto px-4 sm:px-6 pb-2.5 -mt-1">
            @foreach ($tabs as $tab)
                @php
                    $isActive = !empty($tab['active']);
                    $tabClass = $isActive
                        ? 'bg-indigo-50 text-indigo-600 border-indigo-200'
                        : 'bg-slate-50 text-slate-500 border-slate-200 hover:text-slate-700 hover:border-slate-300 hover:bg-slate-100';
                @endphp
                <a href="{{ $tab['href'] }}" class="{{ $tabClass }} rounded-full px-3 py-1 text-xs font-medium border whitespace-nowrap transition-all">
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </div>
    @endisset
</div>

{{-- ==================== NOTIFICATION TOAST + AUDIO ==================== --}}
<div id="notificationContainer" aria-live="polite" aria-atomic="true"
     style="position:fixed; top:70px; right:20px; z-index:1080; max-width:360px;"></div>
<audio id="notificationSound" preload="none" style="display:none;"></audio>

@push('scripts')
    <script src="/assets/js/notification.js?v={{ filemtime(public_path('assets/js/notification.js')) }}"></script>
    <script>
        (function() {
            var NOTIF_RECENT_URL = '{{ route("admin.notifications.recent") }}';
            var NOTIF_MARK_ALL_URL = '{{ route("admin.notifications.markAllRead") }}';
            var CSRF = '{{ csrf_token() }}';

            function renderRecent(data) {
                var $list = $('#notifList');
                if (!data || !data.notifications || data.notifications.length === 0) {
                    $list.html('<span class="dropdown-item-text text-muted small">No notifications.</span>');
                    return;
                }
                var html = '';
                data.notifications.forEach(function(n) {
                    var unread = !n.read_at;
                    var bg = unread ? 'bg-indigo-50' : '';
                    var iconClass = n.icon || 'fa-bell';
                    var colorClass = n.color === 'danger' ? 'text-red-500'
                        : n.color === 'success' ? 'text-emerald-500'
                        : n.color === 'warning' ? 'text-amber-500'
                        : n.color === 'info' ? 'text-cyan-500'
                        : 'text-indigo-500';
                    var body = $('<div>').text(n.body || '').html();
                    var title = $('<div>').text(n.title || '').html();
                    var time = n.created_at || '';
                    html += '<li class="dropdown-item-text py-2 border-b border-slate-100 ' + bg + '">'
                        + '<div class="d-flex align-items-start gap-2">'
                        + '<i class="fas ' + iconClass + ' ' + colorClass + ' mt-0.5 text-xs"></i>'
                        + '<div class="flex-grow-1 min-w-0">'
                        + '<div class="fw-semibold small text-slate-700">' + title + '</div>'
                        + '<div class="text-xs text-slate-500 mt-0.5">' + body + '</div>'
                        + '<div class="text-[0.65rem] text-slate-400 mt-0.5">' + time + '</div>'
                        + '</div></div></li>';
                });
                $list.html(html);
            }

            $('#notifDropdownBtn').on('shown.bs.dropdown', function() {
                $.ajax({
                    url: NOTIF_RECENT_URL,
                    method: 'GET',
                    headers: { 'X-Requested-with': 'XMLHttpRequest' },
                }).done(function(data) {
                    renderRecent(data);
                    if (typeof window.updateNotificationBadge === 'function' && typeof data.unread_count !== 'undefined') {
                        window.updateNotificationBadge(data.unread_count);
                    }
                }).fail(function() {
                    $('#notifList').html('<span class="dropdown-item-text text-muted small">Failed to load.</span>');
                });
            });

            $('#notifMarkAllRead').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $.ajax({
                    url: NOTIF_MARK_ALL_URL,
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF },
                }).done(function() {
                    $('#notifList .bg-indigo-50').removeClass('bg-indigo-50');
                    if (typeof window.updateNotificationBadge === 'function') {
                        window.updateNotificationBadge(0);
                    }
                });
            });
        })();
    </script>
@endpush
