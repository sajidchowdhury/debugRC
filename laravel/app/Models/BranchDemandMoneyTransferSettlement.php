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
     * Note: No MoneyTransfer Eloquent model exists yet. Access the
     * money_transfers table via DB::table('money_transfers') in services.
     * When a MoneyTransfer model is created later, this relationship
     * can be properly defined.
     */
    // public function transfer(): BelongsTo { ... }

    public function demand(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BranchDemand::class, 'demand_id');
    }
}
