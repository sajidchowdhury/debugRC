{{--
  x-erp.top-nav — UNIFIED top navigation bar (shared by layouts.admin AND
  components/layouts/erp).

  Creative premium design with:
    - Vibrant gradient bar with colorful accent line
    - RC ERP brand with animated gradient shimmer
    - Dashboard quick-access button
    - Role badge with colored dot indicator
    - Branch switcher with compact mobile display
    - Notification bell with pulse animation on unread
    - User avatar with initials + dropdown
    - Fully responsive: clean mobile layout with hamburger toggle
    - Sidebar toggle properly wired

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
        'admin'            => ['label' => 'Admin',          'label_bn' => 'অ্যাডমিন',      'dot' => 'bg-rose-400',    'bg' => 'bg-rose-500/15', 'text' => 'text-rose-300', 'border' => 'border-rose-500/30'],
        'superadmin'       => ['label' => 'Super Admin',    'label_bn' => 'সুপার অ্যাডমিন','dot' => 'bg-pink-400',    'bg' => 'bg-pink-500/15', 'text' => 'text-pink-300', 'border' => 'border-pink-500/30'],
        'manager'          => ['label' => 'Manager',        'label_bn' => 'ম্যানেজার',     'dot' => 'bg-cyan-400',    'bg' => 'bg-cyan-500/15', 'text' => 'text-cyan-300', 'border' => 'border-cyan-500/30'],
        'sales_manager'    => ['label' => 'SM',             'label_bn' => 'বিক্রেতা',       'dot' => 'bg-amber-400',  'bg' => 'bg-amber-500/15', 'text' => 'text-amber-300', 'border' => 'border-amber-500/30'],
        'warehouse_manager'=> ['label' => 'WM',             'label_bn' => 'গুদাম',         'dot' => 'bg-orange-400', 'bg' => 'bg-orange-500/15', 'text' => 'text-orange-300', 'border' => 'border-orange-500/30'],
        'dispatcher'       => ['label' => 'Dispatcher',     'label_bn' => 'ডিসপ্যাচার',    'dot' => 'bg-violet-400', 'bg' => 'bg-violet-500/15', 'text' => 'text-violet-300', 'border' => 'border-violet-500/30'],
        'accountant'       => ['label' => 'Accountant',     'label_bn' => 'হিসাবরক্ষক',    'dot' => 'bg-emerald-400','bg' => 'bg-emerald-500/15', 'text' => 'text-emerald-300', 'border' => 'border-emerald-500/30'],
    ];
    $roleCfg = $roleMap[$role] ?? ['label' => ucfirst($role), 'label_bn' => '', 'dot' => 'bg-slate-400', 'bg' => 'bg-slate-500/15', 'text' => 'text-slate-300', 'border' => 'border-slate-500/30'];

    $canSwitchBranch = in_array($role, ['admin', 'superadmin', 'manager']);

    // User initials for avatar
    $userName = auth()->user()?->username ?? 'U';
    $employeeName = auth()->user()?->employee?->name ?? '';
    $initials = $employeeName
        ? strtoupper(mb_substr($employeeName, 0, 1) . (mb_substr($employeeName, 1, 1) ?: ''))
        : strtoupper(mb_substr($userName, 0, 1));
@endphp

<style>
    /* ── Brand gradient shimmer ── */
    @keyframes rcBrandFlow {
        0%   { background-position: 0% center; }
        100% { background-position: 200% center; }
    }
    .rc-brand-premium {
        background: linear-gradient(90deg, #f59e0b, #ef4444, #ec4899, #8b5cf6, #3b82f6, #10b981, #f59e0b);
        background-size: 200% auto;
        animation: rcBrandFlow 4s linear infinite;
    }

    /* ── Notification pulse ── */
    @keyframes notifPulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.5); }
        50%      { box-shadow: 0 0 0 6px rgba(239,68,68,0); }
    }
    .notif-pulse { animation: notifPulse 2s ease-in-out infinite; }

    /* ── Accent line animation ── */
    @keyframes accentSlide {
        0%   { background-position: 0% center; }
        100% { background-position: 300% center; }
    }
    .rc-accent-line {
        height: 3px;
        background: linear-gradient(90deg, #f59e0b, #ef4444, #ec4899, #8b5cf6, #3b82f6, #10b981, #f59e0b, #f59e0b, #ef4444);
        background-size: 300% auto;
        animation: accentSlide 6s linear infinite;
    }

    /* ── Top nav gradient bar ── */
    .rc-topnav {
        background: linear-gradient(135deg, #0c1445 0%, #1e1b4b 30%, #172554 60%, #0f172a 100%);
        backdrop-filter: blur(20px) saturate(200%);
        -webkit-backdrop-filter: blur(20px) saturate(200%);
    }

    /* ── Dropdown dark-purple theme ── */
    .rc-topnav .dropdown-menu {
        background: rgba(15,23,42,0.97);
        backdrop-filter: blur(12px);
        border: 1px solid rgba(139,92,246,0.25);
        color: #e2e8f0;
    }
    .rc-topnav .dropdown-menu .dropdown-item {
        color: #cbd5e1;
    }
    .rc-topnav .dropdown-menu .dropdown-item:hover,
    .rc-topnav .dropdown-menu .dropdown-item:focus {
        background: rgba(139,92,246,0.15);
        color: #c4b5fd;
    }
    .rc-topnav .dropdown-menu .dropdown-item-text {
        color: #e2e8f0;
    }
    .rc-topnav .dropdown-menu .dropdown-divider {
        border-color: rgba(139,92,246,0.2);
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

    /* ── Hamburger button pulse on mobile ── */
    @keyframes hamburgerGlow {
        0%, 100% { box-shadow: 0 0 0 0 rgba(139,92,246,0.3); }
        50%      { box-shadow: 0 0 8px 2px rgba(139,92,246,0.15); }
    }
    .rc-hamburger {
        animation: hamburgerGlow 3s ease-in-out infinite;
    }
</style>

{{-- ==================== STICKY TOP NAV ==================== --}}
<div class="rc-topnav sticky top-0 z-50 no-print">
    {{-- Colorful animated accent line at the very top --}}
    <div class="rc-accent-line"></div>

    <div class="px-2 sm:px-4 py-2">

        {{-- Main row: hamburger + brand + dashboard + ... + actions --}}
        <div class="flex items-center justify-between gap-2">

            {{-- LEFT: hamburger + brand + dashboard --}}
            <div class="flex items-center gap-2 min-w-0 flex-shrink-0">
                {{-- Mobile sidebar toggle — always visible on <lg, hidden on lg+ --}}
                <button type="button"
                        class="rc-hamburger flex items-center justify-center w-9 h-9 rounded-xl border border-violet-500/40 bg-violet-500/10 text-violet-300 hover:text-white hover:bg-violet-500/30 hover:border-violet-400/60 active:scale-95 transition-all lg:hidden"
                        onclick="toggleSidebar()"
                        aria-label="Toggle menu">
                    <i class="fas fa-bars text-base"></i>
                </button>

                {{-- Desktop sidebar collapse toggle --}}
                <button type="button"
                        class="hidden lg:flex items-center justify-center w-8 h-8 rounded-lg border border-violet-500/30 bg-violet-500/8 text-violet-400 hover:text-violet-200 hover:bg-violet-500/20 hover:border-violet-400/50 active:scale-95 transition-all"
                        onclick="toggleSidebar()"
                        aria-label="Toggle sidebar"
                        id="topNavSidebarBtn">
                    <i class="fas fa-outdent text-xs"></i>
                </button>

                {{-- RC ERP Brand with rainbow shimmer --}}
                <div class="rc-brand-premium rounded-lg px-3 py-1 text-white font-extrabold text-sm tracking-wider shadow-lg shadow-violet-500/20 select-none whitespace-nowrap">
                    RC ERP
                </div>

                {{-- Dashboard quick-access (hidden on small mobile) --}}
                <a href="{{ route('dashboard') }}"
                   class="group inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-[0.7rem] font-semibold text-indigo-300 border border-indigo-500/30 bg-indigo-500/10 hover:bg-indigo-500/25 hover:text-indigo-200 hover:border-indigo-400/50 transition-all whitespace-nowrap hidden sm:inline-flex"
                   title="Performance Dashboard">
                    <i class="fas fa-gauge-high text-[0.65rem] text-violet-400 group-hover:text-violet-300 transition-colors"></i>
                    Dashboard
                </a>
            </div>

            {{-- RIGHT: role + branch + bell + user --}}
            <div class="flex items-center gap-1.5 sm:gap-2 min-w-0">

                {{-- Role badge with colored dot (hidden on mobile) --}}
                <span class="hidden md:inline-flex items-center gap-1.5 font-medium text-[0.65rem] rounded-full px-2.5 py-1 border {{ $roleCfg['bg'] }} {{ $roleCfg['text'] }} {{ $roleCfg['border'] }}">
                    <span class="w-1.5 h-1.5 rounded-full {{ $roleCfg['dot'] }}"></span>
                    {{ $roleCfg['label'] }}
                </span>

                {{-- Branch switcher (compact on mobile) --}}
                @if ($canSwitchBranch && $branches->isNotEmpty())
                    <form method="POST" action="{{ route('branch.switch') }}"
                          class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 border border-emerald-500/30 bg-emerald-500/8">
                        @csrf
                        <i class="fas fa-location-dot text-[0.5rem] text-emerald-400 hidden sm:inline"></i>
                        <select name="branch_id" onchange="this.form.submit()"
                                class="bg-transparent border-0 text-[0.65rem] font-medium text-emerald-300 outline-none cursor-pointer focus:ring-0 hover:text-emerald-200 transition-colors max-w-[100px] sm:max-w-[180px] truncate"
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
                    <span class="hidden sm:inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 border border-emerald-500/30 bg-emerald-500/8 text-[0.65rem] font-medium text-emerald-300">
                        <i class="fas fa-location-dot text-[0.5rem] text-emerald-400"></i>
                        {{ session('branch_name', 'No Branch') }}
                    </span>
                @endif

                {{-- Notification bell with dropdown --}}
                <div class="dropdown">
                    <button type="button"
                            class="relative flex items-center justify-center w-8 h-8 rounded-lg border border-rose-500/30 bg-rose-500/8 text-rose-400 hover:text-rose-300 hover:border-rose-400/50 hover:bg-rose-500/15 active:scale-95 transition-all"
                            title="Notifications"
                            id="notifDropdownBtn"
                            data-bs-toggle="dropdown"
                            aria-expanded="false">
                        <i class="fas fa-bell text-sm"></i>
                        <span id="notifBadge"
                              class="absolute -top-1 -right-1 flex items-center justify-center w-4 h-4 rounded-full bg-red-500 text-white font-bold leading-none notif-pulse"
                              style="display:none; font-size:0.5rem;">0</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-xl shadow-black/40"
                        aria-labelledby="notifDropdownBtn"
                        style="min-width: 320px; max-height: 400px; overflow-y: auto;">
                        <li class="dropdown-header d-flex justify-content-between align-items-center">
                            <strong class="text-slate-200"><i class="fas fa-bell me-1.5 text-violet-400"></i>Notifications</strong>
                            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none text-violet-400 hover:text-violet-300"
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
                                <i class="fas fa-inbox me-2 text-violet-400"></i>View all notifications
                            </a>
                        </li>
                        @can('view-notification-rules')
                        <li>
                            <a class="dropdown-item" href="{{ route('admin.notifications.rules') }}">
                                <i class="fas fa-sliders me-2 text-violet-400"></i>Notification settings
                            </a>
                        </li>
                        @endcan
                    </ul>
                </div>

                {{-- User avatar + dropdown --}}
                <div class="dropdown">
                    <button type="button"
                            class="flex items-center gap-1.5 rounded-lg pl-1.5 pr-2 py-1 border border-violet-500/30 bg-violet-500/8 hover:border-violet-400/50 hover:bg-violet-500/15 active:scale-95 transition-all"
                            data-bs-toggle="dropdown"
                            aria-expanded="false">
                        {{-- Avatar circle with gradient initials --}}
                        <span class="flex items-center justify-center w-7 h-7 rounded-full bg-gradient-to-br from-violet-500 via-purple-500 to-fuchsia-500 text-white text-[0.6rem] font-bold shadow-sm shadow-violet-500/30">
                            {{ $initials }}
                        </span>
                        <span class="hidden sm:inline text-[0.7rem] font-medium text-slate-300 max-w-[80px] truncate">{{ $userName }}</span>
                        <i class="fas fa-chevron-down text-[0.45rem] text-slate-500 hidden sm:inline"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-xl shadow-black/40" style="min-width: 200px;">
                        <li class="dropdown-item-text px-3 py-2">
                            <div class="flex items-center gap-2.5">
                                <span class="flex items-center justify-center w-9 h-9 rounded-full bg-gradient-to-br from-violet-500 via-purple-500 to-fuchsia-500 text-white text-xs font-bold shadow shadow-violet-500/30">
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
                                <i class="fas fa-gauge-high me-2 text-violet-400"></i> Dashboard
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
            <div class="flex flex-wrap gap-1.5 overflow-x-auto pt-2 -mb-1">
                @foreach ($tabs as $tab)
                    @php
                        $isActive = !empty($tab['active']);
                        $tabClass = $isActive
                            ? 'bg-violet-500/30 text-violet-200 border-violet-400/60 shadow-sm shadow-violet-500/20'
                            : 'bg-white/5 text-slate-400 border-white/10 hover:text-violet-300 hover:border-violet-500/40 hover:bg-violet-500/10';
                    @endphp
                    <a href="{{ $tab['href'] }}" class="{{ $tabClass }} rounded-full px-2.5 py-0.5 text-[0.65rem] font-medium border whitespace-nowrap transition-all">
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
                    var bg = unread ? 'bg-violet-500/10' : '';
                    var iconClass = n.icon || 'fa-bell';
                    var colorClass = n.color === 'danger' ? 'text-red-400'
                        : n.color === 'success' ? 'text-emerald-400'
                        : n.color === 'warning' ? 'text-amber-400'
                        : n.color === 'info' ? 'text-cyan-400'
                        : 'text-violet-400';
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
                    $('#notifList .bg-violet-500\\/10').removeClass('bg-violet-500/10');
                    if (typeof window.updateNotificationBadge === 'function') {
                        window.updateNotificationBadge(0);
                    }
                });
            });
        })();
    </script>
@endpush
