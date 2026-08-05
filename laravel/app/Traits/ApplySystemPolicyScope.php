<?php

namespace App\Traits;

use App\Services\Compliance\SystemPolicyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Apply System Policy Scope — Phase 11.
 *
 * This trait adds a global Eloquent scope that automatically applies
 * system policy restrictions to queries. Models that have date-based
 * records (sales_invoices, purchase_receives, customer_payments, etc.)
 * use this trait to automatically clamp dates during INVESTIGATION mode.
 *
 * Usage on a model:
 *   class SalesInvoice extends Model {
 *     use ApplySystemPolicyScope;
 *
 *     // Override the date column used for clamping (defaults to created_at):
 *     protected function policyDateColumn(): string
 *     {
 *         return 'invoice_date';
 *     }
 *   }
 *
 * In NORMAL mode: no restriction (SELECT *).
 * In INVESTIGATION mode: WHERE date_column >= fiscal_year_start
 *
 * Controllers should NOT know how investigation mode works — the scope
 * handles it transparently. To bypass (e.g. for audit/admin views), use:
 *   Model::withoutPolicy()->get();
 *
 * NOTE (G-171 / AUDIT-TRAIL-2): the per-model date column is exposed via a
 * METHOD (not a property) so that models can override the value without
 * hitting the PHP < 8.3 restriction on redeclaring a trait property with a
 * different value. This project targets PHP ^8.2, where redeclaring a trait
 * property with a different initial value is a fatal error. Method override
 * is always allowed. The trait was previously unused (dead scaffolding) —
 * this refactor + the 6 model wirings make INVESTIGATION mode functional.
 *
 * Fail-open: if the policy service / cache / DB is unavailable (e.g. during
 * a cache outage or an edge-case bootstrap), the scope logs a warning and
 * applies NO restriction. This prevents a policy-cache outage from breaking
 * every Eloquent query on traited models (the worst case is that
 * INVESTIGATION mode temporarily does not clamp reads, which is preferable
 * to a total query failure across the application).
 */
trait ApplySystemPolicyScope
{
    /**
     * The date column used for INVESTIGATION-mode clamping.
     *
     * Models using this trait SHOULD override this method to return their
     * primary business date column (e.g. 'invoice_date', 'payment_date').
     * The default 'created_at' is a safe fallback for models that have no
     * dedicated business-date column.
     */
    protected function policyDateColumn(): string
    {
        return 'created_at';
    }

    /**
     * Boot the trait — register the global scope.
     */
    public static function bootApplySystemPolicyScope(): void
    {
        static::addGlobalScope('system_policy', function (Builder $builder) {
            try {
                $service = app(SystemPolicyService::class);

                if (!$service->isInvestigation()) {
                    return; // NORMAL mode — no restriction.
                }

                $fiscalStart = $service->getFiscalYearStart();
                if ($fiscalStart) {
                    $column = (new static())->policyDateColumn();
                    $builder->whereDate($column, '>=', $fiscalStart);
                }
            } catch (\Throwable $e) {
                // Fail open: a policy-cache / DB / container outage must not
                // break every query on this model. Log once per occurrence
                // (the scope runs per-query, so this can be noisy under
                // sustained outage — accept that vs. silent breakage).
                Log::warning('ApplySystemPolicyScope: failed to resolve system policy; skipping scope (fail-open).', [
                    'model' => static::class,
                    'exception' => $e->getMessage(),
                ]);
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
