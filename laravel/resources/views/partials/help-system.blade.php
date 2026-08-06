@php
/**
 * Help System Partial — single include that pulls in every help component + assets.
 *
 * Add ONE line to any layout to enable the help system:
 *   @include('partials.help-system')
 *
 * Components are gated on auth() — login/forgot/reset pages render nothing.
 *
 * Phase 2 scaffold: visible buttons + offcanvas shells. Phase 4/5 wire the
 * fetch interactions; Phase 8 polishes the visuals.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §7
 */
use Illuminate\Support\Facades\Route;
use App\Services\Help\HelpService;

$menuKey = null;
$helpService = app(HelpService::class);

// Cache-bust version for the help assets (mirrors the pattern in layouts/admin.blade.php).
$helpCssPath = public_path('assets/css/help-system.css');
$helpJsPath  = public_path('assets/js/help.js');
$helpCssVer = is_file($helpCssPath) ? filemtime($helpCssPath) : '1';
$helpJsVer  = is_file($helpJsPath) ? filemtime($helpJsPath) : '1';
@endphp

@if(auth()->check())
    {{-- Resolve the current route's menu key (Phase 3 adds controller@action fallback) --}}
    @php
        $currentRoute = Route::currentRouteName();
        $menuKey = $helpService->menuKeyForRoute($currentRoute);
    @endphp

    {{-- Door 1: floating help button --}}
    <x-help.help-button :menu-key="$menuKey" />

    {{-- Door 2: fixed footer pill --}}
    <x-help.guide-footer />

    {{-- Shared right offcanvas (menu content) --}}
    <x-help.help-offcanvas />

    {{-- Module offcanvas (loaded from footer → module sheet) --}}
    <x-help.module-offcanvas />

    {{-- Bottom-up module sheet (the 8 colourful cards) --}}
    <x-help.module-sheet :help-service="$helpService" />

    {{-- Assets (cache-busted via filemtime, same pattern as layouts/admin.blade.php) --}}
    <link rel="stylesheet" href="/assets/css/help-system.css?v={{ $helpCssVer }}">
    <script src="/assets/js/help.js?v={{ $helpJsVer }}" defer></script>

    {{-- Config for help.js --}}
    <script>
        window.HELP_CONFIG = {
            endpoints: {
                menu: @json(route('help.menu', ['key' => '__KEY__'])),
                module: @json(route('help.module', ['key' => '__KEY__'])),
            },
            currentMenuKey: @json($menuKey),
            csrfToken: @json(csrf_token()),
            // Module key -> Bangla title map (Phase 5). Lets help.js render the
            // breadcrumb ("মডিউল: সেলস › সেলস ইনভয়েস") without an extra round-trip.
            moduleTitles: @json(collect($helpService->modules())->mapWithKeys(fn ($m, $k) => [$k => $m['title_bn']])),
            // §9.1 in-guide search index: every module + every menu_key (with a
            // derived human label) so the module-sheet search box can filter
            // modules + menus live, client-side, with no extra endpoint.
            searchIndex: @json((function () use ($helpService) {
                $modules = $helpService->modules();
                $out = [];
                foreach ($modules as $modKey => $mod) {
                    $menus = [];
                    foreach ($mod['menus'] ?? [] as $menuKey) {
                        // Derive a human label from the menu_key slug
                        // (e.g. 'sales.customer-payment' -> 'Customer Payment').
                        $slug = str_contains($menuKey, '.')
                            ? substr(strrchr($menuKey, '.'), 1)
                            : $menuKey;
                        $label = ucwords(str_replace('-', ' ', $slug));
                        $menus[] = ['key' => $menuKey, 'label' => $label];
                    }
                    $out[] = [
                        'key' => $modKey,
                        'title_bn' => $mod['title_bn'] ?? $modKey,
                        'title_en' => $mod['title_en'] ?? '',
                        'tagline' => $mod['tagline'] ?? '',
                        'color' => $mod['color'] ?? 'slate',
                        'icon' => $mod['icon'] ?? 'fa-folder',
                        'menus' => $menus,
                    ];
                }
                return $out;
            })()),
        };
    </script>
@endif
