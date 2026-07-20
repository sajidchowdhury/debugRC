<?php
// app/controllers/ReconciliationController.php — Phase 4B GL reconciliation hub

require_once '../core/BaseController.php';
require_once '../app/helpers/Helper.php';
require_once '../app/services/Accounting/ReconciliationService.php';

class ReconciliationController extends BaseController
{
    /**
     * Accounting reconciliation hub.
     * URL: Reconciliation/index · alias Accounting/Reconciliation
     */
    public function index()
    {
        $this->requireRouteAccess();

        $helper = new Helper();
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? ''))
            ? $_GET['from']
            : date('Y-m-01');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? ''))
            ? $_GET['to']
            : date('Y-m-d');

        $canOverrideBranch = $helper->canOverrideBranch();
        if ($canOverrideBranch && array_key_exists('branch_id', $_GET)) {
            $branchId = ($_GET['branch_id'] === '' || $_GET['branch_id'] === 'all')
                ? 0
                : (int)$_GET['branch_id'];
        } else {
            $branchId = Helper::sessionBranchId();
        }

        if ($branchId < 0) {
            $branchId = 0;
        }

        $passBranch = $branchId === 0 ? 0 : ($branchId > 0 ? $branchId : null);
        $service = new ReconciliationService();
        $report = $service->runFullReport($passBranch, $from, $to);

        $this->view('Accounting/reconciliation', [
            'title'               => 'GL Reconciliation',
            'report'              => $report,
            'from'                => $from,
            'to'                  => $to,
            'branch_id'           => $branchId,
            'branches'            => $helper->Get_All_Active_Branches(),
            'can_override_branch' => $canOverrideBranch,
            'recent_alerts'       => ReconciliationService::readRecentAlerts(25),
            'alert_log_path'      => ReconciliationService::alertLogPath(),
            'tolerance_defined'   => defined('GL_RECONCILIATION_TOLERANCE'),
            'tolerance_value'     => defined('GL_RECONCILIATION_TOLERANCE')
                ? (float)GL_RECONCILIATION_TOLERANCE
                : 0.02,
        ]);
    }
}
