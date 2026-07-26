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
        Bootstrap pages. Build: `bun run build:css` (or `bun run dev:css` for watch).
        A pre-commit hook auto-rebuilds this file when Blade changes.
        filemtime() cache-busting ensures browsers always fetch the latest CSS. --}}
    <link rel="stylesheet" href="/assets/css/rc-erp.css?v={{ filemtime(public_path('assets/css/rc-erp.css')) }}">

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
<body class="bg-gradient-to-b from-amber-50/30 to-white min-h-screen flex flex-col font-sans text-gray-900">

    {{-- ==================== HEADER (unified top-nav component — shared with x-layouts.erp) ==================== --}}
    <x-erp.top-nav />

    {{-- ==================== MAIN LAYOUT ==================== --}}
    <div class="container-fluid flex-1">
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
            <main class="col-lg-10 col-md-9 ms-sm-auto px-3 px-md-4 py-4" id="mainContent">
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

    {{-- ==================== STICKY FOOTER (no-print) ==================== --}}
    <footer class="no-print shrink-0 bg-amber-900 text-amber-100 py-3 text-center text-xs mt-auto">
        Remote Center — code with love and coffee by mycreativecode © {{ date('Y') }}
    </footer>

    {{-- ==================== SCRIPTS ==================== --}}
    <script src="/assets/js/bootstrep/bootstrap.bundle.min.js"></script>
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
    </script>
    @stack('scripts')
</body>
</html>
