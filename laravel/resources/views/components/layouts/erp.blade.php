{{--
  x-layouts.erp — sales-module layout shell (Phase 5).

  The rebuilt sales views (Phase 6+) extend this instead of layouts.admin.
  It provides the showcase design: sticky two-row nav (brand + role badge +
  branch switcher + notification bell, optional tab strip), flash messages,
  content slot, and a sticky amber-900 footer.

  CRITICAL: This file lives at resources/views/components/layouts/erp.blade.php
  (NOT resources/views/layouts/) because Laravel only auto-discovers anonymous
  Blade components from resources/views/components/. The <x-layouts.erp> tag
  maps to components/layouts/erp.blade.php.

  Usage:
    <x-layouts.erp :title="$title">
        {{-- page content --}}
    </x-layouts.erp>

    <x-layouts.erp :title="$title" :tabs="[
        ['label' => 'Dashboard', 'href' => route('dashboard'), 'active' => true],
        ['label' => 'Invoices',  'href' => route('admin.sales-invoices.index')],
    ]">
        {{-- page content --}}
    </x-layouts.erp>

  Coexists with layouts/admin.blade.php (Bootstrap) — opt-in per view.
  Loads Bootstrap + custom.css + rc-erp.css so Tailwind utilities work.
--}}
@props([
    'title' => 'Sales',
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
    <link rel="stylesheet" href="/assets/css/custom.css">
    <link rel="stylesheet" href="/assets/css/footer-dropup.css">

    {{-- RC ERP design-system (Tailwind v4, no preflight — coexists with Bootstrap) --}}
    <link rel="stylesheet" href="/assets/css/rc-erp.css">

    {{-- jQuery 3.6 + SweetAlert2 (same as admin layout, for compatibility) --}}
    <script src="/assets/js/bootstrep/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/bootstrep/sweetalert2@11.js"></script>

    {{-- Select2 + DataTables (same as admin layout, for any sales views that use them) --}}
    <link href="/assets/css/select2.min.css" rel="stylesheet">
    <script src="/assets/js/bootstrep/select2.min.js"></script>
    <link rel="stylesheet" href="/assets/css/jquery.dataTables.min.css">
    <script src="/assets/js/bootstrep/jquery.dataTables.min.js"></script>

    <script src="/assets/js/custom.js"></script>

    @stack('css')
</head>
<body class="bg-gradient-to-b from-amber-50/30 to-white min-h-screen flex flex-col font-sans text-gray-900">

    {{-- ==================== STICKY NAV (no-print) ==================== --}}
    <div class="sticky top-0 z-50 bg-white/90 backdrop-blur-md border-b border-amber-200 shadow-sm no-print">
        <div class="max-w-7xl mx-auto px-4 py-2">

            {{-- Row 1: brand + role + branch switcher + notification bell --}}
            <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
                <div class="flex items-center gap-3">
                    <div class="bg-gradient-to-r from-amber-500 to-orange-500 rounded-lg px-3 py-1.5 text-white font-bold text-sm shadow">RC ERP</div>
                    <span class="text-xs text-amber-700 font-medium hidden sm:inline">আর সি বণিক — Sales</span>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    {{-- Role badge (read-only — real role switching = re-login) --}}
                    <span class="inline-flex items-center gap-1 font-medium text-xs rounded-full px-2.5 py-1 border {{ $roleCfg['classes'] }}">
                        <x-erp.icon name="users" class="size-3" />
                        {{ $roleCfg['label'] }}@if ($roleCfg['label_bn']) / {{ $roleCfg['label_bn'] }}@endif
                    </span>

                    {{-- Branch switcher (admin/manager only) --}}
                    @if ($canSwitchBranch && $branches->isNotEmpty())
                        <form method="POST" action="{{ route('branch.switch') }}" class="inline-flex items-center gap-1 bg-amber-50/60 border border-amber-200 rounded-full px-2.5 py-1">
                            @csrf
                            <x-erp.icon name="map-pin" class="size-3 text-amber-600" />
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
                            <x-erp.icon name="map-pin" class="size-3 text-amber-600" />
                            {{ session('branch_name', 'No Branch') }}
                        </span>
                    @endif

                    {{-- Notification bell (links to notification rules page; Phase 10 wires unread count) --}}
                    @can('view-notification-rules')
                        <a href="{{ route('admin.notifications.rules') }}" class="relative inline-flex items-center justify-center size-8 rounded-full bg-amber-50/60 border border-amber-200 text-amber-700 hover:bg-amber-100 transition-colors" title="Notifications">
                            <x-erp.icon name="bell" class="size-4" />
                        </a>
                    @endcan

                    {{-- User menu (logout) --}}
                    <div class="dropdown">
                        <button type="button" class="inline-flex items-center gap-1.5 bg-amber-50/60 border border-amber-200 rounded-full px-2.5 py-1 text-xs font-medium text-amber-900 hover:bg-amber-100 transition-colors" data-bs-toggle="dropdown" aria-expanded="false">
                            <x-erp.icon name="users" class="size-3" />
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

    {{-- ==================== FLASH MESSAGES ==================== --}}
    @if (session('success'))
        <div class="max-w-7xl mx-auto px-4 mt-3">
            <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-lg px-4 py-2 flex items-center gap-2 no-print">
                <x-erp.icon name="check-circle" class="size-4 text-green-500" />
                {{ session('success') }}
            </div>
        </div>
    @endif
    @if (session('error'))
        <div class="max-w-7xl mx-auto px-4 mt-3">
            <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-lg px-4 py-2 flex items-center gap-2 no-print">
                <x-erp.icon name="x-circle" class="size-4 text-red-500" />
                {{ session('error') }}
            </div>
        </div>
    @endif
    @if (session('warning'))
        <div class="max-w-7xl mx-auto px-4 mt-3">
            <div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg px-4 py-2 flex items-center gap-2 no-print">
                <x-erp.icon name="alert-triangle" class="size-4 text-amber-500" />
                {{ session('warning') }}
            </div>
        </div>
    @endif

    {{-- ==================== MAIN CONTENT ==================== --}}
    <main class="flex-1 w-full max-w-7xl mx-auto px-4 py-6">
        {{-- Page title (bilingual if provided) --}}
        @isset($title)
            <h1 class="text-xl font-bold text-amber-900 mb-4">{{ $title }}</h1>
        @endisset

        {{ $slot }}
    </main>

    {{-- ==================== STICKY FOOTER (no-print) ==================== --}}
    <footer class="no-print shrink-0 bg-amber-900 text-amber-100 py-3 text-center text-xs">
        RC ERP / আর সি বণিক — Warehouse Distribution System © {{ date('Y') }}
    </footer>

    {{-- ==================== SCRIPTS ==================== --}}
    <script src="/assets/js/bootstrep/bootstrap.bundle.min.js"></script>
    <script>
        window.CSRF_TOKEN = '{{ csrf_token() }}';
    </script>
    @stack('scripts')
</body>
</html>
