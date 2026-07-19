<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Employee — maps to legacy `employees` table.
 * The role is stored here (not on User), matching legacy schema.
 */
class Employee extends Model
{
    use SoftDeletes, AuditableMasterData, HasFactory;

    protected $table = 'employees';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $dates = ['deleted_at', 'joining_date'];

    protected $fillable = [
        'employee_code',
        'name',
        'role',
        'branch_id',
        'phone',
        'email',
        'photo',
        'address',
        'salary',
        'joining_date',
        'is_active',
        'deleted_by',
    ];

    protected $casts = [
        'salary' => 'decimal:2',
        'is_active' => 'boolean',
        'joining_date' => 'date',
    ];

    // ===================== RELATIONSHIPS =====================

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(User::class, 'employee_id');
    }

    /**
     * Scope: active, non-deleted employees.
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }
}
