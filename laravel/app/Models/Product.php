<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use App\Traits\AuditableMasterData;

/**
 * Product — maps to legacy `products` table.
 * Electronics inventory items with category, group, pricing, and stock tracking.
 */
class Product extends Model
{
    use SoftDeletes, AuditableMasterData, HasFactory;

    protected $table = 'products';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'product_code',
        'product_name',
        'category_id',
        'group_id',
        'unit',
        'purchase_rate',
        'sales_rate',
        'min_stock',
        'max_stock',
        'reorder_level',
        'product_image',
        'is_active',
        'condition_state',
        'deleted_by',
    ];

    protected $casts = [
        'purchase_rate' => 'decimal:2',
        'sales_rate' => 'decimal:2',
        'min_stock' => 'decimal:4',
        'max_stock' => 'decimal:4',
        'reorder_level' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    /**
     * Full-text search scope using PostgreSQL tsvector + GIN.
     *
     * Uses the GENERATED search_vector column (migration 2025_01_20_000005)
     * with weighted 'simple' dictionary:
     *   A = product_name, B = product_code.
     *
     * Falls back to ILIKE if search_vector column doesn't exist
     * (e.g. before migration is run).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $term  Search term (plain text, no special syntax needed)
     * @param  bool  $ranked  Whether to include ts_rank for ordering
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch($query, string $term, bool $ranked = true)
    {
        if ($term === '') {
            return $query;
        }

        // Use full-text search if search_vector column exists
        if ($this->hasSearchVector()) {
            $tsquery = "plainto_tsquery('simple', ?)";
            $binding = $term;

            $query->whereRaw("search_vector @@ {$tsquery}", [$binding]);

            if ($ranked) {
                $query->selectRaw("*, ts_rank(search_vector, {$tsquery}) AS search_rank", [$binding])
                      ->orderByDesc('search_rank');
            }

            return $query;
        }

        // Fallback: ILIKE for pre-migration or if column dropped
        return $query->where(function ($q) use ($term) {
            $q->orWhere('product_name', 'ILIKE', "%{$term}%")
              ->orWhere('product_code', 'ILIKE', "%{$term}%");
        });
    }

    /**
     * Check if the search_vector column exists on the products table.
     * Cached for the request lifetime to avoid repeated schema queries.
     */
    protected function hasSearchVector(): bool
    {
        static $cache = [];

        $key = $this->getTable();

        if (! isset($cache[$key])) {
            $cache[$key] = collect(
                DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = 'search_vector'", [$key])
            )->isNotEmpty();
        }

        return $cache[$key];
    }

    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function group(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }

    public function priceHistory(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductPriceHistory::class, 'product_id');
    }

    /**
     * Phase 5 (Stock Adjustment plan) — the product's BASE unit of measure.
     *
     * The base unit is the UOM whose code matches this product's `unit` column
     * (e.g. a product with unit='Pcs' has base unit = the Pcs row in
     * units_of_measure). The base unit always has an implicit factor of 1 —
     * no product_uom_conversions row is required for the self-conversion.
     *
     * Resolved via UomConversionService::resolveBaseUnit() in the hot path
     * (cached). This relation is for eager-loading when needed.
     */
    public function baseUnit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit', 'code');
    }

    /**
     * Phase 5 — per-product UOM conversion factors (Carton→Pcs etc.).
     */
    public function uomConversions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductUomConversion::class, 'product_id');
    }

    /**
     * Get the current-effective price record (effective_from <= today, effective_to IS NULL or >= today).
     */
    public function currentPrice(): ?ProductPriceHistory
    {
        return $this->priceHistory()
            ->where('effective_from', '<=', today())
            ->where(function ($q) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', today());
            })
            ->orderBy('effective_from', 'desc')
            ->first();
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }
}
