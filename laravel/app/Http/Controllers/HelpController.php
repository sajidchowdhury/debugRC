<?php

namespace App\Http\Controllers;

use App\Services\Help\HelpService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Route;

/**
 * Help System Controller — serves HTML partials for the offcanvas/sheet components.
 *
 * Phase 2 scaffold: two endpoints returning rendered Blade partials.
 *   - GET /help/menu/{key}   → components.help.menu-content
 *   - GET /help/module/{key} → components.help.module-content
 *
 * Both return HTTP 200 with an empty-state view when content does not exist yet
 * (Phase 7 authors the content files). This means the UI degrades gracefully
 * instead of 404-ing.
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §4.2, §4.3
 */
class HelpController extends Controller
{
    public function __construct(private readonly HelpService $help)
    {
    }

    /**
     * GET /help/menu/{key} — render a single menu's Bangla help card.
     *
     * @param  Request  $request
     * @param  string   $key  e.g. 'sales.invoice'
     * @return View
     */
    public function menu(Request $request, string $key): View
    {
        $content = $this->help->loadMenuContent($key);
        $module = $content ? $this->help->loadModuleContent($content['module'] ?? '') : null;

        return view('components.help.menu-content', [
            'key' => $key,
            'content' => $content,
            'module' => $module,
            'isAjax' => $request->ajax(),
        ]);
    }

    /**
     * GET /help/module/{key} — render a module's intro + menu list.
     *
     * @param  Request  $request
     * @param  string   $key  e.g. 'sales'
     * @return View
     */
    public function module(Request $request, string $key): View
    {
        $module = $this->help->loadModuleContent($key);

        return view('components.help.module-content', [
            'key' => $key,
            'module' => $module,
            'isAjax' => $request->ajax(),
        ]);
    }

    /**
     * GET /help/debug — diagnostic endpoint to troubleshoot help system issues.
     * Shows route resolution, file existence, cache status, and content loading.
     */
    public function debug(Request $request): JsonResponse
    {
        // Clear cache first
        $this->help->clearCache();

        $currentRoute = Route::currentRouteName();
        $debug = [
            'timestamp' => now()->toIso8601String(),
            'current_route' => $currentRoute,
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ];

        // Test routes that should have help content
        $testRoutes = [
            'admin.sales.cart' => 'Sales Cart',
            'admin.sales-invoices.index' => 'Sales Invoices',
            'admin.sales-returns.index' => 'Sales Returns',
            'admin.sales-challans.index' => 'Sales Challans',
            'admin.sales.audit' => 'Sales Audit',
            'admin.users.index' => 'Users',
            'admin.branches.index' => 'Branches',
            'admin.customers.index' => 'Customers',
        ];

        $routeTests = [];
        foreach ($testRoutes as $routeName => $label) {
            $menuKey = $this->help->menuKeyForRoute($routeName);
            $content = $menuKey ? $this->help->loadMenuContent($menuKey) : null;
            $routeTests[$routeName] = [
                'label' => $label,
                'menu_key' => $menuKey,
                'content_found' => $content !== null,
                'title_bn' => $content['title_bn'] ?? null,
                'file_exists' => false,
                'file_path' => null,
            ];
            if ($menuKey) {
                $parts = explode('.', $menuKey, 2);
                if (count($parts) === 2) {
                    $path = resource_path("help/menus/{$parts[0]}/{$parts[1]}.php");
                    $routeTests[$routeName]['file_exists'] = is_file($path);
                    $routeTests[$routeName]['file_path'] = $path;
                    $routeTests[$routeName]['file_mtime'] = is_file($path) ? date('Y-m-d H:i:s', filemtime($path)) : null;
                }
            }
        }
        $debug['route_tests'] = $routeTests;

        // Check key files exist
        $keyFiles = [
            'registry' => resource_path('help/registry.php'),
            'modules' => resource_path('help/modules.php'),
            'action_registry' => resource_path('help/action-registry.php'),
            'diagrams' => resource_path('help/diagrams.php'),
            'help_js' => public_path('assets/js/help.js'),
            'help_css' => public_path('assets/css/help-system.css'),
            'help_system_partial' => resource_path('views/partials/help-system.blade.php'),
            'help_button_component' => resource_path('views/components/help/help-button.blade.php'),
            'erp_layout' => resource_path('views/components/layouts/erp.blade.php'),
            'admin_layout' => resource_path('views/layouts/admin.blade.php'),
        ];
        $fileChecks = [];
        foreach ($keyFiles as $name => $path) {
            $fileChecks[$name] = [
                'exists' => is_file($path),
                'path' => $path,
                'mtime' => is_file($path) ? date('Y-m-d H:i:s', filemtime($path)) : null,
                'size' => is_file($path) ? filesize($path) : null,
            ];
        }
        $debug['key_files'] = $fileChecks;

        // Check layout includes
        $adminLayout = file_get_contents(resource_path('views/layouts/admin.blade.php'));
        $erpLayout = file_get_contents(resource_path('views/components/layouts/erp.blade.php'));
        $debug['layout_includes'] = [
            'admin_has_help_system' => str_contains($adminLayout, "partials.help-system"),
            'erp_has_help_system' => str_contains($erpLayout, "partials.help-system"),
        ];

        // Check registry for sales routes
        $registry = $this->help->loadRegistry();  // This will reload after cache clear
        $salesRegistryEntries = [];
        foreach ($registry as $route => $key) {
            if (str_starts_with($route, 'admin.sales') || str_contains($key, 'sales.')) {
                $salesRegistryEntries[$route] = $key;
            }
        }
        $debug['sales_registry_entries'] = $salesRegistryEntries;
        $debug['total_registry_entries'] = count($registry);

        // Check modules
        $modules = $this->help->modules();
        $debug['modules'] = [];
        foreach ($modules as $key => $mod) {
            $debug['modules'][$key] = [
                'title_bn' => $mod['title_bn'] ?? null,
                'menu_count' => count($mod['menus'] ?? []),
                'menus' => $mod['menus'] ?? [],
            ];
        }

        // Check cache store
        $debug['cache_store'] = config('cache.default');

        return response()->json($debug, 200, ['Content-Type' => 'application/json; charset=utf-8']);
    }
}
