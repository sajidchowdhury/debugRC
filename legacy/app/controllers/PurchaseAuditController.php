<?php
// app/controllers/PurchaseAuditController.php

require_once '../core/BaseController.php';
require_once '../app/models/PurchaseAuditModel.php';
require_once '../app/models/BranchModel.php';
require_once '../app/helpers/Helper.php';

class PurchaseAuditController extends BaseController
{
    public function __construct()
    {
        $this->requireLogin();
    }

    /**
     * Default entry — same as checklist (URL: PurchaseAudit or PurchaseAudit/index).
     */
    public function index()
    {
        $this->checklist();
    }

    /**
     * Purchase module audit checklist (reference rules + live DB checks).
     * URL: PurchaseAudit/checklist
     */
    public function checklist()
    {
        $model = new PurchaseAuditModel();
        $report = $model->runHealthChecks();

        $branchId = Helper::sessionBranchId();
        $branchName = 'All branches';
        if ($branchId) {
            $branch = (new BranchModel())->getBranchById($branchId);
            $branchName = $branch['branch_name'] ?? ('Branch #' . $branchId);
        }

        $data = [
            'title'       => 'Purchase Module Audit Checklist',
            'report'      => $report,
            'branch_name' => $branchName,
        ];

        $this->view('PurchaseAudit/checklist', $data);
    }

    /**
     * JSON re-run for refresh button.
     * URL: PurchaseAudit/run_checks
     */
    public function run_checks()
    {
        $model = new PurchaseAuditModel();
        $this->sendJson($model->runHealthChecks());
    }
}