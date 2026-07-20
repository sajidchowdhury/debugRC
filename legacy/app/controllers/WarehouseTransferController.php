<?php
// controllers/WarehouseTransferController.php

require_once '../core/BaseController.php';
require_once '../app/models/WarehouseTransferModel.php';
require_once '../app/models/WarehouseTransferAuditModel.php';
require_once '../app/helpers/Helper.php';
require_once '../app/helpers/InterbranchGlAuditHelper.php';

class WarehouseTransferController extends BaseController {

    private WarehouseTransferModel $model;

    public function __construct() {
        $this->requireLogin();
        $this->model = new WarehouseTransferModel();
    }

    public function index() {
        $filters = [
            'date_from'         => $_GET['date_from'] ?? null,
            'date_to'           => $_GET['date_to'] ?? null,
            'from_warehouse_id' => $_GET['from_warehouse_id'] ?? null,
            'to_warehouse_id'   => $_GET['to_warehouse_id'] ?? null,
            'status'            => $_GET['status'] ?? 'all',
        ];

        $transfers = $this->model->getFilteredTransfers($filters);
        foreach ($transfers as &$row) {
            $row['can_reverse'] = empty($row['is_reversed'])
                && empty($row['branch_demand_id']);
        }
        unset($row);

        $this->view('WarehouseTransfer/index', [
            'title'       => 'Warehouse Transfers',
            'transfers'   => $transfers,
            'warehouses'  => $this->model->getWarehousesForUserFilter(),
            'filters'     => $filters,
            'branch_name' => $_SESSION['branch_name'] ?? 'Branch',
            'is_admin'    => ($_SESSION['role'] ?? '') === 'admin',
        ]);
    }

    public function create() {
        $this->view('WarehouseTransfer/create', [
            'title'       => 'New Warehouse Transfer',
            'warehouses'  => $this->model->getWarehousesForCreate(),
            'branch_name' => $_SESSION['branch_name'] ?? 'Branch',
        ]);
    }

    public function store() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            $items = json_decode($_POST['items'] ?? '[]', true);
            if (!is_array($items)) {
                $items = [];
            }
            $this->sendJson($this->model->createTransfer($_POST, $items));
        }
    }

    public function getWarehouses() {
        $this->sendJson(['status' => 'success', 'data' => $this->model->getWarehousesForCreate()]);
    }

    public function getWarehousesByBranch($branch_id = null) {
        $branch_id = (int)($branch_id ?? ($_GET['branch_id'] ?? 0));
        if ($branch_id > 0) {
            $this->sendJson(['status' => 'success', 'data' => $this->model->Get_Warehouse_By_Branch($branch_id)]);
        } else {
            $this->sendJson(['status' => 'success', 'data' => []]);
        }
    }

    public function getProducts() {
        $this->sendJson(['status' => 'success', 'data' => $this->model->getAllProducts()]);
    }

    public function getProductStockAndPrice() {
        $product_id = (int)($_GET['product_id'] ?? 0);
        $warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
        $this->sendJson(array_merge(
            ['status' => 'success'],
            $this->model->getProductStockAndRate($product_id, $warehouse_id)
        ));
    }

    public function details($id) {
        $transfer = $this->model->getTransferById((int)$id);
        if (!$transfer || !$this->model->userCanAccessTransfer($transfer)) {
            $this->redirect(BASE_URL . 'WarehouseTransfer');
            return;
        }

        $audit = (new WarehouseTransferAuditModel())->runTransferChecks((int)$id);

        $this->view('WarehouseTransfer/details', [
            'title'           => 'Transfer #' . ($transfer['transfer_code'] ?? ''),
            'transfer'        => $transfer,
            'items'           => $this->model->getTransferItems((int)$id),
            'movements'       => $this->model->getTransferMovements((int)$id),
            'journal_blocks'  => InterbranchGlAuditHelper::warehouseTransferJournalBlocks($transfer),
            'transfer_audit'  => $audit,
            'can_reverse'     => $this->model->canUserReverseTransfer($transfer),
        ]);
    }

    public function checklist() {
        $audit = new WarehouseTransferAuditModel();
        $branchName = $_SESSION['branch_name'] ?? 'Branch';
        if (Helper::sessionBranchId() === 0 && ($_SESSION['role'] ?? '') === 'admin') {
            $branchName = 'All branches';
        }

        $this->view('WarehouseTransfer/checklist', [
            'title'       => 'Warehouse Transfer Audit',
            'report'      => $audit->runHealthChecks(),
            'branch_name' => $branchName,
        ]);
    }

    public function run_checks() {
        $this->sendJson((new WarehouseTransferAuditModel())->runHealthChecks());
    }

    public function reverse() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request method'], 405);
            return;
        }

        $this->validateCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $this->sendJson(['status' => 'error', 'message' => 'Transfer id is required']);
            return;
        }

        $reason = trim((string)($_POST['reverse_reason'] ?? $_POST['reason'] ?? ''));
        $this->sendJson($this->model->reverseTransfer($id, $reason));
    }

    public function export() {
        $filters = [
            'date_from'         => $_GET['date_from'] ?? null,
            'date_to'           => $_GET['date_to'] ?? null,
            'from_warehouse_id' => $_GET['from_warehouse_id'] ?? null,
            'to_warehouse_id'   => $_GET['to_warehouse_id'] ?? null,
            'status'            => $_GET['status'] ?? 'all',
        ];

        $transfers = $this->model->getFilteredTransfers($filters);

        if (empty($transfers)) {
            $_SESSION['error'] = 'No records found!';
            $this->redirect('WarehouseTransfer');
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Warehouse_Transfers_' . date('Y-m-d_H-i') . '.csv"');
        header('Pragma: no-cache');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, ['Date', 'Code', 'From WH', 'To WH', 'From Branch', 'To Branch', 'Amount', 'Demand', 'GL', 'Status', 'Created By']);

        foreach ($transfers as $t) {
            fputcsv($output, [
                $t['transfer_date'],
                $t['transfer_code'],
                $t['from_warehouse'],
                $t['to_warehouse'],
                $t['from_branch'],
                $t['to_branch'],
                $t['total_amount'] ?? 0,
                !empty($t['branch_demand_id']) ? 'Yes' : 'No',
                (!empty($t['journal_entry_id']) && !empty($t['journal_entry_id_debtor'])) ? 'Yes' : 'No',
                !empty($t['is_reversed']) ? 'Reversed' : ($t['status'] ?? 'Active'),
                $t['created_by_name'] ?? 'System',
            ]);
        }

        fclose($output);
        exit;
    }
}