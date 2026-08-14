{{--
  x-layouts.erp — sales-module layout shell (Phase 5 + sidebar integration).

  Provides the showcase design: sticky two-row nav (brand + role badge +
  branch switcher + notification bell, optional tab strip), DB-driven sidebar
  (ported from layouts/admin.blade.php so every sales page has the navigation
  menu + top bar), flash messages, content slot, and a sticky amber-900 footer.

  CRITICAL: This file lives at resources/views/components/layouts/erp.blade.php
  (NOT resources/views/layouts/) because Laravel only auto-discovers anonymous
  Blade components from resources/views/components/. The <x-layouts.erp> tag
  maps to components/layouts/erp.blade.php.

  Usage:
    <x-layouts.erp :title="$title">
        ... page content ...
    </x-layouts.erp>

    <x-layouts.erp :title="$title" :tabs="[
        ['label' => 'Dashboard', 'href' => route('dashboard'), 'active' => true],
        ['label' => 'Invoices',  'href' => route('admin.sales-invoices.index')],
    ]">
        ... page content ...
    </x-layouts.erp>

  Coexists with layouts/admin.blade.php (Bootstrap) — opt-in per view.
  Loads Bootstrap + custom.css + rc-erp.css so Tailwind utilities work.
  NOTE: do NOT nest Blade comment markers inside this doc-block.
--}}
@props([
    'title' => 'Sales',
    'tabs' => null,
    'hero' => false,
])

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} — {{ $title }}</title>

    @stack('head_meta')

    {{-- Same stylesheets as layouts/admin.blade.php so Tailwind utilities load --}}
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/all.min.css">
    <link href="/assets/css/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/custom.css?v={{ filemtime(public_path('assets/css/custom.css')) }}">
    <link rel="stylesheet" href="/assets/css/footer-dropup.css">

    {{-- RC ERP design-system (Tailwind v4, no preflight — coexists with Bootstrap).
        filemtime() cache-busting ensures browsers always fetch the latest
        rc-erp.css after a rebuild+pull (prevents stale cached CSS). --}}
    <link rel="stylesheet" href="/assets/css/rc-erp.css?v={{ filemtime(public_path('assets/css/rc-erp.css')) }}">

    {{-- Sidebar toggle: chevron rotation when expanded --}}
    {{-- Sidebar modernization: dark slate-900 background, amber active accent,
         clean spacing/typography. Scoped to #sidebar so it only affects this
         layout (not the legacy layouts/admin.blade.php). Overrides the legacy
         light-gray #f7f7f7 from custom.css. --}}
    <style>
        .sidebar-toggle[aria-expanded="true"] .fa-chevron-down { transform: rotate(180deg); transition: transform 0.2s ease; }
        .sidebar-toggle .fa-chevron-down { transition: transform 0.2s ease; }

        /* ===== SIDEBAR — Crisp dark with indigo gradient ===== */
        #sidebar {
            background: linear-gradient(180deg, #1e1b4b 0%, #1e2759 50%, #0f172a 100%) !important;
            color: #e2e8f0;
            border-right: 1px solid rgba(148,163,184,0.12);
            width: 260px;
        }
        #sidebar .sidebar-header {
            padding: 14px 18px;
            border-bottom: 1px solid rgba(148,163,184,0.12);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        #sidebar .sidebar-header .logo {
            font-size: 1.15rem; font-weight: 800; letter-spacing: 0.03em;
            display: inline-flex; align-items: center; gap: 8px;
            white-space: nowrap; overflow: hidden;
            color: #f1f5f9;
        }
        #sidebar .sidebar-header .logo i { color: #f59e0b; font-size: 1rem; }
        #sidebar .sidebar-header .logo .logo-text { color: #f1f5f9; }
        #sidebar .nav-link {
            color: #cbd5e1; border-radius: 8px; padding: 8px 14px; margin: 2px 8px;
            font-size: 0.82rem; font-weight: 500; transition: background 0.15s ease, color 0.15s ease;
            border-left: 3px solid transparent;
        }
        #sidebar .nav-link:hover { background: rgba(255,255,255,0.08); color: #fff; }
        #sidebar .nav-link.active {
            background: rgba(245,158,11,0.15) !important; color: #fbbf24 !important;
            font-weight: 600; border-left-color: #f59e0b;
        }
        #sidebar .nav-link.active i, #sidebar .nav-link.active .fas { color: #fbbf24 !important; }
        #sidebar .nav-link i { width: 18px; text-align: center; font-size: 0.9rem; }

        /* ── Color-coded section icons ── */
        #sidebar .nav-item[data-section="overview"] > .nav-link i { color: #34d399; }
        #sidebar .nav-item[data-section="admin"] > .nav-link i { color: #a78bfa; }
        #sidebar .nav-item[data-section="sales"] > .nav-link i { color: #fbbf24; }
        #sidebar .nav-item[data-section="purchase"] > .nav-link i { color: #22d3ee; }
        #sidebar .nav-item[data-section="inventory"] > .nav-link i { color: #4ade80; }
        #sidebar .nav-item[data-section="finance"] > .nav-link i { color: #f472b6; }
        #sidebar .nav-item[data-section="accounting"] > .nav-link i { color: #818cf8; }
        #sidebar .nav-item[data-section="reports"] > .nav-link i { color: #fb923c; }
        #sidebar .nav-item[data-section="system"] > .nav-link i { color: #94a3b8; }

        #sidebar .submenu { border-left: 2px solid rgba(148,163,184,0.12); margin-left: 22px; }
        #sidebar .submenu .nav-link { font-size: 0.78rem; padding-left: 18px; }
        #sidebar .submenu:not(.is-open) { display: none; }
        #sidebar .submenu.is-open { display: block; }

        /* ===== MOBILE DRAWER (< lg / 991.98px) ===== */
        @media (max-width: 991.98px) {
            #sidebar {
                position: fixed !important;
                top: 55px;                                /* below sticky top-nav */
                left: -280px;                            /* off-screen left */
                bottom: 0;
                height: calc(100vh - 55px) !important;
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
                top: 55px;                               /* below top-nav */
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
                position: fixed !important;
                top: 55px;                                /* below sticky top-nav */
                left: 0;
                bottom: 0;
                height: calc(100vh - 55px) !important;
                width: 260px;
                z-index: 40;                             /* below top-nav z-50 */
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

    {{-- jQuery 3.6 + SweetAlert2 (same as admin layout, for compatibility) --}}
    <script src="/assets/js/bootstrep/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/bootstrep/sweetalert2@11.js"></script>

    {{-- Select2 + DataTables (same as admin layout, for any sales views that use them) --}}
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

    {{-- ==================== STICKY NAV (unified top-nav component — shared with layouts.admin) ==================== --}}
    <x-erp.top-nav :tabs="$tabs" />

    {{-- ==================== MAIN LAYOUT: SIDEBAR + CONTENT ==================== --}}
    <div class="container-fluid flex-1">
        <div class="row">

            {{-- Sidebar — DB-driven menu with per-user permissions (ported from layouts/admin.blade.php).
                 Modernized to dark slate-900 with amber active accent. On mobile
                 (< lg) it renders as a slide-in drawer with an overlay backdrop. --}}
            <nav id="sidebar" class="sidebar col-lg-2 col-md-3" aria-label="Main navigation">
                <div class="sidebar-header">
                    <span class="logo">
                        <i class="fas fa-store"></i>
                        <span class="logo-text">Remote Center</span>
                    </span>
                </div>
                <ul class="nav flex-column" id="sidebarMenu">
                    @php
                        $menuTree = app(\App\Services\MenuService::class)->getUserMenuTree(auth()->user());
                        $currentUri = '/' . request()->path();
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
            <main class="col-lg-10 col-md-9 ms-sm-auto px-3 px-md-4 {{ $hero ? 'pt-3 pb-6' : 'py-4' }}" id="mainContent">

                {{-- Flash messages --}}
                @if (session('success'))
                    <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-lg px-4 py-2 flex items-center gap-2 no-print mb-3">
                        <x-erp.icon name="check-circle" class="size-4 text-green-500" />
                        {{ session('success') }}
                    </div>
                @endif
                @if (session('error'))
                    <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-lg px-4 py-2 flex items-center gap-2 no-print mb-3">
                        <x-erp.icon name="x-circle" class="size-4 text-red-500" />
                        {{ session('error') }}
                    </div>
                @endif
                @if (session('warning'))
                    <div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg px-4 py-2 flex items-center gap-2 no-print mb-3">
                        <x-erp.icon name="alert-triangle" class="size-4 text-amber-500" />
                        {{ session('warning') }}
                    </div>
                @endif

                {{-- Page title (bilingual if provided). Suppressed when $hero=true
                     because the page provides its own gradient hero header
                     (e.g. the godown page). This eliminates the redundant
                     plain-text H1 that previously sat above the hero card,
                     causing a visible "big gap". --}}
                @isset($title)
                    @unless($hero)
                        <h1 class="text-xl font-bold text-amber-900 mb-4">{{ $title }}</h1>
                    @endunless
                @endisset

                {{ $slot }}
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
                    }
                });
            });
        })();
    </script>
    @stack('scripts')

    {{-- Help System — Menu & Module Helper (Phase 2 scaffold). --}}
    {{-- One include pulls in: floating help button, footer pill, --}}
    {{-- right offcanvas, module offcanvas, bottom-up module sheet, --}}
    {{-- scoped CSS + vanilla JS. Auth-gated (login pages render nil).--}}
    {{-- See docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md                 --}}
    @include('partials.help-system')
</body>
</html>
