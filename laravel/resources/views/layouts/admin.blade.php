<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} — {{ $title ?? 'Overview' }}</title>

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

    {{-- Sidebar: clean white theme with indigo/purple active states
         (succeed+ style). Scoped to #sidebar so it overrides the legacy
         light-gray from custom.css. On mobile (<lg) the sidebar becomes
         a fixed slide-in drawer with overlay backdrop. --}}
    <style>
        /* ── Chevron rotation ── */
        .sidebar-toggle[aria-expanded="true"] .fa-chevron-down { transform: rotate(180deg); }
        .sidebar-toggle .fa-chevron-down { transition: transform 0.25s ease; }

        /* ===== SIDEBAR — Clean white with indigo accents ===== */
        #sidebar {
            background: #ffffff !important;
            color: #334155;
            border-right: 1px solid #e2e8f0;
            width: 264px;
            position: fixed !important;
            top: 56px;
            left: 0;
            z-index: 40 !important;
            overflow-y: auto;
            overflow-x: hidden;
            transition: width 0.3s cubic-bezier(0.4,0,0.2,1);
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }
        #sidebar::-webkit-scrollbar { width: 4px; }
        #sidebar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        /* ── Header ── */
        #sidebar .sidebar-header {
            padding: 18px 18px 14px;
            border-bottom: 1px solid #f1f5f9;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        #sidebar .sidebar-header .logo {
            font-size: 1.2rem; font-weight: 800; letter-spacing: -0.02em;
            display: inline-flex; align-items: center; gap: 10px;
            white-space: nowrap; overflow: hidden;
            color: #1e293b;
        }
        #sidebar .sidebar-header .logo i { color: #6366f1; font-size: 1.1rem; }
        #sidebar .sidebar-header .logo .logo-text { color: #1e293b; }
        #sidebar .sidebar-header .logo .logo-text span { color: #6366f1; }

        /* ── Collapse toggle ── */
        #sidebarCollapseBtn {
            display: flex; align-items: center; justify-content: center;
            width: 28px; height: 28px; border-radius: 8px;
            border: 1px solid #e2e8f0; background: #f8fafc;
            color: #64748b; cursor: pointer; transition: all 0.2s ease; flex-shrink: 0;
        }
        #sidebarCollapseBtn:hover { background: #f1f5f9; color: #334155; border-color: #cbd5e1; }
        #sidebarCollapseBtn i { transition: transform 0.3s ease; font-size: 0.65rem; }

        /* ── Nav links — clean, readable ── */
        #sidebar .nav-link {
            color: #64748b; border-radius: 10px; padding: 10px 14px; margin: 2px 10px;
            font-size: 0.875rem; font-weight: 500; transition: all 0.2s ease;
            border-left: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        #sidebar .nav-link:hover {
            background: #f8fafc; color: #1e293b;
        }
        #sidebar .nav-link.active {
            background: #6366f1 !important;
            color: #ffffff !important; font-weight: 600;
            box-shadow: 0 2px 8px rgba(99,102,241,0.3);
        }
        #sidebar .nav-link.active i, #sidebar .nav-link.active .fas { color: #ffffff !important; }
        #sidebar .nav-link i { width: 20px; text-align: center; font-size: 0.95rem; color: #94a3b8; }
        #sidebar .nav-link:hover i { color: #6366f1; }

        /* ── Color-coded section icons (subtle tints for sidebar groups) ── */
        #sidebar .nav-item[data-section="overview"] > .nav-link i { color: #10b981; }
        #sidebar .nav-item[data-section="admin"] > .nav-link i { color: #8b5cf6; }
        #sidebar .nav-item[data-section="sales"] > .nav-link i { color: #f59e0b; }
        #sidebar .nav-item[data-section="purchase"] > .nav-link i { color: #06b6d4; }
        #sidebar .nav-item[data-section="inventory"] > .nav-link i { color: #22c55e; }
        #sidebar .nav-item[data-section="finance"] > .nav-link i { color: #ec4899; }
        #sidebar .nav-item[data-section="accounting"] > .nav-link i { color: #6366f1; }
        #sidebar .nav-item[data-section="reports"] > .nav-link i { color: #f97316; }
        #sidebar .nav-item[data-section="system"] > .nav-link i { color: #94a3b8; }

        /* ── Submenu ── */
        #sidebar .submenu { border-left: 2px solid #e2e8f0; margin-left: 24px; overflow: hidden; transition: max-height 0.35s ease, opacity 0.25s ease; background: #fafafa; border-radius: 0 8px 8px 0; }
        #sidebar .submenu:not(.is-open) { max-height: 0 !important; opacity: 0; pointer-events: none; }
        #sidebar .submenu.is-open { max-height: 2000px; opacity: 1; pointer-events: auto; }
        #sidebar .submenu .nav-link { font-size: 0.8rem; padding: 7px 14px 7px 16px; }

        /* ── Collapsed state ── */
        #sidebar.collapsed { width: 64px !important; }
        #sidebar.collapsed .logo-text,
        #sidebar.collapsed .nav-link span,
        #sidebar.collapsed .sidebar-toggle .fa-chevron-down,
        #sidebar.collapsed .submenu { display: none !important; }
        #sidebar.collapsed .nav-link { justify-content: center; padding: 10px; margin: 3px 6px; }
        #sidebar.collapsed .nav-link i { width: auto; font-size: 1rem; }
        #sidebar.collapsed .sidebar-header { justify-content: center; padding: 14px 8px 10px; }
        #sidebar.collapsed .sidebar-header .logo i { font-size: 1.2rem; }
        #sidebar.collapsed #sidebarCollapseBtn i { transform: rotate(180deg); }

        /* ===== MOBILE DRAWER ===== */
        @media (max-width: 991.98px) {
            #sidebar { position: fixed !important; top: 56px; left: -280px; bottom: 0; height: calc(100vh - 56px) !important; width: 264px; z-index: 1060; box-shadow: 4px 0 20px rgba(0,0,0,0.08); transition: left 0.3s cubic-bezier(0.4,0,0.2,1); }
            #sidebar.active { left: 0; }
            #sidebar.collapsed { width: 264px !important; }
            #sidebar.collapsed .logo-text, #sidebar.collapsed .nav-link span, #sidebar.collapsed .sidebar-toggle .fa-chevron-down, #sidebar.collapsed .submenu { display: initial !important; }
            #sidebarOverlay { position: fixed; top: 56px; left: 0; right: 0; bottom: 0; background: rgba(15,23,42,0.3); backdrop-filter: blur(4px); z-index: 1055; opacity: 0; pointer-events: none; transition: opacity 0.3s ease; }
            #sidebarOverlay.active { opacity: 1; pointer-events: auto; }
            #mainContent { margin-left: 0 !important; width: 100% !important; }
        }

        /* ===== DESKTOP ===== */
        @media (min-width: 992px) {
            #sidebar { height: calc(100vh - 56px) !important; }
            #mainContent { margin-left: 264px !important; width: calc(100% - 264px) !important; max-width: none !important; transition: margin-left 0.3s cubic-bezier(0.4,0,0.2,1), width 0.3s cubic-bezier(0.4,0,0.2,1); }
            #mainContent.sidebar-collapsed { margin-left: 64px !important; width: calc(100% - 64px) !important; }
        }
    </style>

    {{-- jQuery 3.6 + SweetAlert2 --}}
    <script src="/assets/js/bootstrep/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/bootstrep/sweetalert2@11.js"></script>

    {{-- Select2 + DataTables --}}
    <link href="/assets/css/select2.min.css" rel="stylesheet">
    <link href="/assets/css/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <script src="/assets/js/bootstrep/select2.min.js"></script>
    <link rel="stylesheet" href="/assets/css/jquery.dataTables.min.css">
    <script src="/assets/js/bootstrep/jquery.dataTables.min.js"></script>

    <script src="/assets/js/custom.js?v={{ filemtime(public_path('assets/js/custom.js')) }}"></script>

    @stack('css')
</head>
<body class="bg-slate-50 min-h-screen flex flex-col font-sans text-gray-900">

    {{-- LOW-F (G-313): Global investigation-mode banner. Renders only when
         INVESTIGATION mode is active ($isInvestigation shared by the
         CheckSystemPolicy middleware). Sticky-top, full-width, prominent red
         styling, a11y attributes (role="alert" + aria-live="assertive") so
         regular users see the mode change immediately on every page — not
         just on admin/compliance. The scoped <style> shifts the sticky
         top-nav + sidebars down by the banner's height (~36px) so they
         don't overlap. Uses :has() (modern browsers, 2023+); older browsers
         fall back to the banner overlaying the top-nav (banner still visible). --}}
    @if ($isInvestigation ?? false)
        <style>
            body:has(> .investigation-banner) > .sticky.top-0.z-50 {
                top: 36px !important;
            }
            @media (min-width: 992px) {
                body:has(> .investigation-banner) #sidebar {
                    top: 92px !important;
                    height: calc(100vh - 92px) !important;
                }
            }
            @media (max-width: 991.98px) {
                body:has(> .investigation-banner) #sidebar,
                body:has(> .investigation-banner) #sidebarOverlay {
                    top: 92px !important;
                    height: calc(100vh - 92px) !important;
                }
            }
        </style>
        <div role="alert" aria-live="assertive"
             class="investigation-banner sticky top-0 z-[1070] w-full bg-red-700 text-white py-2 px-4 text-center text-sm font-semibold shadow-md no-print">
            <i class="fas fa-triangle-exclamation me-2"></i>
            ⚠ INVESTIGATION MODE ACTIVE — All financial writes are blocked. Reads are clamped to current fiscal year. Contact your administrator.
        </div>
    @endif

    {{-- ==================== HEADER (unified top-nav component — shared with x-layouts.erp) ==================== --}}
    <x-erp.top-nav />

    {{-- ==================== MAIN LAYOUT ==================== --}}
    <div class="container-fluid flex-1">
        <div class="row">
            {{-- Sidebar — DB-driven menu with per-user permissions --}}
            <nav id="sidebar" class="sidebar col-lg-2 col-md-3" aria-label="Main navigation">
                <div class="sidebar-header">
                    <span class="logo">
                        <i class="fas fa-store"></i>
                        <span class="logo-text">Remote<span>Center</span></span>
                    </span>
                    <button type="button" id="sidebarCollapseBtn" title="Collapse sidebar" aria-label="Toggle sidebar">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                </div>
                <ul class="nav flex-column" id="sidebarMenu">
                    @php
                        $menuTree = app(\App\Services\MenuService::class)->getUserMenuTree(auth()->user());
                        $currentUri = '/' . request()->path();
                        $sectionMap = [
                            'Overview' => 'overview', 'Administration' => 'admin',
                            'Sales' => 'sales', 'Purchase' => 'purchase',
                            'Inventory' => 'inventory', 'Finance' => 'finance',
                            'Accounting' => 'accounting', 'Reports' => 'reports',
                            'System' => 'system',
                        ];
                        $isDashboardActive = $currentUri === '/admin/dashboard' || $currentUri === route('dashboard', [], false);
                    @endphp

                    {{-- Dashboard link — always first item above Overview --}}
                    <li class="nav-item" data-section="overview">
                        <a href="{{ route('dashboard') }}"
                           class="nav-link d-flex align-items-center {{ $isDashboardActive ? 'active' : '' }}">
                            <i class="fas fa-gauge-high"></i>
                            <span class="ms-2">Dashboard</span>
                        </a>
                    </li>

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
                            <li class="nav-item" data-section="{{ $sectionMap[$mainMenu['menu_name']] ?? 'system' }}">
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
                            <li class="nav-item" data-section="{{ $sectionMap[$mainMenu['menu_name']] ?? 'system' }}">
                                <a href="{{ $mainMenu['url'] }}" class="nav-link {{ $topLeafActive ? 'active' : '' }}">
                                    <i class="{{ $mainMenu['icon'] }}"></i>
                                    <span class="ms-2">{{ $mainMenu['menu_name'] }}</span>
                                </a>
                            </li>
                        @endif
                    @endforeach

                    {{-- System menus (always visible to admin/superadmin) --}}
                    @can('manage-system-policy')
                    <li class="nav-item" data-section="system">
                        <a href="{{ route('admin.compliance.index') }}" class="nav-link {{ request()->routeIs('admin.compliance.*') ? 'active' : '' }}">
                            <i class="fas fa-shield-halved"></i> <span class="ms-2">Compliance</span>
                        </a>
                    </li>
                    @endcan
                    <li class="nav-item" data-section="system">
                        <a href="{{ route('admin.archive.index') }}" class="nav-link {{ request()->routeIs('admin.archive.*') ? 'active' : '' }}">
                            <i class="fas fa-archive"></i> <span class="ms-2">Archive</span>
                        </a>
                    </li>
                </ul>
            </nav>

            {{-- Mobile sidebar overlay backdrop — click to close the drawer --}}
            <div id="sidebarOverlay" aria-hidden="true"></div>

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

    {{-- ==================== FOOTER REMOVED ====================
        The standalone amber footer bar has been removed. The floating
        "🧭 My Creative Code Guide" pill (Door 2 of the help system) —
        rendered by @include('partials.help-system') below — now serves
        as the single persistent bottom element. It opens the module
        browser (8 modules / 215 menus) AND carries the brand mark.
        No separate footer is needed. The body uses min-h-screen +
        flex-col + flex-1 on the content container, so removing this
        block leaves no layout gap (the floating pill is position:fixed).
    --}}

    {{-- ==================== SCRIPTS ==================== --}}
    <script src="/assets/js/bootstrep/bootstrap.bundle.min.js"></script>
    <script>
        window.CSRF_TOKEN = '{{ csrf_token() }}';
        // Toggle the sidebar: on mobile (<992) it slides in as a drawer with overlay;
        // on desktop (≥992) it collapses/expands.
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mainContent = document.getElementById('mainContent');
            if (!sidebar) return;

            if (window.innerWidth < 992) {
                // Mobile: toggle drawer open/close
                const isOpen = sidebar.classList.toggle('active');
                if (overlay) overlay.classList.toggle('active', isOpen);
                // Prevent body scroll when drawer is open
                document.body.style.overflow = isOpen ? 'hidden' : '';
            } else {
                // Desktop: toggle collapsed state
                const collapsed = sidebar.classList.toggle('collapsed');
                if (mainContent) mainContent.classList.toggle('sidebar-collapsed', collapsed);
                try { localStorage.setItem('rcerp_sidebar_collapsed', collapsed); } catch(e) {}
            }
        }

        // ── Sidebar collapse/expand (desktop) ──
        (function() {
            var COLLAPSE_KEY = 'rcerp_sidebar_collapsed';
            var sidebar = document.getElementById('sidebar');
            var mainContent = document.getElementById('mainContent');
            var collapseBtn = document.getElementById('sidebarCollapseBtn');

            // Restore saved state
            var isCollapsed = false;
            try { isCollapsed = localStorage.getItem(COLLAPSE_KEY) === 'true'; } catch(e) {}
            if (isCollapsed && sidebar && window.innerWidth >= 992) {
                sidebar.classList.add('collapsed');
                if (mainContent) mainContent.classList.add('sidebar-collapsed');
            }

            // Collapse toggle button click
            if (collapseBtn) {
                collapseBtn.addEventListener('click', function() {
                    if (window.innerWidth < 992) return; // mobile uses drawer, not collapse
                    var collapsed = sidebar.classList.toggle('collapsed');
                    if (mainContent) mainContent.classList.toggle('sidebar-collapsed', collapsed);
                    try { localStorage.setItem(COLLAPSE_KEY, collapsed); } catch(e) {}
                });
            }

            // On window resize, if going from mobile to desktop, remove collapsed if drawer was open
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 992 && sidebar) {
                    // Restore collapse state on desktop
                    var savedCollapsed = false;
                    try { savedCollapsed = localStorage.getItem(COLLAPSE_KEY) === 'true'; } catch(e) {}
                    sidebar.classList.toggle('collapsed', savedCollapsed);
                    if (mainContent) mainContent.classList.toggle('sidebar-collapsed', savedCollapsed);
                }
            });
        })();
        // Close the drawer when the overlay backdrop is clicked.
        document.addEventListener('DOMContentLoaded', function() {
            const overlay = document.getElementById('sidebarOverlay');
            if (overlay) {
                overlay.addEventListener('click', function() {
                    const sidebar = document.getElementById('sidebar');
                    if (sidebar) sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                });
            }
            // Close the drawer on ESC.
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const sidebar = document.getElementById('sidebar');
                    if (sidebar && sidebar.classList.contains('active')) {
                        sidebar.classList.remove('active');
                        const ov = document.getElementById('sidebarOverlay');
                        if (ov) ov.classList.remove('active');
                        document.body.style.overflow = '';
                    }
                }
            });
        });

        // Sidebar submenu toggle — self-contained .is-open class (no Bootstrap .collapse/.show).
        (function() {
            var STORAGE_KEY = 'rcerp_sidebar_expanded_v2';
            try { localStorage.removeItem('rcerp_sidebar_expanded'); } catch(e) {}

            $(document).ready(function() {
                var saved = {};
                try { saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch(e) {}

                $('.sidebar-toggle').each(function() {
                    var targetSel = $(this).data('target');
                    if (!targetSel) return;
                    var $target = $(targetSel);
                    var targetId = targetSel.replace('#', '');

                    if ($target.hasClass('is-open')) {
                        $(this).attr('aria-expanded', 'true');
                        return;
                    }
                    if (saved[targetId] === true) {
                        $target.addClass('is-open');
                        $(this).attr('aria-expanded', 'true');
                    }
                });

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

                    try {
                        var saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
                        saved[targetId] = !isExpanded;
                        localStorage.setItem(STORAGE_KEY, JSON.stringify(saved));
                    } catch(err) {}
                });

                $('#sidebarMenu a.nav-link').not('.sidebar-toggle').on('click', function() {
                    if (window.innerWidth < 992) {
                        var sidebar = document.getElementById('sidebar');
                        var overlay = document.getElementById('sidebarOverlay');
                        if (sidebar) sidebar.classList.remove('active');
                        if (overlay) overlay.classList.remove('active');
                        document.body.style.overflow = '';
                    }
                });
            });
        })();
    </script>
    @stack('scripts')

    {{-- ============================================================ --}}
    {{-- Help System — Menu & Module Helper (Phase 2 scaffold).       --}}
    {{-- One include pulls in: floating help button, footer pill,     --}}
    {{-- right offcanvas, module offcanvas, bottom-up module sheet,   --}}
    {{-- scoped CSS + vanilla JS. Auth-gated (login pages render nil).--}}
    {{-- See docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md                 --}}
    {{-- ============================================================ --}}
    @include('partials.help-system')
</body>
</html>
