<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stock Take Warehouse — Phase 6.4.
 * Links a session to the warehouses being counted. Each warehouse has its own
 * count status (pending → counting → completed).
 *
 * @property int $id
 * @property int $stock_take_session_id
 * @property int $warehouse_id
 * @property string $status pending|counting|completed
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
}
