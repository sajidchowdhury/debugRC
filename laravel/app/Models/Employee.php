<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Employee — maps to legacy `employees` table.
 * The role is stored here (not on User), matching legacy schema.
 *
 * Task 37: Added commissionRule() and commissionEntries() relationships
 * for salesman commission tracking.
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
        'father_name',
        'mother_name',
        'date_of_birth',
        'nid',
        'role',
        'branch_id',
        'phone',
        'mobile',
        'email',
        'photo',
        'address',
        'designation',
        'department',
        'salary',
        'bank_account',
        'blood_group',
        'joining_date',
        'is_active',
        'deleted_by',
    ];

    protected $casts = [
        'salary' => 'decimal:2',
        'is_active' => 'boolean',
        'joining_date' => 'date',
        'date_of_birth' => 'date',
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

    /**
     * Scope: employees with dispatcher role.
     */
    public function scopeDispatchers(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('role', 'dispatcher')->active();
    }

    /**
     * Scope: employees with salesman role.
     */
    public function scopeSalesmen(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('role', 'salesman')->active();
    }

    /**
     * Invoices this employee is assigned to as a dispatcher.
     */
    public function dispatchedInvoices(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(SalesInvoice::class, 'sales_invoice_dispatchers', 'employee_id', 'sales_invoice_id')
            ->withPivot('dispatch_role');
    }

    /**
     * Commission rule currently active for this salesman (Task 37).
     */
    public function commissionRule(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CommissionRule::class, 'salesman_id')
            ->where('is_active', true)
            ->whereNull('effective_to')
            ->orderByDesc('effective_from');
    }

    /**
     * All commission rules for this salesman, including historical (Task 37).
     */
    public function commissionRules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CommissionRule::class, 'salesman_id')
            ->orderByDesc('effective_from');
    }

    /**
     * Commission entries for this salesman (Task 37).
     */
    public function commissionEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CommissionEntry::class, 'salesman_id')
            ->orderByDesc('entry_date');
    }
}
