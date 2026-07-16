<?php
// app/controllers/SalesAuditController.php

require_once '../core/BaseController.php';
require_once '../app/models/SalesAuditModel.php';
require_once '../app/models/SalesModel.php';
require_once '../app/models/BranchModel.php';
require_once '../app/helpers/Helper.php';

class SalesAuditController extends BaseController
{
    public function __construct()
    {
        $this->requireLogin();
    }

    /**
     * Sales module audit checklist (reference rules + live DB checks).
     * URL: SalesAudit/checklist
     */
    public function checklist()
    {
        $this->requireRouteAccess();
        $model = new SalesAuditModel();
        $report = $model->runHealthChecks();

        $branchId = Helper::sessionBranchId();
        $branchName = 'All branches';
        if ($branchId) {
            $branch = (new BranchModel())->getBranchById($branchId);
            $branchName = $branch['branch_name'] ?? ('Branch #' . $branchId);
        }

        $data = [
            'title'       => 'Sales Module Audit Checklist',
            'report'      => $report,
            'branch_name' => $branchName,
        ];

        $this->view('SalesAudit/checklist', $data);
    }

    /**
     * JSON re-run for refresh button.
     * URL: SalesAudit/run_checks
     */
    public function run_checks()
    {
        $this->requireRouteAccess();
        $model = new SalesAuditModel();
        $this->sendJson($model->runHealthChecks());
    }

    /**
     * Cancel stale draft invoices (releases pipeline reservation). Phase 4.
     * URL: SalesAudit/cancel_stale_drafts
     */
    public function cancel_stale_drafts()
    {
        $this->requireRouteAccess();
        $days = isset($_GET['days']) ? max(1, (int)$_GET['days']) : null;
        $branchId = Helper::sessionBranchId() ?: null;
        $model = new SalesModel();
        $this->sendJson($model->cancelStaleDraftInvoices($days, $branchId));
    }

}