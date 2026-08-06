<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * LOW-WAVE-2 (G-257) — Artisan drift-check command for the API reference doc.
 *
 * Compares the set of `/api/v1/*` endpoints registered in the live Laravel
 * route registry (resolved from `routes/api.php` with all prefix groups
 * expanded) against the set of endpoints documented in
 * `laravel/docs/api/API_REFERENCE.md`. Exits with code 1 (so it can be wired
 * into CI / pre-commit hooks / the §8.4 coverage-check step in
 * `AI_CONTEXT/api/api-reference-index.md`) when the two sets diverge.
 *
 * The command complements the runtime drift-guard test
 * `tests/Feature/Api/ApiDocTest::test_api_docs_card_count_matches_v1_route_count`
 * (which covers the *interactive* `/api/docs` page card count, not the
 * hand-written markdown). Together they bound the documentation-drift surface
 * flagged by G5/G6 in `api-reference-index.md` §9.
 *
 * Usage:
 *   php artisan api:check-doc-drift
 *
 * Exit codes:
 *   0 = no drift (route set == documented set)
 *   1 = drift detected (missing-in-docs OR missing-in-routes OR count mismatch)
 *   2 = a source file (routes/api.php or API_REFERENCE.md) could not be read
 */
class CheckApiDocDrift extends Command
{
    /**
     * Command signature.
     */
    protected $signature = 'api:check-doc-drift';

    /**
     * Command description.
     */
    protected $description = 'Detect documentation drift between routes/api.php and docs/api/API_REFERENCE.md (G-257)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $docsPath = base_path('docs/api/API_REFERENCE.md');

        if (! is_file($docsPath)) {
            $this->error("API reference doc not found: {$docsPath}");
            return 2;
        }

        $routes = $this->extractRoutesFromRegistry();
        $docs   = $this->extractDocs((string) file_get_contents($docsPath));

        $routesCount = count($routes);
        $docsCount   = count($docs);

        $this->info("Registered /api/v1/* routes: {$routesCount}");
        $this->info("Endpoints documented in API_REFERENCE.md: {$docsCount}");

        $missingInDocs   = array_values(array_diff($routes, $docs));
        $missingInRoutes = array_values(array_diff($docs, $routes));

        if ($routesCount === $docsCount && $missingInDocs === [] && $missingInRoutes === []) {
            $this->info('✓ No drift detected. Route registry and API_REFERENCE.md are in sync.');
            return 0;
        }

        if ($missingInDocs !== []) {
            $this->warn(sprintf(
                'In routes but MISSING from API_REFERENCE.md (%d):',
                count($missingInDocs),
            ));
            foreach (array_slice($missingInDocs, 0, 30) as $endpoint) {
                $this->line("  - {$endpoint}");
            }
        }
        if ($missingInRoutes !== []) {
            $this->warn(sprintf(
                'In API_REFERENCE.md but MISSING from routes (%d):',
                count($missingInRoutes),
            ));
            foreach (array_slice($missingInRoutes, 0, 30) as $endpoint) {
                $this->line("  - {$endpoint}");
            }
        }

        $this->error("Drift detected: routes={$routesCount}, docs={$docsCount}.");
        return 1;
    }

    /**
     * Extract the set of `METHOD /uri` endpoints from the live Laravel route
     * registry (resolved from routes/api.php with all prefix groups expanded).
     *
     * Only `/api/v1/*` routes are included. The public `/api/docs` route (no
     * `v1` segment) is excluded. HEAD/OPTIONS auto-methods are filtered out so
     * each GET route counts once.
     *
     * @return list<string>  Normalized as `METHOD /path-after-/api/v1`.
     */
    private function extractRoutesFromRegistry(): array
    {
        $endpoints = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            // Only /api/v1/* routes (skip /api/docs, /up, etc.).
            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            // Strip the api/v1/ prefix so the normalized form matches the
            // API_REFERENCE.md paths (which document paths as /branches, not
            // /api/v1/branches).
            $path = '/' . substr($uri, strlen('api/v1/'));

            foreach ($route->methods() as $method) {
                $method = strtoupper($method);
                if ($method === 'HEAD' || $method === 'OPTIONS') {
                    continue;
                }
                $endpoints[] = "{$method} {$path}";
            }
        }

        sort($endpoints);
        return array_values(array_unique($endpoints));
    }

    /**
     * Extract the set of `METHOD /path` endpoints from API_REFERENCE.md.
     *
     * Matches BOTH documentation formats used in the file:
     *   1. `### METHOD /path` headings (the 14 Phase-13 detailed entries +
     *      3 API-6 expansion entries).
     *   2. `| METHOD | \`/path\` | ...` table rows (the per-module endpoint
     *      tables for Sales / Commission / Warehouse Transfer / Stock
     *      Adjustment / Stock Take / Branch Demands).
     *
     * @return list<string>  Normalized as `METHOD /path` (path with leading slash).
     */
    private function extractDocs(string $content): array
    {
        $endpoints = [];

        // Format 1: ### METHOD /path
        preg_match_all(
            '/^###\s+(GET|POST|PUT|PATCH|DELETE)\s+(\/\S+)/m',
            $content,
            $headings,
            PREG_SET_ORDER,
        );
        foreach ($headings as $m) {
            $endpoints[] = "{$m[1]} {$m[2]}";
        }

        // Format 2: | METHOD | `/path` | ...
        preg_match_all(
            '/^\|\s+(GET|POST|PUT|PATCH|DELETE)\s+\|\s+`([^`]+)`/m',
            $content,
            $rows,
            PREG_SET_ORDER,
        );
        foreach ($rows as $m) {
            $endpoints[] = "{$m[1]} {$m[2]}";
        }

        sort($endpoints);
        return array_values(array_unique($endpoints));
    }
}
