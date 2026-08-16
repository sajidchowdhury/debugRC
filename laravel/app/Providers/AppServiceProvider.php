<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Phase 3: Register the LegacySessionBridge as a singleton
        // (it's stateless and shared across requests).
        $this->app->singleton(\App\Session\LegacySessionBridge::class);

        // Phase 9.2: Register LedgerNatureService as singleton (used by JournalPostingService).
        $this->app->singleton(\App\Services\Accounting\LedgerNatureService::class);

        // Phase 9.4: Register SubLedgerService + JournalReversalService as singletons.
        $this->app->singleton(\App\Services\Accounting\SubLedgerService::class);
        $this->app->singleton(\App\Services\Accounting\JournalReversalService::class);

        // P1-3: Register SalesAuditLogger as singleton (shared across services).
        $this->app->singleton(\App\Services\Sales\SalesAuditLogger::class);

        // DB-driven menu system: register MenuService as singleton.
        $this->app->singleton(\App\Services\MenuService::class);

        // Phase 11: Register SystemPolicyService as singleton.
        $this->app->singleton(\App\Services\Compliance\SystemPolicyService::class);

        // Phase 12: Register Archive Layer (Anti-Corruption Layer).
        $this->app->singleton(\App\Archive\Repositories\ArchiveRepositoryInterface::class, \App\Archive\Repositories\LegacyMySQLRepository::class);
        $this->app->singleton(\App\Archive\Services\ArchiveService::class);

        // REPORTS-AUDIT-1 (G-126 / csv-export.md G3): Register CsvExporter
        // as a singleton. The class was converted from all-static methods to
        // an instance class so it can read config values at construction
        // (BOM bytes, Content-Type, chunk size from config/reports.php) and
        // so it can be mocked in tests. The 9 master-data controllers
        // continue to call `CsvExporter::export(...)` via the
        // \App\Facades\CsvExporter Facade — call-site syntax unchanged.
        $this->app->singleton(\App\Services\Export\CsvExporter::class);

        // MEDIUM-WAVE-2-B (G-252 / notification-workflow.md §G14): Register
        // NotificationService + ListenNotifyService as singletons.
        //
        // Both services are stateless — neither carries request-specific
        // data — so singleton binding is safe + correct. The rationale for
        // singleton (vs the previous "let the container auto-resolve on each
        // call" default) is threefold:
        //
        //   1. **Single dispatch queue semantics.** NotificationService is
        //      the central dispatcher for ALL ERP notifications (Phase 10).
        //      Conceptually there should be one logical dispatcher per
        //      process — registering it as a singleton makes that explicit
        //      + lets future hardening (e.g. a per-process in-flight
        //      dispatch log or a deferred-dispatch queue) attach state to
        //      the shared instance instead of refactoring every call site.
        //
        //   2. **Single PG LISTEN connection.** ListenNotifyService bridges
        //      PostgreSQL LISTEN/NOTIFY with Redis Pub/Sub (Phase 1E). The
        //      service exposes `emitNotify()` + `publishToUser()` (called
        //      from NotificationService::dispatch) and is consumed by the
        //      ListenNotifyWorker artisan command. Singleton-binding it
        //      ensures the worker + the dispatcher share the same Redis
        //      publisher abstraction (and, when a long-lived worker
        //      process holds the singleton, the same Redis connection
        //      pool) — no risk of N parallel Redis publishers competing
        //      on the same channel.
        //
        //   3. **Single shared Redis publisher.** Both services publish to
        //      the same `rcerp:sse:*` Redis channels. Sharing a singleton
        //      lets a future refactor centralize the Redis client (one
        //      connection pool, one Pub/Sub multiplexer) without touching
        //      call sites.
        //
        // The NotificationService constructor takes an optional
        // ListenNotifyService dependency (`?ListenNotifyService $listenNotify
        // = null`); the container auto-resolves this when NotificationService
        // is resolved (which is now also a singleton, so the same
        // ListenNotifyService instance is injected on every resolution).
        // ListenNotifyService has NO constructor at all (verified L46 of
        // ListenNotifyService.php) — also safe as a singleton.
        $this->app->singleton(\App\Services\Notification\NotificationService::class);
        $this->app->singleton(\App\Services\Notification\ListenNotifyService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Set the legacy app URL (for the "Back to Legacy App" link).
        if (!config('app.legacy_url')) {
            config(['app.legacy_url' => '/']);
        }

        // Phase 11: Register the system policy gate.
        \Illuminate\Support\Facades\Gate::define('manage-system-policy', function (\App\Models\User $user) {
            return $user->isSuperadmin();
        });

        // Phase 4 F-18a: Notification bell + rule-management visibility.
        // Admins + superadmins see the "Settings" link in the notification
        // dropdown and can access admin/notifications/rules. Previously the
        // Gate was CONSUMED by <components/layouts/erp.blade.php> (@can) but
        // NEVER DEFINED — so the bell was hidden from everyone. The bell
        // itself (unread badge + recent dropdown) is visible to ALL
        // authenticated users since every user receives notifications; only
        // the rule-management entry point is gated here.
        \Illuminate\Support\Facades\Gate::define('view-notification-rules', function (\App\Models\User $user) {
            return $user->isAdmin(); // true for admin + superadmin (User::isAdmin() L168-171)
        });

        // Superadmin bypasses ALL policy checks (mirrors EnsureRole
        // middleware which already lets superadmin through on every route).
        // Without this Gate::before(), every Policy::viewAny()/view()/etc.
        // that checks hasRole('admin', 'manager', ...) blocks superadmin
        // because hasRole() uses strict in_array — 'superadmin' is never
        // in those explicit role lists. This single addition fixes the
        // 403 "This action is unauthorized" for superadmin on every
        // $this->authorize() call in every controller.
        //
        // Session 2 (FY isolation): AMENDED to explicitly exclude the
        // `viewHistoricalData` ability from the super-admin bypass.
        // This is the single most important line of code in the entire
        // Q1 phase — without it, super admin could read closed/locked
        // fiscal year data through any code path that calls
        // Gate::allows('viewHistoricalData', $fy). With the exclusion,
        // the FiscalYearPolicy::viewHistoricalData() method's
        // hard-deny (return false) is honored for everyone.
        \Illuminate\Support\Facades\Gate::before(function (\App\Models\User $user, string $ability) {
            if ($user->isSuperadmin()) {
                // Hard-deny viewHistoricalData even for super admin.
                // See FiscalYearPolicy::viewHistoricalData() docblock.
                if (in_array($ability, ['viewHistoricalData'], true)) {
                    return false;
                }
                return true;
            }
        });

        // Phase 6: Register model Policies. Laravel auto-discovers these
        // (App\Models\SalesInvoice → App\Policies\SalesInvoicePolicy), but
        // explicit registration makes the intent obvious + survives any
        // namespace-discovery edge cases. Each policy mirrors the existing
        // role: middleware rules exactly — $this->authorize() in controllers
        // is defense-in-depth, NOT the primary gate. See the policy class
        // docblocks for the full rule table.
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\SalesInvoice::class,
            \App\Policies\SalesInvoicePolicy::class
        );
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\CustomerPayment::class,
            \App\Policies\CustomerPaymentPolicy::class
        );
        // Phase 1 (Accounts Sub-Ledger): Supplier Transaction policy —
        // defense-in-depth behind the role: middleware on
        // admin/supplier-transactions routes. Mirrors the role matrix
        // exactly (accountant/manager/admin for all actions; branch
        // isolation stays as route middleware + BranchScope).
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\SupplierPayment::class,
            \App\Policies\SupplierTransactionPolicy::class
        );
        // Phase 2 (Accounts Sub-Ledger): Employee Transaction policy —
        // defense-in-depth behind the role: middleware on
        // admin/employee-transactions routes. Mirrors the role matrix
        // exactly (accountant/manager/admin for all actions; branch
        // isolation stays as route middleware + BranchScope).
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\EmployeeTransaction::class,
            \App\Policies\EmployeeTransactionPolicy::class
        );
        // Phase 6 (Accounts Sub-Ledger): Manual Journal policy —
        // defense-in-depth behind the role: middleware on
        // admin/manual-journals routes. Mirrors the role matrix
        // exactly (accountant/manager/admin for all actions; branch
        // isolation stays as route middleware + BranchScope).
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\ManualJournal::class,
            \App\Policies\ManualJournalPolicy::class
        );
        // Phase 1 (Stock Adjustment plan): StockAdjustment policy — defense-in-depth
        // behind the role: middleware on admin/stock-adjustments routes. Mirrors
        // the role matrix exactly (admin/accountant write; manager read-only).
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\StockAdjustment::class,
            \App\Policies\StockAdjustmentPolicy::class
        );
        // Phase 0 (Damage plan): DamageInvoice policy — defense-in-depth behind
        // the role: middleware on admin/damages routes. Mirrors the legacy
        // route_roles.php DamageController matrix (admin/manager/warehouse_manager
        // read+create; admin/manager confirm+cancel). Enforces same-branch for
        // non-admins on view/confirm/cancel.
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\DamageInvoice::class,
            \App\Policies\DamagePolicy::class
        );

        // ============================================================
        // Session 3 — Sub-problem B (Security/RLS cluster):
        // 13 new Policy classes for the 10 ISSUES_REGISTER rows G-026,
        // G-027, G-028, G-029, G-101, G-107 (6-model cluster),
        // G-155, G-156, G-157, G-163. Each policy mirrors the existing
        // route `role:` middleware EXACTLY (defense-in-depth — no
        // behavior change). Controller `$this->authorize()` wiring is
        // a separate follow-up session. See each policy's class
        // docblock for the full role-matrix reference.
        // ============================================================

        // Purchasing cluster (G-026 covered by the 3 policies below —
        // the cluster-level gap "No Purchase*Policy classes" is closed
        // by these + each policy's audit() method).
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\PurchaseOrder::class,
            \App\Policies\PurchaseOrderPolicy::class
        );
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\PurchaseReceive::class,
            \App\Policies\PurchaseReceivePolicy::class
        );
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\PurchaseReturn::class,
            \App\Policies\PurchaseReturnPolicy::class
        );

        // Finance cluster — Branch Demand (G-101).
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\BranchDemand::class,
            \App\Policies\BranchDemandPolicy::class
        );

        // Finance cluster — Consolidation / Intercompany (G-107 covers
        // 6 models — one gap row for the entire cluster).
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\ConsolidationRun::class,
            \App\Policies\ConsolidationRunPolicy::class
        );
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\EliminationRule::class,
            \App\Policies\EliminationRulePolicy::class
        );
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\EliminationEntry::class,
            \App\Policies\EliminationEntryPolicy::class
        );
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\MoneyTransfer::class,
            \App\Policies\MoneyTransferPolicy::class
        );
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\WarehouseTransfer::class,
            \App\Policies\WarehouseTransferPolicy::class
        );
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\Company::class,
            \App\Policies\CompanyPolicy::class
        );

        // Sales cluster (G-155, G-156, G-157, G-163).
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\SalesDraftCart::class,
            \App\Policies\SalesDraftCartPolicy::class
        );
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\SalesChallan::class,
            \App\Policies\SalesChallanPolicy::class
        );
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\SalesReturn::class,
            \App\Policies\SalesReturnPolicy::class
        );
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\CommissionEntry::class,
            \App\Policies\CommissionEntryPolicy::class
        );

        // ============================================================
        // Session 2 — Fiscal Year isolation (Q1 Gap 2).
        // Register FiscalYearPolicy. The policy's viewHistoricalData()
        // method hard-denies for everyone; combined with the
        // Gate::before() amendment above that excludes that ability
        // from the super-admin bypass, this is the application-layer
        // guarantee that no user — not even super admin — can view
        // closed/locked fiscal year data through the UI. The
        // BelongsToFiscalYear trait (applied to all operational models)
        // is the query-layer enforcement.
        // ============================================================
        \Illuminate\Support\Facades\Gate::policy(
            \App\Models\FiscalYear::class,
            \App\Policies\FiscalYearPolicy::class
        );
    }
}
