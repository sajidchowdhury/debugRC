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

    {{-- Shared legacy styles (served by Nginx from /assets/) --}}
    <link rel="stylesheet" href="/assets/css/custom.css">
    <link rel="stylesheet" href="/assets/css/footer-dropup.css">

    {{-- RC ERP design-system (Tailwind v4, no preflight — coexists with Bootstrap).
        Additive utilities + class-scoped custom rules; zero impact on existing
        Bootstrap pages. Build: `npm run build:css` (or `npm run dev:css` for watch). --}}
    <link rel="stylesheet" href="/assets/css/rc-erp.css">

    {{-- jQuery 3.6 + SweetAlert2 --}}
    <script src="/assets/js/bootstrep/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/bootstrep/sweetalert2@11.js"></script>

    {{-- Select2 + DataTables --}}
    <link href="/assets/css/select2.min.css" rel="stylesheet">
    <script src="/assets/js/bootstrep/select2.min.js"></script>
    <link rel="stylesheet" href="/assets/css/jquery.dataTables.min.css">
    <script src="/assets/js/bootstrep/jquery.dataTables.min.js"></script>

    <script src="/assets/js/custom.js"></script>

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
                                <a href="#" class="nav-link d-flex align-items-center {{ $isActive ? 'active' : '' }}"
                                   data-bs-toggle="collapse" data-bs-target="#menu-{{ $mainMenu['id'] }}">
                                    <i class="{{ $mainMenu['icon'] }}"></i>
                                    <span class="ms-2">{{ $mainMenu['menu_name'] }}</span>
                                    <i class="fas fa-chevron-down ms-auto small"></i>
                                </a>
                                <ul class="nav flex-column ms-3 collapse {{ $isActive ? 'show' : '' }}" id="menu-{{ $mainMenu['id'] }}">
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
                                                <a href="#" class="nav-link small d-flex align-items-center {{ $childActive ? 'active' : '' }}"
                                                   data-bs-toggle="collapse" data-bs-target="#submenu-{{ $child['id'] }}">
                                                    <i class="{{ $child['icon'] }}"></i>
                                                    <span class="ms-2">{{ $child['menu_name'] }}</span>
                                                </a>
                                                <ul class="nav flex-column ms-3 collapse {{ $childActive ? 'show' : '' }}" id="submenu-{{ $child['id'] }}">
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
    <script>
        window.CSRF_TOKEN = '{{ csrf_token() }}';
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) sidebar.classList.toggle('d-none');
        }

        // ─── Sidebar collapse state persistence ───────────────────────────
        // Saves which parent menus are expanded/collapsed to localStorage,
        // so the sidebar remembers the user's manual toggles across page
        // navigations (not just the server-side $isActive detection).
        (function() {
            var STORAGE_KEY = 'rcerp_sidebar_expanded';

            // Restore: on page load, apply saved expand/collapse state.
            // Server-side $isActive already added 'show' to active parents;
            // we only ADD more 'show' for parents the user manually expanded
            // on a previous page (we never remove 'show' that $isActive set).
            document.addEventListener('DOMContentLoaded', function() {
                var saved = {};
                try { saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch(e) {}
                document.querySelectorAll('#sidebarMenu .collapse').forEach(function(el) {
                    var id = el.id;
                    if (id && saved[id] === true && !el.classList.contains('show')) {
                        el.classList.add('show');
                    }
                    // Also mark the trigger link as active-style when expanded
                    if (id && el.classList.contains('show')) {
                        var trigger = document.querySelector('[data-bs-target="#' + id + '"]');
                        if (trigger) trigger.setAttribute('aria-expanded', 'true');
                    }
                });
            });

            // Save: when a collapse is toggled, record its new state.
            document.addEventListener('shown.bs.collapse', function(e) {
                if (!e.target.id || !e.target.closest('#sidebarMenu')) return;
                try {
                    var saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
                    saved[e.target.id] = true;
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(saved));
                } catch(err) {}
            });
            document.addEventListener('hidden.bs.collapse', function(e) {
                if (!e.target.id || !e.target.closest('#sidebarMenu')) return;
                try {
                    var saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
                    saved[e.target.id] = false;
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(saved));
                } catch(err) {}
            });
        })();
    </script>
    @stack('scripts')
</body>
</html>
