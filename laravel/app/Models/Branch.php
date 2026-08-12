<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Branch — maps to legacy `branches` table.
 * RC_ERP has 4 branches: Head Office, Patuatuli, Nowabpur, Tarabo.
 */
class Branch extends Model
{
    use SoftDeletes, AuditableMasterData, HasFactory;

    protected $table = 'branches';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'branch_code',
        'branch_name',
        'company_id',
        'address',
        'phone',
        'email',
        'invoice_header_image',
        'invoice_footer_image',
        'invoice_header_text',
        'invoice_footer_text',
        'invoice_watermark_text',
        'invoice_signatory_name',
        'invoice_signatory_title',
        'invoice_terms',
        'is_active',
        'created_by',
        'deleted_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function employees(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Employee::class, 'branch_id');
    }

    public function warehouses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Warehouse::class, 'branch_id');
    }

    /**
     * Dimension values scoped to this branch.
     *
     * G-346 (G22) FINANCE-DIM-1: the dimension_values table has a nullable
     * branch_id FK (migration 2026_08_10_000002 L84). A NULL branch_id means
     * "all branches" (company-wide dimension value); a non-null branch_id
     * means "specific to this branch". This relationship returns ONLY the
     * branch-specific values. Callers needing company-wide values must query
     * DimensionValue::whereNull('branch_id') separately (or use the
     * DimensionValueBranchScope global scope which returns both).
     */
    public function dimensionValues(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\DimensionValue::class, 'branch_id');
    }

    /**
     * Scope: active, non-deleted branches.
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }

    /**
     * Get the full public URL for the invoice header image.
     */
    public function getInvoiceHeaderUrlAttribute(): ?string
    {
        if (!$this->invoice_header_image) return null;
        return \Storage::disk('public')->url($this->invoice_header_image);
    }

    /**
     * Get the full public URL for the invoice footer image.
     */
    public function getInvoiceFooterUrlAttribute(): ?string
    {
        if (!$this->invoice_footer_image) return null;
        return \Storage::disk('public')->url($this->invoice_footer_image);
    }

    /**
     * Get the absolute server path for the invoice header image (for DomPDF).
     */
    public function getInvoiceHeaderPathAttribute(): ?string
    {
        if (!$this->invoice_header_image) return null;
        return storage_path('app/public/' . $this->invoice_header_image);
    }

    /**
     * Get the absolute server path for the invoice footer image (for DomPDF).
     */
    public function getInvoiceFooterPathAttribute(): ?string
    {
        if (!$this->invoice_footer_image) return null;
        return storage_path('app/public/' . $this->invoice_footer_image);
    }
}
