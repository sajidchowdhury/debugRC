<?php

namespace App\Traits;

use App\Services\Compliance\SystemPolicyService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Apply System Policy Scope — Phase 11.
 *
 * This trait adds a global Eloquent scope that automatically applies
 * system policy restrictions to queries. Models that have date-based
 * records (sales_invoices, purchase_receives, journal_entries, etc.)
 * use this trait to automatically clamp dates during INVESTIGATION mode.
 *
 * Usage on a model:
 *   class SalesInvoice extends Model {
 *     use ApplySystemPolicyScope;
 *     protected $policyDateColumn = 'invoice_date'; // the date column to clamp
 *   }
 *
 * In NORMAL mode: no restriction (SELECT *).
 * In INVESTIGATION mode: WHERE date_column >= fiscal_year_start
 *
 * Controllers should NOT know how investigation mode works — the scope
 * handles it transparently. To bypass (e.g. for audit/admin views), use:
 *   Model::withoutPolicy()->get();
 */
trait ApplySystemPolicyScope
{
    /**
     * The date column used for policy clamping (override in model).
     */
    protected string $policyDateColumn = 'created_at';

    /**
     * Boot the trait — register the global scope.
     */
    public static function bootApplySystemPolicyScope(): void
    {
        static::addGlobalScope('system_policy', function (Builder $builder) {
            $service = app(SystemPolicyService::class);

            if (!$service->isInvestigation()) {
                return; // NORMAL mode — no restriction.
            }

            $fiscalStart = $service->getFiscalYearStart();
            if ($fiscalStart) {
                $column = (new static())->policyDateColumn;
                $builder->whereDate($column, '>=', $fiscalStart);
            }
        });
    }

    /**
     * Bypass the system policy scope (for audit/admin views).
     */
    public function scopeWithoutPolicy(Builder $query): Builder
    {
        return $query->withoutGlobalScope('system_policy');
    }
}
