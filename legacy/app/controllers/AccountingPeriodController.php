<?php
// app/controllers/AccountingPeriodController.php — Phase 6B period close management

require_once '../core/BaseController.php';
require_once '../app/helpers/Helper.php';
require_once '../app/services/Accounting/AccountingPeriodService.php';
require_once '../app/services/Accounting/YearEndChecklistService.php';
require_once '../core/UserAudit.php';

class AccountingPeriodController extends BaseController
{
    private AccountingPeriodService $periodService;
    private UserAudit $userAudit;

    public function __construct()
    {
        $this->requireLogin();
        $this->periodService = new AccountingPeriodService();
        $this->userAudit = new UserAudit();
    }

    /**
     * URL: AccountingPeriod/index
     */
    public function index()
    {
        $this->requireAdmin();
        $this->requireRouteAccess();

        $helper = new Helper();

        $this->view('Accounting/period_close', [
            'title'            => 'Accounting Period Close',
            'periods'          => $this->periodService->listBranchPeriods(),
            'branches'         => $helper->Get_All_Active_Branches(),
            'can_reopen'       => $this->isSuperadmin(),
            'admin_override'   => AccountingPeriodService::canBypassPeriodLock() && !$this->isSuperadmin(),
            'override_enabled' => defined('PERIOD_CLOSE_ADMIN_OVERRIDE') && PERIOD_CLOSE_ADMIN_OVERRIDE,
        ]);
    }

    public function close()
    {
        $this->requireAdmin();
        $this->requireRouteAccess();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request'], 405);
            return;
        }

        try {
            $this->validateCSRF();
            $branchId = (int)($_POST['branch_id'] ?? 0);
            $closedThrough = trim((string)($_POST['closed_through_date'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));

            $result = $this->periodService->closePeriod($branchId, $closedThrough, $notes);

            if (($result['status'] ?? '') === 'success') {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'accounting_period_closed', $branchId, [
                    'closed_through_date' => $closedThrough,
                    'notes'               => $notes,
                ]);
            }

            $this->sendJson($result);
        } catch (Throwable $e) {
            error_log('AccountingPeriod close: ' . $e->getMessage());
            $this->sendJson(['status' => 'error', 'message' => 'Could not close period'], 500);
        }
    }

    public function reopen()
    {
        $this->requireSuperadmin();
        $this->requireRouteAccess();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request'], 405);
            return;
        }

        try {
            $this->validateCSRF();
            $branchId = (int)($_POST['branch_id'] ?? 0);
            $result = $this->periodService->reopenPeriod($branchId);

            if (($result['status'] ?? '') === 'success') {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'accounting_period_reopened', $branchId, []);
            }

            $this->sendJson($result);
        } catch (Throwable $e) {
            error_log('AccountingPeriod reopen: ' . $e->getMessage());
            $this->sendJson(['status' => 'error', 'message' => 'Could not reopen period'], 500);
        }
    }

    /**
     * Year-end pre-close checklist UI.
     * URL: AccountingPeriod/year_end
     */
    public function year_end()
    {
        $this->requireRouteAccess();
        $helper = new Helper();

        $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== ''
            ? (int)$_GET['branch_id']
            : Helper::sessionBranchId();

        if (!$helper->canOverrideBranch()) {
            $branchId = Helper::sessionBranchId();
        }

        $service = new YearEndChecklistService();
        $report = $service->runChecklist($branchId > 0 ? $branchId : null, $year);

        $this->view('Accounting/year_end_checklist', [
            'title'    => 'Year-End Pre-Close Checklist',
            'report'   => $report,
            'year'     => $year,
            'branch_id'=> $branchId,
            'branches' => $helper->Get_All_Active_Branches(),
            'can_pick_branch' => $helper->canOverrideBranch(),
        ]);
    }

    public function run_year_end_checks()
    {
        $this->requireRouteAccess();
        $helper = new Helper();

        $year = (int)($_GET['year'] ?? $_POST['year'] ?? date('Y'));
        $branchId = isset($_REQUEST['branch_id']) && $_REQUEST['branch_id'] !== ''
            ? (int)$_REQUEST['branch_id']
            : Helper::sessionBranchId();

        if (!$helper->canOverrideBranch()) {
            $branchId = Helper::sessionBranchId();
        }

        $report = (new YearEndChecklistService())->runChecklist($branchId > 0 ? $branchId : null, $year);
        $this->sendJson(['status' => 'success', 'report' => $report]);
    }

    public function export_year_tb()
    {
        $this->requireRouteAccess();
        $year = (int)($_GET['year'] ?? date('Y'));
        $from = $_GET['from_date'] ?? sprintf('%04d-01-01', $year);
        $to = $_GET['to_date'] ?? sprintf('%04d-12-31', $year);

        (new YearEndChecklistService())->exportTrialBalanceCsv($from, $to);
    }

    public function export_year_gl()
    {
        $this->requireRouteAccess();
        $helper = new Helper();
        $year = (int)($_GET['year'] ?? date('Y'));
        $from = $_GET['from_date'] ?? sprintf('%04d-01-01', $year);
        $to = $_GET['to_date'] ?? sprintf('%04d-12-31', $year);
        $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== ''
            ? (int)$_GET['branch_id']
            : null;

        if (!$helper->canOverrideBranch()) {
            $branchId = Helper::sessionBranchId() ?: null;
        }

        (new YearEndChecklistService())->exportGlArchiveCsv($from, $to, $branchId);
    }
}
