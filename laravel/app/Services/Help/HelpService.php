<?php

namespace App\Services\Help;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Help Service — resolves routes to help menu keys + loads content files.
 *
 * Resolution priority in menuKeyForRoute():
 *   1. Exact route-name match   (resources/help/registry.php)
 *   2. controller@action match (resources/help/action-registry.php)
 *   3. controller@* wildcard    (resources/help/action-registry.php)
 *   4. null -> empty-state card
 *
 * Content lives in resources/help/:
 *   - registry.php          : [route_name => menu_key]                (214 mappings)
 *   - action-registry.php   : [controller@action => menu_key]         (214 + 59 wildcards)
 *   - modules.php            : [module_key => module_meta + menus[]]
 *   - menus/{module}/{slug}.php : per-menu Bangla content (Phase 7)
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §4.2 (content resolution flow)
 */
class HelpService
{
    private const CACHE_TTL = 86400; // 1 day — content files are static
    private const CACHE_KEY_REGISTRY = 'help:registry';
    private const CACHE_KEY_ACTION_REGISTRY = 'help:action-registry';
    private const CACHE_KEY_MODULES = 'help:modules';
    private const CACHE_KEY_DIAGRAMS = 'help:diagrams';

    /**
     * Build a cache key that includes the source file's modification time.
     *
     * This makes the cache AUTO-BUST whenever the file is edited — e.g.
     * after deploying new help content or fixing a registry mapping. The
     * old stale cache entry lingers until its TTL expires (harmless),
     * while the new mtime suffix ensures the next request loads fresh
     * data immediately. Without this, the static cache key would return
     * stale data for up to 86400 s after a file change, causing the `?`
     * help button to show empty-state on pages whose help was recently
     * added or rewritten.
     *
     * @param  string $base  e.g. 'help:registry'
     * @param  string $path  Absolute path to the source .php file
     * @return string        e.g. 'help:registry:1700000000'
     */
    private function mtimeKey(string $base, string $path): string
    {
        $mtime = is_file($path) ? (int) filemtime($path) : 0;
        return $base . ':' . $mtime;
    }

    /** @var array<string,string>|null */
    private ?array $registry = null;

    /** @var array<string,string>|null */
    private ?array $actionRegistry = null;

    /** @var array<string,array>|null */
    private ?array $modules = null;

    /** @var array<string,string>|null */
    private ?array $diagrams = null;

    /**
     * Resolve a Laravel route name to a help menu key.
     *
     * Resolution chain (per plan §4.2):
     *   1. Exact route-name match in registry.php.
     *   2. controller@action match in action-registry.php.
     *   3. controller@* wildcard in action-registry.php.
     *   4. null — caller shows the "not yet written" empty-state card.
     *
     * @param  string|null  $routeName  e.g. 'admin.customers.index'
     * @return string|null  e.g. 'master-data.customers', or null if no help exists.
     */
    public function menuKeyForRoute(?string $routeName): ?string
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }

        $registry = $this->loadRegistry();

        // 1. Exact route-name match.
        if (isset($registry[$routeName])) {
            return $registry[$routeName];
        }

        // 2-3. controller@action + controller@* wildcard fallback.
        $controllerAction = $this->controllerActionForRoute($routeName);
        if ($controllerAction !== null) {
            $actionRegistry = $this->loadActionRegistry();

            // 2. controller@action exact match.
            if (isset($actionRegistry[$controllerAction])) {
                return $actionRegistry[$controllerAction];
            }

            // 3. controller@* wildcard (catches collapsed resource actions:
            //    create/store/show/edit/update/destroy all route to the
            //    controller's primary menu key, which is its index page's help).
            $controller = explode('@', $controllerAction, 2)[0];
            $wildcardKey = $controller . '@*';
            if (isset($actionRegistry[$wildcardKey])) {
                return $actionRegistry[$wildcardKey];
            }
        }

        // 4. No help content for this route.
        return null;
    }

    /**
     * Resolve a route name to its "Controller@action" short form.
     *
     * e.g. 'admin.customers.create' -> 'CustomerController@create'
     * Returns null for Closure routes, missing routes, or routes without
     * a controller action (e.g. view-only routes).
     *
     * @param  string  $routeName
     * @return string|null  e.g. 'CustomerController@create'
     */
    private function controllerActionForRoute(string $routeName): ?string
    {
        $route = Route::getRoutes()->getByName($routeName);
        if ($route === null) {
            return null;
        }

        $action = $route->getAction();
        $controller = $action['controller'] ?? null;
        if (!is_string($controller) || !str_contains($controller, '@')) {
            return null;
        }

        [$fqcn, $method] = explode('@', $controller, 2);
        if ($fqcn === '' || $method === '') {
            return null;
        }

        // Strip namespace, keep short class name (matches inventory CSV format).
        $parts = explode('\\', $fqcn);
        $shortName = end($parts);

        return $shortName . '@' . $method;
    }

    /**
     * Load a menu's Bangla content array from resources/help/menus/{module}/{slug}.php.
     *
     * Menu key format: '{module}.{slug}' (e.g. 'sales.invoice').
     * Returns null if the file does not exist yet (Phase 7 will author them; until
     * then the controller shows the "not yet written" empty-state card).
     *
     * @param  string  $key  e.g. 'sales.invoice'
     * @return array<string,mixed>|null
     */
    public function loadMenuContent(string $key): ?array
    {
        $parts = explode('.', $key, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$module, $slug] = $parts;

        // Guard against path traversal — only allow [a-zA-Z0-9-] in module/slug.
        // Uppercase allowed because many menu slugs use camelCase (e.g. grossMarginCte, balanceSheet).
        if (!preg_match('/^[a-zA-Z0-9-]+$/', $module) || !preg_match('/^[a-zA-Z0-9-]+$/', $slug)) {
            return null;
        }

        $path = resource_path("help/menus/{$module}/{$slug}.php");
        if (!is_file($path)) {
            return null;
        }

        /** @var mixed $content */
        $content = require $path;

        if (!is_array($content)) {
            return null;
        }

        // If the content references a diagram key, attach the Mermaid snippet
        // so the Blade component can render a [data-mermaid] block.
        if (!empty($content['diagram']) && is_string($content['diagram'])) {
            $diagrams = $this->loadDiagrams();
            if (isset($diagrams[$content['diagram']])) {
                $content['_diagram_mermaid'] = $diagrams[$content['diagram']];
            }
        }

        return $content;
    }

    /**
     * Load a module's metadata (title, icon, color, tagline, intro, menus list).
     *
     * If the module declares a 'diagram' key, the matching Mermaid snippet is
     * attached as '_diagram_mermaid' (same pattern as loadMenuContent) so the
     * Blade component can render a mini cycle diagram in the module offcanvas.
     *
     * @param  string  $key  e.g. 'sales'
     * @return array<string,mixed>|null
     */
    public function loadModuleContent(string $key): ?array
    {
        $modules = $this->loadModules();
        $module = $modules[$key] ?? null;

        if ($module === null) {
            return null;
        }

        // Attach the Mermaid snippet if the module references a diagram key.
        if (!empty($module['diagram']) && is_string($module['diagram'])) {
            $diagrams = $this->loadDiagrams();
            if (isset($diagrams[$module['diagram']])) {
                $module['_diagram_mermaid'] = $diagrams[$module['diagram']];
            }
        }

        return $module;
    }

    /**
     * Get all 8 modules (for the bottom-up sheet). Keyed by module key.
     *
     * @return array<string,array<string,mixed>>
     */
    public function modules(): array
    {
        return $this->loadModules();
    }

    // -----------------------------------------------------------------------
    // Internal loaders (cached; cross-request via Laravel cache)
    // -----------------------------------------------------------------------

    /**
     * @return array<string,string>
     */
    private function loadRegistry(): array
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        $path = resource_path('help/registry.php');
        $key  = $this->mtimeKey(self::CACHE_KEY_REGISTRY, $path);

        /** @var array<string,string> $cached */
        $cached = Cache::remember($key, self::CACHE_TTL, function () use ($path): array {
            if (!is_file($path)) {
                return [];
            }
            /** @var mixed $data */
            $data = require $path;
            return is_array($data) ? $data : [];
        });

        return $this->registry = $cached;
    }

    /**
     * @return array<string,string>
     */
    private function loadActionRegistry(): array
    {
        if ($this->actionRegistry !== null) {
            return $this->actionRegistry;
        }

        $path = resource_path('help/action-registry.php');
        $key  = $this->mtimeKey(self::CACHE_KEY_ACTION_REGISTRY, $path);

        /** @var array<string,string> $cached */
        $cached = Cache::remember($key, self::CACHE_TTL, function () use ($path): array {
            if (!is_file($path)) {
                return [];
            }
            /** @var mixed $data */
            $data = require $path;
            return is_array($data) ? $data : [];
        });

        return $this->actionRegistry = $cached;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function loadModules(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $path = resource_path('help/modules.php');
        $key  = $this->mtimeKey(self::CACHE_KEY_MODULES, $path);

        /** @var array<string,array<string,mixed>> $cached */
        $cached = Cache::remember($key, self::CACHE_TTL, function () use ($path): array {
            if (!is_file($path)) {
                return [];
            }
            /** @var mixed $data */
            $data = require $path;
            return is_array($data) ? $data : [];
        });

        return $this->modules = $cached;
    }

    /**
     * Load the Mermaid diagram snippets (keyed by diagram key).
     *
     * @return array<string,string>
     */
    private function loadDiagrams(): array
    {
        if ($this->diagrams !== null) {
            return $this->diagrams;
        }

        $path = resource_path('help/diagrams.php');
        $key  = $this->mtimeKey(self::CACHE_KEY_DIAGRAMS, $path);

        /** @var array<string,string> $cached */
        $cached = Cache::remember($key, self::CACHE_TTL, function () use ($path): array {
            if (!is_file($path)) {
                return [];
            }
            /** @var mixed $data */
            $data = require $path;
            return is_array($data) ? $data : [];
        });

        return $this->diagrams = $cached;
    }

    /**
     * Invalidate the cached registry + action-registry + modules + diagrams.
     *
     * With the mtime-based cache keys (see mtimeKey()), this is mostly a
     * no-op for Redis — the static-base keys below only match entries
     * cached by the OLD (pre-mtime) code. New entries use mtime-suffixed
     * keys that auto-bust when the source file is edited. The in-memory
     * properties are always reset so the next call re-reads from disk.
     *
     * @return void
     */
    public function clearCache(): void
    {
        // Forget legacy static-key entries (pre-mtime code).
        Cache::forget(self::CACHE_KEY_REGISTRY);
        Cache::forget(self::CACHE_KEY_ACTION_REGISTRY);
        Cache::forget(self::CACHE_KEY_MODULES);
        Cache::forget(self::CACHE_KEY_DIAGRAMS);

        // Also forget the current mtime-based keys.
        $files = [
            self::CACHE_KEY_REGISTRY         => resource_path('help/registry.php'),
            self::CACHE_KEY_ACTION_REGISTRY  => resource_path('help/action-registry.php'),
            self::CACHE_KEY_MODULES          => resource_path('help/modules.php'),
            self::CACHE_KEY_DIAGRAMS         => resource_path('help/diagrams.php'),
        ];
        foreach ($files as $base => $path) {
            Cache::forget($this->mtimeKey($base, $path));
        }

        // Reset in-memory state so the next access re-reads from disk.
        $this->registry = null;
        $this->actionRegistry = null;
        $this->modules = null;
        $this->diagrams = null;
    }
}
