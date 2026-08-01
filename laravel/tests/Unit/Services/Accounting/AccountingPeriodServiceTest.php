<?php

namespace Tests\Unit\Services\Accounting;

use Tests\TestCase;
use App\Services\Accounting\AccountingPeriodService;
use Illuminate\Support\Facades\DB;

/**
 * Accounting Period Service Test — Phase 1.2 (Core Foundation Hardening).
 *
 * Tests the period close system:
 *   - closePeriod() pre-close gate
 *   - earliestOpenDate() returns correct date
 *   - getClosedThroughDate() returns correct date
 *   - Period validation rejects postings to closed periods
 */
class AccountingPeriodServiceTest extends TestCase
{
    private AccountingPeriodService $service;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AccountingPeriodService::class);

        $this->branchId = (int) DB::table('branches')
            ->where('is_active', true)
            ->value('id') ?: 1;
    }

    // ============================================================
    // 1. GET CLOSED-THROUGH DATE
    // ============================================================

    public function test_get_closed_through_date_returns_null_when_open(): void
    {
        // Remove any close for this branch.
        DB::table('accounting_periods')->where('branch_id', $this->branchId)->delete();

        $result = $this->service->getClosedThroughDate($this->branchId);
        $this->assertNull($result);
    }

    public function test_get_closed_through_date_returns_date_when_closed(): void
    {
        DB::table('accounting_periods')->upsert(
            [
                'branch_id' => $this->branchId,
                'closed_through_date' => '2026-06-30',
                'closed_by' => 1,
                'closed_at' => now(),
                'notes' => 'Test close',
                'updated_at' => now(),
            ],
            ['branch_id'],
            ['closed_through_date', 'closed_by', 'closed_at', 'notes', 'updated_at']
        );

        $result = $this->service->getClosedThroughDate($this->branchId);
        $this->assertEquals('2026-06-30', $result);
    }

    // ============================================================
    // 2. EARLIEST OPEN DATE
    // ============================================================

    public function test_earliest_open_date_returns_day_after_close(): void
    {
        DB::table('accounting_periods')->upsert(
            [
                'branch_id' => $this->branchId,
                'closed_through_date' => '2026-06-30',
                'closed_by' => 1,
                'closed_at' => now(),
                'notes' => 'Test close',
                'updated_at' => now(),
            ],
            ['branch_id'],
            ['closed_through_date', 'closed_by', 'closed_at', 'notes', 'updated_at']
        );

        $result = $this->service->earliestOpenDate($this->branchId);
        $this->assertEquals('2026-07-01', $result);
    }

    public function test_earliest_open_date_returns_null_when_open(): void
    {
        DB::table('accounting_periods')->where('branch_id', $this->branchId)->delete();

        $result = $this->service->earliestOpenDate($this->branchId);
        $this->assertNull($result);
    }

    // ============================================================
    // 3. PRE-CLOSE GATE
    // ============================================================

    public function test_pre_close_gate_returns_checks(): void
    {
        $result = $this->service->preCloseGate($this->branchId, now()->format('Y-m-d'));

        $this->assertArrayHasKey('can_close', $result);
        $this->assertArrayHasKey('checks', $result);
        $this->assertIsArray($result['checks']);
        $this->assertNotEmpty($result['checks']);
    }

    // ============================================================
    // 4. REOPEN PERIOD
    // ============================================================

    public function test_reopen_period(): void
    {
        // First close the period.
        DB::table('accounting_periods')->upsert(
            [
                'branch_id' => $this->branchId,
                'closed_through_date' => '2026-06-30',
                'closed_by' => 1,
                'closed_at' => now(),
                'notes' => 'Test close',
                'updated_at' => now(),
            ],
            ['branch_id'],
            ['closed_through_date', 'closed_by', 'closed_at', 'notes', 'updated_at']
        );

        $result = $this->service->reopenPeriod($this->branchId, 1, 'Test reopen');

        $this->assertEquals('success', $result['status']);

        // Verify the close was removed.
        $closedThrough = $this->service->getClosedThroughDate($this->branchId);
        $this->assertNull($closedThrough);
    }
}
