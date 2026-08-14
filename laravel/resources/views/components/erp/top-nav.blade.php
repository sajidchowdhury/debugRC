{{--
  x-erp.top-nav — UNIFIED top navigation bar

  Clean, high-contrast design:
    - Dark indigo bar with vivid rainbow accent line
    - "Remote Center" brand in white
    - Mobile-only hamburger (lg:hidden)
    - Right: Role badge | Branch | Notification bell | User dropdown
    - All text/controls HIGH CONTRAST and clearly visible

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
        'admin'            => ['label' => 'Admin',            'dot' => 'bg-rose-400',    'bg' => 'bg-rose-500/30', 'text' => 'text-rose-200', 'hover' => 'hover:text-rose-100', 'border' => 'border-rose-400/50'],
        'superadmin'       => ['label' => 'Super Admin',      'dot' => 'bg-pink-400',    'bg' => 'bg-pink-500/30', 'text' => 'text-pink-200', 'hover' => 'hover:text-pink-100', 'border' => 'border-pink-400/50'],
        'manager'          => ['label' => 'Manager',          'dot' => 'bg-cyan-400',    'bg' => 'bg-cyan-500/30', 'text' => 'text-cyan-200', 'hover' => 'hover:text-cyan-100', 'border' => 'border-cyan-400/50'],
        'sales_manager'    => ['label' => 'Sales Manager',    'dot' => 'bg-amber-400',   'bg' => 'bg-amber-500/30', 'text' => 'text-amber-200', 'hover' => 'hover:text-amber-100', 'border' => 'border-amber-400/50'],
        'warehouse_manager'=> ['label' => 'Warehouse Manager', 'dot' => 'bg-orange-400',  'bg' => 'bg-orange-500/30', 'text' => 'text-orange-200', 'hover' => 'hover:text-orange-100', 'border' => 'border-orange-400/50'],
        'dispatcher'       => ['label' => 'Dispatcher',       'dot' => 'bg-violet-400',  'bg' => 'bg-violet-500/30', 'text' => 'text-violet-200', 'hover' => 'hover:text-violet-100', 'border' => 'border-violet-400/50'],
        'accountant'       => ['label' => 'Accountant',       'dot' => 'bg-emerald-400', 'bg' => 'bg-emerald-500/30', 'text' => 'text-emerald-200', 'hover' => 'hover:text-emerald-100', 'border' => 'border-emerald-400/50'],
    ];
    $roleCfg = $roleMap[$role] ?? ['label' => ucfirst($role), 'dot' => 'bg-slate-400', 'bg' => 'bg-slate-500/30', 'text' => 'text-slate-200', 'hover' => 'hover:text-slate-100', 'border' => 'border-slate-400/50'];

    $canSwitchBranch = in_array($role, ['admin', 'superadmin', 'manager']);

    $userName = auth()->user()?->username ?? 'U';
    $employeeName = auth()->user()?->employee?->name ?? '';
    $initials = $employeeName
        ? strtoupper(mb_substr($employeeName, 0, 1) . (mb_substr($employeeName, 1, 1) ?: ''))
        : strtoupper(mb_substr($userName, 0, 1));
@endphp

<style>
    @keyframes notifPulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.6); }
        50%      { box-shadow: 0 0 0 5px rgba(239,68,68,0); }
    }
    .notif-pulse { animation: notifPulse 2s ease-in-out infinite; }

    @keyframes accentSlide {
        0%   { background-position: 0% center; }
        100% { background-position: 300% center; }
    }
    .rc-accent-line {
        height: 3px;
        background: linear-gradient(90deg, #f59e0b, #ef4444, #ec4899, #8b5cf6, #3b82f6, #10b981, #f59e0b, #ef4444);
        background-size: 300% auto;
        animation: accentSlide 6s linear infinite;
    }

    /* ── Top nav: solid dark indigo (not overly dark) ── */
    .rc-topnav {
        background: linear-gradient(135deg, #1e1b4b 0%, #1e2759 40%, #172554 70%, #0f172a 100%);
    }

    /* ── Dropdown theme ── */
    .rc-topnav .dropdown-menu {
        background: #1e293b;
        border: 1px solid rgba(148,163,184,0.2);
        color: #e2e8f0;
    }
    .rc-topnav .dropdown-menu .dropdown-item { color: #e2e8f0; }
    .rc-topnav .dropdown-menu .dropdown-item:hover,
    .rc-topnav .dropdown-menu .dropdown-item:focus {
        background: rgba(139,92,246,0.2);
        color: #fff;
    }
    .rc-topnav .dropdown-menu .dropdown-item-text { color: #e2e8f0; }
    .rc-topnav .dropdown-menu .dropdown-divider { border-color: rgba(148,163,184,0.15); }
    .rc-topnav .dropdown-menu .dropdown-header { color: #94a3b8; }
    .rc-topnav .dropdown-menu .text-muted { color: #64748b !important; }
    .rc-topnav .dropdown-menu small { color: #94a3b8; }

    .rc-topnav select option { background: #1e293b; color: #f1f5f9; }
</style>

{{-- ==================== STICKY TOP NAV ==================== --}}
<div class="rc-topnav sticky top-0 z-50 no-print">
    <div class="rc-accent-line"></div>

    <div class="px-3 sm:px-5 py-2.5">
        <div class="flex items-center justify-between gap-3">

            {{-- LEFT: hamburger + brand --}}
            <div class="flex items-center gap-3 min-w-0">
                {{-- Mobile hamburger — lg:hidden only --}}
                <button type="button"
                        class="flex items-center justify-center w-9 h-9 rounded-lg bg-white/10 border border-white/20 text-white hover:bg-white/20 hover:border-white/40 active:scale-95 transition-all lg:hidden"
                        onclick="toggleSidebar()"
                        aria-label="Toggle menu">
                    <i class="fas fa-bars text-lg"></i>
                </button>

                {{-- Brand --}}
                <span class="text-white font-bold text-lg tracking-wide select-none whitespace-nowrap">
                    Remote <span class="text-amber-400">Center</span>
                </span>
            </div>

            {{-- RIGHT: role → branch → notification → user --}}
            <div class="flex items-center gap-2 sm:gap-3">

                {{-- Role badge — always visible --}}
                <span class="inline-flex items-center gap-1.5 font-semibold text-xs sm:text-sm rounded-full px-2.5 sm:px-3 py-1.5 border {{ $roleCfg['bg'] }} {{ $roleCfg['text'] }} {{ $roleCfg['hover'] }} {{ $roleCfg['border'] }} transition-colors">
                    <span class="w-2 h-2 rounded-full {{ $roleCfg['dot'] }}"></span>
                    {{ $roleCfg['label'] }}
                </span>

                {{-- Branch selector --}}
                @if ($canSwitchBranch && $branches->isNotEmpty())
                    <form method="POST" action="{{ route('branch.switch') }}"
                          class="inline-flex items-center gap-1.5 rounded-full px-2.5 sm:px-3 py-1.5 border border-sky-400/40 bg-sky-500/20">
                        @csrf
                        <i class="fas fa-location-dot text-xs text-sky-300"></i>
                        <select name="branch_id" onchange="this.form.submit()"
                                class="bg-transparent border-0 text-xs sm:text-sm font-semibold text-sky-200 outline-none cursor-pointer focus:ring-0 hover:text-sky-100 transition-colors max-w-[90px] sm:max-w-[200px] truncate"
                                aria-label="Switch branch">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" {{ (string) $currentBranchId === (string) $branch->id ? 'selected' : '' }}>
                                    {{ $branch->branch_name }} ({{ $branch->branch_code }})
                                </option>
                            @endforeach
                        </select>
                    </form>
                @elseif ($branches->isNotEmpty())
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 sm:px-3 py-1.5 border border-sky-400/40 bg-sky-500/20 text-xs sm:text-sm font-semibold text-sky-200">
                        <i class="fas fa-location-dot text-xs text-sky-300"></i>
                        <span class="max-w-[90px] sm:max-w-[200px] truncate">{{ session('branch_name', 'No Branch') }}</span>
                    </span>
                @endif

                {{-- Notification bell --}}
                <div class="dropdown">
                    <button type="button"
                            class="relative flex items-center justify-center w-9 h-9 rounded-lg border border-amber-400/40 bg-amber-500/20 text-amber-300 hover:text-amber-200 hover:border-amber-300/60 hover:bg-amber-500/30 active:scale-95 transition-all"
                            title="Notifications"
                            id="notifDropdownBtn"
                            data-bs-toggle="dropdown"
                            aria-expanded="false">
                        <i class="fas fa-bell text-base"></i>
                        <span id="notifBadge"
                              class="absolute -top-1 -right-1 flex items-center justify-center w-4 h-4 rounded-full bg-red-500 text-white font-bold leading-none notif-pulse"
                              style="display:none; font-size:0.5rem;">0</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-xl shadow-black/50"
                        aria-labelledby="notifDropdownBtn"
                        style="min-width: 320px; max-height: 400px; overflow-y: auto;">
                        <li class="dropdown-header d-flex justify-content-between align-items-center">
                            <strong class="text-slate-100"><i class="fas fa-bell me-1.5 text-amber-400"></i>Notifications</strong>
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
                                <i class="fas fa-inbox me-2 text-amber-400"></i>View all notifications
                            </a>
                        </li>
                        @can('view-notification-rules')
                        <li>
                            <a class="dropdown-item" href="{{ route('admin.notifications.rules') }}">
                                <i class="fas fa-sliders me-2 text-amber-400"></i>Notification settings
                            </a>
                        </li>
                        @endcan
                    </ul>
                </div>

                {{-- User avatar + dropdown --}}
                <div class="dropdown">
                    <button type="button"
                            class="flex items-center gap-2 rounded-lg pl-2 pr-3 py-1.5 border border-white/20 bg-white/10 hover:border-white/40 hover:bg-white/15 active:scale-95 transition-all"
                            data-bs-toggle="dropdown"
                            aria-expanded="false">
                        <span class="flex items-center justify-center w-8 h-8 rounded-full bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white text-xs font-bold shadow-sm">
                            {{ $initials }}
                        </span>
                        <span class="hidden sm:inline text-sm font-semibold text-white max-w-[100px] truncate">{{ $userName }}</span>
                        <i class="fas fa-chevron-down text-[0.5rem] text-white/60 hidden sm:inline"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-xl shadow-black/50" style="min-width: 220px;">
                        <li class="dropdown-item-text px-3 py-2">
                            <div class="flex items-center gap-3">
                                <span class="flex items-center justify-center w-10 h-10 rounded-full bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white text-sm font-bold shadow">
                                    {{ $initials }}
                                </span>
                                <div class="min-w-0">
                                    <div class="font-semibold text-sm text-white truncate">{{ $employeeName ?: $userName }}</div>
                                    <div class="text-xs {{ $roleCfg['text'] }}">{{ $roleCfg['label'] }}</div>
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

        {{-- Optional tab strip --}}
        @isset($tabs)
            <div class="flex flex-wrap gap-1.5 overflow-x-auto pt-2 -mb-1">
                @foreach ($tabs as $tab)
                    @php
                        $isActive = !empty($tab['active']);
                        $tabClass = $isActive
                            ? 'bg-violet-500/40 text-violet-200 border-violet-400/60'
                            : 'bg-white/5 text-slate-300 border-white/10 hover:text-white hover:border-white/30 hover:bg-white/10';
                    @endphp
                    <a href="{{ $tab['href'] }}" class="{{ $tabClass }} rounded-full px-2.5 py-0.5 text-[0.65rem] font-medium border whitespace-nowrap transition-all">
                        {{ $tab['label'] }}
                    </a>
                @endforeach
            </div>
        @endisset
    </div>
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
                    var bg = unread ? 'bg-violet-500/15' : '';
                    var iconClass = n.icon || 'fa-bell';
                    var colorClass = n.color === 'danger' ? 'text-red-400'
                        : n.color === 'success' ? 'text-emerald-400'
                        : n.color === 'warning' ? 'text-amber-400'
                        : n.color === 'info' ? 'text-cyan-400'
                        : 'text-violet-400';
                    var body = $('<div>').text(n.body || '').html();
                    var title = $('<div>').text(n.title || '').html();
                    var time = n.created_at || '';
                    html += '<li class="dropdown-item-text py-2 border-b border-slate-600/30 ' + bg + '">'
                        + '<div class="d-flex align-items-start gap-2">'
                        + '<i class="fas ' + iconClass + ' ' + colorClass + ' mt-0.5 text-xs"></i>'
                        + '<div class="flex-grow-1 min-w-0">'
                        + '<div class="fw-semibold small text-slate-100">' + title + '</div>'
                        + '<div class="text-xs text-slate-300 mt-0.5">' + body + '</div>'
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
                    $('#notifList .bg-violet-500\\/15').removeClass('bg-violet-500/15');
                    if (typeof window.updateNotificationBadge === 'function') {
                        window.updateNotificationBadge(0);
                    }
                });
            });
        })();
    </script>
@endpush
