<?php

namespace Tests\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Branch Demand Phase 10 test helpers — direct table inserts for
 * branch-demand-specific dependencies that have NOT NULL columns +
 * FK constraints that factories can't easily satisfy.
 *
 * Used by:
 *  - tests/Unit/BranchDemand/BranchDemandAuditLoggerTest
 *  - tests/Unit/BranchDemand/BranchDemandServiceTest
 *  - tests/Unit/BranchDemand/BranchIntercompanyServiceTest
 *  - tests/Unit/BranchDemand/BranchDemandRepricingServiceTest
 *  - tests/Unit/BranchDemand/BranchDemandAuditServiceTest
 *  - tests/Feature/BranchDemand/BranchDemandApiTest
 *
 * Mirrors the InsertsBranchDependencies + InsertsLedgerDependencies pattern.
 */
trait InsertsBranchDemandDependencies
{
    /**
     * Insert a branch_demand_items row with the minimum required columns.
     * Returns the item id.
     */
    protected function insertBranchDemandItem(
        int $demandId,
        int $productId,
        float $qty = 1.0,
        float $costRate = 10.0,
        ?int $fromWarehouseId = null,
        ?int $toWarehouseId = null,
        array $overrides = [],
    ): int {
        return DB::table('branch_demand_items')->insertGetId(array_merge([
            'branch_demand_id' => $demandId,
            'product_id'       => $productId,
            'qty'              => $qty,
            'cost_rate'        => $costRate,
            'from_warehouse_id' => $fromWarehouseId,
            'to_warehouse_id'  => $toWarehouseId,
            'price_min'        => $costRate * 0.9,
            'price_max'        => $costRate * 1.1,
            'price_default'    => $costRate,
        ], $overrides));
    }

    /**
     * Insert a branch_ledger row with the minimum required columns.
     * Returns the ledger id.
     */
    protected function insertBranchLedger(
        int $debtorBranchId,
        int $creditorBranchId,
        float $amount,
        string $entryType = 'demand_send',
        ?int $demandId = null,
        ?int $journalEntryId = null,
        bool $isReversed = false,
        array $overrides = [],
    ): int {
        return DB::table('branch_ledger')->insertGetId(array_merge([
            'from_branch_id'   => $debtorBranchId,
            'to_branch_id'     => $creditorBranchId,
            'transaction_date' => now()->toDateString(),
            'reference_type'   => $entryType,
            'reference_id'     => $demandId,
            'debit'            => $amount,
            'credit'           => 0,
            'running_balance'  => $amount,
            'journal_entry_id' => $journalEntryId,
            'is_reversed'      => $isReversed,
            'created_at'       => now(),
        ], $overrides));
    }

    /**
     * Insert a branch_demand_repricing row with the minimum required columns.
     * Returns the repricing id.
     */
    protected function insertBranchDemandRepricing(
        int $demandId,
        float $originalTotal,
        float $newTotal,
        ?int $approvedBy = null,
        ?int $journalEntryId = null,
        ?int $createdBy = null,
        array $overrides = [],
    ): int {
        return DB::table('branch_demand_repricing')->insertGetId(array_merge([
            'branch_demand_id'    => $demandId,
            'original_total_value' => $originalTotal,
            'new_total_value'     => $newTotal,
            'adjustment_amount'   => $newTotal - $originalTotal,
            'reason'              => 'Test repricing adjustment',
            'approved_by'         => $approvedBy,
            'journal_entry_id'    => $journalEntryId,
            'created_by'          => $createdBy,
            'created_at'          => now(),
        ], $overrides));
    }

    /**
     * Insert a branch_demand_audit_log row with the minimum required columns.
     * Returns the audit log id.
     */
    protected function insertBranchDemandAuditLog(
        int $demandId,
        string $action,
        ?int $branchId = null,
        ?int $actorId = null,
        array $payload = [],
    ): int {
        return DB::table('branch_demand_audit_log')->insertGetId([
            'branch_demand_id' => $demandId,
            'branch_id'        => $branchId,
            'action'           => $action,
            'actor_id'         => $actorId,
            'actor_role'       => 'admin',
            'payload'          => json_encode($payload),
            'ip_address'       => '127.0.0.1',
            'user_agent'       => 'PHPUnit',
            'created_at'       => now(),
        ]);
    }

    /**
     * Insert a branch_demand_money_transfer_settlements row.
     * Returns the settlement id.
     */
    protected function insertMoneyTransferSettlement(
        int $demandId,
        int $transferId,
        float $settledAmount,
        array $overrides = [],
    ): int {
        return DB::table('branch_demand_money_transfer_settlements')->insertGetId(array_merge([
            'demand_id'      => $demandId,
            'transfer_id'    => $transferId,
            'settled_amount' => $settledAmount,
            'created_at'     => now(),
        ], $overrides));
    }

    /**
     * Insert a branch_demand_customer_payment_settlements row.
     * Returns the settlement id.
     */
    protected function insertCustomerPaymentSettlement(
        int $demandId,
        int $paymentId,
        float $settledAmount,
        array $overrides = [],
    ): int {
        return DB::table('branch_demand_customer_payment_settlements')->insertGetId(array_merge([
            'demand_id'      => $demandId,
            'payment_id'     => $paymentId,
            'settled_amount' => $settledAmount,
            'created_at'     => now(),
        ], $overrides));
    }

    /**
     * Insert a product_price_history row.
     * Returns the id.
     */
    protected function insertProductPriceHistory(
        int $productId,
        float $defaultRate = 10.0,
        float $minRate = 9.0,
        float $maxRate = 11.0,
        array $overrides = [],
    ): int {
        return DB::table('product_price_history')->insertGetId(array_merge([
            'product_id'    => $productId,
            'default_rate'  => $defaultRate,
            'min_rate'      => $minRate,
            'max_rate'      => $maxRate,
            'effective_from' => now()->toDateString(),
            'created_at'    => now(),
        ], $overrides));
    }
}
