<?php
// app/helpers/AccountingModuleHelper.php — shared accounting UI context (Phase 6B)

require_once __DIR__ . '/Helper.php';
require_once __DIR__ . '/../services/Accounting/AccountingPeriodService.php';
require_once __DIR__ . '/../../core/Auth.php';

class AccountingModuleHelper
{
    /** Controllers that show the period-close banner. */
    public const PERIOD_BANNER_CONTROLLERS = [
        'LedgerController',
        'ManualJournalController',
        'OtherExpenseController',
        'OtherIncomeController',
        'MoneyTransferController',
        'CustomerTransactionController',
        'SupplierTransactionController',
        'EmployeeTransactionController',
        'PaymentController',
        'ReconciliationController',
        'AccountingPeriodController',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function periodBannerForSession(): array
    {
        $branchId = Helper::sessionBranchId() ?: (int)($_SESSION['branch_id'] ?? 0);
        $banner = (new AccountingPeriodService())->bannerForBranch($branchId);
        $banner['can_manage'] = Auth::isAdmin();
        return $banner;
    }

    public static function shouldShowPeriodBanner(?string $controllerName): bool
    {
        return in_array($controllerName ?? '', self::PERIOD_BANNER_CONTROLLERS, true);
    }

    public static function minPostingDateForSession(): ?string
    {
        $branchId = Helper::sessionBranchId() ?: (int)($_SESSION['branch_id'] ?? 0);
        if (AccountingPeriodService::canBypassPeriodLock()) {
            return null;
        }
        return (new AccountingPeriodService())->earliestOpenDate($branchId);
    }
}
