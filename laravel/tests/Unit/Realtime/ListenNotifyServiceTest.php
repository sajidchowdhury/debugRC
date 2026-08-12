<?php

namespace Tests\Unit\Realtime;

use App\Services\Notification\ListenNotifyService;
use App\Services\Notification\NotificationService;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * G-217 (MEDIUM-WAVE-3) — ListenNotifyService unit tests.
 *
 * Covers `app/Services/Notification/ListenNotifyService.php` (393L):
 *   - `PG_CHANNELS` public constant (L52-73): the 10 channels the worker LISTENs on.
 *   - `CHANNEL_EVENT_MAP` private constant (L119): the PG channel → Laravel
 *     notification event name map. **Currently EMPTY** per the G-076/G-078/
 *     G-079 (CRITICAL, WORKFLOWS-NOTIFICATION) double-dispatch fix — the
 *     worker-forward path is intentionally disabled. Direct PHP dispatch
 *     (SalesInvoiceService, SalesChallanService, CustomerPaymentService,
 *     SalesReturnService) handles rule-based notification with full $context.
 *   - `forwardToNotificationService()` (L257-296): the worker-forward path.
 *     Early-returns for every channel because CHANNEL_EVENT_MAP is empty.
 *   - `buildNotificationBody()` (L371-391, private): the human-readable body
 *     builder — tested via ReflectionMethod.

 * ## Divergence from the G-217 gap text
 *
 * The gap text (architecture/realtime-events.md L1510-1515) describes
 * CHANNEL_EVENT_MAP as having "5 mappings" (`rcerp_sales_invoice` →
 * `sales_finalize`, etc.). That was the pre-G-076 state. The CURRENT code at
 * ListenNotifyService.php L84-119 has the map EMPTY (with a 36-line
 * docblock explaining the G-076/G-078/G-079 fix). The test names below
 * reflect the ACTUAL code reality (map is empty) rather than the gap text's
 * outdated description — each test's docblock cites the G-076 history so
 * future maintainers understand why the test diverges from the gap text.
 *
 * The original 5 mappings still appear in the §7.1 docstring block in
 * realtime-events.md (L195-206) — that's stale documentation that should be
 * updated to reflect the empty map. OUT OF SCOPE for this task (only the
 * G-214/G-215/G-217 RESOLVED blockquotes are added in this wave).
 *
 * ## Mocking strategy
 *
 * - `NotificationService` is mocked via Mockery (concrete class, no
 *   constructor call — `Mockery::mock(NotificationService::class)` bypasses
 *   the `?ListenNotifyService $listenNotify` constructor arg).
 * - Private `buildNotificationBody()` is invoked via ReflectionMethod (same
 *   pattern as tests/Unit/StockTake/StockTakeServiceTest.php L378).
 * - Private `CHANNEL_EVENT_MAP` constant is read via ReflectionClass::
 *   getConstant('CHANNEL_EVENT_MAP').
 *
 * `forwardToNotificationService()` does NOT touch Redis or DB — it only reads
 * the `CHANNEL_EVENT_MAP` constant + calls `NotificationService::dispatch()`
 * (mocked). So no Redis facade spy is needed for the forwardToNotificationService
 * tests. The `publishToRedis()` / `publishToUser()` paths (which DO write to
 * Redis) are not exercised here — they're tested implicitly via the
 * `SseStatusTest` integration with the worker's heartbeat.
 */
class ListenNotifyServiceTest extends TestCase
{
    use BuildsRoleUsers;

    /**
     * The 5 channels the gap text expected to be mapped (pre-G-076).
     * Retained for the test_notification_dispatched_channel_not_in_event_map
     * assertion's reference — even though the map is now empty, the
     * `rcerp_notification_dispatched` channel must NEVER appear in it (BR8).
     */
    private const GAP_TEXT_EXPECTED_MAPPED_CHANNELS = [
        'rcerp_sales_invoice',
        'rcerp_sales_challan',
        'rcerp_sales_return',
        'rcerp_customer_payment',
        'rcerp_system',
    ];

    /**
     * The 5 channels the gap text expected to be UNMAPPED (SSE-only refresh
     * signals + the emit-only channel). All 10 channels are now unmapped
     * per G-076, but this list documents the original 5 that were NEVER in
     * the map.
     */
    private const GAP_TEXT_EXPECTED_UNMAPPED_CHANNELS = [
        'rcerp_stock_change',
        'rcerp_journal_entry',
        'rcerp_notification_dispatched',
        'rcerp_damage_change',
        'rcerp_damage_attachment_change',
    ];

    private ListenNotifyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->service = new ListenNotifyService();
    }

    /**
     * Test 1 (spec item 2): PG_CHANNELS has exactly 10 entries.
     *
     * Basis: ListenNotifyService.php L52-73 — the public const PG_CHANNELS
     * array literal with 10 channel names (5 original + rcerp_system +
     * rcerp_notification_dispatched + 2 damage channels).
     *
     * This pins the contract that the worker LISTENs on 10 channels — adding
     * or removing a channel without updating this test would force a
     * conscious decision (and a doc update to §7.1's channel classification
     * table).
     */
    public function test_pg_channels_has_10_entries(): void
    {
        $channels = ListenNotifyService::PG_CHANNELS;

        $this->assertCount(10, $channels, 'PG_CHANNELS should have exactly 10 entries.');

        // Assert each of the 10 expected channels is present (catches
        // accidental rename of a channel name).
        $expected = [
            'rcerp_sales_invoice',
            'rcerp_sales_challan',
            'rcerp_sales_return',
            'rcerp_customer_payment',
            'rcerp_stock_change',
            'rcerp_journal_entry',
            'rcerp_system',
            'rcerp_notification_dispatched',
            'rcerp_damage_change',
            'rcerp_damage_attachment_change',
        ];
        foreach ($expected as $ch) {
            $this->assertContains($ch, $channels, "PG_CHANNELS must include '{$ch}'.");
        }
    }

    /**
     * Test 2 (renamed from spec's "test_channel_event_map_has_5_mappings"):
     * CHANNEL_EVENT_MAP is EMPTY per the G-076/G-078/G-079 (CRITICAL,
     * WORKFLOWS-NOTIFICATION) double-dispatch fix.
     *
     * Basis: ListenNotifyService.php L84-119 — the private const is declared
     * as `private const CHANNEL_EVENT_MAP = [];` with a 36-line docblock
     * explaining the G-076 (double dispatch), G-078 (wrong event forwarded
     * on UPDATE), and G-079 (worker-forwarded events have no $context) fix.
     *
     * The gap text's "5 mappings" reflects the PRE-G-076 state. The current
     * code reality is 0 mappings. This test asserts the current reality.
     * If CHANNEL_EVENT_MAP is ever repopulated (e.g. trigger payloads are
     * enriched to carry $context), this test will fail and force the
     * maintainer to:
     *   (a) re-enable the spec's `test_forward_to_notification_service_
     *       dispatches_mapped_channels` test, and
     *   (b) update this test's assertion to match.
     */
    public function test_channel_event_map_is_empty_per_double_dispatch_fix(): void
    {
        $map = $this->getChannelEventMap();

        $this->assertSame(
            [],
            $map,
            'CHANNEL_EVENT_MAP must be empty per G-076/G-078/G-079 fix. '
            . 'If you re-populated it, update this test + re-enable the '
            . 'dispatch-mapped-channels test. Got: ' . json_encode($map)
        );
    }

    /**
     * Test 3 (spec item 3): for channels NOT in CHANNEL_EVENT_MAP,
     * forwardToNotificationService returns early WITHOUT calling dispatch.
     *
     * Basis: ListenNotifyService.php L262-271 — `$eventName = self::
     * CHANNEL_EVENT_MAP[$pgChannel] ?? null;` then `if (!$eventName) return;`.
     * With the map empty, $eventName is null for EVERY channel → early-return
     * fires for every channel → dispatch is never called.
     *
     * Asserts the safety design: the worker-forward path is disabled for
     * ALL 10 PG channels. This is the G-076 fix's safety guarantee — no
     * double-dispatch is possible because the worker never calls
     * NotificationService::dispatch.
     */
    public function test_forward_to_notification_service_skips_unmapped_channels(): void
    {
        // Mock NotificationService — dispatch should NEVER be called.
        $nsMock = Mockery::mock(NotificationService::class);
        $nsMock->shouldNotReceive('dispatch');

        // Test 5 sample unmapped channels (the spec's expected unmapped set).
        foreach (self::GAP_TEXT_EXPECTED_UNMAPPED_CHANNELS as $channel) {
            $this->service->forwardToNotificationService(
                $channel,
                ['table' => 'whatever', 'action' => 'INSERT', 'id' => 1, 'branch_id' => 1],
                $nsMock
            );
        }

        // Mockery verifies expectations at tearDown (Mockery::close via Laravel).
        $this->assertTrue(true, 'No dispatch calls made for unmapped channels (assertion via Mockery shouldNotReceive).');
    }

    /**
     * Test 4 (renamed from spec's "test_forward_to_notification_service_
     * dispatches_mapped_channels"): because CHANNEL_EVENT_MAP is empty per
     * the G-076 fix, NO channel triggers dispatch.
     *
     * Basis: ListenNotifyService.php L262-271 — the `if (!$eventName) return;`
     * guard fires for ALL 10 PG channels because the map is empty. The
     * inverse of the spec's "dispatches_mapped_channels" test.
     *
     * If the map is ever repopulated with the original 5 mappings (or any
     * subset), this test will FAIL until:
     *   (a) the test is renamed back to the spec's name, AND
     *   (b) the assertion is updated to expect dispatch for the mapped
     *       channels (and assert the correct event name is forwarded).
     *
     * This test verifies the G-076 fix's safety property end-to-end across
     * ALL 10 channels — the worker NEVER calls dispatch, period.
     */
    public function test_forward_to_notification_service_never_dispatches_any_channel(): void
    {
        $nsMock = Mockery::mock(NotificationService::class);
        $nsMock->shouldNotReceive('dispatch');

        // Exercise ALL 10 PG channels — none should trigger dispatch.
        foreach (ListenNotifyService::PG_CHANNELS as $channel) {
            $this->service->forwardToNotificationService(
                $channel,
                ['table' => 'demo', 'action' => 'INSERT', 'id' => 1, 'branch_id' => 1],
                $nsMock
            );
        }

        $this->assertTrue(true, 'No dispatch calls made for ANY of the 10 PG channels (G-076 fix verified).');
    }

    /**
     * Test 5 (spec item 5): `rcerp_notification_dispatched` is NOT in
     * CHANNEL_EVENT_MAP (BR8 infinite-loop prevention).
     *
     * Basis: BR8 in architecture/realtime-events.md L161 — "`rcerp_
     * notification_dispatched` MUST NOT be in CHANNEL_EVENT_MAP — otherwise
     * dispatch → emitNotify → worker → forwardToNotificationService →
     * dispatch would infinite-loop. It is emit-only (no DB trigger) and
     * listen-only (no forward-back)."
     *
     * With the G-076 fix emptying the entire map, this assertion is
     * trivially true — but the test is retained to document the BR8
     * invariant. If the map is ever repopulated, this test MUST still pass
     * (rcerp_notification_dispatched must NEVER be added back).
     */
    public function test_notification_dispatched_channel_not_in_event_map(): void
    {
        $map = $this->getChannelEventMap();

        $this->assertArrayNotHasKey(
            'rcerp_notification_dispatched',
            $map,
            'BR8 invariant violation: rcerp_notification_dispatched must NEVER '
            . 'be in CHANNEL_EVENT_MAP (would cause infinite loop: dispatch → '
            . 'emitNotify → worker → forwardToNotificationService → dispatch).'
        );

        // Also assert the channel IS in PG_CHANNELS (so the worker LISTENs
        // on it for SSE delivery — the listen-only half of BR8).
        $this->assertContains(
            'rcerp_notification_dispatched',
            ListenNotifyService::PG_CHANNELS,
            'rcerp_notification_dispatched must be in PG_CHANNELS so the worker '
            . 'LISTENs on it for SSE delivery (the listen-only half of BR8).'
        );
    }

    /**
     * Test 6 (spec item 6): when the payload lacks 'table',
     * buildNotificationBody falls back to 'record'.
     *
     * Basis: ListenNotifyService.php L371-391 — the private method:
     *
     *   $table = $payload['table'] ?? 'record';
     *   $action = $payload['action'] ?? 'changed';
     *   $id = $payload['id'] ?? '?';
     *   ...
     *   return ucfirst(str_replace('_', ' ', $table)) . " #{$id} {$actionLabel}{$changeDescription}";
     *
     * The 'record' fallback is the safety net for payloads that don't carry
     * a `table` key (e.g. application-level NOTIFY via emitNotify that
     * doesn't include the standard table/action/id schema).
     *
     * buildNotificationBody is PRIVATE — invoked via ReflectionMethod (same
     * pattern as tests/Unit/StockTake/StockTakeServiceTest.php L378 for the
     * private computeVarianceValue).
     */
    public function test_build_notification_body_falls_back_to_record(): void
    {
        $method = new ReflectionMethod($this->service, 'buildNotificationBody');
        $method->setAccessible(true);

        // Case A: payload has NO 'table' key → fallback to 'record'.
        $bodyA = $method->invoke(
            $this->service,
            'rcerp_sales_invoice',
            ['action' => 'INSERT', 'id' => 42]
        );
        $this->assertStringStartsWith('Record #42', $bodyA, 'Body should fall back to "Record" when payload lacks "table".');
        $this->assertStringContainsString('created', $bodyA, 'Body should label INSERT action as "created".');

        // Case B: payload HAS 'table' → uses the table name (no fallback).
        $bodyB = $method->invoke(
            $this->service,
            'rcerp_sales_invoice',
            ['table' => 'sales_invoices', 'action' => 'UPDATE', 'id' => 99, 'changes' => ['status' => 'finalized']]
        );
        $this->assertStringStartsWith('Sales invoices #99', $bodyB, 'Body should use the payload "table" value when present.');
        $this->assertStringContainsString('updated', $bodyB, 'Body should label UPDATE action as "updated".');
        $this->assertStringContainsString('"status":"finalized"', $bodyB, 'Body should include change descriptions when "changes" is non-empty.');

        // Case C: payload is completely empty → 'record' + 'changed' + '?'.
        $bodyC = $method->invoke($this->service, 'rcerp_sales_invoice', []);
        $this->assertSame('Record #? changed', $bodyC, 'Body should use all defaults (record, changed, ?) for empty payload.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reflection helpers for private constant + private method access.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Read the private CHANNEL_EVENT_MAP constant via ReflectionClass.
     *
     * @return array<string,string>
     */
    private function getChannelEventMap(): array
    {
        $reflection = new ReflectionClass(ListenNotifyService::class);
        $map = $reflection->getConstant('CHANNEL_EVENT_MAP');

        $this->assertIsArray($map, 'CHANNEL_EVENT_MAP must be an array (constant lookup failed).');

        return $map;
    }
}
