<?php

namespace Tests\Unit\StockTake;

use App\Models\Branch;
use App\Models\StockTakeSession;
use App\Services\Stock\StockTakeService;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Phase 12 — unit tests for StockTakeService pure-logic + edge-case methods.
 *
 * Scope: methods that DON'T require the full postSession / cancelSession /
 * reverseSession DB lifecycle (those are covered by the Phase 12 feature
 * tests). This file exercises:
 *
 *   - validateCountScope(): the 7 valid scopes (full / category / abc / group
 *     / ad_hoc / negative_only / zero_only) via a @dataProvider, plus the
 *     InvalidArgumentException paths for empty payloads, invalid abc_classes,
 *     and an unsupported scope name.
 *   - validateCountScope() normalization: string-id → int-id coercion and
 *     duplicate-id dedupe (via normalizeIntList()).
 *   - describeScope(): the human-readable one-line summary for each of the
 *     6 scopes exercised (full / category / abc / ad_hoc / negative_only /
 *     zero_only) — verifies the strings the UI surfaces to users.
 *   - POST_ADVISORY_LOCK_NAMESPACE constant: the "STKP" 0x53544B50 hex
 *     constant used with pg_advisory_xact_lock(int, int) — pinned so a
 *     future refactor cannot drift the value silently.
 *   - generateSessionCode() (via createSession): the code format is
 *     ST-YYYYMMDD-NNNN (DocumentSequenceService::nextCode with prefix 'ST',
 *     datePart Ymd, padLength 4) — asserted via regex.
 *   - computeVarianceValue() (private): called via ReflectionMethod. The
 *     brief notes this is private; reflection is the cleanest way to test
 *     the |diff|*rate summation in isolation without driving the full
 *     postSession chain.
 *   - StockTakeSession state-check methods (isDraft/isCounting/isSubmitted/
 *     isApproved/isPosted/isCancelled/isReversed): a single test inserts
 *     one session row per status via DB::table and verifies each isXxx()
 *     returns true ONLY for the matching status (all others false).
 *
 * All tests run inside the DatabaseTransactions wrapper inherited from
 * TestCase; per-test DB writes roll back on tearDown. The service is
 * resolved from the container in setUp() so its 5 constructor dependencies
 * (StockService, JournalPostingService, StockTakeAuditLogger,
 * StockTakePolicyService, AbcClassificationService) wire up automatically.
 *
 * DIVERGENCE NOTES (also documented inline):
 *   - POST_ADVISORY_LOCK_NAMESPACE decimal: the brief's parenthetical
 *     "decimal 1397032048" is INCORRECT — 0x53544B50 = 1398033232 (verified
 *     via Python). The assertion uses the hex form which matches the
 *     production code exactly; the comment here records the correct decimal.
 *   - generateSessionCode format: the brief's regex `/^ST-[A-Z0-9]{6,8}$/`
 *     does NOT match the actual format ST-YYYYMMDD-NNNN (13 chars after ST-).
 *     The actual format comes from DocumentSequenceService::nextCode with
 *     prefix='ST', datePart=Ymd (8 digits), separator='-', padLength=4. The
 *     regex used here is `/^ST-\d{8}-\d{4}$/` which matches the real format.
 */
class StockTakeServiceTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected StockTakeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->service = app(StockTakeService::class);
    }

    // ─────────────────────────────────────────────────────────────────────
    // validateCountScope — data-provider-driven coverage of all 7 scopes
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Provider for test_validate_count_scope_validates_each_scope_correctly.
     *
     * Each row is:
     *   [scope, payload|null, fixtureType|null, expectedPayloadKeys|null,
     *    expectedExceptionClass|null, expectedExceptionMessageFragment|null]
     *
     * fixtureType is null for cases that need no DB fixture (full /
     * negative_only / zero_only / abc / all exception cases), 'category' /
     * 'group' / 'product' for the three happy-path cases that need a real
     * master-data row to pass the missingIds() existence check.
     *
     * @return array<string, array{0:string,1:?array,2:?string,3:?array,4:?class-string,5:?string}>
     */
    public static function validateCountScopeProvider(): array
    {
        return [
            // Happy paths — 7 valid scopes.
            'full returns empty payload'          => ['full',          null,                              null,      [], null, null],
            'negative_only returns empty payload' => ['negative_only', null,                              null,      [], null, null],
            'zero_only returns empty payload'     => ['zero_only',     null,                              null,      [], null, null],
            'abc uppercases + dedupes classes'    => ['abc',           ['abc_classes' => ['a', 'b', 'A']], null,      ['abc_classes' => ['A', 'B']], null, null],
            'category with valid ids'             => ['category',      null,                              'category', ['category_ids' => '__FIXTURE__'], null, null],
            'group with valid ids'                => ['group',         null,                              'group',    ['group_ids'    => '__FIXTURE__'], null, null],
            'ad_hoc with valid ids'               => ['ad_hoc',        null,                              'product',  ['product_ids'  => '__FIXTURE__'], null, null],

            // Exception paths — unsupported scope, empty payloads, invalid abc.
            'bogus scope throws'           => ['bogus',    null,                          null, null, \InvalidArgumentException::class, 'Unsupported count_scope'],
            'empty category ids throws'    => ['category', [],                            null, null, \InvalidArgumentException::class, 'category scope requires'],
            'empty abc classes throws'     => ['abc',      [],                            null, null, \InvalidArgumentException::class, 'abc scope requires'],
            'invalid abc class throws'     => ['abc',      ['abc_classes' => ['X']],      null, null, \InvalidArgumentException::class, 'abc_classes must be a subset'],
            'empty group ids throws'       => ['group',    [],                            null, null, \InvalidArgumentException::class, 'group scope requires'],
            'empty ad_hoc ids throws'      => ['ad_hoc',   [],                            null, null, \InvalidArgumentException::class, 'ad_hoc scope requires'],
        ];
    }

    /**
     * @dataProvider validateCountScopeProvider
     */
    public function test_validate_count_scope_validates_each_scope_correctly(
        string $scope,
        ?array $payload,
        ?string $fixtureType,
        ?array $expectedPayload,
        ?string $expectedExceptionClass,
        ?string $expectedExceptionMessageFragment
    ): void {
        // 1. Create DB fixture (if any) and build the input payload.
        $fixtureIds = $this->createValidateScopeFixture($fixtureType);
        $inputPayload = $this->buildValidateScopePayload($payload, $fixtureType, $fixtureIds);

        // 2. If an exception is expected, configure PHPUnit to expect it,
        //    then call validateCountScope — the throw satisfies the
        //    expectation and the test method exits (no assertions run).
        if ($expectedExceptionClass !== null) {
            $this->expectException($expectedExceptionClass);
            if ($expectedExceptionMessageFragment !== null) {
                $this->expectExceptionMessage($expectedExceptionMessageFragment);
            }
            $this->service->validateCountScope($scope, $inputPayload);
            return; // never reached when exception is thrown
        }

        // 3. Happy path — call + assert the returned payload.
        $result = $this->service->validateCountScope($scope, $inputPayload);

        if ($expectedPayload === []) {
            // 'full' / 'negative_only' / 'zero_only' all return an empty array.
            $this->assertSame([], $result, "Scope '{$scope}' should return an empty payload.");
            return;
        }

        // Replace the __FIXTURE__ marker with the actual fixture IDs so we
        // can do a strict same-check on the returned payload.
        $expected = $this->resolveExpectedPayload($expectedPayload, $fixtureType, $fixtureIds);
        $this->assertSame($expected, $result, "Scope '{$scope}' returned an unexpected payload.");
    }

    public function test_validate_count_scope_normalizes_string_ids_to_int(): void
    {
        // normalizeIntList() trims + intval()s every entry. String '1' / '2'
        // should come back as ints 1 / 2.
        $result = $this->service->validateCountScope('abc', ['abc_classes' => ['A']]);
        $this->assertSame(['abc_classes' => ['A']], $result);

        // For category scope we need a real category row to satisfy
        // missingIds(). Insert one, then pass its id as a STRING and assert
        // the returned payload contains the int form.
        $catId = (string) DB::table('product_categories')->insertGetId([
            'category_name' => 'Normalize Test ' . uniqid(),
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $result = $this->service->validateCountScope('category', [
            'category_ids' => [$catId],
        ]);

        $this->assertArrayHasKey('category_ids', $result);
        $this->assertSame([(int) $catId], $result['category_ids']);
        foreach ($result['category_ids'] as $id) {
            $this->assertIsInt($id, 'normalizeIntList must coerce strings to ints.');
        }
    }

    public function test_validate_count_scope_dedupes_ids(): void
    {
        // Insert two real category rows so missingIds() doesn't reject them.
        $ids = [];
        foreach (range(1, 2) as $i) {
            $ids[] = DB::table('product_categories')->insertGetId([
                'category_name' => 'Dedupe Test ' . $i . ' ' . uniqid(),
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // Pass [id1, id1, id2] — should dedupe to [id1, id2].
        $result = $this->service->validateCountScope('category', [
            'category_ids' => [$ids[0], $ids[0], $ids[1]],
        ]);

        $this->assertArrayHasKey('category_ids', $result);
        $this->assertSame([$ids[0], $ids[1]], $result['category_ids']);
        $this->assertCount(2, $result['category_ids']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // describeScope — string summaries for the UI / audit timeline
    // ─────────────────────────────────────────────────────────────────────

    public function test_describe_scope_full_returns_full_warehouse_count(): void
    {
        $session = $this->makeScopeSession('full', null);
        $this->assertStringContainsString('Full', $this->service->describeScope($session));
    }

    public function test_describe_scope_category_includes_category_count(): void
    {
        $catIds = [];
        foreach (range(1, 2) as $i) {
            $catIds[] = DB::table('product_categories')->insertGetId([
                'category_name' => 'Describe Cat ' . $i . ' ' . uniqid(),
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        $session = $this->makeScopeSession('category', ['category_ids' => $catIds]);
        $desc = $this->service->describeScope($session);

        // The service returns "... (2 categories)" — singular "1 category"
        // vs plural "2 categories". 2 ids → "2 categories".
        $this->assertStringContainsString('2 categories', $desc);
    }

    public function test_describe_scope_abc_includes_classes_listed(): void
    {
        $session = $this->makeScopeSession('abc', ['abc_classes' => ['A', 'B']]);
        $desc = $this->service->describeScope($session);

        $this->assertStringContainsString('A', $desc);
        $this->assertStringContainsString('B', $desc);
    }

    public function test_describe_scope_ad_hoc_includes_product_count(): void
    {
        $productIds = [];
        foreach (range(1, 3) as $i) {
            $productIds[] = $this->insertProduct();
        }

        $session = $this->makeScopeSession('ad_hoc', ['product_ids' => $productIds]);
        $desc = $this->service->describeScope($session);

        // 3 ids → "Ad-hoc: 3 products" (plural "products").
        $this->assertStringContainsString('3 products', $desc);
    }

    public function test_describe_scope_negative_only_returns_negative_stock_text(): void
    {
        $session = $this->makeScopeSession('negative_only', null);
        $desc = $this->service->describeScope($session);

        $this->assertStringContainsString('Negative', $desc);
    }

    public function test_describe_scope_zero_only_returns_zero_stock_text(): void
    {
        $session = $this->makeScopeSession('zero_only', null);
        $desc = $this->service->describeScope($session);

        $this->assertStringContainsString('Zero', $desc);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST_ADVISORY_LOCK_NAMESPACE constant — pinned so future refactors
    // don't silently change the lock namespace and break isolation.
    // ─────────────────────────────────────────────────────────────────────

    public function test_post_advisory_lock_namespace_constant_is_stkp_hex(): void
    {
        // 0x53544B50 = ASCII "STKP" (S=0x53, T=0x54, K=0x4B, P=0x50) packed
        // as a 4-byte big-endian int. Decimal value = 1398033232.
        //
        // DIVERGENCE: the task brief's parenthetical said "decimal 1397032048"
        // which is INCORRECT — that hex would be 0x53450470. The actual
        // 0x53544B50 = 1398033232 (verified via Python). The assertion uses
        // the hex form which matches the production source verbatim.
        $this->assertSame(0x53544B50, StockTakeService::POST_ADVISORY_LOCK_NAMESPACE);
        // Sanity: the same value as a decimal int.
        $this->assertSame(1398033232, StockTakeService::POST_ADVISORY_LOCK_NAMESPACE);
    }

    // ─────────────────────────────────────────────────────────────────────
    // generateSessionCode (via createSession) — ST-YYYYMMDD-NNNN format
    // ─────────────────────────────────────────────────────────────────────

    public function test_create_session_generates_unique_session_code_format(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $session = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'created_by'    => auth()->id(),
        ]);

        // The format is ST-YYYYMMDD-NNNN (4-digit zero-padded sequence).
        // DIVERGENCE: the task brief's regex `/^ST-[A-Z0-9]{6,8}$/` does NOT
        // match this — the real format has 8 digits (date) + 4 digits
        // (sequence) separated by '-'. Reading generateSessionCode() in the
        // service + DocumentSequenceService::nextCode confirms: prefix='ST',
        // datePart=now()->format('Ymd'), separator='-', padLength=4. The
        // regex used here matches the actual format.
        $this->assertMatchesRegularExpression(
            '/^ST-\d{8}-\d{4}$/',
            $session->session_code,
            "session_code '{$session->session_code}' does not match ST-YYYYMMDD-NNNN."
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // computeVarianceValue (private) — ReflectionMethod
    // ─────────────────────────────────────────────────────────────────────

    public function test_compute_variance_value_sums_absolute_differences_times_rate(): void
    {
        // Setup: 1 branch + 1 warehouse + 3 products + 1 session + 3 items.
        //   item 1 (gain): system=10, physical=12, rate=10 → diff=+2, |diff|*rate=20
        //   item 2 (loss): system=10, physical=7,  rate=5  → diff=-3, |diff|*rate=15
        //   item 3 (zero): system=10, physical=10, rate=10 → diff=0,  excluded by WHERE
        // Expected: 20 + 15 = 35.
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        $pid1 = $this->insertProduct();
        $pid2 = $this->insertProduct();
        $pid3 = $this->insertProduct();

        $sessionId = DB::table('stock_take_sessions')->insertGetId([
            'session_code'  => 'ST-' . substr(uniqid(), -8),
            'session_date'  => now()->toDateString(),
            'branch_id'     => $branch->id,
            'status'        => 'counting',
            'is_reversed'   => false,
            'count_scope'   => 'full',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Insert the 3 items directly. The `difference` column is GENERATED
        // (physical - system) so we omit it. rate is the post-time rate used
        // for GL valuation; computeVarianceValue uses it for the |diff|*rate
        // math. branch_id is denormalized NOT NULL.
        foreach ([
            [$pid1, 10, 12, 10], // system, physical, rate
            [$pid2, 10, 7,  5],
            [$pid3, 10, 10, 10],
        ] as [$pid, $system, $physical, $rate]) {
            DB::table('stock_take_items')->insert([
                'stock_take_session_id' => $sessionId,
                'warehouse_id'          => $wid,
                'product_id'            => $pid,
                'system_qty'            => $system,
                'physical_qty'          => $physical,
                'rate'                  => $rate,
                'is_applied'            => false,
                'branch_id'             => $branch->id,
                'revaluation_amount'    => 0,
            ]);
        }

        // computeVarianceValue is private — invoke via reflection.
        $method = new ReflectionMethod($this->service, 'computeVarianceValue');
        $method->setAccessible(true);
        $value = $method->invoke($this->service, $sessionId);

        $this->assertSame(35.0, $value);
    }

    // ─────────────────────────────────────────────────────────────────────
    // StockTakeSession state-check methods — isXxx() per status value
    // ─────────────────────────────────────────────────────────────────────

    public function test_session_state_check_methods_return_correct_booleans(): void
    {
        $branch = Branch::factory()->create();
        $statuses = ['draft', 'counting', 'submitted', 'approved', 'posted', 'cancelled', 'reversed'];

        foreach ($statuses as $status) {
            $sessionId = DB::table('stock_take_sessions')->insertGetId([
                'session_code'  => 'ST-' . $status . '-' . substr(uniqid(), -6),
                'session_date'  => now()->toDateString(),
                'branch_id'     => $branch->id,
                'status'        => $status,
                // 'reversed' is the only status where is_reversed is also
                // true in production (set by reverseSession). For all others
                // it stays false. The state-check methods only look at status
                // (not is_reversed), so the value here is informational.
                'is_reversed'   => $status === 'reversed',
                'count_scope'   => 'full',
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            $session = StockTakeSession::find($sessionId);
            $this->assertNotNull($session, "Failed to load session for status='{$status}'.");

            // Each isXxx() must return true ONLY for its matching status.
            $this->assertSame($status === 'draft',     $session->isDraft(),     "isDraft() wrong for status='{$status}'.");
            $this->assertSame($status === 'counting',  $session->isCounting(),  "isCounting() wrong for status='{$status}'.");
            $this->assertSame($status === 'submitted', $session->isSubmitted(), "isSubmitted() wrong for status='{$status}'.");
            $this->assertSame($status === 'approved',  $session->isApproved(),  "isApproved() wrong for status='{$status}'.");
            $this->assertSame($status === 'posted',    $session->isPosted(),    "isPosted() wrong for status='{$status}'.");
            $this->assertSame($status === 'cancelled', $session->isCancelled(), "isCancelled() wrong for status='{$status}'.");
            $this->assertSame($status === 'reversed',  $session->isReversed(),  "isReversed() wrong for status='{$status}'.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build a minimal in-memory StockTakeSession with the given scope +
     * payload — describeScope() only reads these two properties, so a full
     * DB round-trip via createSession() is unnecessary (and would also fail
     * for scopes whose payload references non-existent master-data).
     */
    private function makeScopeSession(string $scope, ?array $payload): StockTakeSession
    {
        $session = new StockTakeSession();
        $session->count_scope = $scope;
        $session->count_scope_payload = $payload;
        return $session;
    }

    /**
     * Create DB fixture rows for the validateCountScope data-provider cases
     * that need real master-data (category / group / product). Returns the
     * array of created IDs (empty when fixtureType is null).
     */
    private function createValidateScopeFixture(?string $fixtureType): array
    {
        if ($fixtureType === null) {
            return [];
        }

        switch ($fixtureType) {
            case 'category':
                return [
                    DB::table('product_categories')->insertGetId([
                        'category_name' => 'VCS Cat ' . uniqid(),
                        'is_active'     => true,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]),
                ];
            case 'group':
                return [
                    DB::table('product_groups')->insertGetId([
                        'group_name'    => 'VCS Grp ' . uniqid(),
                        'is_active'     => true,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]),
                ];
            case 'product':
                return [$this->insertProduct()];
            default:
                return [];
        }
    }

    /**
     * Build the input payload for validateCountScope. Three modes:
     *   1. $payload is non-null — use it as-is (covers the abc + exception
     *      cases where the payload is supplied inline).
     *   2. $payload is null + $fixtureType is non-null — build the
     *      scope-appropriate payload using the fixture IDs (e.g.
     *      ['category_ids' => $ids]).
     *   3. Otherwise — return null (validateCountScope treats null as []).
     */
    private function buildValidateScopePayload(?array $payload, ?string $fixtureType, array $fixtureIds): ?array
    {
        if ($payload !== null) {
            return $payload;
        }
        if ($fixtureType === null || empty($fixtureIds)) {
            return null;
        }
        switch ($fixtureType) {
            case 'category':
                return ['category_ids' => $fixtureIds];
            case 'group':
                return ['group_ids' => $fixtureIds];
            case 'product':
                return ['product_ids' => $fixtureIds];
            default:
                return null;
        }
    }

    /**
     * Replace the __FIXTURE__ marker in the expected payload with the actual
     * fixture IDs (so we can do a strict same-check on the returned payload).
     */
    private function resolveExpectedPayload(array $expected, ?string $fixtureType, array $fixtureIds): array
    {
        if ($fixtureType === null || empty($fixtureIds)) {
            return $expected;
        }
        $key = ['category' => 'category_ids', 'group' => 'group_ids', 'product' => 'product_ids'][$fixtureType] ?? null;
        if ($key !== null && isset($expected[$key]) && $expected[$key] === '__FIXTURE__') {
            $expected[$key] = $fixtureIds;
        }
        return $expected;
    }
}
