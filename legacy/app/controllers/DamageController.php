<?php
// controllers/DamageController.php

require_once '../core/BaseController.php';
require_once '../app/models/DamageModel.php';
require_once '../app/models/DamageAuditModel.php';
require_once '../app/helpers/Helper.php';
require_once '../app/helpers/StockGlAuditHelper.php';

class DamageController extends BaseController {

    private DamageModel $model;

    public function __construct() {
        $this->requireLogin();
        $this->model = new DamageModel();
    }

    public function index() {
        $filters = [
            'date_from'    => $_GET['date_from'] ?? null,
            'date_to'      => $_GET['date_to'] ?? null,
            'warehouse_id' => $_GET['warehouse_id'] ?? null,
            'status'       => $_GET['status'] ?? 'all',
            'source'       => $_GET['source'] ?? 'all',
            'sales_return_id' => $_GET['sales_return_id'] ?? null,
        ];

        $damages = $this->model->getFilteredDamages($filters);
        foreach ($damages as &$row) {
            $row['can_reverse'] = empty($row['is_reversed']);
        }
        unset($row);

        $this->view('Damage/index', [
            'title'       => 'Damage / Write-offs',
            'damages'     => $damages,
            'warehouses'  => $this->model->getWarehousesForUser(),
            'filters'     => $filters,
            'branch_name' => $_SESSION['branch_name'] ?? 'Branch',
        ]);
    }

    public function create() {
        $this->view('Damage/create', [
            'title'       => 'Record Damage',
            'warehouses'  => $this->model->getWarehousesForUser(),
            'branch_name' => $_SESSION['branch_name'] ?? 'Branch',
        ]);
    }

    public function store() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request'], 405);
            return;
        }

        $this->validateCSRF();

        $items = json_decode($_POST['items'] ?? '[]', true);
        if (!is_array($items)) {
            $items = [];
        }

        $this->sendJson($this->model->createDamage($_POST, $items));
    }

    public function getBranchWarehouses() {
        $this->sendJson(['status' => 'success', 'data' => $this->model->getWarehousesForUser()]);
    }

    public function getProducts() {
        $this->sendJson(['status' => 'success', 'data' => $this->model->getAllProducts()]);
    }

    public function getProductStockAndPrice() {
        $productId   = (int)($_GET['product_id'] ?? $_GET['id'] ?? 0);
        $warehouseId = (int)($_GET['warehouse_id'] ?? 0);
        $this->sendJson(array_merge(
            ['status' => 'success'],
            $this->model->getProductStockAndRate($productId, $warehouseId)
        ));
    }

    public function details($id) {
        $damage = $this->model->getDamageById((int)$id);
        if (!$damage || !$this->model->userCanAccessDamage($damage)) {
            $this->redirect(BASE_URL . 'Damage');
            return;
        }

        $audit = (new DamageAuditModel())->runDamageChecks((int)$id);

        $this->view('Damage/details', [
            'title'          => 'Damage #' . ($damage['damage_code'] ?? ''),
            'damage'         => $damage,
            'items'          => $this->model->getDamageItems((int)$id),
            'movements'      => $this->model->getDamageMovements((int)$id),
            'journal_blocks' => StockGlAuditHelper::damageJournalBlocks($damage),
            'damage_audit'   => $audit,
            'can_reverse'    => $this->model->canUserReverseDamage($damage),
        ]);
    }

    public function reverse() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request'], 405);
            return;
        }

        $this->validateCSRF();

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $this->sendJson(['status' => 'error', 'message' => 'Damage id is required']);
            return;
        }

        $reason = trim((string)($_POST['reverse_reason'] ?? $_POST['reason'] ?? ''));
        $this->sendJson($this->model->reverseDamage($id, $reason));
    }

    public function export() {
        $filters = [
            'date_from'    => $_GET['date_from'] ?? null,
            'date_to'      => $_GET['date_to'] ?? null,
            'warehouse_id' => $_GET['warehouse_id'] ?? null,
            'status'       => $_GET['status'] ?? 'all',
        ];

        $damages = $this->model->getFilteredDamages($filters);

        if (empty($damages)) {
            $_SESSION['error'] = 'No records found!';
            $this->redirect('Damage');
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Damage_' . date('Y-m-d_H-i') . '.csv"');
        header('Pragma: no-cache');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, ['Date', 'Code', 'Warehouse', 'Branch', 'Damage Amount', 'GL', 'Status', 'Created By']);

        foreach ($damages as $d) {
            fputcsv($output, [
                $d['damage_date'],
                $d['damage_code'],
                $d['warehouse_name'],
                $d['branch_name'],
                $d['total_value'] ?? 0,
                !empty($d['journal_entry_id']) ? 'Yes' : 'No',
                !empty($d['is_reversed']) ? 'Reversed' : 'Active',
                $d['created_by_name'] ?? 'System',
            ]);
        }

        fclose($output);
        exit;
    }
}