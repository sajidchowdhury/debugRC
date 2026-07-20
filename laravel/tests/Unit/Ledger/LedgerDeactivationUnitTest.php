<?php

namespace Tests\Unit\Ledger;

use App\Http\Controllers\Admin\LedgerController;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsLedgerDependencies;
use Tests\TestCase;

/**
 * Ledger Deactivation Unit Test — directly tests the protected
 * canDeactivate() method on LedgerController via reflection.
 *
 * Tests the 4 safety checks in isolation (Phase 15 — admin audit Phase 7):
 *   1. System ledger — is_system=true ledgers are required by the posting
 *      engine and can never be deactivated.
 *   2. Journal history — journal_lines > 0 means the ledger has historical
 *      references; deactivating would orphan the audit trail.
 *   3. Child ledgers — has children attached; hierarchy integrity.
 *   4. Sole active critical nature — only one active ledger for that
 *      critical nature; deactivating would orphan the posting engine's
 *      resolver (JournalPostingService::resolveLedgerByNature).
 *
 * Phase 15 commit.
 */
class LedgerDeactivationUnitTest extends TestCase
{
    use BuildsRoleUsers, InsertsLedgerDependencies;

    private LedgerController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->controller = app(LedgerController::class);
    }

    /**
     * Invoke the protected canDeactivate() method via reflection.
     *
     * @return array{ok: bool, message: string}
     */
    private function callCanDeactivate(Ledger $ledger): array
    {
        $method = new ReflectionMethod($this->controller, 'canDeactivate');
        $method->setAccessible(true);

        return $method->invoke($this->controller, $ledger);
    }

    /**
     * Convenience: create a Ledger with the given overrides.
     */
    private function makeLedger(array $overrides = []): Ledger
    {
        return Ledger::factory()->create($overrides);
    }

    // ====================================================================
    // Happy path — no blockers
    // ====================================================================

    public function test_can_deactivate_ledger_with_no_dependencies(): void
    {
        $ledger = $this->makeLedger();

        $result = $this->callCanDeactivate($ledger);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['message']);
    }

    public function test_can_deactivate_ledger_with_no_journal_history(): void
    {
        $ledger = $this->makeLedger(['ledger_nature' => 'other_income']);

        $result = $this->callCanDeactivate($ledger);

        $this->assertTrue($result['ok']);
    }

    public function test_can_deactivate_non_critical_nature_ledger_with_no_children(): void
    {
        $ledger = $this->makeLedger(['ledger_nature' => 'other_income']);

        $result = $this->callCanDeactivate($ledger);

        $this->assertTrue($result['ok']);
    }

    public function test_can_deactivate_ledger_with_non_critical_nature_when_sole_active(): void
    {
        // Non-critical nature: even if it's the only active ledger for that
        // nature, it can still be deactivated (the posting engine doesn't
        // depend on it).
        $ledger = $this->makeLedger(['ledger_nature' => 'other_income']);

        $result = $this->callCanDeactivate($ledger);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Blocker 1: System ledger (is_system=true)
    // ====================================================================

    public function test_cannot_deactivate_system_ledger(): void
    {
        $ledger = $this->makeLedger(['is_system' => true]);

        $result = $this->callCanDeactivate($ledger);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('system ledger', $result['message']);
    }

    public function test_system_ledger_blocker_message_mentions_posting_engine(): void
    {
        $ledger = $this->makeLedger(['is_system' => true, 'ledger_name' => 'Cash in Hand']);

        $result = $this->callCanDeactivate($ledger);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('posting engine', $result['message']);
        $this->assertStringContainsString('Cash in Hand', $result['message']);
    }

    public function test_system_ledger_blocker_takes_precedence_over_other_blockers(): void
    {
        // System ledger that ALSO has journal history — system ledger
        // blocker should be the only one reported.
        $ledger = $this->makeLedger(['is_system' => true]);
        $otherLedger = $this->makeLedger();
        $this->insertBalancedJournalPair($ledger->id, $otherLedger->id);

        $result = $this->callCanDeactivate($ledger);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('system ledger', $result['message']);
        $this->assertStringNotContainsString('journal line', $result['message']);
    }

    // ====================================================================
    // Blocker 2: Journal history (journal_lines > 0)
    // ====================================================================

    public function test_cannot_deactivate_ledger_with_journal_history(): void
    {
        $ledger = $this->makeLedger();
        $otherLedger = $this->makeLedger();
        $this->insertBalancedJournalPair($ledger->id, $otherLedger->id);

        $result = $this->callCanDeactivate($ledger);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('journal line', $result['message']);
    }

    public function test_journal_history_blocker_message_includes_count(): void
    {
        $ledger = $this->makeLedger();
        $otherLedger = $this->makeLedger();
        $this->insertBalancedJournalPair($ledger->id, $otherLedger->id);

        $result = $this->callCanDeactivate($ledger);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('1 journal line(s)', $result['message']);
    }

    public function test_journal_history_blocker_aggregates_multiple_lines(): void
    {
        $ledger = $this->makeLedger();
        $otherLedger = $this->makeLedger();

        // Insert 3 balanced journal entries (each posts 1 debit + 1 credit).
        for ($i = 0; $i < 3; $i++) {
            $this->insertBalancedJournalPair($ledger->id, $otherLedger->id);
        }

        $result = $this->callCanDeactivate($ledger);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('3 journal line(s)', $result['message']);
    }

    // ====================================================================
    // Blocker 3: Child ledgers (parent_id references)
    // ====================================================================

    public function test_cannot_deactivate_ledger_with_child_ledgers(): void
    {
        $parent = $this->makeLedger();
        $this->insertChildLedger($parent->id);

        $result = $this->callCanDeactivate($parent);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('child ledger', $result['message']);
    }

    public function test_child_ledger_blocker_message_includes_count(): void
    {
        $parent = $this->makeLedger();
        $this->insertChildLedger($parent->id);
        $this->insertChildLedger($parent->id);

        $result = $this->callCanDeactivate($parent);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('2 child ledger(s)', $result['message']);
    }

    public function test_can_deactivate_ledger_with_soft_deleted_children(): void
    {
        // Children that have been soft-deleted should NOT block deactivation.
        $parent = $this->makeLedger();
        $childId = $this->insertChildLedger($parent->id);

        // Soft-delete the child.
        DB::table('ledgers')->where('id', $childId)->update([
            'deleted_at' => now(),
            'is_active'  => false,
        ]);

        $result = $this->callCanDeactivate($parent);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Blocker 4: Sole active ledger for a critical nature
    // ====================================================================

    public function test_cannot_deactivate_sole_active_critical_nature_ledger(): void
    {
        $ledger = $this->makeLedger(['ledger_nature' => 'cash_bank']);

        $result = $this->callCanDeactivate($ledger);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sole active', $result['message']);
        $this->assertStringContainsString('cash_bank', $result['message']);
    }

    public function test_can_deactivate_critical_nature_ledger_when_another_active_exists(): void
    {
        // Two active ledgers with the same critical nature — deactivating
        // one is allowed (the other still satisfies the resolver).
        $ledgerA = $this->makeLedger(['ledger_nature' => 'cash_bank']);
        $this->makeLedger(['ledger_nature' => 'cash_bank']);

        $result = $this->callCanDeactivate($ledgerA);

        $this->assertTrue($result['ok']);
    }

    public function test_sole_active_blocker_covers_all_7_critical_natures(): void
    {
        foreach (Ledger::criticalNatures() as $nature) {
            $ledger = $this->makeLedger(['ledger_nature' => $nature]);

            $result = $this->callCanDeactivate($ledger);

            $this->assertFalse(
                $result['ok'],
                "Critical nature '{$nature}' should be blocked from deactivation when sole active"
            );
            $this->assertStringContainsString($nature, $result['message']);
        }
    }

    public function test_sole_active_blocker_ignores_inactive_ledgers_with_same_nature(): void
    {
        // An inactive ledger with the same nature doesn't satisfy the
        // resolver — sole-active block still triggers.
        $active = $this->makeLedger(['ledger_nature' => 'ar']);
        $this->makeLedger(['ledger_nature' => 'ar', 'is_active' => false]);

        $result = $this->callCanDeactivate($active);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sole active', $result['message']);
    }

    public function test_sole_active_blocker_ignores_soft_deleted_ledgers_with_same_nature(): void
    {
        $active = $this->makeLedger(['ledger_nature' => 'inventory']);
        $this->insertLedger([
            'ledger_nature' => 'inventory',
            'is_active'     => false,
            'deleted_at'    => now(),
        ]);

        $result = $this->callCanDeactivate($active);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sole active', $result['message']);
    }

    // ====================================================================
    // Combined blockers — multiple reported in single message
    // ====================================================================

    public function test_journal_history_and_child_blockers_both_appear_in_message(): void
    {
        $parent = $this->makeLedger();
        $otherLedger = $this->makeLedger();
        $this->insertBalancedJournalPair($parent->id, $otherLedger->id);
        $this->insertChildLedger($parent->id);

        $result = $this->callCanDeactivate($parent);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('journal line', $result['message']);
        $this->assertStringContainsString('child ledger', $result['message']);
    }

    public function test_sole_active_and_child_blockers_both_appear_in_message(): void
    {
        $parent = $this->makeLedger(['ledger_nature' => 'cash_bank']);
        $this->insertChildLedger($parent->id);

        $result = $this->callCanDeactivate($parent);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sole active', $result['message']);
        $this->assertStringContainsString('child ledger', $result['message']);
    }

    public function test_all_three_non_system_blockers_appear_in_message(): void
    {
        $parent = $this->makeLedger(['ledger_nature' => 'cash_bank']);
        $otherLedger = $this->makeLedger();
        $this->insertBalancedJournalPair($parent->id, $otherLedger->id);
        $this->insertChildLedger($parent->id);

        $result = $this->callCanDeactivate($parent);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('journal line', $result['message']);
        $this->assertStringContainsString('child ledger', $result['message']);
        $this->assertStringContainsString('sole active', $result['message']);
    }

    // ====================================================================
    // Return shape contract
    // ====================================================================

    public function test_returns_array_with_ok_and_message_keys(): void
    {
        $ledger = $this->makeLedger();

        $result = $this->callCanDeactivate($ledger);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertIsBool($result['ok']);
        $this->assertIsString($result['message']);
    }

    public function test_returns_ok_false_with_non_empty_message_when_blocked(): void
    {
        $ledger = $this->makeLedger(['is_system' => true]);

        $result = $this->callCanDeactivate($ledger);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['message']);
    }

    public function test_returns_ok_true_with_empty_message_when_not_blocked(): void
    {
        $ledger = $this->makeLedger();

        $result = $this->callCanDeactivate($ledger);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['message']);
    }
}
