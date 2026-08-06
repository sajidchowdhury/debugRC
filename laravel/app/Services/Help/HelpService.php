<?php

namespace App\Services\Help;

use Illuminate\Support\Facades\Cache;

/**
 * Help Service — resolves routes to help menu keys + loads content files.
 *
 * Phase 2 scaffold: route-name exact-match resolution + module/menu file loading.
 * Phase 3 will add controller@action + controller@* wildcard fallback.
 *
 * Content lives in resources/help/:
 *   - registry.php   : [route_name => menu_key]
 *   - modules.php    : [module_key => module_meta + menus[]]
 *   - menus/{module}/{slug}.php  : per-menu Bangla content (Phase 7)
 *
 * @see docs/HELP_SYSTEM_IMPLEMENTATION_PLAN.md §4.2 (content resolution flow)
 */
class HelpService
{
    private const CACHE_TTL = 86400; // 1 day — content files are static
    private const CACHE_KEY_REGISTRY = 'help:registry';
    private const CACHE_KEY_MODULES = 'help:modules';

    /** @var array<string,string>|null */
    private ?array $registry = null;

    /** @var array<string,array>|null */
    private ?array $modules = null;

    /**
     * Resolve a Laravel route name to a help menu key.
     *
     * Phase 2: exact match only. Phase 3 adds controller@action + wildcard fallback.
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

        // 2-3. controller@action + controller@* wildcard fallback — Phase 3 TODO.
        return null;
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

        // Guard against path traversal — only allow [a-z0-9-] in module/slug.
        if (!preg_match('/^[a-z0-9-]+$/', $module) || !preg_match('/^[a-z0-9-]+$/', $slug)) {
            return null;
        }

        $path = resource_path("help/menus/{$module}/{$slug}.php");
        if (!is_file($path)) {
            return null;
        }

        /** @var mixed $content */
        $content = require $path;

        return is_array($content) ? $content : null;
    }

    /**
     * Load a module's metadata (title, icon, color, tagline, menus list).
     *
     * @param  string  $key  e.g. 'sales'
     * @return array<string,mixed>|null
     */
    public function loadModuleContent(string $key): ?array
    {
        $modules = $this->loadModules();
        return $modules[$key] ?? null;
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

        /** @var array<string,string> $cached */
        $cached = Cache::remember(self::CACHE_KEY_REGISTRY, self::CACHE_TTL, function (): array {
            $path = resource_path('help/registry.php');
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
     * @return array<string,array<string,mixed>>
     */
    private function loadModules(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        /** @var array<string,array<string,mixed>> $cached */
        $cached = Cache::remember(self::CACHE_KEY_MODULES, self::CACHE_TTL, function (): array {
            $path = resource_path('help/modules.php');
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
     * Invalidate the cached registry + modules (called when content files change).
     *
     * @return void
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY_REGISTRY);
        Cache::forget(self::CACHE_KEY_MODULES);
        $this->registry = null;
        $this->modules = null;
    }
}
