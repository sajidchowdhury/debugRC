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
    }
}
