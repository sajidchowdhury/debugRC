<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} — {{ $title ?? 'Dashboard' }}</title>

    {{-- Per-page <head> meta tags (PWA manifest link, theme-color, apple-*
         tags, etc.) — pushed by individual blade templates via
         @push('head_meta'). Empty by default. --}}
    @stack('head_meta')

    {{-- All assets served locally from /assets/ — no CDN dependency
         (CDN unreachable from some regions caused "site is only text" bug) --}}
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/all.min.css">
    <link href="/assets/css/sweetalert2.min.css" rel="stylesheet">

    {{-- Shared legacy styles (served by Nginx from /assets/).
        filemtime() cache-busting ensures browsers always fetch the latest
        custom.css after deploys (prevents stale cached CSS from hiding
        sidebar fixes). --}}
    <link rel="stylesheet" href="/assets/css/custom.css?v={{ filemtime(public_path('assets/css/custom.css')) }}">
    <link rel="stylesheet" href="/assets/css/footer-dropup.css">

    {{-- RC ERP design-system (Tailwind v4, no preflight — coexists with Bootstrap).
        Additive utilities + class-scoped custom rules; zero impact on existing
        Bootstrap pages. Build: `npm run build:css` (or `npm run dev:css` for watch). --}}
    <link rel="stylesheet" href="/assets/css/rc-erp.css">

    {{-- Sidebar toggle: chevron rotation when expanded --}}
    <style>
        .sidebar-toggle[aria-expanded="true"] .fa-chevron-down {
            transform: rotate(180deg);
            transition: transform 0.2s ease;
        }
        .sidebar-toggle .fa-chevron-down {
            transition: transform 0.2s ease;
        }
    </style>

    {{-- jQuery 3.6 + SweetAlert2 --}}
    <script src="/assets/js/bootstrep/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/bootstrep/sweetalert2@11.js"></script>

    {{-- Select2 + DataTables --}}
    <link href="/assets/css/select2.min.css" rel="stylesheet">
    <script src="/assets/js/bootstrep/select2.min.js"></script>
    <link rel="stylesheet" href="/assets/css/jquery.dataTables.min.css">
    <script src="/assets/js/bootstrep/jquery.dataTables.min.js"></script>

    <script src="/assets/js/custom.js?v={{ filemtime(public_path('assets/js/custom.js')) }}"></script>

    @stack('css')
</head>
<body>

    {{-- ==================== HEADER ==================== --}}
    <nav class="navbar navbar-expand-lg navbar-dark shadow sticky-top" style="background:#f7f7f7;">
        <div class="container-fluid">
            {{-- Mobile sidebar toggle --}}
            <button class="btn btn-outline-dark me-2 d-lg-none" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>

            {{-- Center: Branch Name --}}
            <div class="mx-auto fw-bold d-none d-lg-block" style="color:#61bc91;">
                <i class="fas fa-building me-2"></i>
                Branch: {{ session('branch_name', 'No Branch') }}
            </div>

            {{-- Right: User Dropdown --}}
            <div class="d-flex align-items-center gap-2">
                <a href="{{ url('/') }}" class="btn btn-light btn-outline-dark btn-sm" title="Legacy App">
                    <i class="fas fa-home"></i>
                </a>

                {{-- Phase 4 F-18a: Notification bell — visible to ALL authenticated
                     users (everyone receives notifications). The "Settings" link
                     inside the dropdown is gated to admin/superadmin via the
                     view-notification-rules Gate. Real-time push is via SSE
                     (PostgreSQL LISTEN/NOTIFY → Redis → EventSource) handled by
                     /assets/js/notification.js — NO database polling. --}}
                <div class="dropdown">
                    <button class="btn btn-outline-dark btn-sm dropdown-toggle position-relative"
                            type="button" id="notifDropdownBtn" data-bs-toggle="dropdown"
                            aria-expanded="false" title="Notifications">
                        <i class="fas fa-bell"></i>
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
                <div class="dropdown">
                    <a href="#" class="btn btn-outline-dark btn-sm dropdown-toggle d-flex align-items-center"
                       data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-2"></i>
                        {{ session('employee_name', Auth::user()->username ?? 'User') }}
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li class="dropdown-item-text">
                            <strong>{{ session('employee_name', Auth::user()->username ?? '') }}</strong><br>
                            <small class="text-muted">{{ session('role', '') }}</small>
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
    </nav>

    {{-- ==================== MAIN LAYOUT ==================== --}}
    <div class="container-fluid">
        <div class="row">
            {{-- Sidebar — DB-driven menu with per-user permissions --}}
            <nav id="sidebar" class="sidebar col-lg-2 col-md-3 d-none d-lg-block">
                <div class="sidebar-header">
                    <span class="logo">
                        <i class="fas fa-store"></i>
                        <span class="logo-text">RC ERP</span>
                    </span>
                </div>
                <ul class="nav flex-column" id="sidebarMenu">
                    @php
                        $menuTree = app(\App\Services\MenuService::class)->getUserMenuTree(auth()->user());
                        $currentUri = '/' . request()->path();
                    @endphp

                    @foreach ($menuTree as $mainMenu)
                        @php
                            $hasChildren = !empty($mainMenu['children']);
                            $mainMenuPath = parse_url($mainMenu['url'] ?? '#', PHP_URL_PATH) ?: '';
                            $isActive = $hasChildren && collect($mainMenu['children'])->contains(function ($child) use ($currentUri, $mainMenuPath) {
                                $childPath = parse_url($child['url'] ?? '#', PHP_URL_PATH) ?: '';
                                if (empty($childPath) || $childPath === '/' || $childPath === '#') {
                                    return false;
                                }
                                // Match if current URI starts with the child's path
                                // (e.g., /admin/sales-invoices/5/edit starts with /admin/sales-invoices)
                                return str_starts_with($currentUri, $childPath)
                                    || ($mainMenuPath && $mainMenuPath !== '#' && str_starts_with($currentUri, $mainMenuPath));
                            });
                        @endphp

                        @if ($hasChildren)
                            {{-- Dropdown parent --}}
                            <li class="nav-item">
                                <a href="#" class="nav-link d-flex align-items-center sidebar-toggle {{ $isActive ? 'active' : '' }}"
                                   data-target="#menu-{{ $mainMenu['id'] }}" aria-expanded="{{ $isActive ? 'true' : 'false' }}">
                                    <i class="{{ $mainMenu['icon'] }}"></i>
                                    <span class="ms-2">{{ $mainMenu['menu_name'] }}</span>
                                    <i class="fas fa-chevron-down ms-auto small"></i>
                                </a>
                                <ul class="nav flex-column ms-3 submenu {{ $isActive ? 'is-open' : '' }}" id="menu-{{ $mainMenu['id'] }}">
                                    @foreach ($mainMenu['children'] as $child)
                                        @php
                                            $hasGrandchildren = !empty($child['children']);
                                            $childPath = parse_url($child['url'] ?? '#', PHP_URL_PATH) ?: '';
                                            $childActive = !empty($childPath) && $childPath !== '/' && $childPath !== '#' &&
                                                str_starts_with($currentUri, $childPath);
                                        @endphp

                                        @if ($hasGrandchildren)
                                            {{-- Sub-dropdown --}}
                                            <li class="nav-item">
                                                <a href="#" class="nav-link small d-flex align-items-center sidebar-toggle {{ $childActive ? 'active' : '' }}"
                                                   data-target="#submenu-{{ $child['id'] }}" aria-expanded="{{ $childActive ? 'true' : 'false' }}">
                                                    <i class="{{ $child['icon'] }}"></i>
                                                    <span class="ms-2">{{ $child['menu_name'] }}</span>
                                                </a>
                                                <ul class="nav flex-column ms-3 submenu {{ $childActive ? 'is-open' : '' }}" id="submenu-{{ $child['id'] }}">
                                                    @foreach ($child['children'] as $grandchild)
                                                        <li class="nav-item">
                                                            <a href="{{ $grandchild['url'] }}" class="nav-link small">
                                                                <i class="{{ $grandchild['icon'] }}"></i>
                                                                <span class="ms-2">{{ $grandchild['menu_name'] }}</span>
                                                            </a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </li>
                                        @else
                                            {{-- Leaf item --}}
                                            <li class="nav-item">
                                                @php
                                                    $leafPath = parse_url($child['url'] ?? '#', PHP_URL_PATH) ?: '';
                                                    $leafActive = !empty($leafPath) && $leafPath !== '/' && $leafPath !== '#' &&
                                                        str_starts_with($currentUri, $leafPath);
                                                @endphp
                                                <a href="{{ $child['url'] }}" class="nav-link small {{ $leafActive ? 'active' : '' }}">
                                                    <i class="{{ $child['icon'] }}"></i>
                                                    <span class="ms-2">{{ $child['menu_name'] }}</span>
                                                </a>
                                            </li>
                                        @endif
                                    @endforeach
                                </ul>
                            </li>
                        @else
                            {{-- Top-level leaf --}}
                            @php
                                $topLeafPath = parse_url($mainMenu['url'] ?? '#', PHP_URL_PATH) ?: '';
                                $topLeafActive = !empty($topLeafPath) && $topLeafPath !== '/' && $topLeafPath !== '#' &&
                                    str_starts_with($currentUri, $topLeafPath);
                            @endphp
                            <li class="nav-item">
                                <a href="{{ $mainMenu['url'] }}" class="nav-link {{ $topLeafActive ? 'active' : '' }}">
                                    <i class="{{ $mainMenu['icon'] }}"></i>
                                    <span class="ms-2">{{ $mainMenu['menu_name'] }}</span>
                                </a>
                            </li>
                        @endif
                    @endforeach

                    {{-- System menus (always visible to admin/superadmin) --}}
                    @can('manage-system-policy')
                    <li class="nav-item">
                        <a href="{{ route('admin.compliance.index') }}" class="nav-link {{ request()->routeIs('admin.compliance.*') ? 'active' : '' }}">
                            <i class="fas fa-shield-halved"></i> <span class="ms-2">Compliance</span>
                        </a>
                    </li>
                    @endcan
                    <li class="nav-item">
                        <a href="{{ route('admin.archive.index') }}" class="nav-link {{ request()->routeIs('admin.archive.*') ? 'active' : '' }}">
                            <i class="fas fa-archive"></i> <span class="ms-2">Archive</span>
                        </a>
                    </li>
                </ul>
            </nav>

            {{-- Main content --}}
            <main class="col-lg-10 col-md-9 ms-sm-auto px-3 px-md-4 py-2" id="mainContent">
                {{-- Flash messages --}}
                @if (session('success'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif
                @if (session('error'))
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>{{ session('error') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif
                @if (session('warning'))
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>{{ session('warning') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>

    {{-- ==================== SCRIPTS ==================== --}}
    <script src="/assets/js/bootstrep/bootstrap.bundle.min.js"></script>

    {{-- Toast container + (silent) notification sound element — referenced
         by notification.js showBeautifulNotification() + playNotificationSound().
         Placed BEFORE notification.js so the elements exist when the script's
         top-level const notificationSound = document.getElementById(...) runs.
         The audio element has no src so play() rejects silently via .catch(). --}}
    <div id="notificationContainer" aria-live="polite" aria-atomic="true"
         style="position:fixed; top:70px; right:20px; z-index:1080; max-width:360px;"></div>
    <audio id="notificationSound" preload="none" style="display:none;"></audio>

    {{-- Phase 4 F-18a: Notification engine — SSE real-time push
         (PostgreSQL LISTEN/NOTIFY → Redis → EventSource). Loaded on every
         authenticated page so the bell badge + toast popups work app-wide.
         NO database polling — the only DB hits are (a) one unread-count
         fetch on page load + dropdown open, and (b) one recent-list fetch
         on dropdown open. All subsequent updates arrive via SSE push. --}}
    <script src="/assets/js/notification.js?v={{ filemtime(public_path('assets/js/notification.js')) }}"></script>
    <script>
        window.CSRF_TOKEN = '{{ csrf_token() }}';
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (!sidebar) return;
            // On mobile the sidebar is hidden by `d-none` (display:none) AND
            // positioned off-screen (left:-300px). To reveal it we must remove
            // `d-none` (so it renders) and add `.active` (so it slides into
            // view). Toggling both keeps behaviour consistent with
            // closeSidebarOnMobile() in custom.js.
            sidebar.classList.toggle('d-none');
            sidebar.classList.toggle('active');
        }

        // ─── Sidebar submenu toggle (truly bulletproof — no Bootstrap .collapse/.show) ──
        // Earlier attempts toggled Bootstrap's `.show` class on `.collapse` <ul>s,
        // but in this Bootstrap 5 + Tailwind v4 environment that mechanism was
        // unreliable (submenu flashed open then vanished, or stayed invisible).
        // We now use a SELF-CONTAINED `.is-open` class on `.submenu` elements,
        // with display:none/block rules scoped to `.sidebar .submenu` (see
        // custom.css) — nothing in Bootstrap or Tailwind can override them.
        // jQuery just toggles `.is-open` and aria-expanded; chevron rotates via CSS.
        (function() {
            // Versioned key. Earlier ('rcerp_sidebar_expanded', no suffix) stored
            // .show-based boolean state that, after the .is-open migration,
            // caused EVERY submenu to auto-restore as open on page load
            // ("all menus open by default"). Bumping the suffix discards that
            // stale data so the sidebar starts collapsed (only the server-side
            // active section opens via the $isActive 'is-open' class).
            var STORAGE_KEY = 'rcerp_sidebar_expanded_v2';
            // One-time cleanup of the obsolete key (best effort).
            try { localStorage.removeItem('rcerp_sidebar_expanded'); } catch(e) {}

            // Restore saved state on DOM ready
            $(document).ready(function() {
                var saved = {};
                try { saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch(e) {}

                // For each collapsible submenu, apply saved state
                $('.sidebar-toggle').each(function() {
                    var targetSel = $(this).data('target');
                    if (!targetSel) return;
                    var $target = $(targetSel);
                    var targetId = targetSel.replace('#', '');

                    // If server-side $isActive already added 'is-open', respect it
                    if ($target.hasClass('is-open')) {
                        $(this).attr('aria-expanded', 'true');
                        return;
                    }

                    // If localStorage says it was expanded, expand it
                    if (saved[targetId] === true) {
                        $target.addClass('is-open');
                        $(this).attr('aria-expanded', 'true');
                    }
                });

                // Click handler for parent menu toggles
                $('.sidebar-toggle').on('click', function(e) {
                    e.preventDefault();
                    var targetSel = $(this).data('target');
                    if (!targetSel) return;
                    var $target = $(targetSel);
                    var targetId = targetSel.replace('#', '');
                    var isExpanded = $target.hasClass('is-open');

                    if (isExpanded) {
                        $target.removeClass('is-open');
                        $(this).attr('aria-expanded', 'false');
                    } else {
                        $target.addClass('is-open');
                        $(this).attr('aria-expanded', 'true');
                    }

                    // Save state to localStorage
                    try {
                        var saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
                        saved[targetId] = !isExpanded;
                        localStorage.setItem(STORAGE_KEY, JSON.stringify(saved));
                    } catch(err) {}
                });

                // Mobile: close sidebar when a leaf nav-link (no data-target) is clicked
                $('#sidebarMenu a.nav-link').not('.sidebar-toggle').on('click', function() {
                    if (window.innerWidth < 992) {
                        var sidebar = document.getElementById('sidebar');
                        if (sidebar) {
                            sidebar.classList.remove('active');
                            sidebar.classList.add('d-none'); // fully hide (matches toggleSidebar)
                        }
                    }
                });
            });
        })();

        // ─── Phase 4 F-18a: Notification bell dropdown ───
        // Populates the recent-notifications list when the dropdown opens,
        // and wires the "Mark all read" button. The unread-count badge +
        // real-time toast popups are handled by notification.js (SSE-driven).
        (function() {
            var NOTIF_RECENT_URL = '{{ route("admin.notifications.recent") }}';
            var NOTIF_MARK_ALL_URL = '{{ route("admin.notifications.markAllRead") }}';
            var CSRF = '{{ csrf_token() }}';
            var listLoaded = false;

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

            function refreshBadge() {
                if (typeof window.lightCheckNotifications === 'function') {
                    window.lightCheckNotifications();
                }
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
    @stack('scripts')
</body>
</html>
