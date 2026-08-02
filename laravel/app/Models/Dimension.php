<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dimension — a reporting axis (cost_center, profit_center, department,
 * project, location) that enables segment reporting.
 *
 * Inspired by SAP B1's dimension system (up to 5 user-defined dimensions)
 * and Tally's Cost Categories. Each dimension has dimension_values
 * (e.g. "Department" dimension → "Sales", "Admin", "HR" values).
 *
 * journal_lines.dimension_value_id links each posting to a dimension value,
 * enabling segment P&L and segment Balance Sheet.
 */
class Dimension extends Model
{
    use SoftDeletes;

    protected $table = 'dimensions';

    protected $fillable = [
        'name',
        'type',
        'code',
        'is_active',
        'description',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Relationships ───────────────────────────────────────────────

    public function values(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DimensionValue::class, 'dimension_id');
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }

    public function scopeOfType(\Illuminate\Database\Eloquent\Builder $query, string $type): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('type', $type);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public static function typeOptions(): array
    {
        return [
            'cost_center'   => 'Cost Center',
            'profit_center' => 'Profit Center',
            'department'    => 'Department',
            'project'       => 'Project',
            'location'      => 'Location',
        ];
    }

    public function typeLabel(): string
    {
        return self::typeOptions()[$this->type] ?? $this->type;
    }
}
