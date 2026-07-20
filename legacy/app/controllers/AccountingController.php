<?php
// app/controllers/AccountingController.php — Phase 8A/8B accounting hub

require_once '../core/BaseController.php';
require_once '../app/helpers/AccountingNavHelper.php';
require_once '../app/helpers/Helper.php';
require_once '../app/services/Accounting/AccountingDashboardService.php';

class AccountingController extends BaseController
{
    public function __construct()
    {
        $this->requireLogin();
    }

    /**
     * URL: Accounting/index
     */
    public function index()
    {
        $this->requireRouteAccess();

        $branchId = Helper::sessionBranchId();
        $dashboard = (new AccountingDashboardService())->build($branchId);

        $this->view('Accounting/index', [
            'title'         => 'Accounting',
            'dashboard'     => $dashboard,
            'hub_sections'  => AccountingNavHelper::hubSections(),
            'quick_nav'     => AccountingNavHelper::quickNavItems(),
        ]);
    }

    /**
     * URL: Accounting/guide — accountant user guide (Phase 8D)
     */
    public function guide()
    {
        $this->requireRouteAccess();

        $this->view('Accounting/guide', [
            'title' => 'Accounting user guide',
        ]);
    }
}
