<?php
// controllers/StockTakeController.php

require_once '../core/BaseController.php';
require_once '../app/models/StockTakeModel.php';
require_once '../app/models/StockTakeAuditModel.php';
require_once '../app/models/Reports/StockTakeVarianceReport.php';
require_once '../app/helpers/Helper.php';
require_once '../app/helpers/StockGlAuditHelper.php';

class StockTakeController extends BaseController {

    private StockTakeModel $model;

    public function __construct() {
        $this->requireLogin();
        $this->model = new StockTakeModel();
    }

    public function index() {
        $filters = [
            'date_from'  => $_GET['date_from'] ?? null,
            'date_to'    => $_GET['date_to'] ?? null,
            'search'     => trim($_GET['search'] ?? ''),
            'status'     => $_GET['status'] ?? 'all',
            'reversed'   => $_GET['reversed'] ?? 'all',
            'branch_id'  => (int)($_GET['branch_id'] ?? 0),
        ];

        $helper = new Helper();
        $branches = (($_SESSION['role'] ?? '') === 'admin')
            ? $helper->Get_All_Active_Branches()
            : [];

        $data = [
            'title'       => 'Stock Take Sessions',
            'sessions'    => $this->model->getFilteredSessions($filters),
            'filters'     => $filters,
            'branch_name' => $_SESSION['branch_name'] ?? 'Branch',
            'branches'    => $branches,
            'is_admin'    => ($_SESSION['role'] ?? '') === 'admin',
        ];

        $this->view('StockTake/index', $data);
    }

    public function create() {
        $helper = new Helper();
        $data = [
            'title'       => 'New Stock Take Session',
            'branches'    => $helper->Get_All_Active_Branches(),
            'branch_name' => $_SESSION['branch_name'] ?? 'Branch',
        ];
        $this->view('StockTake/create', $data);
    }

    public function details($id) {
        $session = $this->model->getSessionById((int)$id);
        if (!$session) {
            $this->redirect(BASE_URL . 'StockTake');
            return;
        }

        $warehouses = $this->model->getSessionWarehouses((int)$id);
        $progress   = $this->model->getSessionProgress((int)$id);
        $variances  = $this->model->getVarianceLines((int)$id);
        $movements  = ($session['status'] ?? '') === 'adjusted' || !empty($session['is_reversed'])
            ? $this->model->getStockMovements((int)$id)
            : [];

        $pendingWh = 0;
        $countedWh = 0;
        foreach ($warehouses as $w) {
            if (($w['status'] ?? '') === 'pending') {
                $pendingWh++;
            }
            if (in_array($w['status'] ?? '', ['counted', 'posted'], true)) {
                $countedWh++;
            }
        }

        $canPost = empty($session['is_reversed'])
            && ($session['status'] ?? '') === 'counting'
            && $pendingWh === 0
            && $countedWh > 0;

        $audit = (new StockTakeAuditModel())->runSessionChecks((int)$id);

        $this->view('StockTake/details', [
            'title'          => 'Stock Take #' . ($session['session_code'] ?? ''),
            'session'        => $session,
            'warehouses'     => $warehouses,
            'progress'       => $progress,
            'variances'      => $variances,
            'movements'      => $movements,
            'can_post'       => $canPost,
            'journal_blocks' => StockGlAuditHelper::stockTakeJournalBlocks(
                $session,
                (float)($progress['loss_value'] ?? 0),
                (float)($progress['gain_value'] ?? 0)
            ),
            'session_audit'  => $audit,
        ]);
    }

    public function checklist() {
        $audit = new StockTakeAuditModel();
        $helper = new Helper();
        $branchId = Helper::sessionBranchId();
        $branchName = $_SESSION['branch_name'] ?? 'Branch';
        if (!$branchId && ($_SESSION['role'] ?? '') === 'admin') {
            $branchName = 'All branches';
        } elseif ($branchId) {
            $b = $helper->Get_Branch_By_Id($branchId);
            $branchName = $b['branch_name'] ?? $branchName;
        }

        $this->view('StockTake/checklist', [
            'title'       => 'Stock Take Audit Checklist',
            'report'      => $audit->runHealthChecks(),
            'branch_name' => $branchName,
        ]);
    }

    public function run_checks() {
        $audit = new StockTakeAuditModel();
        $this->sendJson($audit->runHealthChecks());
    }

    public function weekly() {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
        $dateTo   = $_GET['date_to'] ?? date('Y-m-d');
        $branchId = (int)($_GET['branch_id'] ?? 0);
        if (Helper::sessionBranchId()) {
            $branchId = Helper::sessionBranchId();
        }

        $report = new StockTakeVarianceReport();
        $data = $report->getWeeklyReport($dateFrom, $dateTo, $branchId ?: null);

        $helper = new Helper();
        $this->view('StockTake/weekly', [
            'title'       => 'Stock Take — Weekly control',
            'report'      => $data,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
            'branch_id'   => $branchId,
            'branches'    => (($_SESSION['role'] ?? '') === 'admin') ? $helper->Get_All_Active_Branches() : [],
            'is_admin'    => ($_SESSION['role'] ?? '') === 'admin',
            'branch_name' => $_SESSION['branch_name'] ?? 'Branch',
        ]);
    }

    public function exportWeekly() {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
        $dateTo   = $_GET['date_to'] ?? date('Y-m-d');
        $branchId = (int)($_GET['branch_id'] ?? 0);
        if (Helper::sessionBranchId()) {
            $branchId = Helper::sessionBranchId();
        }
        $report = new StockTakeVarianceReport();
        $report->exportWeeklyCsv($report->getWeeklyReport($dateFrom, $dateTo, $branchId ?: null));
    }

    public function variance() {
        $helper = new Helper();
        $this->view('StockTake/variance', [
            'title'       => 'Stock Take Variance Report',
            'branch_name' => $_SESSION['branch_name'] ?? 'Branch',
            'branches'    => (($_SESSION['role'] ?? '') === 'admin') ? $helper->Get_All_Active_Branches() : [],
            'is_admin'    => ($_SESSION['role'] ?? '') === 'admin',
        ]);
    }

    public function getSessionsList() {
        $report = new StockTakeVarianceReport();
        $this->sendJson(['status' => 'success', 'data' => $report->getSessionsList()]);
    }

    public function getVarianceReport() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        $this->validateCSRF();
        $filters = [
            'session_id'   => (int)($_POST['session_id'] ?? 0),
            'branch_id'    => (int)($_POST['branch_id'] ?? 0),
            'warehouse_id' => (int)($_POST['warehouse_id'] ?? 0),
            'product_id'   => (int)($_POST['product_id'] ?? 0),
        ];
        $report = new StockTakeVarianceReport();
        $rows = $report->getVarianceLines($filters);
        $summary = $report->summarizeVarianceLines($rows);
        $this->sendJson(array_merge(['status' => 'success'], $summary, ['data' => $rows]));
    }

    public function exportVarianceReport() {
        $filters = [
            'session_id'   => (int)($_GET['session_id'] ?? 0),
            'branch_id'    => (int)($_GET['branch_id'] ?? 0),
            'warehouse_id' => (int)($_GET['warehouse_id'] ?? 0),
            'product_id'   => (int)($_GET['product_id'] ?? 0),
        ];
        $report = new StockTakeVarianceReport();
        $report->exportVarianceCsv($report->getVarianceLines($filters));
    }

    public function WarehousesByBranch() {
        $branch_id = (int)($_GET['branch_id'] ?? 0);
        $this->model->getWarehousesByBranch($branch_id);
    }

    public function store() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            $warehouse_ids = $_POST['warehouse_ids'] ?? [];
            if (!is_array($warehouse_ids)) {
                $warehouse_ids = [$warehouse_ids];
            }
            $result = $this->model->createSession($_POST, $warehouse_ids);
            $this->sendJson($result);
        }
    }

    public function saveCount() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            $result = $this->model->saveCount($_POST);
            $this->sendJson($result);
        }
    }

    public function post() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            $id = (int)($_POST['id'] ?? 0);
            $result = $this->model->postSession($id);
            $this->sendJson($result);
        }
    }

    public function count($session_id, $warehouse_id) {
        $session = $this->model->getSessionById((int)$session_id);
        if (!$session) {
            $this->redirect(BASE_URL . 'StockTake');
            return;
        }

        if (!empty($session['is_reversed']) || ($session['status'] ?? '') === 'adjusted') {
            $_SESSION['error'] = 'This session can no longer be counted.';
            $this->redirect(BASE_URL . 'StockTake/details/' . (int)$session_id);
            return;
        }

        $warehouse = $this->model->Get_Warehouse_By_Id((int)$warehouse_id);
        if (!$warehouse) {
            $this->redirect(BASE_URL . 'StockTake/details/' . (int)$session_id);
            return;
        }

        $whRows = $this->model->getSessionWarehouses((int)$session_id);
        $whStatus = 'pending';
        foreach ($whRows as $w) {
            if ((int)$w['warehouse_id'] === (int)$warehouse_id) {
                $whStatus = $w['status'] ?? 'pending';
                break;
            }
        }
        if ($whStatus === 'posted') {
            $_SESSION['error'] = 'This warehouse is already posted.';
            $this->redirect(BASE_URL . 'StockTake/details/' . (int)$session_id);
            return;
        }

        $helper = new Helper();
        $products = $this->model->getProductsForCounting((int)$warehouse_id);

        $savedCounts = $this->model->getSavedCounts((int)$session_id, (int)$warehouse_id);

        $this->view('StockTake/count', [
            'title'         => 'Count — ' . ($warehouse['warehouse_name'] ?? ''),
            'session'       => $session,
            'warehouse'     => $warehouse,
            'products'      => $products,
            'savedCounts'   => $savedCounts,
            'wh_status'     => $whStatus,
            'wh_saved_lines'=> count($savedCounts),
            'categories'    => $helper->Get_All_Categories(),
            'product_total' => count($products),
        ]);
    }

    public function delete() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            $id = (int)($_POST['id'] ?? 0);
            $this->sendJson($this->model->deleteDraftSession($id));
        }
    }

    public function reverse() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            $id = (int)($_POST['id'] ?? 0);
            $reason = $_POST['reason'] ?? $_POST['reverse_reason'] ?? '';
            $this->sendJson($this->model->reverseSession($id, $reason));
        }
    }

    public function export() {
        $filters = [
            'date_from'  => $_GET['date_from'] ?? null,
            'date_to'    => $_GET['date_to'] ?? null,
            'search'     => trim($_GET['search'] ?? ''),
            'status'     => $_GET['status'] ?? 'all',
            'reversed'   => $_GET['reversed'] ?? 'all',
            'branch_id'  => (int)($_GET['branch_id'] ?? 0),
        ];

        $sessions = $this->model->getFilteredSessions($filters);

        if (empty($sessions)) {
            $_SESSION['error'] = 'No records found!';
            $this->redirect('StockTake');
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Stock_Take_' . date('Y-m-d_H-i') . '.csv"');
        header('Pragma: no-cache');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, ['Session Code', 'Date', 'Branch', 'Warehouses', 'Counted', 'Status', 'Variance lines', 'Variance value', 'Reversed', 'Created By']);

        foreach ($sessions as $s) {
            fputcsv($output, [
                $s['session_code'],
                $s['take_date'],
                $s['branch_name'],
                $s['warehouse_count'],
                ($s['warehouses_counted'] ?? 0) . '/' . ($s['warehouse_count'] ?? 0),
                $s['is_reversed'] ? 'reversed' : ($s['status'] ?? ''),
                $s['variance_lines'] ?? 0,
                $s['variance_value'] ?? 0,
                $s['is_reversed'] ? 'Yes' : 'No',
                $s['created_by_name'] ?? '',
            ]);
        }

        fclose($output);
        exit;
    }
}