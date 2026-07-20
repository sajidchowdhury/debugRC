<?php

namespace Tests\Unit\Bank;

use App\Http\Controllers\Admin\BankController;
use App\Models\Bank;
use ReflectionMethod;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBankDependencies;
use Tests\TestCase;

/**
 * Bank Deactivation Unit Test — directly tests the protected
 * canDeactivate() method on BankController via reflection.
 *
 * Tests the 2 safety checks in isolation (Phase 13):
 *   1. Non-zero balance — bank has money sitting in it. Deactivating would
 *      orphan funds (no way to reconcile back to GL).
 *   2. Active bank_ledger_mapping — bank is linked to a GL ledger of
 *      nature `cash_bank`. Deactivating would break cash-book reconciliation.
 *
 * Phase 13 commit.
 */
class BankDeactivationUnitTest extends TestCase
{
    use BuildsRoleUsers, InsertsBankDependencies;

    private BankController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->controller = app(BankController::class);
    }

    /**
     * Invoke the protected canDeactivate() method via reflection.
     *
     * @return array{ok: bool, message: string}
     */
    private function callCanDeactivate(Bank $bank): array
    {
        $method = new ReflectionMethod($this->controller, 'canDeactivate');
        $method->setAccessible(true);

        return $method->invoke($this->controller, $bank);
    }

    /**
     * Convenience: create a Bank with the given overrides.
     */
    private function makeBank(array $overrides = []): Bank
    {
        return Bank::factory()->create($overrides);
    }

    // ====================================================================
    // Happy path — no blockers
    // ====================================================================

    public function test_can_deactivate_bank_with_no_dependencies(): void
    {
        $bank = $this->makeBank();

        $result = $this->callCanDeactivate($bank);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['message']);
    }

    public function test_can_deactivate_bank_with_zero_balance(): void
    {
        $bank = $this->makeBank(['balance' => 0]);

        $result = $this->callCanDeactivate($bank);

        $this->assertTrue($result['ok']);
    }

    public function test_can_deactivate_bank_with_null_balance(): void
    {
        // DB allows NULL balance; canDeactivate treats NULL as 0.
        $bank = $this->makeBank(['balance' => null]);

        $result = $this->callCanDeactivate($bank);

        $this->assertTrue($result['ok']);
    }

    public function test_can_deactivate_bank_with_no_ledger_mapping(): void
    {
        $bank = $this->makeBank();

        $result = $this->callCanDeactivate($bank);

        $this->assertTrue($result['ok']);
    }

    // ====================================================================
    // Blocker 1: Non-zero balance
    // ====================================================================

    public function test_cannot_deactivate_bank_with_positive_balance(): void
    {
        $bank = $this->makeBank(['balance' => 5000.00]);

        $result = $this->callCanDeactivate($bank);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('balance', $result['message']);
        $this->assertStringContainsString('5,000.00', $result['message']);
    }

    public function test_cannot_deactivate_bank_with_negative_balance(): void
    {
        // Negative balance means the bank is overdrawn — still non-zero,
        // still a blocker (can't deactivate an overdrawn account).
        $bank = $this->makeBank(['balance' => -250.50]);

        $result = $this->callCanDeactivate($bank);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('balance', $result['message']);
    }

    public function test_balance_blocker_message_includes_amount(): void
    {
        $bank = $this->makeBank(['balance' => 1234.56]);

        $result = $this->callCanDeactivate($bank);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('1,234.56', $result['message']);
    }

    public function test_tiny_non_zero_balance_still_blocks(): void
    {
        // Even a 1-cent balance is non-zero → block (prevents orphaning funds).
        $bank = $this->makeBank(['balance' => 0.01]);

        $result = $this->callCanDeactivate($bank);

        $this->assertFalse($result['ok']);
    }

    // ====================================================================
    // Blocker 2: Active bank_ledger_mapping (GL link)
    // ====================================================================

    public function test_cannot_deactivate_bank_with_active_ledger_mapping(): void
    {
        $bank = $this->makeBank();
        $ledgerId = $this->insertLedger();
        $this->insertBankLedgerMapping($bank->id, $ledgerId);

        $result = $this->callCanDeactivate($bank);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('ledger mapping', $result['message']);
    }

    public function test_ledger_mapping_blocker_message_includes_count(): void
    {
        $bank = $this->makeBank();
        $ledgerId = $this->insertLedger();
        $this->insertBankLedgerMapping($bank->id, $ledgerId);

        $result = $this->callCanDeactivate($bank);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('1 active GL ledger mapping', $result['message']);
    }

    // ====================================================================
    // Combined blockers — both reported in single message
    // ====================================================================

    public function test_both_balance_and_mapping_blockers_appear_in_message(): void
    {
        $bank = $this->makeBank(['balance' => 750.00]);
        $ledgerId = $this->insertLedger();
        $this->insertBankLedgerMapping($bank->id, $ledgerId);

        $result = $this->callCanDeactivate($bank);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('balance', $result['message']);
        $this->assertStringContainsString('ledger mapping', $result['message']);
        $this->assertStringContainsString('750.00', $result['message']);
    }

    public function test_mapping_blocker_returned_when_balance_is_zero(): void
    {
        $bank = $this->makeBank(['balance' => 0]);
        $ledgerId = $this->insertLedger();
        $this->insertBankLedgerMapping($bank->id, $ledgerId);

        $result = $this->callCanDeactivate($bank);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('ledger mapping', $result['message']);
        $this->assertStringNotContainsString('balance', $result['message']);
    }

    public function test_balance_blocker_returned_when_no_mapping(): void
    {
        $bank = $this->makeBank(['balance' => 1000.00]);

        $result = $this->callCanDeactivate($bank);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('balance', $result['message']);
        $this->assertStringNotContainsString('ledger mapping', $result['message']);
    }

    // ====================================================================
    // Return shape contract
    // ====================================================================

    public function test_returns_array_with_ok_and_message_keys(): void
    {
        $bank = $this->makeBank();

        $result = $this->callCanDeactivate($bank);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertIsBool($result['ok']);
        $this->assertIsString($result['message']);
    }

    public function test_returns_ok_false_with_non_empty_message_when_blocked(): void
    {
        $bank = $this->makeBank(['balance' => 10.00]);

        $result = $this->callCanDeactivate($bank);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['message']);
    }

    public function test_returns_ok_true_with_empty_message_when_not_blocked(): void
    {
        $bank = $this->makeBank();

        $result = $this->callCanDeactivate($bank);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['message']);
    }
}
