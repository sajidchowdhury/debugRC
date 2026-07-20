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
    }
}
