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
    }
}
