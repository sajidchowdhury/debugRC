<?php
// controllers/BranchDemandController.php

require_once '../core/BaseController.php';
require_once '../app/models/BranchDemandModel.php';
require_once '../app/models/BranchIntercompanyAuditModel.php';
require_once '../app/helpers/Helper.php';
require_once '../app/helpers/InterbranchGlAuditHelper.php';

class BranchDemandController extends BaseController {

    private $model;

    public function __construct() {
        $this->requireLogin();
        $this->model = new BranchDemandModel();
    }

    public function index() {
        $filters = [
            'date_from'    => $_GET['date_from'] ?? null,
            'date_to'      => $_GET['date_to'] ?? null,
            'demand_type'  => $_GET['demand_type'] ?? 'both',
            'status'       => $_GET['status'] ?? 'all'
        ];

        $demands = $this->model->getFilteredDemands($filters);

        $data = [
            'title'       => 'My Branch Demands',
            'demands'     => $demands,
            'filters'     => $filters,
            'branch_name' => $_SESSION['branch_name'] ?? 'Branch',
        ];

        $this->view('BranchDemand/index', $data);
    }

    public function pending() {
        $demands = $this->model->getPendingDemandsForMe();
        $data = [
            'title'       => 'Pending Demands for Me',
            'demands'     => $demands,
            'branch_name' => $_SESSION['branch_name'] ?? 'Branch',
        ];
        $this->view('BranchDemand/pending', $data);
    }

    public function create() {
        $data = [
            'title'       => 'Create New Branch Demand',
            'branch_name' => $_SESSION['branch_name'] ?? 'Branch',
        ];
        $this->view('BranchDemand/create', $data);
    }

    public function store() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            $items = json_decode($_POST['items'] ?? '[]', true);
            $result = $this->model->createDemand($_POST, $items);
            $this->sendJson($result);
        }
    }

    public function getBranches() {
        $this->model->getOtherBranches();
    }

    public function getProducts() {
        $this->model->getAllProducts();
    }

    public function getWarehousesByBranch() {
        $branch_id = (int)($_GET['branch_id'] ?? 0);
        $this->model->getDefaultWarehouse($branch_id);
    }

    public function details($id) {
        $demand = $this->model->getDemandById($id);
        if (!$demand) {
            $this->redirect(BASE_URL . 'BranchDemand');
            return;
        }

        $items = $this->model->getDemandItems($id);

        $myBranchId = $_SESSION['branch_id'] ?? 0;

        // Enrich items with warehouse stock for the "To Branch" user
        foreach ($items as &$item) {
            if ($demand['to_branch_id'] == $myBranchId) {
                $stockData = $this->model->WarehouseWiseProductStock($item['product_id'], $myBranchId);
                $item['from_warehouses'] = $stockData;
            }
        }

        $toWarehouses = $this->model->getDefaultWarehouse($demand['from_branch_id']);

        $stockTrace = ($demand['status'] ?? '') === 'received'
            ? $this->model->getStockTraceForDemand((int)$id)
            : [];

        $settlements = ($demand['status'] ?? '') === 'received'
            ? $this->model->getSettlementsForDemand((int)$id)
            : [];

        $riskFlags = ['flags' => [], 'has_alerts' => false];
        if (($demand['status'] ?? '') === 'received') {
            require_once '../app/models/Reports/BranchIntercompanyWeeklyReport.php';
            $riskFlags = (new BranchIntercompanyWeeklyReport())->getDemandAntiGamingFlags((int)$id);
        }

        $data = [
            'title'          => 'Demand Details #' . ($demand['demand_code'] ?? ''),
            'demand'         => $demand,
            'items'          => $items,
            'toWarehouses'   => $toWarehouses,
            'myBranchId'     => $myBranchId,
            'stock_trace'    => $stockTrace,
            'settlements'    => $settlements,
            'risk_flags'     => $riskFlags,
            'journal_blocks' => InterbranchGlAuditHelper::demandJournalBlocks($demand),
        ];

        $this->view('BranchDemand/details', $data);
    }

    public function checklist() {
        $audit = new BranchIntercompanyAuditModel();
        $branchName = $_SESSION['branch_name'] ?? 'Branch';
        if (Helper::sessionBranchId() === 0 && ($_SESSION['role'] ?? '') === 'admin') {
            $branchName = 'All branches';
        }

        $this->view('BranchDemand/checklist', [
            'title'       => 'Inter-branch GL Audit',
            'report'      => $audit->runHealthChecks(),
            'branch_name' => $branchName,
        ]);
    }

    public function run_checks() {
        $this->sendJson((new BranchIntercompanyAuditModel())->runHealthChecks());
    }

    public function weekly() {
        $helper = new Helper();
        $branches = $helper->Get_All_Active_Branches();
        $sessionBranch = Helper::sessionBranchId();

        $filters = [
            'from_date'              => $_GET['from_date'] ?? date('Y-m-d', strtotime('-6 days')),
            'to_date'                => $_GET['to_date'] ?? date('Y-m-d'),
            'branch_id'              => (int)($_GET['branch_id'] ?? $sessionBranch),
            'counterparty_branch_id' => (int)($_GET['counterparty_branch_id'] ?? 0),
        ];

        $report = null;
        if (isset($_GET['search']) || isset($_GET['export'])) {
            require_once '../app/models/Reports/BranchIntercompanyWeeklyReport.php';
            $reportModel = new BranchIntercompanyWeeklyReport();
            $report = $reportModel->buildWeeklyReport($filters);

            if (isset($_GET['export'])) {
                $reportModel->exportCsv($report);
            }
        }

        $this->view('BranchDemand/weekly', [
            'title'    => 'Inter-branch Weekly Control',
            'branches' => $branches,
            'filters'  => $filters,
            'report'   => $report,
            'can_pick_branch' => ($_SESSION['role'] ?? '') === 'admin',
        ]);
    }


    // ===================== SEND GOODS =====================
    public function send() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            $demand_id = (int)($_POST['demand_id'] ?? 0);
            $items = json_decode($_POST['items'] ?? '[]', true);

            $result = $this->model->sendGoodsWithWarehouses($demand_id, $items);
            $this->sendJson($result);
        }
    }

    // ===================== DELETE DRAFT DEMAND =====================
    public function delete() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            $id = (int)($_POST['id'] ?? 0);
            $result = $this->model->deleteDraftDemand($id);
            $this->sendJson($result);
        }
    }

    // ===================== REVERSE DEMAND =====================
    public function reverse() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            $id = (int)($_POST['id'] ?? 0);
            $reason = $_POST['reverse_reason'] ?? 'Reversed by user';

            $result = $this->model->reverseDemand($id, $reason);
            $this->sendJson($result);
        }
    }

    public function export() {
        $filters = [
            'date_from'    => $_GET['date_from'] ?? null,
            'date_to'      => $_GET['date_to'] ?? null,
            'demand_type'  => $_GET['demand_type'] ?? 'both',
            'status'       => $_GET['status'] ?? 'all'
        ];

        $demands = $this->model->getFilteredDemands($filters);

        if (empty($demands)) {
            $_SESSION['error'] = "No records found!";
            $this->redirect('BranchDemand');
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Branch_Demands_' . date('Y-m-d_H-i') . '.csv"');
        header('Pragma: no-cache');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($output, ['Date', 'Demand Code', 'From Branch', 'To Branch', 'Status', 'Total Value']);

        foreach ($demands as $d) {
            fputcsv($output, [
                $d['demand_date'],
                $d['demand_code'],
                $d['from_branch'],
                $d['to_branch'],
                $d['status'],
                $d['total_value'] ?? 0
            ]);
        }

        fclose($output);
        exit;
    }
}