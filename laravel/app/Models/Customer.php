<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use App\Traits\AuditableMasterData;

/**
 * Customer — maps to legacy `customers` table.
 * Shop accounts for sales invoices, challan, payments, and customer_ledger AR.
 */
class Customer extends Model
{
    use SoftDeletes, AuditableMasterData, HasFactory;

    protected $table = 'customers';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    /**
     * Hide internal FTS columns from JSON serialization.
     *
     * The `search_vector` GENERATED tsvector column is used internally by
     * scopeSearch() for full-text lookups. Exposing it in JSON responses
     * (e.g. DataTables AJAX) can cause serialization issues with some
     * PDO_PGSQL driver versions that return tsvector as a non-string type.
     * Hiding it keeps the AJAX payload lean and avoids "DataTables Ajax
     * error" warnings triggered by malformed JSON.
     */
    protected $hidden = ['search_vector'];

    protected $fillable = [
        'customer_code',
        'customer_name',
        'phone',
        'mobile',
        'email',
        'address',
        'branch_id',
        'sales_person_id',
        'credit_limit',
        'opening_balance',
        'balance_type',
        'is_active',
        'deleted_by',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function salesPerson(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class, 'sales_person_id');
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }

    /**
     * Full-text search scope using PostgreSQL tsvector + GIN.
     *
     * Uses the GENERATED search_vector column (migration 2025_01_20_000005)
     * with weighted 'simple' dictionary:
     *   A = customer_name, B = customer_code, C = phone + mobile, D = address.
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
            $q->orWhere('customer_name', 'ILIKE', "%{$term}%")
              ->orWhere('customer_code', 'ILIKE', "%{$term}%")
              ->orWhere('mobile', 'ILIKE', "%{$term}%")
              ->orWhere('phone', 'ILIKE', "%{$term}%");
        });
    }

    /**
     * Check if the search_vector column exists on the customers table.
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

    // ───────── Reverse Relationships (Customer 360 Hub) ─────────

    /**
     * All sales invoices for this customer (including reversed/cancelled).
     * Use ->where('is_reversed', false)->whereNotIn('status', ['cancelled']) for active only.
     */
    public function salesInvoices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'customer_id');
    }

    /**
     * All payments received from this customer.
     */
    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CustomerPayment::class, 'customer_id');
    }

    /**
     * Customer sub-ledger entries (AR running balance).
     */
    public function ledgerEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CustomerLedger::class, 'customer_id');
    }

    /**
     * All sales returns for this customer.
     */
    public function salesReturns(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SalesReturn::class, 'customer_id');
    }
}
