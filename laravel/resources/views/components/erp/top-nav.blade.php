{{--
  x-erp.top-nav — UNIFIED top navigation bar (shared by layouts.admin AND
  components/layouts/erp).

  This is the SINGLE source of truth for the top head across the entire RC ERP
  application. Both the legacy Bootstrap layout (layouts/admin.blade.php) and
  the clean Tailwind layout (components/layouts/erp.blade.php) include this
  component so EVERY page renders the identical premium top head:
    - Sticky white-translucent bar (backdrop-blur, amber bottom border)
    - Row 1: hamburger + "RC ERP" gradient badge + role badge + branch
      switcher pill + notification bell (full dropdown w/ SSE) + user menu
    - Row 2: optional tab strip (via $tabs prop)

  The notification bell retains the FULL dropdown functionality (recent list,
  mark-all-read, SSE real-time push via notification.js) that was previously
  only on layouts/admin — now available app-wide.

  Usage (do NOT use blade-comment delimiters inside this header — they are
  not nestable and would break this block):
    <x-erp.top-nav />                  (no tabs)
    <x-erp.top-nav :tabs="$tabs" />    (with tab strip)

  Dependencies (must be loaded in the layout's head BEFORE this component's
  @push('scripts') runs):
    - jQuery 3.6  (for $() in dropdown JS)
    - Bootstrap bundle (for data-bs-toggle="dropdown")
    - Font Awesome (for fas fa-* icons)
    - rc-erp.css (for .nav-btn tab classes)
  All three layouts already load these.
--}}
@props([
    'tabs' => null,
])

@php
    // Active branches for the switcher dropdown (small list, cached per request)
    $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
    $currentBranchId = session('branch_id');
    $currentBranchCode = session('branch_code');

    // Current user role for the badge
    $role = auth()->user()?->getRole() ?? 'user';
    $roleMap = [
        'admin'            => ['label' => 'Admin',          'label_bn' => 'অ্যাডমিন',      'classes' => 'bg-gray-100 text-gray-700 border-gray-300'],
        'superadmin'       => ['label' => 'Super Admin',    'label_bn' => 'সুপার অ্যাডমিন','classes' => 'bg-gray-100 text-gray-700 border-gray-300'],
        'manager'          => ['label' => 'Manager',        'label_bn' => 'ম্যানেজার',     'classes' => 'bg-cyan-100 text-cyan-700 border-cyan-300'],
        'sales_manager'    => ['label' => 'SM',             'label_bn' => 'বিক্রেতা',       'classes' => 'bg-amber-100 text-amber-700 border-amber-300'],
        'warehouse_manager'=> ['label' => 'WM',             'label_bn' => 'গুদাম',         'classes' => 'bg-orange-100 text-orange-700 border-orange-300'],
        'dispatcher'       => ['label' => 'Dispatcher',     'label_bn' => 'ডিসপ্যাচার',    'classes' => 'bg-gray-100 text-gray-700 border-gray-300'],
        'accountant'       => ['label' => 'Accountant',     'label_bn' => 'হিসাবরক্ষক',    'classes' => 'bg-gray-100 text-gray-700 border-gray-300'],
    ];
    $roleCfg = $roleMap[$role] ?? ['label' => ucfirst($role), 'label_bn' => '', 'classes' => 'bg-gray-100 text-gray-700 border-gray-300'];

    // Can this user switch branches? (admin/superadmin/manager only)
    $canSwitchBranch = in_array($role, ['admin', 'superadmin', 'manager']);
@endphp

{{-- ==================== STICKY TOP NAV (no-print) ==================== --}}
<div class="sticky top-0 z-50 bg-white/90 backdrop-blur-md border-b border-amber-200 shadow-sm no-print">
    <div class="px-4 py-2">

        {{-- Row 1: hamburger + brand + role + branch switcher + bell + user menu --}}
        <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
            <div class="flex items-center gap-3">
                {{-- Mobile sidebar toggle (mirrors layouts/admin.blade.php) --}}
                <button type="button" class="btn btn-outline-dark btn-sm d-lg-none" onclick="toggleSidebar()" aria-label="Toggle menu">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="bg-gradient-to-r from-amber-500 to-orange-500 rounded-lg px-3 py-1.5 text-white font-bold text-sm shadow">RC ERP</div>
                {{-- Dashboard button — links to user performance dashboard (separate from sidebar "Overview") --}}
                <a href="{{ route('dashboard') }}"
                   class="inline-flex items-center gap-1.5 bg-white/80 border border-amber-300 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-50 hover:border-amber-400 transition-colors shadow-sm"
                   title="Performance Dashboard">
                    <i class="fas fa-tachometer-alt" style="font-size:0.75rem;"></i>
                    Dashboard
                </a>
                <span class="text-xs text-amber-700 font-medium hidden sm:inline">আর সি বণিক — Sales</span>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                {{-- Role badge (read-only — real role switching = re-login) --}}
                <span class="inline-flex items-center gap-1 font-medium text-xs rounded-full px-2.5 py-1 border {{ $roleCfg['classes'] }}">
                    <i class="fas fa-user-tag" style="font-size:0.6rem;"></i>
                    {{ $roleCfg['label'] }}@if ($roleCfg['label_bn']) / {{ $roleCfg['label_bn'] }}@endif
                </span>

                {{-- Branch switcher (admin/manager only) --}}
                @if ($canSwitchBranch && $branches->isNotEmpty())
                    <form method="POST" action="{{ route('branch.switch') }}" class="inline-flex items-center gap-1 bg-amber-50/60 border border-amber-200 rounded-full px-2.5 py-1">
                        @csrf
                        <i class="fas fa-map-pin" style="font-size:0.6rem; color:#d97706;"></i>
                        <select name="branch_id" onchange="this.form.submit()"
                            class="bg-transparent border-0 text-xs font-medium text-amber-900 outline-none cursor-pointer focus:ring-0"
                            aria-label="Switch branch">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" {{ (string) $currentBranchId === (string) $branch->id ? 'selected' : '' }}>
                                    {{ $branch->branch_name }} ({{ $branch->branch_code }})
                                </option>
                            @endforeach
                        </select>
                    </form>
                @elseif ($branches->isNotEmpty())
                    {{-- Read-only branch pill for non-admin users --}}
                    <span class="inline-flex items-center gap-1 bg-amber-50/60 border border-amber-200 rounded-full px-2.5 py-1 text-xs font-medium text-amber-900">
                        <i class="fas fa-map-pin" style="font-size:0.6rem; color:#d97706;"></i>
                        {{ session('branch_name', 'No Branch') }}
                    </span>
                @endif

                {{-- Notification bell — full dropdown (SSE-driven, all authenticated users) --}}
                <div class="dropdown">
                    <button type="button"
                            class="relative inline-flex items-center justify-center size-8 rounded-full bg-amber-50/60 border border-amber-200 text-amber-700 hover:bg-amber-100 transition-colors"
                            title="Notifications"
                            id="notifDropdownBtn"
                            data-bs-toggle="dropdown"
                            aria-expanded="false">
                        <i class="fas fa-bell text-sm"></i>
                        <span id="notifBadge"
                              class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                              style="display:none; font-size:0.65em; min-width:1.2em;">0</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow"
                        aria-labelledby="notifDropdownBtn"
                        style="min-width: 340px; max-height: 420px; overflow-y: auto;">
                        <li class="dropdown-header d-flex justify-content-between align-items-center">
                            <strong><i class="fas fa-bell me-1"></i>Notifications</strong>
                            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none"
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
                                <i class="fas fa-inbox me-2"></i>View all notifications
                            </a>
                        </li>
                        @can('view-notification-rules')
                        <li>
                            <a class="dropdown-item" href="{{ route('admin.notifications.rules') }}">
                                <i class="fas fa-cog me-2"></i>Notification settings
                            </a>
                        </li>
                        @endcan
                    </ul>
                </div>

                {{-- User menu (logout) --}}
                <div class="dropdown">
                    <button type="button"
                            class="inline-flex items-center gap-1.5 bg-amber-50/60 border border-amber-200 rounded-full px-2.5 py-1 text-xs font-medium text-amber-900 hover:bg-amber-100 transition-colors"
                            data-bs-toggle="dropdown"
                            aria-expanded="false">
                        <i class="fas fa-user-circle text-sm"></i>
                        <span class="max-w-[100px] truncate">{{ auth()->user()?->username ?? 'User' }}</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li class="dropdown-item-text">
                            <strong>{{ auth()->user()?->employee?->name ?? auth()->user()?->username }}</strong><br>
                            <small class="text-muted">{{ $role }}</small>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="{{ route('dashboard') }}">
                                <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="fas fa-sign-out-alt me-2"></i> Logout
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Row 2: optional tab strip (via $tabs prop) --}}
        @isset($tabs)
            <div class="flex flex-wrap gap-1.5 overflow-x-auto pb-1">
                @foreach ($tabs as $tab)
                    @php
                        $isActive = !empty($tab['active']);
                        $tabClass = $isActive
                            ? 'nav-btn active text-white'
                            : 'nav-btn text-amber-700 hover:bg-amber-50';
                    @endphp
                    <a href="{{ $tab['href'] }}" class="{{ $tabClass }} rounded-full px-3 py-1 text-xs font-medium border border-amber-200 whitespace-nowrap">
                        {{ $tab['label'] }}
                    </a>
                @endforeach
            </div>
        @endisset
    </div>
</div>

{{-- ==================== NOTIFICATION TOAST CONTAINER + AUDIO ==================== --}}
{{-- Required by notification.js (showBeautifulNotification + playNotificationSound).
     Positioned fixed so placement in the DOM does not affect layout. --}}
<div id="notificationContainer" aria-live="polite" aria-atomic="true"
     style="position:fixed; top:70px; right:20px; z-index:1080; max-width:360px;"></div>
<audio id="notificationSound" preload="none" style="display:none;"></audio>

@push('scripts')
    {{-- Phase 4 F-18a: Notification engine — SSE real-time push
         (PostgreSQL LISTEN/NOTIFY → Redis → EventSource). Loaded on every
         authenticated page so the bell badge + toast popups work app-wide.
         NO database polling — the only DB hits are (a) one unread-count
         fetch on page load + dropdown open, and (b) one recent-list fetch
         on dropdown open. All subsequent updates arrive via SSE push. --}}
    <script src="/assets/js/notification.js?v={{ filemtime(public_path('assets/js/notification.js')) }}"></script>
    <script>
        // ─── Notification bell dropdown ───
        // Populates the recent-notifications list when the dropdown opens,
        // and wires the "Mark all read" button. The unread-count badge +
        // real-time toast popups are handled by notification.js (SSE-driven).
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
                    var bg = unread ? 'bg-amber-50' : '';
                    var iconClass = n.icon || 'fa-bell';
                    var colorClass = n.color === 'danger' ? 'text-danger'
                        : n.color === 'success' ? 'text-success'
                        : n.color === 'warning' ? 'text-warning'
                        : n.color === 'info' ? 'text-info'
                        : 'text-primary';
                    var body = $('<div>').text(n.body || '').html();
                    var title = $('<div>').text(n.title || '').html();
                    var time = n.created_at || '';
                    html += '<li class="dropdown-item-text py-2 border-bottom ' + bg + '">'
                        + '<div class="d-flex align-items-start gap-2">'
                        + '<i class="fas ' + iconClass + ' ' + colorClass + ' mt-1"></i>'
                        + '<div class="flex-grow-1">'
                        + '<div class="fw-semibold small">' + title + '</div>'
                        + '<div class="text-muted" style="font-size:0.78rem;">' + body + '</div>'
                        + '<div class="text-muted" style="font-size:0.7rem;">' + time + '</div>'
                        + '</div>'
                        + '</div>'
                        + '</li>';
                });
                $list.html(html);
            }

            // Load recent list on first dropdown open.
            $('#notifDropdownBtn').on('shown.bs.dropdown', function() {
                $.ajax({
                    url: NOTIF_RECENT_URL,
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                }).done(function(data) {
                    renderRecent(data);
                    // Sync badge with the server's unread_count (source of truth).
                    if (typeof window.updateNotificationBadge === 'function' && typeof data.unread_count !== 'undefined') {
                        window.updateNotificationBadge(data.unread_count);
                    }
                }).fail(function() {
                    $('#notifList').html('<span class="dropdown-item-text text-muted small">Failed to load.</span>');
                });
            });

            // Mark all as read.
            $('#notifMarkAllRead').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $.ajax({
                    url: NOTIF_MARK_ALL_URL,
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF },
                }).done(function() {
                    // Visually mark all items as read + clear badge.
                    $('#notifList .bg-amber-50').removeClass('bg-amber-50');
                    if (typeof window.updateNotificationBadge === 'function') {
                        window.updateNotificationBadge(0);
                    }
                });
            });
        })();
    </script>
@endpush
