<?php

namespace App\Http\Controllers\Api\Concerns;

/**
 * SortsLists — reusable sort-parameter parsing for API list endpoints.
 *
 * G-196 (MEDIUM, H2): the API had no sort convention — every list endpoint
 * hard-coded `orderBy('created_at', 'desc')` (or a similar single/double
 * sort). This trait gives every paginated `index()` a one-line opt-in to
 * accept `?sort=field&order=asc|desc` query parameters with a per-endpoint
 * whitelist of sortable column names.
 *
 * Convention (see `AI_CONTEXT/api/api-conventions.md` §8.5):
 *
 *   - `?sort=<column>`  — MUST be one of the per-endpoint whitelist. Unknown
 *     values are silently ignored (the endpoint's default sort applies) so
 *     mobile clients do NOT receive a 422 for an outdated column name.
 *   - `?order=asc|desc` — defaults to `desc`. Any value other than `asc` or
 *     `desc` (case-insensitive) falls back to the default direction.
 *   - Omitting both `?sort` and `?order` keeps the endpoint's existing
 *     default sort (the 3rd/4th arguments to `applySort`).
 *   - A stable tie-breaker `ORDER BY id <direction>` is appended automatically
 *     when the resolved sort field is not already `id` — this preserves the
 *     historical `orderBy(field, dir)->orderBy('id', dir)` pattern that
 *     guarantees deterministic pagination when the primary sort field has
 *     duplicate values.
 *
 * Whitelist rationale: the whitelist is defense against SQL injection AND
 * against information disclosure (clients must not be able to sort by
 * internal columns such as `journal_entry_id` or `reversed_by`). Each
 * endpoint publishes its whitelist in the controller's `index()` docblock
 * + `api-conventions.md` §8.5.
 *
 * Usage:
 *
 *   class FooApiController extends Controller
 *   {
 *       use \App\Http\Controllers\Api\Concerns\SortsLists;
 *
 *       public function index(Request $request): JsonResponse
 *       {
 *           $query = Foo::query()->when(...);
 *           $query = $this->applySort(
 *               $query,
 *               ['id', 'foo_code', 'foo_date', 'total_amount', 'status', 'created_at'],
 *               'foo_date',
 *               'desc',
 *           );
 *           $paginator = $query->paginate($perPage);
 *           ...
 *       }
 *   }
 *
 * @see \App\Http\Controllers\Api\V1\BranchApiController::index      — Phase 13 reference impl
 * @see \App\Http\Controllers\Api\V1\Sales\SalesInvoiceApiController::index
 * @see AI_CONTEXT/api/api-conventions.md §8.5
 */
trait SortsLists
{
    /**
     * Apply a client-requested sort to a query builder.
     *
     * Reads `?sort=` and `?order=` from the active request. Validates `sort`
     * against `$allowedSortFields` (whitelist); validates `order` against
     * `['asc', 'desc']`. Unknown sort fields silently fall back to
     * `$defaultField`. Unknown order values silently fall back to
     * `$defaultDirection`. Neither case raises an error — this keeps the
     * API forgiving for mobile clients that may carry an outdated column
     * name across an app upgrade.
     *
     * A stable tie-breaker `ORDER BY id <direction>` is appended when the
     * resolved sort field is not already `id` (preserves the historical
     * `orderBy(field, dir)->orderBy('id', dir)` pattern for deterministic
     * pagination).
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  array<int,string>  $allowedSortFields  Per-endpoint whitelist of sortable column names.
     * @param  string             $defaultField       Column to sort by when `?sort=` is omitted or unknown.
     * @param  string             $defaultDirection   Direction when `?order=` is omitted or invalid ('asc'|'desc').
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  The same builder (chainable).
     */
    public function applySort(
        $query,
        array $allowedSortFields,
        $defaultField = 'created_at',
        $defaultDirection = 'desc'
    ) {
        $field     = (string) request('sort', '');
        $direction = strtolower((string) request('order', ''));

        if (!in_array($field, $allowedSortFields, true)) {
            // Unknown sort field — silently fall back to the endpoint default.
            $field = $defaultField;
        }

        if ($direction !== 'asc' && $direction !== 'desc') {
            // Unknown / missing direction — fall back to the endpoint default.
            $direction = $defaultDirection;
        }

        $query->orderBy($field, $direction);

        // Stable pagination tie-breaker (mirrors the historical
        // `orderBy(field, dir)->orderBy('id', dir)` pattern). Skipped when
        // the primary sort is already `id` to avoid a redundant clause.
        if ($field !== 'id') {
            $query->orderBy('id', $direction);
        }

        return $query;
    }
}
