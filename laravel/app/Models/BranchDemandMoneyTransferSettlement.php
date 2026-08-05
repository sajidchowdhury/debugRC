<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Branch Demand Money Transfer Settlement — tracks which money transfers
 * have settled which branch demands (FIFO).
 *
 * When an inter-branch money transfer (cash_to_cash or cash_to_bank) is made,
 * it auto-settles open branch demands in FIFO order (oldest first).
 *
 * @property int $id
 * @property int $transfer_id FK to money_transfers
 * @property int $demand_id FK to branch_demands
 * @property string $settled_amount
 * @property string $created_at
 */
class BranchDemandMoneyTransferSettlement extends Model
{
    protected $table = 'branch_demand_money_transfer_settlements';

    public $timestamps = false;

    protected $fillable = [
        'transfer_id',
        'demand_id',
        'settled_amount',
        'created_at',
    ];

    protected $casts = [
        'settled_amount' => 'decimal:2',
        'transfer_id' => 'integer',
        'demand_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * The money transfer that created this settlement.
     *
     * G-283 (G21) FINANCE-CONSOLIDATION-1 + G-328 (G11) FINANCE-BD-1:
     * the prior docblock was stale — it claimed "No MoneyTransfer Eloquent
     * model exists yet" and left the relationship commented out. The
     * MoneyTransfer model has existed since Phase 4 (app/Models/MoneyTransfer.php,
     * 153L, uses MoneyTransferBranchScope). The FK column transfer_id
     * REFERENCES money_transfers(id) ON DELETE CASCADE (migration
     * 2026_07_29_000014 L25). The relationship is now properly defined.
     * Existing service-layer code that uses DB::table('money_transfers')
     * directly (BranchDemandAuditService, BranchIntercompanyService) is
     * unaffected — this relationship is purely additive.
     */
    public function transfer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MoneyTransfer::class, 'transfer_id');
    }

    public function demand(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BranchDemand::class, 'demand_id');
    }
}
