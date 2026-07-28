<?php

namespace App\Services\Stock;

use App\Exceptions\StockTakeNegativeStockException;
use App\Models\StockTakeSession;
use App\Models\StockTakeItem;
use App\Services\Accounting\DocumentSequenceService;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Stock\StockTakeAuditLogger;
use App\Services\Stock\StockTakePolicyService;

/**
 * Stock Take Service — Phase 6.4.
 *
 * Workflow (re-derived from inventory audit principles):
 *   1. createSession: header + selected warehouses (status=draft). NO counts yet.
 *   2. setupCounts: for each warehouse, load all active products + their current
 *      system_qty (warehouse_stock.qty) + avg_cost into stock_take_items.
 *   3. saveCount: user enters physical_qty per item. status → counting.
 *   4. postSession: for each item with variance (physical ≠ system):
 *      a. Apply stock movement via StockService (reference_type='stock_take')
 *         - Positive variance (physical > system): stock IN at current avg_cost
 *         - Negative variance (physical < system): stock OUT at current avg_cost
 *      b. Post GL journal (Dr/Cr Inventory vs Shrinkage/Surplus)
 *      c. Mark item is_applied=true
 *      status → posted
 *   5. cancelSession: Phase 10 — draft/counting only (no reversal). Sets
 *      status='cancelled'. For posted sessions, use reverseSession (below).
 *   5b. reverseSession: Phase 10 — posted only. Full stock + GL reversal.
 *      Sets status='reversed' + reversal columns. Re-openable.
 *   5c. reOpen: Phase 10 — reversed → counting. Preserves the reversal rows
 *      as audit history; resets stock_take_items.is_applied=false so the
 *      counts can be corrected and the session re-posted (creates a NEW
 *      journal entry; the old one stays linked via reversal_of_entry_id).
 *      Capped by the stock_take.max_reopens policy.
 *
 * The difference column (physical_qty - system_qty) is a PG GENERATED STORED
 * column — the DB computes it, not the app.
 *
 * GL posting rules (same as stock adjustments — re-derived from double-entry):
 *   - Net gain (physical > system): Dr Inventory / Cr Inventory Surplus
 *   - Net loss (physical < system): Dr Inventory Shrinkage / Cr Inventory
 */
class StockTakeService
{
    /**
     * Phase 8: advisory-lock namespace for stock-take postSession.
     *
     * Used with pg_advisory_xact_lock(namespace, warehouse_id) — the two-int
     * form. The namespace isolates stock-take post locks from every other
     * advisory lock in the system (DocumentSequenceService uses the single-
     * int form with a CRC32 hash; the two-int form with a fixed namespace
     * cannot collide with it). The value 0x53544B50 ("STKP" in hex) was
     * chosen to be memorable + unlikely to clash with any other namespace
     * constant someone might add later.
     */
    public const POST_ADVISORY_LOCK_NAMESPACE = 0x53544B50;

    public function __construct(
        private StockService $stockService,
        private JournalPostingService $journalPosting,
        private StockTakeAuditLogger $auditLogger,
        private StockTakePolicyService $policyService,
        private AbcClassificationService $abcService
    ) {}

    /**
     * Phase 1: Create a stock take session (draft) with selected warehouses.
     *
     * Phase 3: now accepts an optional `freeze_outbound` flag. When true, the
     * covered warehouses are immediately marked is_frozen_for_count=true and
     * StockService::applyTransaction will reject any outbound movement for
     * them while the session is active (draft/counting). frozen_at is set to
     * now() for audit purposes. The flag is released on post/cancel by
     * refreshWarehouseFreezeFlags (which honors overlapping sessions).
     *
     * Phase 5: now accepts `count_scope` + `count_scope_payload`. The scope
     * narrows which products setupWarehouseCounts loads (full / category /
     * abc / group / ad_hoc / negative_only / zero_only). Defaults to 'full'
     * (pre-Phase-5 behaviour). The payload is validated per-scope before the
     * session row is inserted.
     *
     * @param array $data {
     *     branch_id: int,
     *     session_date: string (Y-m-d),
     *     warehouse_ids: array<int>,
     *     notes: string|null,
     *     freeze_outbound: bool (default false),
     *     count_scope: string (default 'full'),
     *     count_scope_payload: array|null,
     *     created_by: int,
     * }
     * @return StockTakeSession
     */
    public function createSession(array $data): StockTakeSession
    {
        if (empty($data['branch_id']) || (int) $data['branch_id'] <= 0) {
            throw new \InvalidArgumentException('branch_id is required.');
        }
        if (empty($data['warehouse_ids']) || !is_array($data['warehouse_ids'])) {
            throw new \InvalidArgumentException('At least one warehouse is required.');
        }

        $sessionCode = $this->generateSessionCode();
        $freezeOutbound = !empty($data['freeze_outbound']);

        // Phase 5: normalize + validate the count scope BEFORE the insert so
        // an invalid scope/payload never produces a session row. 'full' is
        // the default and carries an empty payload.
        $countScope = $data['count_scope'] ?? 'full';
        $countScopePayload = $this->validateCountScope($countScope, $data['count_scope_payload'] ?? null);

        // Phase 8: pre-check the no-overlapping-frozen-sessions invariant
        // BEFORE opening the transaction + inserting the session row. This
        // is the friendly-error path; the DB trigger
        // (prevent_overlapping_frozen_stock_take) is the race-condition
        // backstop for two concurrent createSession calls. We check here so
        // the user gets a clear message naming the conflicting warehouse(s)
        // rather than a generic trigger exception.
        if ($freezeOutbound) {
            $conflictingWarehouseIds = $this->findWarehousesWithActiveFrozenSession(
                $data['warehouse_ids']
            );
            if (!empty($conflictingWarehouseIds)) {
                $names = DB::table('warehouses')
                    ->whereIn('id', $conflictingWarehouseIds)
                    ->pluck('warehouse_name')
                    ->all();
                throw new \RuntimeException(
                    'Cannot freeze outbound for warehouse(s): ' . implode(', ', $names)
                    . ' — another active stock-take session already froze them. '
                    . 'Post or cancel the existing session first, or create this session without the outbound freeze.'
                );
            }
        }

        return DB::transaction(function () use ($data, $sessionCode, $freezeOutbound, $countScope, $countScopePayload) {
            $sessionId = DB::table('stock_take_sessions')->insertGetId([
                'session_code' => $sessionCode,
                'session_date' => $data['session_date'] ?? now()->format('Y-m-d'),
                'branch_id' => (int) $data['branch_id'],
                'status' => 'draft',
                'is_reversed' => false,
                // Phase 3: outbound freeze columns.
                'freeze_outbound' => $freezeOutbound,
                'frozen_at' => $freezeOutbound ? now() : null,
                // Phase 5: cycle-count scope + payload (jsonb stored as JSON text).
                'count_scope' => $countScope,
                'count_scope_payload' => empty($countScopePayload)
                    ? null
                    : json_encode($countScopePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $warehouseIds = [];
            foreach ($data['warehouse_ids'] as $wid) {
                $wid = (int) $wid;
                if ($wid <= 0) continue;
                try {
                    DB::table('stock_take_warehouses')->insert([
                        'stock_take_session_id' => $sessionId,
                        'warehouse_id' => $wid,
                        // Phase 8: denormalized branch_id + freeze_outbound so
                        // RLS can scope reads without a join and the no-overlap
                        // trigger can read the flag off the row directly.
                        'branch_id' => (int) $data['branch_id'],
                        'freeze_outbound' => $freezeOutbound,
                        'status' => 'pending',
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // Two expected SQLSTATEs from the stw insert:
                    //   23505 = unique_violation on uk_stw_session_wh — a
                    //           duplicate warehouse_id in the request (the
                    //           service should have deduped, but a malformed
                    //           request could still try). Surface a clear msg.
                    //   23000 = the prevent_overlapping_frozen_stock_take
                    //           trigger's ERRCODE for the overlap case. Its
                    //           message already names the warehouse + explains
                    //           the conflict, so surface it verbatim.
                    // Anything else is unexpected — re-throw unchanged.
                    $sqlState = $e->errorInfo[0] ?? $e->getCode();
                    if ($sqlState === '23505') {
                        throw new \RuntimeException(
                            "Warehouse {$wid} is already part of this stock-take session (duplicate warehouse_id in the request)."
                        );
                    }
                    if ($sqlState === '23000') {
                        throw new \RuntimeException($e->getMessage());
                    }
                    throw $e;
                }
                $warehouseIds[] = $wid;
            }

            // Phase 3: if outbound freeze is on, mark the covered warehouses
            // as frozen for count. refreshWarehouseFreezeFlags recomputes the
            // flag from the set of active freezing sessions, so it correctly
            // handles the case where another session already froze the same
            // warehouse (flag stays true) or this is the first (flag set true).
            if ($freezeOutbound && !empty($warehouseIds)) {
                $this->refreshWarehouseFreezeFlags($warehouseIds);
            }

            // Phase 2: audit-log the creation (same transaction).
            $this->auditLogger->log(
                session:    ['id' => $sessionId, 'branch_id' => (int) $data['branch_id']],
                action:     'create',
                fromStatus: null,
                toStatus:   'draft',
                payload:    [
                    'session_code'   => $sessionCode,
                    'session_date'   => $data['session_date'] ?? now()->format('Y-m-d'),
                    'warehouse_ids'  => array_map('intval', $data['warehouse_ids']),
                    'warehouse_count' => count($data['warehouse_ids']),
                    'notes'          => $data['notes'] ?? null,
                    // Phase 3: record the freeze decision in the audit trail.
                    'freeze_outbound' => $freezeOutbound,
                    'frozen_at'       => $freezeOutbound ? now()->toDateTimeString() : null,
                    // Phase 5: record the cycle-count scope + payload.
                    'count_scope'         => $countScope,
                    'count_scope_payload' => $countScopePayload,
                ],
                actorId:    $data['created_by'] ?? null,
            );

            return StockTakeSession::with('warehouses.warehouse', 'branch')->find($sessionId);
        });
    }

    /**
     * Phase 2: Setup counts for a warehouse — load all active products with
     * their current system_qty + avg_cost into stock_take_items.
     *
     * @param int $sessionId
     * @param int $warehouseId
     * @return int Number of items created
     */
    public function setupWarehouseCounts(int $sessionId, int $warehouseId): int
    {
        return DB::transaction(function () use ($sessionId, $warehouseId) {
            // Phase 1: Lock the session row to serialize concurrent counters.
            // Prevents two users from simultaneously setting up counts for the
            // same session (which would cause a delete+insert race on items).
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }

            // Verify the warehouse belongs to this session.
            $stw = DB::table('stock_take_warehouses')
                ->where('stock_take_session_id', $sessionId)
                ->where('warehouse_id', $warehouseId)
                ->first();
            if (!$stw) {
                throw new \RuntimeException("Warehouse {$warehouseId} is not part of session {$sessionId}.");
            }

            // Delete any existing items for this warehouse (re-setup allowed).
            DB::table('stock_take_items')
                ->where('stock_take_session_id', $sessionId)
                ->where('warehouse_id', $warehouseId)
                ->delete();

            // Phase 5: load the product set for this warehouse, narrowed by the
            // session's count_scope. The base query (all active products + their
            // current warehouse_stock) is the 'full' scope; the other scopes
            // layer additional WHERE/JOIN filters on top. Phase 3 also fetches
            // product_code + product_name so the snapshot captured below can
            // reconstruct the count even after a product is later renamed or
            // soft-deleted.
            $products = $this->buildScopedProductsQuery($session, $warehouseId)->get();

            $rows = [];
            $now = now();
            foreach ($products as $p) {
                $rows[] = [
                    'stock_take_session_id' => $sessionId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $p->product_id,
                    'system_qty' => $p->system_qty,
                    'physical_qty' => $p->system_qty, // default = system (no variance until user enters)
                    'rate' => $p->rate,
                    'reason' => null,
                    'is_applied' => false,
                    // Phase 8: denormalized branch_id for RLS on stock_take_items
                    // (the security fix that closes the cross-branch data leak).
                    // Read off the (already locked) session row so it is always
                    // consistent with the session's branch.
                    'branch_id' => (int) $session->branch_id,
                    // Phase 9: system_rate is the setup-time avg cost snapshot.
                    // Immutable after insert — the post-time cost (post_rate) is
                    // captured separately at postSession, and the drift between
                    // the two drives the revaluation adjusting entry. `rate`
                    // above is the post-time rate used for GL; it starts as the
                    // setup rate (so the variance report shows something useful
                    // before post) and is overwritten with the live avg cost at
                    // post time.
                    'system_rate' => $p->rate,
                    'post_rate' => null,
                    'revaluation_amount' => 0,
                    'revaluation_line_id' => null,
                    'updated_at' => $now,
                ];
            }

            if (!empty($rows)) {
                DB::table('stock_take_items')->insert($rows);
            }

            // Phase 3: capture the count_snapshot for this warehouse — the
            // frozen product list at setup time (product_id, product_code,
            // product_name, unit, system_qty, avg_cost). Stored on the session
            // row as jsonb keyed by warehouse_id, merged across warehouses so a
            // multi-warehouse session accumulates one snapshot per warehouse.
            // This lets the session detail page reconstruct "what the counter
            // saw" months later, even if products are renamed/deleted or stock
            // drifts. Re-setup overwrites that warehouse's slice.
            $this->captureWarehouseSnapshot($sessionId, $warehouseId, $products);

            // Mark warehouse as counting.
            DB::table('stock_take_warehouses')
                ->where('stock_take_session_id', $sessionId)
                ->where('warehouse_id', $warehouseId)
                ->update(['status' => 'counting']);

            // Mark session as counting.
            $fromStatus = $session->status;
            DB::table('stock_take_sessions')
                ->where('id', $sessionId)
                ->update(['status' => 'counting', 'updated_at' => now()]);

            // Phase 2: audit-log the setup (warehouse-scoped action).
            $this->auditLogger->log(
                session:      $session,
                action:       'setup',
                fromStatus:   $fromStatus,
                toStatus:     'counting',
                payload:      [
                    'warehouse_id'   => $warehouseId,
                    'products_loaded' => count($rows),
                    're_setup'       => true, // setup always deletes existing items first
                    // Phase 3: record that a snapshot was captured for this wh.
                    'snapshot_captured' => true,
                    // Phase 5: record the scope that produced this product set,
                    // so the audit timeline shows exactly which products were
                    // eligible to be counted (and which filter produced them).
                    'count_scope'         => $session->count_scope ?? 'full',
                    'count_scope_payload' => $session->count_scope_payload,
                ],
                warehouseId:  $warehouseId,
            );

            return count($rows);
        });
    }

    /**
     * Phase 5: validate + normalize the count_scope / count_scope_payload.
     *
     * Returns the normalized payload array (empty for 'full' and for the
     * no-payload scopes negative_only/zero_only). Throws on an unsupported
     * scope or a payload that doesn't match the scope's contract.
     *
     * The payload contracts:
     *   - full / negative_only / zero_only : no payload (ignored).
     *   - category : {category_ids: int[]}  — at least one, all must exist.
     *   - group    : {group_ids: int[]}     — at least one, all must exist.
     *   - abc      : {abc_classes: string[]} — subset of ['A','B','C'], ≥1.
     *   - ad_hoc   : {product_ids: int[]}   — at least one, all must be active
     *                                          non-deleted products.
     *
     * Existence/active checks for category_ids / group_ids / product_ids are
     * done here (cheap COUNT queries) so the create form gets a clear error
     * BEFORE the session row is inserted, rather than a silent empty count at
     * setup time.
     *
     * @param string $scope
     * @param array|null $payload
     * @return array  Normalized payload (possibly empty).
     * @throws \InvalidArgumentException
     */
    public function validateCountScope(string $scope, ?array $payload): array
    {
        $allowed = ['full', 'category', 'abc', 'group', 'ad_hoc', 'negative_only', 'zero_only'];
        if (!in_array($scope, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Unsupported count_scope '{$scope}'. Allowed: " . implode(', ', $allowed) . '.'
            );
        }

        $payload = is_array($payload) ? $payload : [];

        // Scopes that take no payload.
        if (in_array($scope, ['full', 'negative_only', 'zero_only'], true)) {
            return [];
        }

        switch ($scope) {
            case 'category':
                $ids = $this->normalizeIntList($payload['category_ids'] ?? []);
                if (empty($ids)) {
                    throw new \InvalidArgumentException('category scope requires at least one category_id.');
                }
                $missing = $this->missingIds('product_categories', 'id', $ids, activeColumn: 'is_active');
                if (!empty($missing)) {
                    throw new \InvalidArgumentException(
                        'Unknown/inactive category_ids: ' . implode(', ', $missing) . '.'
                    );
                }
                return ['category_ids' => $ids];

            case 'group':
                $ids = $this->normalizeIntList($payload['group_ids'] ?? []);
                if (empty($ids)) {
                    throw new \InvalidArgumentException('group scope requires at least one group_id.');
                }
                $missing = $this->missingIds('product_groups', 'id', $ids, activeColumn: 'is_active');
                if (!empty($missing)) {
                    throw new \InvalidArgumentException(
                        'Unknown/inactive group_ids: ' . implode(', ', $missing) . '.'
                    );
                }
                return ['group_ids' => $ids];

            case 'abc':
                $classes = $this->normalizeStringList($payload['abc_classes'] ?? []);
                $invalid = array_diff($classes, ['A', 'B', 'C']);
                if (!empty($invalid)) {
                    throw new \InvalidArgumentException(
                        'abc_classes must be a subset of A, B, C. Invalid: ' . implode(', ', $invalid) . '.'
                    );
                }
                if (empty($classes)) {
                    throw new \InvalidArgumentException('abc scope requires at least one abc_class (A, B, and/or C).');
                }
                return ['abc_classes' => array_values($classes)];

            case 'ad_hoc':
                $ids = $this->normalizeIntList($payload['product_ids'] ?? []);
                if (empty($ids)) {
                    throw new \InvalidArgumentException('ad_hoc scope requires at least one product_id.');
                }
                // Validate every requested product exists + is active + not
                // soft-deleted. We do NOT scope to a warehouse here (products
                // are global); setupWarehouseCounts will LEFT JOIN warehouse_stock
                // so a product with zero stock in a warehouse still gets a
                // count line (system_qty=0). This honours "includes exactly
                // those products" — if any requested id is invalid, we throw
                // rather than silently dropping it.
                $missing = $this->missingIds('products', 'id', $ids, activeColumn: 'is_active', deletedAt: true);
                if (!empty($missing)) {
                    throw new \InvalidArgumentException(
                        'Unknown/inactive/deleted product_ids: ' . implode(', ', $missing) . '.'
                    );
                }
                return ['product_ids' => $ids];
        }

        return [];
    }

    /**
     * Phase 5: build the product query for a warehouse, narrowed by the
     * session's count_scope. Returns the Builder (caller ->get()s it).
     *
     * The base query is the pre-Phase-5 'full' scope: all active, non-deleted
     * products LEFT JOINed to warehouse_stock for the given warehouse (so a
     * product with zero stock still appears with system_qty=0). The other
     * scopes layer additional filters:
     *
     *   - category       : WHERE p.category_id IN (payload.category_ids)
     *   - group          : WHERE p.group_id IN (payload.group_ids)
     *   - abc            : INNER JOIN mv_product_abc_classification ON
     *                      (warehouse_id, product_id) WHERE abc_class IN (...)
     *                      → only classified products appear. A product with
     *                      no usage in the lookback window has no MV row, so
     *                      it's excluded from an ABC cycle count (correct:
     *                      you're counting the high-value movers).
     *   - ad_hoc         : WHERE p.id IN (payload.product_ids) — exactly the
     *                      requested products (already validated at create).
     *   - negative_only  : WHERE COALESCE(ws.qty,0) < -0.0001 (negative on-hand)
     *   - zero_only      : WHERE ABS(COALESCE(ws.qty,0)) < 0.0001 (dead stock)
     *
     * @param StockTakeSession $session  (already locked for update by caller)
     * @param int $warehouseId
     * @return \Illuminate\Database\Query\Builder
     */
    private function buildScopedProductsQuery($session, int $warehouseId)
    {
        $scope = $session->count_scope ?? 'full';
        $payload = is_array($session->count_scope_payload) ? $session->count_scope_payload : [];

        $query = DB::table('products as p')
            ->leftJoin('warehouse_stock as ws', function ($join) use ($warehouseId) {
                $join->on('ws.product_id', '=', 'p.id')
                     ->where('ws.warehouse_id', '=', $warehouseId);
            })
            ->where('p.is_active', true)
            ->whereNull('p.deleted_at')
            ->select(
                'p.id as product_id',
                'p.product_code',
                'p.product_name',
                'p.unit',
                DB::raw('COALESCE(ws.qty, 0) as system_qty'),
                DB::raw('COALESCE(ws.avg_cost, 0) as rate')
            );

        switch ($scope) {
            case 'category':
                $ids = $this->normalizeIntList($payload['category_ids'] ?? []);
                $query->whereIn('p.category_id', $ids);
                break;

            case 'group':
                $ids = $this->normalizeIntList($payload['group_ids'] ?? []);
                $query->whereIn('p.group_id', $ids);
                break;

            case 'abc':
                // Guard: if the Phase 5 MV doesn't exist, the INNER JOIN
                // would throw SQLSTATE[42P01]. Fail with a clear, actionable
                // message instead — the user needs to run the migration or
                // refresh the ABC view before an ABC-scope session can be
                // set up. (Silently returning 0 products would be worse:
                // the user would post an empty count with no variance.)
                if (! $this->abcService->viewExists()) {
                    throw new \RuntimeException(
                        'ABC classification has not been computed yet. '
                        . 'Run `php artisan migrate` to create the materialized view, '
                        . 'then click "Refresh ABC" on the ABC report (or wait for the '
                        . 'nightly pg_cron job) before creating an ABC-scope stock take session.'
                    );
                }
                $classes = $this->normalizeStringList($payload['abc_classes'] ?? ['A', 'B', 'C']);
                // INNER JOIN → only products with an ABC classification row
                // for THIS warehouse appear. The mv is keyed by (warehouse_id,
                // product_id) so the join is exact.
                $query->join('mv_product_abc_classification as abc', function ($join) use ($warehouseId) {
                    $join->on('abc.product_id', '=', 'p.id')
                         ->where('abc.warehouse_id', '=', $warehouseId);
                })->whereIn('abc.abc_class', $classes);
                break;

            case 'ad_hoc':
                $ids = $this->normalizeIntList($payload['product_ids'] ?? []);
                $query->whereIn('p.id', $ids);
                break;

            case 'negative_only':
                // Negative on-hand is a red flag (oversold / data error) —
                // these are exactly the products that need a recount. The
                // warehouse_stock CHECK allows a tiny negative tolerance
                // (-0.0001), so use a threshold below it.
                $query->whereRaw('COALESCE(ws.qty, 0) < -0.0001');
                break;

            case 'zero_only':
                // Dead stock: truly zero on-hand (no warehouse_stock row OR
                // qty = 0). Worth counting periodically to catch shrinkage of
                // "should-be-zero" items and to clear obsolete SKUs.
                $query->whereRaw('ABS(COALESCE(ws.qty, 0)) < 0.0001');
                break;

            case 'full':
            default:
                // No extra filter — every active product.
                break;
        }

        return $query->orderBy('p.product_name');
    }

    /**
     * Phase 5: human-readable description of a session's count scope, for the
     * show page + audit timeline. Returns a one-line summary like:
     *   - "Full warehouse count"
     *   - "Category: Beverages, Snacks (2 categories)"
     *   - "ABC classes: A, B"
     *   - "Product group: Grocery (1 group)"
     *   - "Ad-hoc: 12 products"
     *   - "Negative-stock-only count"
     *   - "Zero-stock-only count"
     *
     * @param StockTakeSession $session
     * @return string
     */
    public function describeScope($session): string
    {
        $scope = $session->count_scope ?? 'full';
        $payload = is_array($session->count_scope_payload) ? $session->count_scope_payload : [];

        switch ($scope) {
            case 'full':
                return 'Full warehouse count';
            case 'category':
                $ids = $this->normalizeIntList($payload['category_ids'] ?? []);
                $names = $ids ? DB::table('product_categories')
                    ->whereIn('id', $ids)->pluck('category_name')->all() : [];
                return 'Category: ' . ($names ? implode(', ', $names) : '(none)')
                    . ' (' . count($ids) . ' categor' . (count($ids) === 1 ? 'y' : 'ies') . ')';
            case 'group':
                $ids = $this->normalizeIntList($payload['group_ids'] ?? []);
                $names = $ids ? DB::table('product_groups')
                    ->whereIn('id', $ids)->pluck('group_name')->all() : [];
                return 'Product group: ' . ($names ? implode(', ', $names) : '(none)')
                    . ' (' . count($ids) . ' group' . (count($ids) === 1 ? '' : 's') . ')';
            case 'abc':
                $classes = $this->normalizeStringList($payload['abc_classes'] ?? []);
                return 'ABC classes: ' . ($classes ? implode(', ', $classes) : '(none)');
            case 'ad_hoc':
                $ids = $this->normalizeIntList($payload['product_ids'] ?? []);
                return 'Ad-hoc: ' . count($ids) . ' product' . (count($ids) === 1 ? '' : 's');
            case 'negative_only':
                return 'Negative-stock-only count';
            case 'zero_only':
                return 'Zero-stock-only count';
            default:
                return ucfirst($scope);
        }
    }

    /**
     * Phase 5: normalize a mixed list of ids into a unique array of positive
     * integers. Accepts ints, numeric strings, and nested arrays (flattened).
     *
     * @param mixed $list
     * @return array<int>
     */
    private function normalizeIntList($list): array
    {
        if (!is_array($list)) {
            $list = $list === null ? [] : [$list];
        }
        $flat = [];
        array_walk_recursive($list, function ($v) use (&$flat) {
            $v = is_string($v) ? trim($v) : $v;
            if ($v === '' || $v === null) return;
            $i = (int) $v;
            if ($i > 0) $flat[$i] = $i;
        });
        return array_values($flat);
    }

    /**
     * Phase 5: normalize a mixed list of class strings into uppercased unique
     * values. Accepts strings or single strings.
     *
     * @param mixed $list
     * @return array<string>
     */
    private function normalizeStringList($list): array
    {
        if (!is_array($list)) {
            $list = $list === null || $list === '' ? [] : [$list];
        }
        $out = [];
        foreach ($list as $v) {
            $v = is_string($v) ? trim($v) : $v;
            if ($v === '' || $v === null) continue;
            $out[mb_strtoupper((string) $v)] = mb_strtoupper((string) $v);
        }
        return array_values($out);
    }

    /**
     * Phase 5: return the subset of $ids that do NOT exist (or are inactive /
     * soft-deleted) in the given table. Used by validateCountScope to give the
     * user a precise "these ids are bad" error.
     *
     * @param string $table
     * @param string $idColumn
     * @param array<int> $ids
     * @param string|null $activeColumn  Column to require = true (null to skip).
     * @param bool $deletedAt  When true, require deleted_at IS NULL.
     * @return array<int>  Missing/invalid ids.
     */
    private function missingIds(string $table, string $idColumn, array $ids, ?string $activeColumn = null, bool $deletedAt = false): array
    {
        if (empty($ids)) return [];
        $query = DB::table($table)->whereIn($idColumn, $ids);
        if ($activeColumn) $query->where($activeColumn, true);
        if ($deletedAt) $query->whereNull('deleted_at');
        $found = $query->pluck($idColumn)->all();
        return array_values(array_diff($ids, array_map('intval', $found)));
    }

    /**
     * Phase 3: Save physical counts for a warehouse.
     *
     * @param int $sessionId
     * @param int $warehouseId
     * @param array $counts [product_id => physical_qty]
     * @return int Number of items updated
     */
    public function saveCounts(int $sessionId, int $warehouseId, array $counts): int
    {
        return DB::transaction(function () use ($sessionId, $warehouseId, $counts) {
            // Phase 1: Lock the session row to serialize concurrent counters.
            // Prevents lost-update when two users save counts simultaneously.
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }

            $updated = 0;
            foreach ($counts as $productId => $physicalQty) {
                $productId = (int) $productId;
                $physicalQty = (float) $physicalQty;
                if ($productId <= 0) continue;

                DB::table('stock_take_items')
                    ->where('stock_take_session_id', $sessionId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->update([
                        'physical_qty' => $physicalQty,
                        'updated_at' => now(),
                    ]);
                $updated++;
            }

            // Mark warehouse as completed.
            DB::table('stock_take_warehouses')
                ->where('stock_take_session_id', $sessionId)
                ->where('warehouse_id', $warehouseId)
                ->update(['status' => 'completed']);

            // Phase 2: audit-log the save (warehouse-scoped; records count
            // of lines saved + which products had reasons attached).
            $this->auditLogger->log(
                session:      $session,
                action:       'save_count',
                fromStatus:   $session->status,
                toStatus:     $session->status, // session status does not change here
                payload:      [
                    'warehouse_id'  => $warehouseId,
                    'lines_saved'   => $updated,
                    'product_ids'   => array_map('intval', array_keys($counts)),
                ],
                warehouseId:  $warehouseId,
            );

            return $updated;
        });
    }

    // ========================================================================
    // Phase 7 (Stock Take plan) — Count UX: barcode, bulk paste, CSV import,
    // recount, autosave. The methods below are the single-line / batch / recount
    // entry points used by the new count page. Each runs inside its own
    // DB::transaction with a session-row lockForUpdate (same concurrency story
    // as saveCounts) and writes an audit row in the same transaction.
    // ========================================================================

    /**
     * Phase 7: Transition a completed warehouse back to counting for a recount.
     *
     * Flow (atomic, single transaction):
     *   1. Lock the session row.
     *   2. Verify the warehouse belongs to the session AND is currently
     *      'completed' (a recount of a pending/counting warehouse is a no-op
     *      — the counter is already in the counting page).
     *   3. Capture a pre-recount snapshot of every item's physical_qty
     *      (product_id, product_code, physical_qty) — this is the forensic
     *      record the acceptance criterion demands ("the previous physical_qty
     *      values are preserved in the audit log").
     *   4. Set stock_take_warehouses.status = 'recounting' (the transient
     *      state — audited distinctly from a plain save_count).
     *   5. Stamp recounted_at = now() + recounted_by = actor on every item in
     *      the warehouse. Optionally reset physical_qty = system_qty when the
     *      stock_take.recount_reset_to_system policy is true (default false =
     *      preserve, so the counter sees the prior count and adjusts).
     *   6. Flip the warehouse to 'counting' (open for re-entry).
     *   7. If the session was 'submitted' or 'approved', push it back to
     *      'counting' (a recount invalidates any prior approval) and clear
     *      the approval artifacts so the workflow restarts cleanly.
     *   8. Audit-log the recount (warehouse-scoped, action='recount',
     *      from_status='completed', to_status='counting', payload carries
     *      the pre-recount snapshot + the reset decision + line count).
     *
     * Returns a summary: { lines_recounted, reset_to_system, previous_snapshot }
     * (previous_snapshot is the same array persisted to the audit payload —
     * returned so the controller can flash a "we kept your previous counts"
     * hint to the counter).
     *
     * @param int $sessionId
     * @param int $warehouseId
     * @param string $reason  Mandatory (the counter needs to know why a
     *     recount was requested; surfaced in the audit timeline).
     * @param int|null $actorId  Defaults to auth()->id().
     * @return array{lines_recounted: int, reset_to_system: bool, previous_snapshot: array<int, array{product_id: int, product_code: string, physical_qty: float}>}
     * @throws \RuntimeException  When the warehouse is not 'completed' or not
     *     part of the session, or the session is in a terminal state.
     */
    public function recountWarehouse(int $sessionId, int $warehouseId, string $reason, ?int $actorId = null): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A recount reason is required (the counter needs to know why).');
        }
        if (mb_strlen($reason) > 1000) {
            throw new \InvalidArgumentException('Recount reason is too long (max 1000 characters).');
        }

        return DB::transaction(function () use ($sessionId, $warehouseId, $reason, $actorId) {
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }
            // A recount is only meaningful before the session is posted. A
            // posted/cancelled session is frozen — use reverse+re-open (Phase 10)
            // instead. This guard keeps recount strictly a pre-post correction.
            if (in_array($session->status, ['posted', 'cancelled', 'reversed'], true)) {
                throw new \RuntimeException(
                    "Cannot recount a {$session->status} session. Use reverse + re-open instead."
                );
            }

            $stw = DB::table('stock_take_warehouses')
                ->where('stock_take_session_id', $sessionId)
                ->where('warehouse_id', $warehouseId)
                ->first();
            if (!$stw) {
                throw new \RuntimeException("Warehouse {$warehouseId} is not part of session {$sessionId}.");
            }
            if ($stw->status !== 'completed') {
                throw new \RuntimeException(
                    "Only completed warehouses can be recounted (current: {$stw->status}). "
                    . 'Open the count page to continue counting instead.'
                );
            }

            // Pre-recount snapshot — the forensic record of the previous
            // physical_qty values. Stored on the audit row's payload so it
            // survives even if the counter overwrites every line.
            $snapshotRows = DB::table('stock_take_items as sti')
                ->join('products as p', 'p.id', '=', 'sti.product_id')
                ->where('sti.stock_take_session_id', $sessionId)
                ->where('sti.warehouse_id', $warehouseId)
                ->orderBy('p.product_code')
                ->select('sti.product_id', 'p.product_code', 'sti.physical_qty', 'sti.system_qty')
                ->get();

            $previousSnapshot = $snapshotRows->map(fn($r) => [
                'product_id'   => (int) $r->product_id,
                'product_code' => $r->product_code,
                'physical_qty' => (float) $r->physical_qty,
                'system_qty'   => (float) $r->system_qty,
            ])->all();

            // Phase 7 policy: reset physical_qty to system_qty, or preserve?
            $resetToSystem = $this->policyService->recountResetToSystem();

            $now = now();
            $update = [
                'recounted_at' => $now,
                'recounted_by' => $actorId ?? auth()->id(),
                'updated_at'   => $now,
            ];
            if ($resetToSystem) {
                // Counter starts fresh — physical_qty is reset to the snapshot
                // system_qty so the counter re-counts from "as-book".
                $update['physical_qty'] = DB::raw('system_qty');
            }
            DB::table('stock_take_items')
                ->where('stock_take_session_id', $sessionId)
                ->where('warehouse_id', $warehouseId)
                ->update($update);

            // Warehouse: completed → recounting → counting (two writes so the
            // audit timeline shows the transient state distinctly; both happen
            // in the same transaction so no reader ever sees 'recounting'
            // committed unless a future async flow deliberately leaves it).
            DB::table('stock_take_warehouses')
                ->where('stock_take_session_id', $sessionId)
                ->where('warehouse_id', $warehouseId)
                ->update(['status' => 'recounting']);
            DB::table('stock_take_warehouses')
                ->where('stock_take_session_id', $sessionId)
                ->where('warehouse_id', $warehouseId)
                ->update(['status' => 'counting']);

            // If the session was submitted/approved, a recount invalidates the
            // approval — push back to counting and clear the approval artifacts
            // so the workflow restarts cleanly after the recount + save.
            $sessionFromStatus = $session->status;
            if (in_array($sessionFromStatus, ['submitted', 'approved'], true)) {
                DB::table('stock_take_sessions')
                    ->where('id', $sessionId)
                    ->update([
                        'status'             => 'counting',
                        'submitted_by'       => null,
                        'submitted_at'       => null,
                        'approved_by'        => null,
                        'approved_at'        => null,
                        'approval_comments'  => null,
                        'updated_at'         => $now,
                    ]);
            } else {
                DB::table('stock_take_sessions')
                    ->where('id', $sessionId)
                    ->update(['status' => 'counting', 'updated_at' => $now]);
            }

            $this->auditLogger->log(
                session:      $session,
                action:       'recount',
                fromStatus:   $sessionFromStatus,
                toStatus:     'counting',
                payload:      [
                    'warehouse_id'     => $warehouseId,
                    'reason'           => $reason,
                    'lines_recounted'  => $snapshotRows->count(),
                    'reset_to_system'  => $resetToSystem,
                    'previous_physical_qty' => $previousSnapshot,
                ],
                warehouseId:  $warehouseId,
                actorId:      $actorId,
            );

            return [
                'lines_recounted'  => $snapshotRows->count(),
                'reset_to_system'  => $resetToSystem,
                'previous_snapshot' => $previousSnapshot,
            ];
        });
    }

    /**
     * Phase 7: Upsert a single count line by product code (barcode scan path).
     *
     * Resolves the product by EXACT product_code match (barcodes are expected
     * to encode the product code; a fuzzy match would silently bind a scan to
     * the wrong product — unacceptable for a count). If the product is not in
     * the session's stock_take_items for this warehouse, the scan is rejected
     * with a clear error (the product was either never loaded — out of cycle-
     * count scope — or the code is wrong).
     *
     * Runs inside a DB::transaction with a session-row lockForUpdate. The
     * warehouse is NOT auto-completed (a scan is one line among many); the
     * counter finishes with the normal Save Counts button (or autosave handles
     * persistence line-by-line). This matches the acceptance criterion:
     * "saving updates the grid without a full reload" — the controller returns
     * the updated line + recomputed variance so the page updates live.
     *
     * @param int $sessionId
     * @param int $warehouseId
     * @param string $productCode  The scanned/typed code (exact match).
     * @param float $physicalQty   The counted quantity.
     * @param string|null $reason  Optional per-line reason.
     * @param int|null $actorId
     * @return array{product_id: int, product_code: string, product_name: string, unit: string, system_qty: float, physical_qty: float, difference: float, rate: float, value_diff: float, updated_at: string}
     * @throws \RuntimeException  When the product code is unknown, not in this
     *     warehouse's count, or the session/warehouse state doesn't allow edits.
     */
    public function upsertCount(int $sessionId, int $warehouseId, string $productCode, float $physicalQty, ?string $reason = null, ?int $actorId = null): array
    {
        $productCode = trim($productCode);
        if ($productCode === '') {
            throw new \InvalidArgumentException('Product code is required.');
        }
        if ($physicalQty < 0) {
            throw new \InvalidArgumentException('Physical quantity cannot be negative.');
        }

        return DB::transaction(function () use ($sessionId, $warehouseId, $productCode, $physicalQty, $reason, $actorId) {
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }
            // Scans are only valid while the session is editable (draft/counting/
            // submitted/approved — a submitted/approved session can still be
            // edited during a recount, but a posted/cancelled one cannot).
            if (in_array($session->status, ['posted', 'cancelled', 'reversed'], true)) {
                throw new \RuntimeException("Cannot edit a {$session->status} session.");
            }

            // Resolve the product by exact code. A failed resolution is the
            // most common scan error — surface it as a clear message.
            $product = DB::table('products')
                ->where('product_code', $productCode)
                ->select('id', 'product_code', 'product_name', 'unit')
                ->first();
            if (!$product) {
                throw new \RuntimeException("Unknown product code '{$productCode}'. No product matches that barcode.");
            }

            // The product must be in this warehouse's count (it was loaded at
            // setup). If not, it's out of scope for a cycle count or was never
            // set up — reject so the counter doesn't silently add a phantom line.
            $item = DB::table('stock_take_items')
                ->where('stock_take_session_id', $sessionId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $product->id)
                ->first();
            if (!$item) {
                throw new \RuntimeException(
                    "Product '{$productCode}' is not in this warehouse's count. "
                    . 'It may be out of the cycle-count scope — set up counts first or pick a different product.'
                );
            }

            $now = now();
            DB::table('stock_take_items')
                ->where('id', $item->id)
                ->update([
                    'physical_qty' => $physicalQty,
                    'reason'       => $reason !== null ? $reason : $item->reason,
                    'updated_at'   => $now,
                ]);

            $systemQty = (float) $item->system_qty;
            $rate      = (float) $item->rate;
            $difference = $physicalQty - $systemQty;

            $this->auditLogger->log(
                session:      $session,
                action:       'scan_count',
                fromStatus:   $session->status,
                toStatus:     $session->status,
                payload:      [
                    'warehouse_id'  => $warehouseId,
                    'product_id'    => (int) $product->id,
                    'product_code'  => $product->product_code,
                    'physical_qty'  => $physicalQty,
                    'system_qty'    => $systemQty,
                    'difference'    => $difference,
                    'reason'        => $reason,
                ],
                warehouseId:  $warehouseId,
                itemId:       (int) $item->id,
                actorId:      $actorId,
            );

            return [
                'product_id'   => (int) $product->id,
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'unit'         => $product->unit ?? '',
                'system_qty'   => $systemQty,
                'physical_qty' => $physicalQty,
                'difference'   => $difference,
                'rate'         => $rate,
                'value_diff'   => $difference * $rate,
                'updated_at'   => $now->toDateTimeString(),
            ];
        });
    }

    /**
     * Phase 7: Bulk-upsert count lines from parsed `code,qty` rows.
     *
     * Used by both the bulk-paste modal and the CSV import path (the
     * controller parses the input into a uniform $lines array before calling
     * this). Each line is {code: string, qty: float, reason?: string}. Unknown
     * codes / out-of-scope products are SKIPPED (not fatal) — the caller gets
     * a per-line report so the user can fix the bad rows and re-submit them.
     *
     * Runs inside a single DB::transaction with a session-row lockForUpdate,
     * so either ALL valid lines upsert or NONE do (the acceptance criterion:
     * "Pasting 50 code,qty lines upserts all 50 in one transaction"). Skipped
     * rows are reported back but do not abort the batch.
     *
     * @param int $sessionId
     * @param int $warehouseId
     * @param array<int, array{code: string, qty: numeric, reason?: string|null}> $lines
     * @param int|null $actorId
     * @param string $channel  Audit-action label distinguishing the import
     *     source: 'bulk_upsert' (bulk paste, default) or 'csv_import' (CSV
     *     upload). Both write the same data; the label lets the audit timeline
     *     show which channel produced the change.
     * @return array{updated: int, skipped: int, errors: array<int, array{line: int, code: string, error: string}>}
     * @throws \RuntimeException  When the session is not editable.
     */
    public function bulkUpsertCounts(int $sessionId, int $warehouseId, array $lines, ?int $actorId = null, string $channel = 'bulk_upsert'): array
    {
        if (empty($lines)) {
            return ['updated' => 0, 'skipped' => 0, 'errors' => []];
        }

        return DB::transaction(function () use ($sessionId, $warehouseId, $lines, $actorId) {
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }
            if (in_array($session->status, ['posted', 'cancelled', 'reversed'], true)) {
                throw new \RuntimeException("Cannot edit a {$session->status} session.");
            }

            // Pre-load the session's items for this warehouse keyed by
            // product_code → item row. One query, then in-memory lookups —
            // faster than N single-row queries and avoids N round-trips.
            $itemsByCode = DB::table('stock_take_items as sti')
                ->join('products as p', 'p.id', '=', 'sti.product_id')
                ->where('sti.stock_take_session_id', $sessionId)
                ->where('sti.warehouse_id', $warehouseId)
                ->select('sti.*', 'p.product_code', 'p.product_name', 'p.unit')
                ->get()
                ->keyBy('product_code');

            $updated = 0;
            $skipped = 0;
            $errors  = [];
            $now     = now();
            $touchedCodes = [];

            foreach ($lines as $idx => $line) {
                $lineNo = $idx + 1;
                $code = trim((string) ($line['code'] ?? ''));
                $qty  = $line['qty'] ?? null;
                $reason = isset($line['reason']) ? trim((string) $line['reason']) : null;

                if ($code === '') {
                    $skipped++;
                    $errors[] = ['line' => $lineNo, 'code' => '', 'error' => 'Empty product code.'];
                    continue;
                }
                if ($qty === null || !is_numeric($qty)) {
                    $skipped++;
                    $errors[] = ['line' => $lineNo, 'code' => $code, 'error' => 'Quantity is not a number.'];
                    continue;
                }
                $qty = (float) $qty;
                if ($qty < 0) {
                    $skipped++;
                    $errors[] = ['line' => $lineNo, 'code' => $code, 'error' => 'Quantity cannot be negative.'];
                    continue;
                }

                $item = $itemsByCode->get($code);
                if (!$item) {
                    $skipped++;
                    $errors[] = [
                        'line'  => $lineNo,
                        'code'  => $code,
                        'error' => "Product code '{$code}' is not in this warehouse's count (out of scope or unknown).",
                    ];
                    continue;
                }

                // Skip duplicate codes within the same batch — keep the FIRST
                // occurrence so the user sees a deterministic result (and a
                // clear error pointing at the duplicate).
                if (isset($touchedCodes[$code])) {
                    $skipped++;
                    $errors[] = [
                        'line'  => $lineNo,
                        'code'  => $code,
                        'error' => "Duplicate code '{$code}' in this batch (already updated on line {$touchedCodes[$code]}).",
                    ];
                    continue;
                }
                $touchedCodes[$code] = $lineNo;

                DB::table('stock_take_items')
                    ->where('id', $item->id)
                    ->update([
                        'physical_qty' => $qty,
                        'reason'       => $reason !== null && $reason !== '' ? $reason : $item->reason,
                        'updated_at'   => $now,
                    ]);
                $updated++;
            }

            $this->auditLogger->log(
                session:      $session,
                action:       $channel,
                fromStatus:   $session->status,
                toStatus:     $session->status,
                payload:      [
                    'warehouse_id'  => $warehouseId,
                    'lines_received' => count($lines),
                    'lines_updated'  => $updated,
                    'lines_skipped'  => $skipped,
                    'errors'         => $errors,
                ],
                warehouseId:  $warehouseId,
                actorId:      $actorId,
            );

            return ['updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
        });
    }

    /**
     * Phase 7: Auto-save a single count line with optimistic concurrency.
     *
     * The count page auto-saves each line as the counter types (debounced).
     * To prevent lost-update when two counters have the page open, the caller
     * passes the `updated_at` it last saw for the row; this method rejects
     * (HTTP 409-style) if the row's updated_at has moved since then, returning
     * the fresh row so the UI can re-prompt.
     *
     * @param int $sessionId
     * @param int $warehouseId
     * @param int $productId
     * @param float $physicalQty
     * @param string|null $reason
     * @param string|null $expectedUpdatedAt  ISO timestamp the caller last saw.
     * @param int|null $actorId
     * @return array{status: 'saved'|'conflict', line: array, current_updated_at: string}
     * @throws \RuntimeException  When the session/item is not editable.
     */
    public function autosaveCount(int $sessionId, int $warehouseId, int $productId, float $physicalQty, ?string $reason = null, ?string $expectedUpdatedAt = null, ?int $actorId = null): array
    {
        if ($physicalQty < 0) {
            throw new \InvalidArgumentException('Physical quantity cannot be negative.');
        }

        return DB::transaction(function () use ($sessionId, $warehouseId, $productId, $physicalQty, $reason, $expectedUpdatedAt, $actorId) {
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }
            if (in_array($session->status, ['posted', 'cancelled', 'reversed'], true)) {
                throw new \RuntimeException("Cannot edit a {$session->status} session.");
            }

            $item = DB::table('stock_take_items')
                ->where('stock_take_session_id', $sessionId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->first();
            if (!$item) {
                throw new \RuntimeException("Product {$productId} is not in this warehouse's count.");
            }

            $currentUpdatedAt = $item->updated_at ? (\Illuminate\Support\Carbon::parse($item->updated_at)->toDateTimeString()) : null;

            // Optimistic concurrency: if the caller passed an expected
            // updated_at and it doesn't match the current row, someone else
            // saved the line since the caller last loaded it. Return the
            // fresh row (status='conflict') so the UI can re-prompt; do NOT
            // overwrite the newer value.
            if ($expectedUpdatedAt !== null && $currentUpdatedAt !== null && $expectedUpdatedAt !== $currentUpdatedAt) {
                return [
                    'status'            => 'conflict',
                    'line'              => $this->formatItemLine($item),
                    'current_updated_at' => $currentUpdatedAt,
                ];
            }

            $now = now();
            DB::table('stock_take_items')
                ->where('id', $item->id)
                ->update([
                    'physical_qty' => $physicalQty,
                    'reason'       => $reason !== null ? $reason : $item->reason,
                    'updated_at'   => $now,
                ]);

            $this->auditLogger->log(
                session:      $session,
                action:       'autosave',
                fromStatus:   $session->status,
                toStatus:     $session->status,
                payload:      [
                    'warehouse_id'  => $warehouseId,
                    'product_id'    => $productId,
                    'physical_qty'  => $physicalQty,
                    'reason'        => $reason,
                ],
                warehouseId:  $warehouseId,
                itemId:       (int) $item->id,
                actorId:      $actorId,
            );

            // Reload to pick up the fresh updated_at + computed difference.
            $fresh = DB::table('stock_take_items')->where('id', $item->id)->first();
            return [
                'status'            => 'saved',
                'line'              => $this->formatItemLine($fresh),
                'current_updated_at' => $now->toDateTimeString(),
            ];
        });
    }

    /**
     * Phase 7: format a stock_take_items row (joined with product) as the
     * JSON shape the count page's autosave + scan handlers expect. Kept
     * private — only the Phase 7 count-UX methods return this shape.
     */
    private function formatItemLine($item): array
    {
        // When called from autosave, $item is the bare stock_take_items row
        // (no product join). Re-fetch the joined shape for the UI.
        if (!isset($item->product_code)) {
            $item = DB::table('stock_take_items as sti')
                ->join('products as p', 'p.id', '=', 'sti.product_id')
                ->where('sti.id', $item->id)
                ->select('sti.*', 'p.product_code', 'p.product_name', 'p.unit')
                ->first();
        }
        $systemQty  = (float) ($item->system_qty ?? 0);
        $physicalQty = (float) ($item->physical_qty ?? 0);
        $rate        = (float) ($item->rate ?? 0);
        $difference  = $physicalQty - $systemQty;
        return [
            'id'           => (int) $item->id,
            'product_id'   => (int) $item->product_id,
            'product_code' => $item->product_code,
            'product_name' => $item->product_name,
            'unit'         => $item->unit ?? '',
            'system_qty'   => $systemQty,
            'physical_qty' => $physicalQty,
            'difference'   => $difference,
            'rate'         => $rate,
            'value_diff'   => $difference * $rate,
            'reason'       => $item->reason,
            'updated_at'   => $item->updated_at ? \Illuminate\Support\Carbon::parse($item->updated_at)->toDateTimeString() : null,
            'recounted_at' => $item->recounted_at ?? null,
        ];
    }

    /**
     * Phase 4 (Stock Take plan): Submit a counting session for approval.
     *
     * Transition: counting → submitted. Records submitted_by/at. The session
     * is now locked for counters — only an approver (a different user, with
     * an approver role) can move it forward (approve → posted) or back
     * (reject → counting).
     *
     * Submitting is allowed only when all warehouses are 'completed' (the
     * same precondition as post). This keeps the "ready for review" check
     * in one place — the UI hides Submit until completed, but this server-
     * side guard closes the direct-POST bypass hole.
     *
     * If the policy says approval is NOT required for this session's
     * variance value, submit() still works (it's a no-op gate-wise) but
     * the counter could equally call post() directly. The typical flow
     * when require_approval=true is: counting → submit → approve → post.
     *
     * @param int $sessionId
     * @param int $submittedBy
     * @return StockTakeSession
     */
    public function submit(int $sessionId, int $submittedBy): StockTakeSession
    {
        return DB::transaction(function () use ($sessionId, $submittedBy) {
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }
            if (!$session->isCounting()) {
                throw new \RuntimeException(
                    "Only counting sessions can be submitted (current: {$session->status})."
                );
            }

            // Reuse the post-preflight guard: all warehouses must be completed.
            $incompleteCount = DB::table('stock_take_warehouses')
                ->where('stock_take_session_id', $sessionId)
                ->where('status', '<>', 'completed')
                ->count();
            if ($incompleteCount > 0) {
                throw new \RuntimeException(
                    "All warehouses must be marked 'completed' before submitting ({$incompleteCount} warehouse(s) still pending/counting)."
                );
            }

            $fromStatus = $session->status;
            $now = now();
            DB::table('stock_take_sessions')
                ->where('id', $sessionId)
                ->update([
                    'status'             => 'submitted',
                    'submitted_by'       => $submittedBy,
                    'submitted_at'       => $now,
                    // Clear any prior approval artifacts (e.g. from a previous
                    // submit→reject→resubmit cycle) so the new submission
                    // starts clean.
                    'approved_by'        => null,
                    'approved_at'        => null,
                    'approval_comments'  => null,
                    'updated_at'         => $now,
                ]);

            // Phase 3: the freeze stays on — a submitted session is still
            // mid-count from the warehouse's perspective. No call to
            // releaseSessionFreeze here.

            $this->auditLogger->log(
                session:    $session,
                action:     'submit',
                fromStatus: $fromStatus,
                toStatus:   'submitted',
                payload:    [
                    'submitted_by'      => $submittedBy,
                    'require_approval'  => $this->policyService->requireApproval(),
                    'approver_roles'    => $this->policyService->approverRoles(),
                    'variance_value'    => $this->computeVarianceValue($sessionId),
                ],
                actorId:    $submittedBy,
            );

            return StockTakeSession::with(['warehouses.warehouse', 'branch'])->find($sessionId);
        });
    }

    /**
     * Phase 4 (Stock Take plan): Approve a submitted session.
     *
     * Transition: submitted → approved. Records approved_by/at + comments.
     *
     * Segregation of duties: the approver CANNOT be the same user who
     * submitted (submitted_by). This is the core SoD check — a counter
     * cannot self-approve their own count. The check is here in the
     * service (not just the UI) so a forged request cannot bypass it.
     *
     * After approval, the session can be posted by any user with the post
     * permission (typically the same approver, or an admin). The post
     * guard below requires status='approved' when approval was required.
     *
     * @param int $sessionId
     * @param int $approvedBy
     * @param string $comments  Approver comments (optional but recommended).
     * @return StockTakeSession
     */
    public function approve(int $sessionId, int $approvedBy, string $comments = ''): StockTakeSession
    {
        return DB::transaction(function () use ($sessionId, $approvedBy, $comments) {
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }
            if (!$session->isSubmitted()) {
                throw new \RuntimeException(
                    "Only submitted sessions can be approved (current: {$session->status})."
                );
            }

            // ── Segregation of duties: approver ≠ submitter ─────────────
            // The counter who submitted cannot approve their own count.
            // admin/superadmin can override ONLY via an explicit policy
            // decision (not implemented by default — SoD is a hard rule).
            if ($session->submitted_by !== null && (int) $session->submitted_by === (int) $approvedBy) {
                throw new \RuntimeException(
                    'Segregation of duties: the user who submitted this session cannot approve it. '
                    . 'Ask another approver to review.'
                );
            }

            $fromStatus = $session->status;
            $now = now();
            DB::table('stock_take_sessions')
                ->where('id', $sessionId)
                ->update([
                    'status'            => 'approved',
                    'approved_by'       => $approvedBy,
                    'approved_at'       => $now,
                    'approval_comments' => $comments !== '' ? $comments : null,
                    'updated_at'        => $now,
                ]);

            $this->auditLogger->log(
                session:    $session,
                action:     'approve',
                fromStatus: $fromStatus,
                toStatus:   'approved',
                payload:    [
                    'approved_by'         => $approvedBy,
                    'submitted_by'        => $session->submitted_by,
                    'comments'            => $comments,
                    'variance_value'      => $this->computeVarianceValue($sessionId),
                ],
                actorId:    $approvedBy,
            );

            return StockTakeSession::with(['warehouses.warehouse', 'branch'])->find($sessionId);
        });
    }

    /**
     * Phase 4 (Stock Take plan): Reject a submitted session.
     *
     * Transition: submitted → counting. The session goes back to the
     * counter for re-count / correction. approval_comments carries the
     * approver's rejection reason (required — the counter needs to know
     * what to fix).
     *
     * The submitted_by/at columns are PRESERVED (not cleared) so the audit
     * timeline retains the full submit→reject→resubmit chain. The
     * approved_* columns are cleared because no approval happened.
     *
     * @param int $sessionId
     * @param int $rejectedBy
     * @param string $comments  Rejection reason (required).
     * @return StockTakeSession
     */
    public function reject(int $sessionId, int $rejectedBy, string $comments = ''): StockTakeSession
    {
        return DB::transaction(function () use ($sessionId, $rejectedBy, $comments) {
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }
            if (!$session->isSubmitted()) {
                throw new \RuntimeException(
                    "Only submitted sessions can be rejected (current: {$session->status})."
                );
            }
            if (trim($comments) === '') {
                throw new \RuntimeException('A rejection reason is required.');
            }

            $fromStatus = $session->status;
            $now = now();
            DB::table('stock_take_sessions')
                ->where('id', $sessionId)
                ->update([
                    'status'            => 'counting',
                    // Clear approval artifacts; keep submitted_by/at as history.
                    'approved_by'       => null,
                    'approved_at'       => null,
                    'approval_comments' => $comments,
                    'updated_at'        => $now,
                ]);

            // Reset warehouse statuses from 'completed' back to 'counting'
            // so the counter sees the session as "needs re-count". Items
            // are NOT reset — the counter keeps their previous physical_qty
            // values as a starting point.
            DB::table('stock_take_warehouses')
                ->where('stock_take_session_id', $sessionId)
                ->update(['status' => 'counting']);

            $this->auditLogger->log(
                session:    $session,
                action:     'reject',
                fromStatus: $fromStatus,
                toStatus:   'counting',
                payload:    [
                    'rejected_by'   => $rejectedBy,
                    'submitted_by'  => $session->submitted_by,
                    'comments'      => $comments,
                ],
                actorId:    $rejectedBy,
            );

            return StockTakeSession::with(['warehouses.warehouse', 'branch'])->find($sessionId);
        });
    }

    /**
     * Phase 4: Post the session — apply variances + post GL.
     *
     * For each item where physical ≠ system:
     *   - Apply stock movement via StockService (reference_type='stock_take')
     *   - Mark item is_applied=true
     * Then post a single GL journal for the net gain/loss.
     *
     * Phase 4 approval gate (enforced BEFORE any stock movement):
     *   - If approval is required for this session's variance value, the
     *     session MUST be in status='approved'. Posting from counting/
     *     draft is rejected with a clear error.
     *   - If approval is NOT required, posting from counting/draft is still
     *     allowed (backward-compatible — pre-Phase-4 behaviour).
     *   - Auto-approve path: if the session is in counting/draft, approval
     *     IS required, AND the variance value is strictly below
     *     auto_approve_below_value, the session is auto-approved inline
     *     (actor = system user id 0, comments='Auto-approved: below
     *     threshold') and then posted. This lets small-variance counts
     *     flow through without a human approver.
     *
     * @param int $sessionId
     * @param int $postedBy
     * @return StockTakeSession
     * @throws \RuntimeException If not postable, or stock/GL posting fails.
     */
    public function postSession(int $sessionId, int $postedBy): StockTakeSession
    {
        return DB::transaction(function () use ($sessionId, $postedBy) {
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }

            // Phase 4: approval gate (decided BEFORE any stock movement).
            //
            // Decision tree:
            //   1. status='approved' → post (the approval already happened).
            //   2. status='counting'/'draft':
            //      a. require_approval=true:
            //         - value < auto_approve_below_value → auto-approve inline,
            //           then post (no human approver needed).
            //         - else → REJECT: must be submitted + approved first.
            //      b. require_approval=false:
            //         - value >= variance_threshold_block → REJECT: forced
            //           through approval despite the global gate being off.
            //         - else → post directly (legacy pre-Phase-4 behaviour).
            //   3. Any other status (submitted/cancelled/posted) → REJECT.
            $varianceValue = $this->computeVarianceValue($sessionId);
            $autoApproved = false;

            if ($session->isApproved()) {
                // Already approved by a human approver (or auto-approved in
                // a previous post attempt that rolled back). Proceed to post.
            } elseif (in_array($session->status, ['counting', 'draft'], true)) {
                $requireApproval = $this->policyService->requireApproval();
                $autoBelow = $this->policyService->autoApproveBelowValue();
                $forceThreshold = $this->policyService->varianceThresholdBlock();

                if ($requireApproval) {
                    if ($autoBelow > 0 && $varianceValue < $autoBelow) {
                        // Inline auto-approve. Actor = system (null actor_id).
                        $autoApproved = $this->autoApproveInline(
                            $session,
                            $varianceValue,
                            $autoBelow
                        );
                    } else {
                        throw new \RuntimeException(
                            'This session requires approval before posting (variance value '
                            . number_format($varianceValue, 2) . ' meets the approval threshold). '
                            . 'Submit the session for approval and have an approver review it.'
                        );
                    }
                } else {
                    // require_approval is off — but the force-threshold can
                    // still mandate approval for high-impact variances.
                    if ($forceThreshold > 0 && $varianceValue >= $forceThreshold) {
                        throw new \RuntimeException(
                            'This session requires approval before posting (variance value '
                            . number_format($varianceValue, 2) . ' meets or exceeds the force-approval threshold '
                            . number_format($forceThreshold, 2) . '). '
                            . 'Submit the session for approval and have an approver review it.'
                        );
                    }
                    // Otherwise: post directly (legacy path).
                }
            } else {
                throw new \RuntimeException(
                    "Only approved/counting/draft sessions can be posted (current: {$session->status})."
                );
            }


            // Capture the transition's from-status BEFORE any writes mutate
            // the session row (used by the Phase 2 audit log below).
            $fromStatus = $session->status;

            // Phase 1: Guard — all warehouses must be 'completed' before posting.
            // The UI hides the Post button until all warehouses are completed, but
            // this server-side guard closes the "direct POST bypasses UI" hole.
            $incompleteCount = DB::table('stock_take_warehouses')
                ->where('stock_take_session_id', $sessionId)
                ->where('status', '<>', 'completed')
                ->count();
            if ($incompleteCount > 0) {
                throw new \RuntimeException(
                    "All warehouses must be marked 'completed' before posting ({$incompleteCount} warehouse(s) still pending/counting)."
                );
            }

            // Phase 8: per-warehouse transaction-scoped advisory lock.
            //
            // Two concurrent postSession calls for the SAME warehouse (e.g. the
            // same session double-clicked, or two overlapping sessions covering
            // a shared warehouse posted at once) would race on the
            // warehouse_stock rows and the negative-stock pre-check below. The
            // session-row lockForUpdate above already serializes posts of the
            // SAME session; this advisory lock additionally serializes posts
            // that touch the SAME warehouse across DIFFERENT sessions (which
            // the session-row lock cannot do — different sessions, different
            // rows).
            //
            // We use the two-int form pg_advisory_xact_lock(namespace, key)
            // with a fixed namespace constant so stock-take posts cannot
            // collide with the DocumentSequenceService single-int locks (which
            // hash doc_type/branch/period into one int4). The lock is
            // transaction-scoped — auto-released on COMMIT/ROLLBACK, so no
            // manual unlock is needed if the post throws partway through.
            $postWarehouseIds = DB::table('stock_take_warehouses')
                ->where('stock_take_session_id', $sessionId)
                ->pluck('warehouse_id')
                ->all();
            foreach ($postWarehouseIds as $lockWhId) {
                DB::select(
                    'SELECT pg_advisory_xact_lock(?, ?)',
                    [StockTakeService::POST_ADVISORY_LOCK_NAMESPACE, (int) $lockWhId]
                );
            }

            // Get all items with variance that haven't been applied yet.
            $varianceItems = DB::table('stock_take_items')
                ->where('stock_take_session_id', $sessionId)
                ->where('is_applied', false)
                ->whereRaw('physical_qty <> system_qty')
                ->get();

            // Phase 1: Negative-stock pre-check.
            // For each shortage (difference < 0), verify current warehouse_stock.qty
            // is sufficient. Locks the warehouse_stock rows (FOR UPDATE via the join)
            // so no other transaction can change them between this check and the
            // actual applyTransaction calls below. Throws a friendly exception with
            // the product list instead of letting the DB trigger raise a generic
            // check_violation error partway through the post.
            $this->assertNoNegativeStockOutcomes($sessionId);

            // Phase 3: Reconcile the setup-time snapshot (system_qty) against
            // the LIVE warehouse_stock.qty for every counted product. If stock
            // moved between setup and post (possible when freeze_outbound=false,
            // or via inbound receipts while frozen), the variance computed from
            // the stale system_qty would be wrong. This is a WARNING, not a
            // block — the post still proceeds (the negative-stock pre-check
            // above already prevents corruption); the drift is recorded in the
            // audit payload so reviewers can see exactly which products moved
            // during the count. With freeze_outbound=true this list should be
            // empty by construction for outbound drift (only inbound receipts
            // can have changed live qty).
            $stockDrift = $this->reconcileSnapshotWithLiveStock($sessionId);

            $totalGain = 0.0;
            $totalLoss = 0.0;
            $totalRevaluation = 0.0;
            // item_id => [rate, post_rate, system_rate, revaluation_amount, revaluation_line_id]
            // (resolved per line; written back to the item rows after GL posting so
            // the per-line journal_line_id / revaluation_line_id are available.)
            $resolved = [];

            // Phase 9: epsilon for the revaluation trigger. When the post-time
            // avg cost drifts from the setup-time system_rate by more than this,
            // an additional Dr/Cr Inventory/Inventory Revaluation Expense line is
            // posted for (post_rate - system_rate) * physical_qty.
            $revaluationEpsilon = $this->policyService->revaluationEpsilon();

            foreach ($varianceItems as $item) {
                $variance = (float) $item->physical_qty - (float) $item->system_qty;

                // Phase 9: re-fetch the LIVE post-time avg cost. The snapshot
                // rate (item->rate, captured at setup) may have drifted because
                // of inbound receipts, cost adjustments, transfers, or another
                // posted stock-take between setup and post. The GL must reflect
                // the cost as it stands at the moment of posting, not setup —
                // otherwise the books drift out of sync with the stock value.
                $postRate = $this->stockService->getWarehouseAvgCost(
                    $item->warehouse_id, $item->product_id
                );
                // Guard against a zero/negative post-time cost (e.g. a brand-
                // new product with no inbound yet). Fall back to the snapshot
                // rate so the GL entry still posts with a defensible value.
                if ($postRate <= 0) {
                    $postRate = (float) $item->rate;
                    if ($postRate <= 0) {
                        $postRate = (float) ($item->system_rate ?? 0);
                    }
                }
                $rate = $postRate; // the rate used for GL valuation = post-time cost

                // system_rate is the setup-time snapshot. For rows created
                // before Phase 9, the migration backfills system_rate from
                // rate; for rows created after, setupWarehouseCounts sets it.
                $systemRate = (float) ($item->system_rate ?? $item->rate ?? 0);

                // Apply the stock movement.
                // Positive variance = IN (use rate for avg_cost recalc).
                // Negative variance = OUT (rate is the cost; avg unchanged).
                $this->stockService->applyTransaction([
                    'warehouse_id' => $item->warehouse_id,
                    'product_id' => $item->product_id,
                    'qty' => $variance, // signed: +IN / -OUT
                    'rate' => $rate,
                    'reference_type' => 'stock_take',
                    'reference_id' => $sessionId,
                    'notes' => 'Stock Take #' . $session->session_code
                        . ($item->reason ? ' — ' . $item->reason : ''),
                    'transaction_date' => $session->session_date->format('Y-m-d'),
                    'created_by' => $postedBy,
                ]);

                // Track gain/loss for GL (at post-time cost).
                $value = abs($variance) * $rate;
                if ($variance > 0) {
                    $totalGain += $value;
                } else {
                    $totalLoss += $value;
                }

                // Phase 9: compute the revaluation adjusting amount for this
                // line. When the post-time cost drifted from the setup-time
                // snapshot by more than epsilon, the book value of the
                // COUNTED quantity (physical_qty) must be brought in line
                // with the post-time cost. The revaluation amount is:
                //   (post_rate - system_rate) * physical_qty
                // Positive = cost rose → Cr Inventory Revaluation Expense /
                // Dr Inventory (book value of counted stock increases).
                // Negative = cost fell → Dr Revaluation Expense / Cr Inventory.
                //
                // Only triggered when physical_qty ≠ 0 (a zero count has no
                // book value to revalue) AND the drift exceeds epsilon.
                $revaluationAmount = 0.0;
                $physicalQty = (float) $item->physical_qty;
                if ($physicalQty != 0.0 && abs($postRate - $systemRate) > $revaluationEpsilon) {
                    $revaluationAmount = ($postRate - $systemRate) * $physicalQty;
                    // Round to 6dp to avoid float dust (matches the column precision).
                    $revaluationAmount = round($revaluationAmount, 6);
                    $totalRevaluation += $revaluationAmount;
                }

                $resolved[$item->id] = [
                    'rate'                => $rate,
                    'post_rate'           => $postRate,
                    'system_rate'         => $systemRate,
                    'revaluation_amount'  => $revaluationAmount,
                ];
            }

            // Post GL journal (single entry for the net gain/loss + optional
            // revaluation adjusting lines). Phase 9: totalRevaluation is folded
            // into the same journal entry as separate Dr/Cr lines against
            // Inventory Revaluation Expense, so the entire post's GL impact
            // lives in one auditable journal entry.
            $journalEntryId = null;
            $gainInventoryLineId = null;
            $lossInventoryLineId = null;
            $revaluationInventoryLineId = null;
            $revaluationExpenseLineId = null;
            if ($totalGain >= 0.01 || $totalLoss >= 0.01 || abs($totalRevaluation) >= 0.01) {
                $journalEntryId = $this->postStockTakeGL(
                    $session,
                    $totalGain,
                    $totalLoss,
                    $postedBy,
                    $totalRevaluation
                );

                // Phase 1: Capture per-line journal_line_id for traceability.
                // Query back the journal lines and identify the Inventory-side lines
                // (gain → Dr Inventory; loss → Cr Inventory). Each variance item is
                // linked to the Inventory line of its bucket. Phase 9 also captures
                // the revaluation lines so each item with cost drift links to its
                // revaluation GL line.
                $inventoryLedgerId = $this->journalPosting->lookupLedgerByNature('inventory');
                if ($inventoryLedgerId) {
                    $journalLines = DB::table('journal_lines')
                        ->where('journal_entry_id', $journalEntryId)
                        ->get();
                    $gainLine = $journalLines->first(
                        fn($l) => $l->ledger_id == $inventoryLedgerId && $l->debit > 0
                    );
                    $lossLine = $journalLines->first(
                        fn($l) => $l->ledger_id == $inventoryLedgerId && $l->credit > 0
                    );
                    $gainInventoryLineId = $gainLine?->id;
                    $lossInventoryLineId = $lossLine?->id;

                    // Phase 9: the revaluation pair. When totalRevaluation > 0
                    // (cost rose), Dr Inventory / Cr Revaluation Expense. When
                    // < 0 (cost fell), Dr Revaluation Expense / Cr Inventory.
                    // The Inventory-side line of the revaluation pair is the
                    // one linked to each item row (revaluation_line_id).
                    if (abs($totalRevaluation) >= 0.01) {
                        if ($totalRevaluation > 0) {
                            // Dr Inventory, Cr Revaluation Expense.
                            $revaluationInventoryLineId = $journalLines->first(
                                fn($l) => $l->ledger_id == $inventoryLedgerId
                                    && $l->debit > 0
                                    && str_contains((string) $l->memo, 'revaluation')
                            )?->id;
                        } else {
                            // Dr Revaluation Expense, Cr Inventory.
                            $revaluationInventoryLineId = $journalLines->first(
                                fn($l) => $l->ledger_id == $inventoryLedgerId
                                    && $l->credit > 0
                                    && str_contains((string) $l->memo, 'revaluation')
                            )?->id;
                        }
                    }
                }
            }

            // Phase 1: Mark all variance items as applied + back-link journal_line_id.
            // (Deferred to here — after GL posting — so journal_line_id is available.
            // If the transaction fails before this point, all stock movements and GL
            // inserts are rolled back, so items correctly remain is_applied=false.)
            // Phase 9: also persist post_rate, system_rate, revaluation_amount, and
            // revaluation_line_id so the variance report can show the cost columns
            // without recomputation.
            foreach ($varianceItems as $item) {
                $variance = (float) $item->physical_qty - (float) $item->system_qty;
                $lineId = $variance > 0 ? $gainInventoryLineId : $lossInventoryLineId;
                $r = $resolved[$item->id];
                // Only link the revaluation line when this item actually
                // contributed a revaluation amount (otherwise revaluation_line_id
                // stays null, matching the 0 revaluation_amount).
                $revalLineId = abs($r['revaluation_amount']) >= 0.01
                    ? $revaluationInventoryLineId
                    : null;
                DB::table('stock_take_items')
                    ->where('id', $item->id)
                    ->update([
                        'is_applied' => true,
                        'rate' => $r['rate'],
                        'system_rate' => $r['system_rate'],
                        'post_rate' => $r['post_rate'],
                        'revaluation_amount' => $r['revaluation_amount'],
                        'journal_line_id' => $lineId,
                        'revaluation_line_id' => $revalLineId,
                        'updated_at' => now(),
                    ]);
            }

            // Mark session as posted.
            DB::table('stock_take_sessions')
                ->where('id', $sessionId)
                ->update([
                    'status' => 'posted',
                    'journal_entry_id' => $journalEntryId,
                    'updated_at' => now(),
                ]);

            // Phase 3: release the outbound freeze on this session's warehouses.
            // A posted session is no longer "actively counting", so its freeze
            // must end. refreshWarehouseFreezeFlags recomputes the flag from
            // ALL remaining active (draft/counting) freezing sessions, so if
            // another overlapping session still covers a warehouse, its flag
            // stays true (acceptance: "flag stays true until the last session
            // ends").
            $this->releaseSessionFreeze($sessionId);

            // Phase 2: audit-log the post (the critical transition). Logged
            // AFTER the session status update, so the to_status='posted'
            // reflects the committed state. If anything in this transaction
            // rolls back, the audit row rolls back with it (same transaction).
            $this->auditLogger->log(
                session:    $session,
                action:     'post',
                fromStatus: $fromStatus,
                toStatus:   'posted',
                payload:    [
                    'variance_lines'   => $varianceItems->count(),
                    'total_gain'       => round($totalGain, 4),
                    'total_loss'       => round($totalLoss, 4),
                    'journal_entry_id' => $journalEntryId,
                    // Phase 10: if this is a re-post (after re-open), record
                    // the re-open context so the audit timeline shows the full
                    // post → reverse → re_open → re-post chain. re_open_count
                    // is 0 for a first post; >0 for a re-post. reversal_of_
                    // entry_id links back to the prior reversed post's JE.
                    'is_repost'             => ((int) $session->re_open_count) > 0,
                    're_open_count'         => (int) $session->re_open_count,
                    'reversal_of_entry_id'  => $session->reversal_of_entry_id,
                    'stock_movements'  => $varianceItems->count(),
                    // Phase 9: cost-drift revaluation summary. total_revaluation
                    // is the net (post_rate - system_rate) * physical_qty across
                    // all variance lines where the drift exceeded epsilon. A
                    // non-zero value means an additional Dr/Cr Inventory/
                    // Inventory Revaluation Expense pair was posted in the same
                    // journal entry. reval_lines = count of items that
                    // contributed a non-zero revaluation_amount.
                    'total_revaluation'  => round($totalRevaluation, 6),
                    'reval_lines'        => collect($resolved)
                        ->filter(fn($r) => abs($r['revaluation_amount']) >= 0.01)
                        ->count(),
                    'revaluation_epsilon' => $revaluationEpsilon,
                    // Phase 3: surface the stock-drift warning so reviewers can
                    // see which products moved during the count. Empty when the
                    // outbound freeze held for the full count.
                    'stock_drift'      => $stockDrift,
                    'stock_drift_count' => count($stockDrift),
                    'freeze_outbound'  => (bool) $session->freeze_outbound,
                    // Phase 4: record the approval-gate decision that admitted
                    // this post. auto_approved=true means the session was
                    // promoted counting→approved inline at post time (no human
                    // approver); approval_required=false means the post went
                    // through the legacy direct path.
                    'approval_required' => $this->policyService->approvalRequiredForVariance($varianceValue),
                    'auto_approved'     => $autoApproved,
                    'approved_by'       => $session->approved_by,
                    'variance_value'    => round($varianceValue, 4),
                ],
                actorId:    $postedBy,
            );

            return StockTakeSession::with(['warehouses.warehouse', 'branch', 'items.product', 'journalEntry.lines.ledger'])
                ->find($sessionId);
        });
    }

    /**
     * Phase 1: Pre-check that no shortage variance would drive warehouse_stock
     * below zero. Locks the relevant warehouse_stock rows (FOR UPDATE via the
     * join) for the duration of the transaction so the check is race-free.
     *
     * For each shortage item (physical_qty < system_qty), compares the current
     * warehouse_stock.qty against the shortage magnitude. If any would result in
     * a negative qty, throws StockTakeNegativeStockException with the full
     * product list — BEFORE any stock movement is applied.
     *
     * @throws StockTakeNegativeStockException
     */
    private function assertNoNegativeStockOutcomes(int $sessionId): void
    {
        $shortages = DB::table('stock_take_items as sti')
            ->leftJoin('warehouse_stock as ws', function ($join) {
                $join->on('ws.warehouse_id', '=', 'sti.warehouse_id')
                     ->on('ws.product_id', '=', 'sti.product_id');
            })
            ->join('products as p', 'p.id', '=', 'sti.product_id')
            ->where('sti.stock_take_session_id', $sessionId)
            ->where('sti.is_applied', false)
            ->whereRaw('sti.physical_qty < sti.system_qty')
            ->select(
                'sti.product_id',
                'sti.warehouse_id',
                'sti.system_qty',
                'sti.physical_qty',
                DB::raw('COALESCE(ws.qty, 0) as current_qty'),
                'p.product_code',
                'p.product_name'
            )
            ->lockForUpdate()
            ->get();

        $offending = [];
        foreach ($shortages as $s) {
            $variance = (float) $s->physical_qty - (float) $s->system_qty;
            $resultingQty = (float) $s->current_qty + $variance;
            if ($resultingQty < -0.0001) {
                $offending[] = [
                    'product_id' => $s->product_id,
                    'product_code' => $s->product_code,
                    'product_name' => $s->product_name,
                    'warehouse_id' => $s->warehouse_id,
                    'system_qty' => (float) $s->system_qty,
                    'physical_qty' => (float) $s->physical_qty,
                    'current_stock' => (float) $s->current_qty,
                    'shortage' => abs($variance),
                    'resulting_qty' => $resultingQty,
                ];
            }
        }

        if (!empty($offending)) {
            throw new StockTakeNegativeStockException($offending);
        }
    }

    /**
     * Phase 10: Cancel a session.
     *
     * Draft/counting only — NO stock or GL reversal (nothing was applied).
     * Sets status='cancelled'. For posted sessions, use reverseSession() —
     * cancelling a posted session is a category error (it has stock + GL
     * impact that must be reversed, not just marked dead).
     *
     * Phase 0–9 behaviour was conflated: cancelSession did both "cancel a
     * draft" and "reverse a posted session" under one method. Phase 10
     * splits them so the audit trail distinguishes the two semantically
     * different operations. The old `cancelled` terminal state is now
     * reserved for never-posted sessions; `reversed` is the terminal-ish
     * state for posted sessions that have been rolled back.
     *
     * @param int $sessionId
     * @param int $cancelledBy
     * @param string $reason
     * @return StockTakeSession
     * @throws \RuntimeException If session is posted (use reverseSession),
     *                           already cancelled/reversed, or not found.
     */
    public function cancelSession(int $sessionId, int $cancelledBy, string $reason = ''): StockTakeSession
    {
        return DB::transaction(function () use ($sessionId, $cancelledBy, $reason) {
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }
            if ($session->isCancelled()) {
                throw new \RuntimeException("Session is already cancelled.");
            }
            if ($session->isReversed()) {
                throw new \RuntimeException("Session is already reversed. Use re-open to return it to counting.");
            }
            if ($session->isPosted()) {
                // Phase 10: posted sessions must be REVERSED, not cancelled.
                // Reversal properly undoes the stock movements + GL entry;
                // cancelling would leave the books out of sync. Point the
                // caller at the right method.
                throw new \RuntimeException(
                    "Posted sessions cannot be cancelled — they must be reversed. "
                    . "Call reverseSession() to undo the stock movements and GL entry, "
                    . "then optionally reOpen() to return the session to counting."
                );
            }

            // Capture the transition's from-status BEFORE any writes (Phase 2).
            $fromStatus = $session->status;

            DB::table('stock_take_sessions')
                ->where('id', $sessionId)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            // Phase 3: release the outbound freeze on this session's warehouses.
            // A cancelled session is no longer "actively counting" — the freeze
            // must end. If the session was never frozen (freeze_outbound=false)
            // this is a no-op recomputation that leaves flags unchanged.
            // Honors overlapping sessions.
            $this->releaseSessionFreeze($sessionId);

            // Phase 2: audit-log the cancel (no reversal — nothing to reverse).
            $this->auditLogger->log(
                session:    $session,
                action:     'cancel',
                fromStatus: $fromStatus,
                toStatus:   'cancelled',
                payload:    [
                    'reason'      => $reason,
                    'was_posted'  => false,
                    // Phase 3: record whether the freeze was released.
                    'freeze_outbound' => (bool) $session->freeze_outbound,
                    'freeze_released' => (bool) $session->freeze_outbound,
                ],
                actorId:    $cancelledBy,
            );

            return StockTakeSession::find($sessionId);
        });
    }

    /**
     * Phase 10: Reverse a POSTED session.
     *
     * Full stock + GL reversal. Sets status='reversed' + the four reversal
     * columns (is_reversed, reversed_at, reversed_by, reverse_reason). The
     * prior journal_entry_id is preserved on the session row AND linked via
     * the new reversal_of_entry_id column so the audit chain is traceable
     * (the original post's JE stays linked even after a re-post creates a
     * new JE).
     *
     * The reversal is append-only: the original stock_transactions and
     * journal_entries rows stay (they are history); new reversal rows are
     * created with is_reversed=true pointing back at them. This matches the
     * Phase 0 reversal pattern used by every other transactional table.
     *
     * A reversed session can be re-opened via reOpen() (reversed → counting)
     * up to the stock_take.max_reopens policy cap.
     *
     * @param int $sessionId
     * @param int $reversedBy
     * @param string $reason  REQUIRED — the reversal reason (audit trail).
     * @return StockTakeSession
     * @throws \RuntimeException If session is not posted, already reversed,
     *                           or not found.
     */
    public function reverseSession(int $sessionId, int $reversedBy, string $reason = ''): StockTakeSession
    {
        if (trim($reason) === '') {
            throw new \RuntimeException('A reversal reason is required.');
        }

        return DB::transaction(function () use ($sessionId, $reversedBy, $reason) {
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }
            if ($session->isReversed()) {
                throw new \RuntimeException("Session is already reversed.");
            }
            if ($session->isCancelled()) {
                throw new \RuntimeException("Session is cancelled (never posted) — nothing to reverse.");
            }
            if (!$session->isPosted()) {
                throw new \RuntimeException(
                    "Only posted sessions can be reversed (current: {$session->status}). "
                    . "Draft/counting sessions should be cancelled instead."
                );
            }

            // Capture the transition's from-status BEFORE any writes (Phase 2).
            $fromStatus = $session->status;
            $priorJournalEntryId = $session->journal_entry_id;

            $stockTxsReversed = 0;
            $journalReversed = false;
            $reversalEntryId = null;

            // Reverse GL.
            if ($priorJournalEntryId) {
                $reversalEntryId = $this->journalPosting->reverseJournalEntry(
                    $priorJournalEntryId,
                    $reversedBy,
                    "Stock take reversed: {$reason}"
                );
                $journalReversed = true;
            }

            // Reverse each stock movement.
            $stockTxs = DB::table('stock_transactions')
                ->where('reference_type', 'stock_take')
                ->where('reference_id', $sessionId)
                ->where('is_reversed', false)
                ->get();

            foreach ($stockTxs as $tx) {
                $this->stockService->reverseTransaction(
                    $tx->id, $reversedBy,
                    "Stock take reversed: {$reason}"
                );
                $stockTxsReversed++;
            }

            // Phase 10: set status='reversed' + reversal columns + link the
            // prior journal entry via reversal_of_entry_id (the audit chain).
            // journal_entry_id is left pointing at the ORIGINAL post's JE
            // (which is now is_reversed=true) so the show page can still
            // render the original JE; reversal_of_entry_id holds the SAME
            // value, distinguishing "this is the post being reversed" from
            // a future re-post's fresh journal_entry_id.
            DB::table('stock_take_sessions')
                ->where('id', $sessionId)
                ->update([
                    'status' => 'reversed',
                    'is_reversed' => true,
                    'reversed_at' => now(),
                    'reversed_by' => $reversedBy,
                    'reverse_reason' => $reason,
                    // Preserve journal_entry_id (the original post's JE) AND
                    // mirror it into reversal_of_entry_id so the audit chain
                    // survives a re-post (which will overwrite journal_entry_id
                    // with the NEW JE's id, leaving reversal_of_entry_id as
                    // the permanent link to the reversed prior post).
                    'reversal_of_entry_id' => $priorJournalEntryId,
                    'updated_at' => now(),
                ]);

            // Phase 3: release the outbound freeze on this session's
            // warehouses. A reversed session is no longer "actively counting".
            $this->releaseSessionFreeze($sessionId);

            // Phase 2: audit-log the reversal. action='reverse' so the
            // critical-events partial index catches it. from→to is
            // posted→reversed (the new Phase 10 distinction).
            $this->auditLogger->log(
                session:    $session,
                action:     'reverse',
                fromStatus: $fromStatus,
                toStatus:   'reversed',
                payload:    [
                    'reason'              => $reason,
                    'stock_reversed'      => $stockTxsReversed,
                    'journal_reversed'    => $journalReversed,
                    'journal_entry_id'    => $priorJournalEntryId,
                    'reversal_entry_id'   => $reversalEntryId,
                    'reversal_of_entry_id' => $priorJournalEntryId,
                ],
                actorId:    $reversedBy,
            );

            return StockTakeSession::find($sessionId);
        });
    }

    /**
     * Phase 10: Re-open a REVERSED session for correction + re-posting.
     *
     * Transitions reversed → counting. The reversal rows in
     * stock_transactions and journal_entries STAY (they are audit history
     * — append-only). stock_take_items.is_applied is reset to false so the
     * counts can be re-entered and the session re-posted. Re-posting
     * (postSession) creates a NEW journal entry; the old reversed entry
     * stays linked on the session via reversal_of_entry_id.
     *
     * The re_open_count is incremented and capped by the
     * stock_take.max_reopens policy (default 1). Exceeding the cap throws
     * a clear error. last_reopened_at/by record the most recent re-open.
     *
     * The approval workflow is reset: submitted_by/at and approved_by/at
     * are cleared so the re-counted session goes through approval again
     * (a re-opened session is a materially different count from the
     * original; the prior approval does not carry over).
     *
     * @param int $sessionId
     * @param int $reopenedBy
     * @param string $reason  REQUIRED — the re-open reason (audit trail).
     * @return StockTakeSession
     * @throws \RuntimeException If session is not reversed, the re-open cap
     *                           is exceeded, or the session is not found.
     */
    public function reOpen(int $sessionId, int $reopenedBy, string $reason = ''): StockTakeSession
    {
        if (trim($reason) === '') {
            throw new \RuntimeException('A re-open reason is required.');
        }

        return DB::transaction(function () use ($sessionId, $reopenedBy, $reason) {
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }
            if (!$session->isReversed()) {
                throw new \RuntimeException(
                    "Only reversed sessions can be re-opened (current: {$session->status})."
                );
            }

            // Phase 10: enforce the re-open cap. max_reopens=0 forbids re-
            // opening entirely (reversed = hard terminal). max_reopens=1
            // (default) allows one re-open per session.
            $maxReopens = $this->policyService->maxReopens();
            $currentCount = (int) $session->re_open_count;
            if ($maxReopens <= 0) {
                throw new \RuntimeException(
                    "This session cannot be re-opened (policy stock_take.max_reopens=0 — "
                    . "reversed is a hard terminal state)."
                );
            }
            if ($currentCount >= $maxReopens) {
                throw new \RuntimeException(
                    "This session has already been re-opened {$currentCount} time(s) and "
                    . "cannot be re-opened again (policy cap: stock_take.max_reopens={$maxReopens}). "
                    . "Create a new stock-take session if further correction is needed."
                );
            }

            // Capture the transition's from-status BEFORE any writes (Phase 2).
            $fromStatus = $session->status;
            $newCount = $currentCount + 1;

            // Reset stock_take_items so the counts can be re-entered:
            //   - is_applied = false (so postSession will re-apply them)
            //   - journal_line_id = null (the old line points at the reversed JE)
            //   - revaluation_line_id = null (same — the old reval line is reversed)
            //   - post_rate = null (Phase 9: re-fetched at the next post)
            //   - revaluation_amount = 0 (Phase 9: recomputed at the next post)
            // physical_qty is PRESERVED so the counter sees the prior count
            // and adjusts (same UX as a recount — the counter doesn't start
            // from a blank slate). system_rate is the immutable setup
            // snapshot and stays. rate (the GL rate) is overwritten at the
            // next post with the new post-time cost.
            DB::table('stock_take_items')
                ->where('stock_take_session_id', $sessionId)
                ->update([
                    'is_applied' => false,
                    'journal_line_id' => null,
                    'revaluation_line_id' => null,
                    'post_rate' => null,
                    'revaluation_amount' => 0,
                    'updated_at' => now(),
                ]);

            // Reset warehouse statuses: any 'completed' warehouse goes back
            // to 'counting' so the counter can re-enter counts. 'pending'/
            // 'counting' warehouses stay as-is (the counter was mid-count).
            // 'recounting' (Phase 7 transient) also goes to 'counting'.
            DB::table('stock_take_warehouses')
                ->where('stock_take_session_id', $sessionId)
                ->whereIn('status', ['completed', 'recounting'])
                ->update(['status' => 'counting', 'updated_at' => now()]);

            // Transition reversed → counting + bump re_open_count + record
            // who/when + reset the approval workflow (the re-counted session
            // must go through approval again — the prior approval does not
            // carry over to a materially different count).
            //
            // journal_entry_id is LEFT pointing at the (now-reversed) prior
            // post's JE. The show page renders it with a "reversed" badge.
            // When the session is re-posted, postSession overwrites it with
            // the NEW JE's id. reversal_of_entry_id stays as the permanent
            // audit link to the reversed prior post.
            DB::table('stock_take_sessions')
                ->where('id', $sessionId)
                ->update([
                    'status' => 'counting',
                    're_open_count' => $newCount,
                    'last_reopened_at' => now(),
                    'last_reopened_by' => $reopenedBy,
                    // Reset approval workflow — the re-counted session must
                    // be submitted + approved again.
                    'submitted_by' => null,
                    'submitted_at' => null,
                    'approved_by' => null,
                    'approved_at' => null,
                    'approval_comments' => null,
                    'updated_at' => now(),
                ]);

            // Phase 3: re-assert the outbound freeze if the session was
            // originally freezing. A re-opened counting session is "actively
            // counting" again, so the freeze must resume. refreshWarehouse-
            // FreezeFlags recomputes from ALL active sessions — if this
            // session freezes, its warehouses' flags go back to true.
            if ($session->freeze_outbound) {
                $whIds = DB::table('stock_take_warehouses')
                    ->where('stock_take_session_id', $sessionId)
                    ->pluck('warehouse_id')
                    ->all();
                $this->refreshWarehouseFreezeFlags($whIds);
            }

            // Phase 2: audit-log the re-open. action='re_open' so the
            // critical-events partial index catches it. from→to is
            // reversed→counting.
            $this->auditLogger->log(
                session:    $session,
                action:     're_open',
                fromStatus: $fromStatus,
                toStatus:   'counting',
                payload:    [
                    'reason'              => $reason,
                    're_open_count'       => $newCount,
                    'max_reopens'         => $maxReopens,
                    'reopens_remaining'   => max(0, $maxReopens - $newCount),
                    'prior_journal_entry_id' => $session->journal_entry_id,
                    'reversal_of_entry_id'   => $session->reversal_of_entry_id,
                    'approval_reset'      => true,
                ],
                actorId:    $reopenedBy,
            );

            return StockTakeSession::find($sessionId);
        });
    }

    /**
     * Post the GL journal entry for a stock-take post.
     *
     * Lines posted:
     *   - Gain (totalGain > 0):  Dr Inventory / Cr Inventory Surplus
     *   - Loss (totalLoss > 0):  Dr Inventory Shrinkage / Cr Inventory
     *   - Phase 9 Revaluation (|totalRevaluation| > 0):
     *       cost rose (totalRevaluation > 0): Dr Inventory / Cr Revaluation Expense
     *       cost fell (totalRevaluation < 0): Dr Revaluation Expense / Cr Inventory
     *
     * All lines live in ONE journal entry so the entire post's GL impact is
     * a single auditable unit. The Inventory-side line of each bucket is the
     * one back-linked to each stock_take_items row (journal_line_id for
     * variance, revaluation_line_id for revaluation).
     *
     * Phase 9: the $totalRevaluation param is new. When zero/absent, the
     * behaviour is identical to pre-Phase-9 (no revaluation lines posted).
     *
     * @param float $totalRevaluation  Net (post_rate - system_rate) * physical_qty.
     * @return int  journal_entries.id
     */
    private function postStockTakeGL(
        StockTakeSession $session,
        float $totalGain,
        float $totalLoss,
        int $createdBy,
        float $totalRevaluation = 0.0
    ): int {
        $inventoryLedgerId = $this->journalPosting->lookupLedgerByNature('inventory');
        if (!$inventoryLedgerId) {
            throw new \RuntimeException('Inventory ledger not found (nature: inventory).');
        }

        $lines = [];

        // Gain: Dr Inventory / Cr Surplus
        if ($totalGain >= 0.01) {
            $surplusLedgerId = $this->journalPosting->lookupLedgerByNature('inventory_surplus');
            if (!$surplusLedgerId) {
                throw new \RuntimeException('Inventory surplus ledger not found.');
            }
            $lines[] = [
                'ledger_id' => $inventoryLedgerId,
                'debit' => $totalGain, 'credit' => 0,
                'memo' => 'Stock take gain — ' . $session->session_code,
            ];
            $lines[] = [
                'ledger_id' => $surplusLedgerId,
                'debit' => 0, 'credit' => $totalGain,
                'memo' => 'Stock take surplus — ' . $session->session_code,
            ];
        }

        // Loss: Dr Shrinkage / Cr Inventory
        if ($totalLoss >= 0.01) {
            $shrinkageLedgerId = $this->journalPosting->lookupLedgerByNature('inventory_shrinkage');
            if (!$shrinkageLedgerId) {
                throw new \RuntimeException('Inventory shrinkage ledger not found.');
            }
            $lines[] = [
                'ledger_id' => $shrinkageLedgerId,
                'debit' => $totalLoss, 'credit' => 0,
                'memo' => 'Stock take loss — ' . $session->session_code,
            ];
            $lines[] = [
                'ledger_id' => $inventoryLedgerId,
                'debit' => 0, 'credit' => $totalLoss,
                'memo' => 'Stock take decrease — ' . $session->session_code,
            ];
        }

        // Phase 9: cost-drift revaluation. When the post-time avg cost
        // differs from the setup-time system_rate by more than epsilon,
        // post an adjusting entry to bring the book value of the counted
        // quantity in line with the post-time cost.
        //   totalRevaluation > 0 (cost rose): Dr Inventory / Cr Revaluation Expense
        //   totalRevaluation < 0 (cost fell): Dr Revaluation Expense / Cr Inventory
        // The memo carries 'revaluation' so postSession can identify the
        // Inventory-side line of the pair when back-linking revaluation_line_id.
        if (abs($totalRevaluation) >= 0.01) {
            $revaluationLedgerId = $this->journalPosting->lookupLedgerByNature('inventory_revaluation');
            if (!$revaluationLedgerId) {
                throw new \RuntimeException(
                    'Inventory revaluation ledger not found (nature: inventory_revaluation). '
                    . 'Run the Phase 9 migration to seed it, or add a ledger with that nature manually.'
                );
            }
            $absAmount = abs($totalRevaluation);
            if ($totalRevaluation > 0) {
                // Cost rose → book value of counted stock increases.
                $lines[] = [
                    'ledger_id' => $inventoryLedgerId,
                    'debit' => $absAmount, 'credit' => 0,
                    'memo' => 'Stock take revaluation (cost rose) — ' . $session->session_code,
                ];
                $lines[] = [
                    'ledger_id' => $revaluationLedgerId,
                    'debit' => 0, 'credit' => $absAmount,
                    'memo' => 'Stock take revaluation gain — ' . $session->session_code,
                ];
            } else {
                // Cost fell → book value of counted stock decreases.
                $lines[] = [
                    'ledger_id' => $revaluationLedgerId,
                    'debit' => $absAmount, 'credit' => 0,
                    'memo' => 'Stock take revaluation loss — ' . $session->session_code,
                ];
                $lines[] = [
                    'ledger_id' => $inventoryLedgerId,
                    'debit' => 0, 'credit' => $absAmount,
                    'memo' => 'Stock take revaluation (cost fell) — ' . $session->session_code,
                ];
            }
        }

        return $this->journalPosting->createJournalEntry([
            'entry_date' => $session->session_date->format('Y-m-d'),
            'reference_type' => 'stock_take',
            'reference_id' => $session->id,
            'branch_id' => $session->branch_id,
            'description' => 'Stock Take ' . $session->session_code
                . ($session->notes ? ' — ' . $session->notes : ''),
            'source' => 'stock_take',
            'created_by' => $createdBy,
        ], $lines);
    }

    /**
     * Generate atomic session code: ST-YYYYMMDD-NNNN.
     * Uses DocumentSequenceService with advisory locks (Task 20).
     */
    private function generateSessionCode(): string
    {
        return DocumentSequenceService::nextCode(
            docType:  'stock_take',
            prefix:   'ST',
            datePart: now()->format('Ymd'),
            padLength: 4,
        );
    }

    /**
     * Phase 4: compute the total |gain|+|loss| value of a session's variance.
     *
     * This is the single number that drives the approval-gate decision:
     *   - approvalRequiredForVariance(value) → does this session need a
     *     human approver before posting?
     *
     * The value is `SUM(ABS(physical_qty - system_qty) * COALESCE(rate, 0))`
     * across all items in the session. It uses the per-line `rate` captured
     * at setup time (Phase 3 snapshot); the post-time GL posting may use a
     * slightly different post-time avg cost (Phase 9 will reconcile that).
     *
     * Runs OUTSIDE the postSession transaction's row locks (called before
     * the lockForUpdate in the approval-gate block), but the value is
     * read-only here — the actual post re-fetches everything under the
     * session row lock. A race between this read and the post-commit state
     * is harmless: the gate uses a snapshot of the variance value, and the
     * post's negative-stock pre-check still catches corruption.
     *
     * @param int $sessionId
     * @return float  Total |gain|+|loss| value in currency units (≥ 0).
     */
    private function computeVarianceValue(int $sessionId): float
    {
        $row = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sessionId)
            ->whereRaw('physical_qty <> system_qty')
            ->selectRaw(
                'COALESCE(SUM(ABS(physical_qty - system_qty) * COALESCE(rate, 0)), 0) as total_value'
            )
            ->first();
        return (float) ($row->total_value ?? 0);
    }

    /**
     * Phase 4: inline auto-approval — promote a counting/draft session to
     * 'approved' without a human approver, because its variance value is
     * below the auto_approve_below_value threshold.
     *
     * Called from postSession when:
     *   - require_approval=true
     *   - session is counting/draft (not already approved)
     *   - varianceValue < autoApproveBelowValue
     *
     * Writes the approved_by=null / approved_at=now() / approval_comments
     * = "Auto-approved: ..." row, logs an 'approve' audit row with
     * auto_approved=true (actor_id=null = system), and refreshes the
     * session model so the caller's post logic sees status='approved'.
     *
     * MUST run inside the caller's DB::transaction (it is). The session
     * row is already locked by postSession's lockForUpdate.
     *
     * @param StockTakeSession $session  (already locked for update)
     * @param float $varianceValue
     * @param float $autoBelow  The auto_approve_below_value threshold.
     * @return bool  Always true (the caller uses the return as a flag).
     */
    private function autoApproveInline(StockTakeSession $session, float $varianceValue, float $autoBelow): bool
    {
        $now = now();
        $fromStatus = $session->status;
        $comments = 'Auto-approved: variance value '
            . number_format($varianceValue, 2)
            . ' is below threshold '
            . number_format($autoBelow, 2) . '.';

        DB::table('stock_take_sessions')
            ->where('id', $session->id)
            ->update([
                'status'            => 'approved',
                'approved_by'       => null, // system — no human approver
                'approved_at'       => $now,
                'approval_comments' => $comments,
                'updated_at'        => $now,
            ]);

        $session->refresh();

        $this->auditLogger->log(
            session:    $session,
            action:     'approve',
            fromStatus: $fromStatus,
            toStatus:   'approved',
            payload:    [
                'auto_approved'  => true,
                'variance_value' => $varianceValue,
                'threshold'      => $autoBelow,
                'comments'       => $comments,
            ],
            actorId:    null, // system
        );

        return true;
    }

    /**
     * Phase 3: capture the per-warehouse count_snapshot jsonb on the session
     * row. Merges this warehouse's product list (product_id, product_code,
     * product_name, unit, system_qty, avg_cost) into the session-level jsonb
     * keyed by warehouse_id. Re-setup overwrites that warehouse's slice.
     *
     * Must run inside the caller's transaction (it reads-modifies-writes the
     * session row). The session row is already locked by setupWarehouseCounts'
     * lockForUpdate.
     *
     * @param int $sessionId
     * @param int $warehouseId
     * @param \Illuminate\Support\Collection $products  Rows from the products
     *     query in setupWarehouseCounts (product_id, product_code,
     *     product_name, unit, system_qty, rate).
     */
    private function captureWarehouseSnapshot(int $sessionId, int $warehouseId, $products): void
    {
        $slice = [];
        foreach ($products as $p) {
            $slice[] = [
                'product_id'   => (int) $p->product_id,
                'product_code' => $p->product_code,
                'product_name' => $p->product_name,
                'unit'         => $p->unit,
                'system_qty'   => (float) $p->system_qty,
                'avg_cost'     => (float) $p->rate,
            ];
        }

        // Read the raw jsonb (DB::table returns the jsonb column as a string).
        $raw = DB::table('stock_take_sessions')
            ->where('id', $sessionId)
            ->value('count_snapshot');

        $snapshot = $raw ? json_decode($raw, true) : [];
        if (!is_array($snapshot)) {
            $snapshot = [];
        }
        if (!isset($snapshot['warehouses']) || !is_array($snapshot['warehouses'])) {
            $snapshot['warehouses'] = [];
        }

        $snapshot['warehouses'][(string) $warehouseId] = [
            'warehouse_id' => $warehouseId,
            'captured_at'  => now()->toDateTimeString(),
            'product_count' => count($slice),
            'products'     => $slice,
        ];
        // Refresh the top-level captured_at so the latest setup is reflected.
        $snapshot['captured_at'] = now()->toDateTimeString();

        DB::table('stock_take_sessions')
            ->where('id', $sessionId)
            ->update([
                'count_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /**
     * Phase 3: reconcile the setup-time snapshot (system_qty on each
     * stock_take_items row) against the LIVE warehouse_stock.qty.
     *
     * Returns a list of products whose live qty has drifted from the snapshot
     * captured at setup — i.e. stock moved between setup and post. Each entry
     * carries the product identity, the snapshot system_qty, the live qty, and
     * the delta (live − snapshot) so the UI can say "product X changed from
     * 10 to 7".
     *
     * This is a WARNING, not a block. The post still proceeds; the drift is
     * recorded in the post audit payload + surfaced on the session detail
     * page. With freeze_outbound=true, outbound drift should be empty by
     * construction; only inbound receipts (purchases, transfers IN) can have
     * changed live qty while frozen.
     *
     * @param int $sessionId
     * @return array<int, array{product_id: int, product_code: string, product_name: string, warehouse_id: int, warehouse_name: string, snapshot_qty: float, live_qty: float, delta: float}>
     */
    private function reconcileSnapshotWithLiveStock(int $sessionId): array
    {
        $rows = DB::table('stock_take_items as sti')
            ->join('products as p', 'p.id', '=', 'sti.product_id')
            ->join('warehouses as w', 'w.id', '=', 'sti.warehouse_id')
            ->leftJoin('warehouse_stock as ws', function ($join) {
                $join->on('ws.warehouse_id', '=', 'sti.warehouse_id')
                     ->on('ws.product_id', '=', 'sti.product_id');
            })
            ->where('sti.stock_take_session_id', $sessionId)
            ->whereRaw('COALESCE(ws.qty, 0) <> sti.system_qty')
            ->select(
                'sti.product_id',
                'p.product_code',
                'p.product_name',
                'sti.warehouse_id',
                'w.warehouse_name',
                'sti.system_qty',
                DB::raw('COALESCE(ws.qty, 0) as live_qty')
            )
            ->orderBy('w.warehouse_name')
            ->orderBy('p.product_name')
            ->get();

        $drift = [];
        foreach ($rows as $r) {
            $snapshot = (float) $r->system_qty;
            $live = (float) $r->live_qty;
            $drift[] = [
                'product_id'     => (int) $r->product_id,
                'product_code'   => $r->product_code,
                'product_name'   => $r->product_name,
                'warehouse_id'   => (int) $r->warehouse_id,
                'warehouse_name' => $r->warehouse_name,
                'snapshot_qty'   => $snapshot,
                'live_qty'       => $live,
                'delta'          => round($live - $snapshot, 4),
            ];
        }
        return $drift;
    }

    /**
     * Phase 3: release the outbound freeze for a session's warehouses.
     *
     * Called on post + cancel (the two terminal transitions) and would be
     * called on delete if a delete flow is added. Recomputes the
     * is_frozen_for_count flag for each of the session's warehouses based on
     * ALL remaining ACTIVE (draft/counting) sessions with freeze_outbound=true
     * that still cover the warehouse — so an overlapping session keeps the
     * flag true until IT ends.
     *
     * @param int $sessionId
     */
    private function releaseSessionFreeze(int $sessionId): void
    {
        $warehouseIds = DB::table('stock_take_warehouses')
            ->where('stock_take_session_id', $sessionId)
            ->pluck('warehouse_id')
            ->all();

        $this->refreshWarehouseFreezeFlags($warehouseIds);
    }

    /**
     * Phase 8: find which of the given warehouse_ids are already covered by
     * an ACTIVE (status in draft/counting/submitted/approved) stock-take
     * session that has freeze_outbound=true.
     *
     * Used by createSession() as the friendly-error pre-check before opening
     * the transaction + inserting. The DB trigger
     * prevent_overlapping_frozen_stock_take is the race-condition backstop;
     * this method gives the user a clear message naming the conflicting
     * warehouses instead of a generic trigger exception.
     *
     * Mirrors the predicate in refreshWarehouseFreezeFlags (so the two always
     * agree on what "active + frozen" means).
     *
     * @param array<int> $warehouseIds
     * @return array<int>  Subset of $warehouseIds that conflict (may be empty).
     */
    private function findWarehousesWithActiveFrozenSession(array $warehouseIds): array
    {
        $warehouseIds = array_values(array_unique(array_filter(array_map('intval', $warehouseIds))));
        if (empty($warehouseIds)) {
            return [];
        }

        return DB::table('stock_take_warehouses as stw')
            ->join('stock_take_sessions as sts', 'sts.id', '=', 'stw.stock_take_session_id')
            ->whereIn('stw.warehouse_id', $warehouseIds)
            ->where('sts.freeze_outbound', true)
            ->whereIn('sts.status', ['draft', 'counting', 'submitted', 'approved'])
            ->pluck('stw.warehouse_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Phase 3: recompute the is_frozen_for_count flag for the given warehouses
     * based on the set of ACTIVE stock-take sessions with freeze_outbound=true
     * that cover each warehouse.
     *
     * Phase 4: "active" now includes 'submitted' and 'approved' — a session
     * awaiting approval (or already approved but not yet posted) has not yet
     * applied any variance, so the outbound freeze must remain in force.
     * Only posted/cancelled/reversed sessions release the freeze.
     *
     * This is the single source of truth for the denormalized flag. It is
     * called from createSession (set true when the first freezing session
     * covers a warehouse) and from releaseSessionFreeze (post/cancel — clear
     * when the last freezing session ends). Idempotent: running it twice with
     * the same state produces the same flags.
     *
     * @param array<int> $warehouseIds
     */
    private function refreshWarehouseFreezeFlags(array $warehouseIds): void
    {
        $warehouseIds = array_values(array_unique(array_filter(array_map('intval', $warehouseIds))));
        if (empty($warehouseIds)) {
            return;
        }

        foreach ($warehouseIds as $wid) {
            // A warehouse is frozen iff at least one ACTIVE (draft/counting/
            // submitted/approved) session with freeze_outbound=true covers it.
            $frozen = DB::table('stock_take_warehouses as stw')
                ->join('stock_take_sessions as sts', 'sts.id', '=', 'stw.stock_take_session_id')
                ->where('stw.warehouse_id', $wid)
                ->where('sts.freeze_outbound', true)
                ->whereIn('sts.status', ['draft', 'counting', 'submitted', 'approved'])
                ->exists();

            DB::table('warehouses')
                ->where('id', $wid)
                ->update([
                    'is_frozen_for_count' => $frozen,
                    'updated_at' => now(),
                ]);
        }
    }
}
