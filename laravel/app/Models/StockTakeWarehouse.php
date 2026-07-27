<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stock Take Warehouse — Phase 6.4.
 * Links a session to the warehouses being counted. Each warehouse has its own
 * count status (pending → counting → completed).
 *
 * Phase 7: 'recounting' added as a transient state between 'completed' and
 * 'counting'. recountWarehouse() sets 'recounting' (audited) then immediately
 * 'counting'; the vocab is forward-compatible with a future async recount
 * assignment that leaves the warehouse in 'recounting' until the counter
 * opens the page.
 *
 * @property int $id
 * @property int $stock_take_session_id
 * @property int $warehouse_id
 * @property string $status pending|counting|completed|recounting
 */
class StockTakeWarehouse extends Model
{
    protected $table = 'stock_take_warehouses';

    public $timestamps = false;

    protected $fillable = [
        'stock_take_session_id',
        'warehouse_id',
        'status',
    ];

    protected $casts = [
        'stock_take_session_id' => 'integer',
        'warehouse_id' => 'integer',
    ];

    public function session(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(StockTakeSession::class, 'stock_take_session_id');
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockTakeItem::class, 'stock_take_session_id', 'stock_take_session_id')
                    ->whereColumn('warehouse_id', 'warehouse_id');
    }

    /**
     * Phase 7: is this warehouse mid-recount? 'recounting' is transient
     * (recountWarehouse() flips it to 'counting' in the same transaction),
     * but this helper lets the count page badge a recount in progress if a
     * future async flow leaves the state here.
     */
    public function isRecounting(): bool { return $this->status === 'recounting'; }
}
