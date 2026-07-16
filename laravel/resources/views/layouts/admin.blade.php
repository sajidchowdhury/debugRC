<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} — {{ $title ?? 'Dashboard' }}</title>

    {{-- Bootstrap 5.3.3 (same CDN as legacy) --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

    {{-- Legacy shared assets (served by Nginx from /assets/) --}}
    <link rel="stylesheet" href="/assets/css/custom.css">
    <link rel="stylesheet" href="/assets/css/footer-dropup.css">

    {{-- jQuery 3.6 + SweetAlert2 (same as legacy) --}}
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    {{-- Select2 + DataTables (same as legacy) --}}
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>

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
            {{-- Sidebar --}}
            <nav id="sidebar" class="sidebar col-lg-2 col-md-3 d-none d-lg-block">
                <div class="sidebar-header">
                    <span class="logo">
                        <i class="fas fa-store"></i>
                        <span class="logo-text">RC ERP</span>
                    </span>
                </div>
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.products.index') }}" class="nav-link {{ request()->routeIs('admin.products.*') ? 'active' : '' }}">
                            <i class="fas fa-boxes-stacked"></i> Products
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.customers.index') }}" class="nav-link {{ request()->routeIs('admin.customers.*') ? 'active' : '' }}">
                            <i class="fas fa-users"></i> Customers
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.suppliers.index') }}" class="nav-link {{ request()->routeIs('admin.suppliers.*') ? 'active' : '' }}">
                            <i class="fas fa-truck"></i> Suppliers
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.employees.index') }}" class="nav-link {{ request()->routeIs('admin.employees.*') ? 'active' : '' }}">
                            <i class="fas fa-id-badge"></i> Employees
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.banks.index') }}" class="nav-link {{ request()->routeIs('admin.banks.*') ? 'active' : '' }}">
                            <i class="fas fa-university"></i> Banks
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.ledgers.index') }}" class="nav-link {{ request()->routeIs('admin.ledgers.*') ? 'active' : '' }}">
                            <i class="fas fa-book"></i> Ledgers
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.branches.index') }}" class="nav-link {{ request()->routeIs('admin.branches.*') ? 'active' : '' }}">
                            <i class="fas fa-sitemap"></i> Branches
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.warehouses.index') }}" class="nav-link {{ request()->routeIs('admin.warehouses.*') ? 'active' : '' }}">
                            <i class="fas fa-warehouse"></i> Warehouses
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.reports.index') }}" class="nav-link {{ request()->routeIs('admin.reports.*') ? 'active' : '' }}">
                            <i class="fas fa-chart-bar"></i> Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.reconciliation.index') }}" class="nav-link {{ request()->routeIs('admin.reconciliation.*') ? 'active' : '' }}">
                            <i class="fas fa-scale-balanced"></i> Reconciliation
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.stock.transactions') }}" class="nav-link {{ request()->routeIs('admin.stock.*') ? 'active' : '' }}">
                            <i class="fas fa-boxes-stacked"></i> Stock
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.stock-adjustments.index') }}" class="nav-link {{ request()->routeIs('admin.stock-adjustments.*') ? 'active' : '' }}">
                            <i class="fas fa-sliders"></i> Adjustments
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.stock-take.index') }}" class="nav-link {{ request()->routeIs('admin.stock-take.*') ? 'active' : '' }}">
                            <i class="fas fa-clipboard-check"></i> Stock Take
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.warehouse-transfers.index') }}" class="nav-link {{ request()->routeIs('admin.warehouse-transfers.*') ? 'active' : '' }}">
                            <i class="fas fa-right-left"></i> Transfers
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.damages.index') }}" class="nav-link {{ request()->routeIs('admin.damages.*') ? 'active' : '' }}">
                            <i class="fas fa-triangle-exclamation"></i> Damages
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.purchase-orders.index') }}" class="nav-link {{ request()->routeIs('admin.purchase-orders.*') ? 'active' : '' }}">
                            <i class="fas fa-shopping-cart"></i> Purchase Orders
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.purchase-receives.index') }}" class="nav-link {{ request()->routeIs('admin.purchase-receives.*') ? 'active' : '' }}">
                            <i class="fas fa-truck-ramp-box"></i> GRN
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.purchase-returns.index') }}" class="nav-link {{ request()->routeIs('admin.purchase-returns.*') ? 'active' : '' }}">
                            <i class="fas fa-rotate-left"></i> P. Returns
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.sales.cart') }}" class="nav-link {{ request()->routeIs('admin.sales.cart') ? 'active' : '' }}">
                            <i class="fas fa-cart-shopping"></i> Sales Cart
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.sales-invoices.index') }}" class="nav-link {{ request()->routeIs('admin.sales-invoices.*') ? 'active' : '' }}">
                            <i class="fas fa-file-invoice-dollar"></i> Invoices
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.sales-challans.index') }}" class="nav-link {{ request()->routeIs('admin.sales-challans.*') ? 'active' : '' }}">
                            <i class="fas fa-truck"></i> Challans
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.customer-payments.index') }}" class="nav-link {{ request()->routeIs('admin.customer-payments.*') ? 'active' : '' }}">
                            <i class="fas fa-hand-holding-dollar"></i> Payments
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.sales-returns.index') }}" class="nav-link {{ request()->routeIs('admin.sales-returns.*') ? 'active' : '' }}">
                            <i class="fas fa-rotate-left"></i> S. Returns
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('admin.accounting.period-close') }}" class="nav-link {{ request()->routeIs('admin.accounting.*') ? 'active' : '' }}">
                            <i class="fas fa-lock"></i> Period Close
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.CSRF_TOKEN = '{{ csrf_token() }}';
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) sidebar.classList.toggle('d-none');
        }
    </script>
    @stack('scripts')
</body>
</html>
