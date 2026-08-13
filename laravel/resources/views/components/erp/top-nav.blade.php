{{--
  x-erp.top-nav — UNIFIED top navigation bar (shared by layouts.admin AND
  components/layouts/erp).

  Premium dark-glass design with:
    - Frosted glass bar with subtle gradient + amber accent line
    - RC ERP brand with animated shimmer effect
    - Dashboard quick-access button
    - Role badge with colored dot indicator
    - Branch switcher with smooth select
    - Notification bell with pulse animation on unread
    - User avatar with initials + dropdown
    - Fully responsive: collapses gracefully on mobile

  Usage:
    <x-erp.top-nav />                  (no tabs)
    <x-erp.top-nav :tabs="$tabs" />    (with tab strip)

  Dependencies:
    - jQuery 3.6, Bootstrap bundle, Font Awesome, rc-erp.css
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
        'admin'            => ['label' => 'Admin',          'label_bn' => 'অ্যাডমিন',      'dot' => 'bg-red-400',    'classes' => 'bg-slate-800/60 text-slate-200 border-slate-600/50'],
        'superadmin'       => ['label' => 'Super Admin',    'label_bn' => 'সুপার অ্যাডমিন','dot' => 'bg-rose-400',   'classes' => 'bg-slate-800/60 text-slate-200 border-slate-600/50'],
        'manager'          => ['label' => 'Manager',        'label_bn' => 'ম্যানেজার',     'dot' => 'bg-cyan-400',   'classes' => 'bg-slate-800/60 text-slate-200 border-slate-600/50'],
        'sales_manager'    => ['label' => 'SM',             'label_bn' => 'বিক্রেতা',       'dot' => 'bg-amber-400',  'classes' => 'bg-slate-800/60 text-slate-200 border-slate-600/50'],
        'warehouse_manager'=> ['label' => 'WM',             'label_bn' => 'গুদাম',         'dot' => 'bg-orange-400', 'classes' => 'bg-slate-800/60 text-slate-200 border-slate-600/50'],
        'dispatcher'       => ['label' => 'Dispatcher',     'label_bn' => 'ডিসপ্যাচার',    'dot' => 'bg-violet-400', 'classes' => 'bg-slate-800/60 text-slate-200 border-slate-600/50'],
        'accountant'       => ['label' => 'Accountant',     'label_bn' => 'হিসাবরক্ষক',    'dot' => 'bg-emerald-400','classes' => 'bg-slate-800/60 text-slate-200 border-slate-600/50'],
    ];
    $roleCfg = $roleMap[$role] ?? ['label' => ucfirst($role), 'label_bn' => '', 'dot' => 'bg-slate-400', 'classes' => 'bg-slate-800/60 text-slate-200 border-slate-600/50'];

    $canSwitchBranch = in_array($role, ['admin', 'superadmin', 'manager']);

    // User initials for avatar
    $userName = auth()->user()?->username ?? 'U';
    $employeeName = auth()->user()?->employee?->name ?? '';
    $initials = $employeeName
        ? strtoupper(mb_substr($employeeName, 0, 1) . (mb_substr($employeeName, 1, 1) ?: ''))
        : strtoupper(mb_substr($userName, 0, 1));
@endphp

<style>
    /* ── Brand shimmer animation ── */
    @keyframes rcShimmer {
        0%   { background-position: -200% center; }
        100% { background-position: 200% center; }
    }
    .rc-brand-shimmer {
        background: linear-gradient(
            90deg,
            #f59e0b 0%, #f97316 25%, #fbbf24 50%, #f97316 75%, #f59e0b 100%
        );
        background-size: 200% auto;
        animation: rcShimmer 3s linear infinite;
    }

    /* ── Notification pulse ── */
    @keyframes notifPulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.4); }
        50%      { box-shadow: 0 0 0 6px rgba(239,68,68,0); }
    }
    .notif-pulse {
        animation: notifPulse 2s ease-in-out infinite;
    }

    /* ── Top nav glass effect ── */
    .rc-topnav {
        background: linear-gradient(135deg, rgba(15,23,42,0.95) 0%, rgba(30,41,59,0.92) 100%);
        backdrop-filter: blur(16px) saturate(180%);
        -webkit-backdrop-filter: blur(16px) saturate(180%);
        border-bottom: 2px solid;
        border-image: linear-gradient(90deg, #f59e0b, #f97316, #f59e0b) 1;
    }

    /* ── Dropdown dark theme override ── */
    .rc-topnav .dropdown-menu {
        background: rgba(15,23,42,0.97);
        backdrop-filter: blur(12px);
        border: 1px solid rgba(71,85,105,0.4);
        color: #e2e8f0;
    }
    .rc-topnav .dropdown-menu .dropdown-item {
        color: #cbd5e1;
    }
    .rc-topnav .dropdown-menu .dropdown-item:hover,
    .rc-topnav .dropdown-menu .dropdown-item:focus {
        background: rgba(245,158,11,0.15);
        color: #fbbf24;
    }
    .rc-topnav .dropdown-menu .dropdown-item-text {
        color: #e2e8f0;
    }
    .rc-topnav .dropdown-menu .dropdown-divider {
        border-color: rgba(71,85,105,0.4);
    }
    .rc-topnav .dropdown-menu .dropdown-header {
        color: #94a3b8;
    }
    .rc-topnav .dropdown-menu .text-muted {
        color: #64748b !important;
    }
    .rc-topnav .dropdown-menu small {
        color: #64748b;
    }
</style>

{{-- ==================== STICKY TOP NAV ==================== --}}
<div class="rc-topnav sticky top-0 z-50 no-print">
    <div class="px-3 sm:px-5 py-2.5">

        {{-- Main row: hamburger + brand + dashboard + ... + actions --}}
        <div class="flex items-center justify-between gap-3">

            {{-- LEFT: hamburger + brand + dashboard --}}
            <div class="flex items-center gap-2.5 min-w-0">
                {{-- Mobile sidebar toggle --}}
                <button type="button"
                        class="flex items-center justify-center size-9 rounded-lg border border-slate-600/50 text-slate-300 hover:text-amber-400 hover:border-amber-500/50 hover:bg-slate-700/50 transition-all lg:hidden"
                        onclick="toggleSidebar()"
                        aria-label="Toggle menu">
                    <i class="fas fa-bars text-sm"></i>
                </button>

                {{-- RC ERP Brand with shimmer --}}
                <div class="rc-brand-shimmer rounded-xl px-3.5 py-1.5 text-white font-extrabold text-sm tracking-wide shadow-lg shadow-amber-500/20 select-none whitespace-nowrap">
                    RC ERP
                </div>

                {{-- Dashboard quick-access --}}
                <a href="{{ route('dashboard') }}"
                   class="group inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold text-slate-300 border border-slate-600/40 bg-slate-700/30 hover:bg-amber-500/20 hover:text-amber-300 hover:border-amber-500/50 transition-all whitespace-nowrap"
                   title="Performance Dashboard">
                    <i class="fas fa-gauge-high text-[0.7rem] text-amber-500 group-hover:text-amber-300 transition-colors"></i>
                    <span class="hidden sm:inline">Dashboard</span>
                </a>
            </div>

            {{-- RIGHT: role + branch + bell + user --}}
            <div class="flex items-center gap-2 sm:gap-2.5">

                {{-- Role badge with colored dot --}}
                <span class="hidden md:inline-flex items-center gap-1.5 font-medium text-[0.7rem] rounded-full px-2.5 py-1 border {{ $roleCfg['classes'] }}">
                    <span class="size-1.5 rounded-full {{ $roleCfg['dot'] }}"></span>
                    {{ $roleCfg['label'] }}
                </span>

                {{-- Branch switcher --}}
                @if ($canSwitchBranch && $branches->isNotEmpty())
                    <form method="POST" action="{{ route('branch.switch') }}"
                          class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 border border-slate-600/40 bg-slate-700/30">
                        @csrf
                        <i class="fas fa-location-dot text-[0.55rem] text-amber-500"></i>
                        <select name="branch_id" onchange="this.form.submit()"
                                class="bg-transparent border-0 text-[0.7rem] font-medium text-slate-300 outline-none cursor-pointer focus:ring-0 hover:text-amber-300 transition-colors"
                                aria-label="Switch branch">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" {{ (string) $currentBranchId === (string) $branch->id ? 'selected' : '' }}
                                        class="bg-slate-800 text-slate-200">
                                    {{ $branch->branch_name }} ({{ $branch->branch_code }})
                                </option>
                            @endforeach
                        </select>
                    </form>
                @elseif ($branches->isNotEmpty())
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 border border-slate-600/40 bg-slate-700/30 text-[0.7rem] font-medium text-slate-300">
                        <i class="fas fa-location-dot text-[0.55rem] text-amber-500"></i>
                        {{ session('branch_name', 'No Branch') }}
                    </span>
                @endif

                {{-- Notification bell with dropdown --}}
                <div class="dropdown">
                    <button type="button"
                            class="relative flex items-center justify-center size-9 rounded-xl border border-slate-600/40 bg-slate-700/30 text-slate-400 hover:text-amber-400 hover:border-amber-500/40 hover:bg-amber-500/10 transition-all"
                            title="Notifications"
                            id="notifDropdownBtn"
                            data-bs-toggle="dropdown"
                            aria-expanded="false">
                        <i class="fas fa-bell text-sm"></i>
                        <span id="notifBadge"
                              class="absolute -top-1 -right-1 flex items-center justify-center size-4 rounded-full bg-red-500 text-white font-bold leading-none notif-pulse"
                              style="display:none; font-size:0.55rem;">0</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-xl shadow-black/30"
                        aria-labelledby="notifDropdownBtn"
                        style="min-width: 340px; max-height: 420px; overflow-y: auto;">
                        <li class="dropdown-header d-flex justify-content-between align-items-center">
                            <strong class="text-slate-200"><i class="fas fa-bell me-1.5 text-amber-500"></i>Notifications</strong>
                            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-amber-400 hover:text-amber-300"
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
                                <i class="fas fa-inbox me-2 text-amber-500"></i>View all notifications
                            </a>
                        </li>
                        @can('view-notification-rules')
                        <li>
                            <a class="dropdown-item" href="{{ route('admin.notifications.rules') }}">
                                <i class="fas fa-sliders me-2 text-amber-500"></i>Notification settings
                            </a>
                        </li>
                        @endcan
                    </ul>
                </div>

                {{-- User avatar + dropdown --}}
                <div class="dropdown">
                    <button type="button"
                            class="flex items-center gap-2 rounded-xl pl-2 pr-3 py-1 border border-slate-600/40 bg-slate-700/30 hover:border-amber-500/40 hover:bg-amber-500/10 transition-all"
                            data-bs-toggle="dropdown"
                            aria-expanded="false">
                        {{-- Avatar circle with initials --}}
                        <span class="flex items-center justify-center size-7 rounded-full bg-gradient-to-br from-amber-500 to-orange-600 text-white text-[0.65rem] font-bold shadow-sm">
                            {{ $initials }}
                        </span>
                        <span class="hidden sm:inline text-xs font-medium text-slate-300 max-w-[90px] truncate">{{ $userName }}</span>
                        <i class="fas fa-chevron-down text-[0.5rem] text-slate-500 hidden sm:inline"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-xl shadow-black/30" style="min-width: 200px;">
                        <li class="dropdown-item-text px-3 py-2">
                            <div class="flex items-center gap-2.5">
                                <span class="flex items-center justify-center size-9 rounded-full bg-gradient-to-br from-amber-500 to-orange-600 text-white text-xs font-bold shadow">
                                    {{ $initials }}
                                </span>
                                <div class="min-w-0">
                                    <div class="font-semibold text-sm truncate">{{ $employeeName ?: $userName }}</div>
                                    <div class="text-xs text-slate-400">{{ $roleCfg['label'] }}@if ($roleCfg['label_bn']) · {{ $roleCfg['label_bn'] }}@endif</div>
                                </div>
                            </div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="{{ route('dashboard') }}">
                                <i class="fas fa-gauge-high me-2 text-amber-500"></i> Dashboard
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item !text-red-400 hover:!text-red-300">
                                    <i class="fas fa-right-from-bracket me-2"></i> Logout
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Row 2: optional tab strip --}}
        @isset($tabs)
            <div class="flex flex-wrap gap-1.5 overflow-x-auto pt-2">
                @foreach ($tabs as $tab)
                    @php
                        $isActive = !empty($tab['active']);
                        $tabClass = $isActive
                            ? 'bg-amber-500 text-white border-amber-500 shadow-sm shadow-amber-500/30'
                            : 'bg-slate-700/40 text-slate-400 border-slate-600/40 hover:text-amber-300 hover:border-amber-500/40 hover:bg-amber-500/10';
                    @endphp
                    <a href="{{ $tab['href'] }}" class="{{ $tabClass }} rounded-full px-3 py-1 text-[0.7rem] font-medium border whitespace-nowrap transition-all">
                        {{ $tab['label'] }}
                    </a>
                @endforeach
            </div>
        @endisset
    </div>
</div>

{{-- ==================== NOTIFICATION TOAST CONTAINER + AUDIO ==================== --}}
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
                    var bg = unread ? 'bg-amber-500/10' : '';
                    var iconClass = n.icon || 'fa-bell';
                    var colorClass = n.color === 'danger' ? 'text-red-400'
                        : n.color === 'success' ? 'text-emerald-400'
                        : n.color === 'warning' ? 'text-amber-400'
                        : n.color === 'info' ? 'text-cyan-400'
                        : 'text-amber-500';
                    var body = $('<div>').text(n.body || '').html();
                    var title = $('<div>').text(n.title || '').html();
                    var time = n.created_at || '';
                    html += '<li class="dropdown-item-text py-2 border-b border-slate-700/50 ' + bg + '">'
                        + '<div class="d-flex align-items-start gap-2">'
                        + '<i class="fas ' + iconClass + ' ' + colorClass + ' mt-0.5 text-xs"></i>'
                        + '<div class="flex-grow-1 min-w-0">'
                        + '<div class="fw-semibold small text-slate-200">' + title + '</div>'
                        + '<div class="text-xs text-slate-400 mt-0.5">' + body + '</div>'
                        + '<div class="text-[0.65rem] text-slate-500 mt-0.5">' + time + '</div>'
                        + '</div>'
                        + '</div>'
                        + '</li>';
                });
                $list.html(html);
            }

            $('#notifDropdownBtn').on('shown.bs.dropdown', function() {
                $.ajax({
                    url: NOTIF_RECENT_URL,
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
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
                    $('#notifList .bg-amber-500\\/10').removeClass('bg-amber-500/10');
                    if (typeof window.updateNotificationBadge === 'function') {
                        window.updateNotificationBadge(0);
                    }
                });
            });
        })();
    </script>
@endpush
