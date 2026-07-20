<?php
// controllers/StockAdjustmentController.php

require_once '../core/BaseController.php';
require_once '../app/models/StockAdjustmentModel.php';
require_once '../app/models/StockAdjustmentAuditModel.php';
require_once '../app/helpers/Helper.php';
require_once '../app/helpers/StockGlAuditHelper.php';

class StockAdjustmentController extends BaseController {

    private StockAdjustmentModel $model;

    public function __construct() {
        $this->requireLogin();
        $this->model = new StockAdjustmentModel();
    }

    public function index() {
        $filters = [
            'date_from'        => $_GET['date_from'] ?? null,
            'date_to'          => $_GET['date_to'] ?? null,
            'warehouse_id'     => $_GET['warehouse_id'] ?? null,
            'adjustment_type'  => $_GET['adjustment_type'] ?? 'all',
            'status'           => $_GET['status'] ?? 'all',
        ];

        $this->view('StockAdjustment/index', [
            'title'        => 'Stock Adjustments',
            'adjustments'  => $this->model->getFilteredAdjustments($filters),
            'warehouses'   => $this->model->getWarehousesForUser(),
            'filters'      => $filters,
            'branch_name'  => $_SESSION['branch_name'] ?? 'Branch',
            'is_admin'     => ($_SESSION['role'] ?? '') === 'admin',
        ]);
    }

    public function create() {
        $this->view('StockAdjustment/create', [
            'title'       => 'New Stock Adjustment',
            'warehouses'  => $this->model->getWarehousesForUser(),
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
            $this->sendJson($this->model->createAdjustment($_POST, $items));
        }
    }

    public function getProductPrice() {
        $productId = (int)($_GET['id'] ?? 0);
        $warehouseId = (int)($_GET['warehouse_id'] ?? 0);
        $price = $this->model->getProductRateForWarehouse($warehouseId, $productId);
        $this->sendJson(['status' => 'success', 'price' => $price]);
    }

    public function details($id) {
        $adjustment = $this->model->getAdjustmentById((int)$id);
        if (!$adjustment || !$this->model->userCanAccessAdjustment($adjustment)) {
            $this->redirect(BASE_URL . 'StockAdjustment');
            return;
        }

        $audit = (new StockAdjustmentAuditModel())->runAdjustmentChecks((int)$id);

        $this->view('StockAdjustment/details', [
            'title'            => 'Adjustment #' . ($adjustment['adjustment_code'] ?? ''),
            'adjustment'       => $adjustment,
            'items'            => $this->model->getAdjustmentItems((int)$id),
            'movements'        => $this->model->getAdjustmentMovements((int)$id),
            'journal_blocks'   => StockGlAuditHelper::adjustmentJournalBlocks($adjustment),
            'adjustment_audit' => $audit,
        ]);
    }

    public function checklist() {
        $audit = new StockAdjustmentAuditModel();
        $branchName = $_SESSION['branch_name'] ?? 'Branch';
        if (Helper::sessionBranchId() === 0 && ($_SESSION['role'] ?? '') === 'admin') {
            $branchName = 'All branches';
        }

        $this->view('StockAdjustment/checklist', [
            'title'       => 'Stock Adjustment Audit',
            'report'      => $audit->runHealthChecks(),
            'branch_name' => $branchName,
        ]);
    }

    public function run_checks() {
        $this->sendJson((new StockAdjustmentAuditModel())->runHealthChecks());
    }

    public function reverse() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            $id = (int)($_POST['id'] ?? 0);
            $reason = $_POST['reverse_reason'] ?? $_POST['reason'] ?? '';
            $this->sendJson($this->model->reverseAdjustment($id, $reason));
        }
    }

    public function export() {
        $filters = [
            'date_from'        => $_GET['date_from'] ?? null,
            'date_to'          => $_GET['date_to'] ?? null,
            'warehouse_id'     => $_GET['warehouse_id'] ?? null,
            'adjustment_type'  => $_GET['adjustment_type'] ?? 'all',
            'status'           => $_GET['status'] ?? 'all',
        ];

        $adjustments = $this->model->getFilteredAdjustments($filters);

        if (empty($adjustments)) {
            $_SESSION['error'] = 'No records found!';
            $this->redirect('StockAdjustment');
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Stock_Adjustments_' . date('Y-m-d_H-i') . '.csv"');
        header('Pragma: no-cache');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, ['Date', 'Code', 'Warehouse', 'Branch', 'Type', 'Amount', 'GL', 'Status', 'Created By']);

        foreach ($adjustments as $a) {
            fputcsv($output, [
                $a['adjustment_date'],
                $a['adjustment_code'],
                $a['warehouse_name'],
                $a['branch_name'] ?? '',
                ucfirst($a['adjustment_type'] ?? ''),
                $a['total_amount'],
                !empty($a['journal_entry_id']) ? 'Yes' : 'No',
                !empty($a['is_reversed']) ? 'Reversed' : 'Active',
                $a['created_by_name'] ?? 'System',
            ]);
        }

        fclose($output);
        exit;
    }
}