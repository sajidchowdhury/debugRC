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

    {{-- Sidebar toggle: chevron rotation when expanded --}}
    {{-- Sidebar modernization: dark slate-900 background, amber active accent,
         clean spacing/typography. Scoped to #sidebar so it overrides the legacy
         light-gray #f7f7f7 from custom.css. On mobile (<lg) the sidebar becomes
         a fixed slide-in drawer with an overlay backdrop (matching the Z.ai
         preview / x-layouts.erp design). Ported from components/layouts/erp.blade.php
         so EVERY page that extends layouts.admin gets the same premium sidebar. --}}
    <style>
        .sidebar-toggle[aria-expanded="true"] .fa-chevron-down {
            transform: rotate(180deg);
            transition: transform 0.2s ease;
        }
        .sidebar-toggle .fa-chevron-down {
            transition: transform 0.2s ease;
        }

        /* ===== MODERN SIDEBAR (dark slate, matching the design preview) ===== */
        #sidebar {
            background: #0f172a !important;            /* slate-900 */
            color: #cbd5e1;                              /* slate-300 */
            border-right: 1px solid #1e293b;             /* slate-800 */
            width: 260px;
            position: fixed !important;                  /* win over .sidebar in custom.css */
            top: 56px;                                   /* below sticky top-nav (always) */
            left: 0;
            z-index: 40 !important;                      /* below top-nav z-50, above content */
            overflow-y: auto;
        }
        #sidebar .sidebar-header {
            padding: 14px 18px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            margin-bottom: 8px;
        }
        #sidebar .sidebar-header .logo {
            color: #f8fafc;                              /* slate-50 */
            font-size: 1.05rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        #sidebar .sidebar-header .logo i {
            color: #f59e0b;                              /* amber-500 */
            font-size: 1.1rem;
        }
        #sidebar .nav-link {
            color: #94a3b8;                              /* slate-400 */
            border-radius: 8px;
            padding: 8px 14px;
            margin: 2px 8px;
            font-size: 0.8125rem;
            font-weight: 500;
            transition: background 0.15s ease, color 0.15s ease;
            border-left: 3px solid transparent;
        }
        #sidebar .nav-link:hover {
            background: rgba(255,255,255,0.06);
            color: #f1f5f9;                              /* slate-100 */
        }
        #sidebar .nav-link.active {
            background: rgba(245,158,11,0.10) !important; /* amber tint */
            color: #fbbf24 !important;                   /* amber-400 */
            font-weight: 600;
            border-left-color: #f59e0b;                  /* amber-500 */
        }
        #sidebar .nav-link.active i,
        #sidebar .nav-link.active .fas {
            color: #fbbf24 !important;                   /* amber-400 */
        }
        #sidebar .nav-link i {
            width: 18px;
            text-align: center;
            font-size: 0.85rem;
        }
        #sidebar .submenu {
            border-left: 1px solid rgba(255,255,255,0.06);
            margin-left: 22px;
        }
        #sidebar .submenu .nav-link {
            font-size: 0.78rem;
            padding-left: 18px;
        }
        /* Submenu show/hide via .is-open (no Bootstrap .collapse) */
        #sidebar .submenu:not(.is-open) {
            display: none;
        }
        #sidebar .submenu.is-open {
            display: block;
        }

        /* ===== MOBILE DRAWER (< lg / 991.98px) ===== */
        @media (max-width: 991.98px) {
            #sidebar {
                position: fixed !important;
                top: 56px;                                /* below sticky top-nav */
                left: -280px;                            /* off-screen left */
                bottom: 0;
                height: calc(100vh - 56px) !important;
                width: 260px;
                z-index: 1060;
                transition: left 0.3s ease;
                overflow-y: auto;
                box-shadow: 0 0 40px rgba(0,0,0,0.3);
            }
            #sidebar.active {
                left: 0;                                 /* slide in */
            }
            #sidebarOverlay {
                position: fixed;
                top: 56px;                               /* below top-nav */
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(15,23,42,0.55);        /* slate-900/55 */
                z-index: 1055;
                opacity: 0;
                pointer-events: none;
                transition: opacity 0.3s ease;
            }
            #sidebarOverlay.active {
                opacity: 1;
                pointer-events: auto;
            }
            /* Main content full-width on mobile (sidebar is a drawer) */
            #mainContent {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }

        /* ===== DESKTOP (>= lg / 992px) — fixed sidebar + offset main ===== */
        @media (min-width: 992px) {
            #sidebar {
                height: calc(100vh - 56px) !important;
            }
            /* Offset main content to account for the fixed 260px sidebar.
               Overrides Bootstrap col-lg-10 + ms-auto which misaligns with
               a fixed-width sidebar. */
            #mainContent {
                margin-left: 260px !important;
                width: calc(100% - 260px) !important;
                max-width: none !important;
            }
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
<body class="bg-gradient-to-b from-amber-50/30 to-white min-h-screen flex flex-col font-sans text-gray-900">

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
        // Toggle the mobile sidebar drawer + overlay backdrop. On desktop
        // (lg+) the sidebar is always visible in normal flow — this is a
        // no-op. On mobile (<lg) the sidebar is a fixed slide-in drawer.
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (!sidebar) return;
            sidebar.classList.toggle('active');
            if (overlay) overlay.classList.toggle('active');
        }
        // Close the drawer when the overlay backdrop is clicked.
        document.addEventListener('DOMContentLoaded', function() {
            const overlay = document.getElementById('sidebarOverlay');
            if (overlay) {
                overlay.addEventListener('click', function() {
                    const sidebar = document.getElementById('sidebar');
                    if (sidebar) sidebar.classList.remove('active');
                    overlay.classList.remove('active');
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
