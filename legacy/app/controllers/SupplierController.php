<?php
// app/controllers/SupplierController.php

require_once '../core/BaseController.php';
require_once '../app/models/SupplierModel.php';
require_once '../core/UserAudit.php';
require_once __DIR__ . '/../helpers/MasterDataAuditHelper.php';

class SupplierController extends BaseController {

    private $supplierModel;
    private $userAudit;

    public function __construct() {
        $this->requireLogin();
        $this->supplierModel = new SupplierModel();
        $this->userAudit = new UserAudit();
    }

    public function index() {
        if (isset($_GET['draw'])) {
            $params = $_GET;
            $params['includeDeleted'] = isset($_GET['deleted']) && $_GET['deleted'] == '1';

            $response = $this->supplierModel->getSuppliersForDataTable($params);
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }

        $showDeleted = isset($_GET['deleted']) && $_GET['deleted'] == '1';

        $data = [
            'title'       => $showDeleted ? 'Inactive suppliers' : 'Supplier directory',
            'showDeleted' => $showDeleted,
            'stats'       => $this->supplierModel->getSupplierIndexStats(),
        ];
        $this->view('supplier/index', $data);
    }

    public function show($id = null) {
        if (!$id) {
            $this->redirect('supplier/index');
        }

        $supplierId = (int)$id;
        $supplier = $this->supplierModel->getSupplierById($supplierId);
        if (!$supplier) {
            $_SESSION['error'] = 'Supplier not found!';
            $this->redirect('supplier/index');
        }

        $this->view('supplier/show', [
            'title'    => ($supplier['supplier_name'] ?? 'Supplier') . ' — Hub',
            'supplier' => $supplier,
            'summary'  => $this->supplierModel->getSupplierHubSummary($supplierId),
            'ledger'   => $this->supplierModel->getRecentLedgerEntries($supplierId),
            'receives' => $this->supplierModel->getRecentPurchaseReceives($supplierId),
            'payments' => $this->supplierModel->getRecentPayments($supplierId),
        ]);
    }

    public function create() {
        $data = ['title' => 'Create New Supplier'];
        $this->view('supplier/create', $data);
    }

    public function store() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();

            $result = $this->supplierModel->createSupplier($_POST);

            if ($result['status'] === 'success') {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'supplier_created', (int)($result['id'] ?? 0), [
                    'supplier_code' => $result['supplier_code'] ?? '',
                    'supplier_name' => $_POST['supplier_name'] ?? '',
                    'mobile'        => $_POST['mobile'] ?? '',
                ]);

                $_SESSION['success'] = $result['message'];
                $this->redirect('supplier/index');
            } else {
                $_SESSION['error'] = $result['message'];
                $this->redirect('supplier/create');
            }
        }
    }

    public function edit($id = null) {
        if (!$id) $this->redirect('supplier/index');

        $supplier = $this->supplierModel->getSupplierById($id);
        if (!$supplier) {
            $_SESSION['error'] = "Supplier not found!";
            $this->redirect('supplier/index');
        }

        $data = [
            'title'    => 'Edit Supplier',
            'supplier' => $supplier,
            'usage'    => $this->supplierModel->getSupplierUsage((int)$id),
        ];
        $this->view('supplier/edit', $data);
    }

    public function update($id = null) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
            $this->validateCSRF();

            $supplierId = (int)$id;
            $before = $this->supplierModel->getSupplierById($supplierId);
            if (!$before) {
                $_SESSION['error'] = 'Supplier not found!';
                $this->redirect('supplier/index');
            }

            $result = $this->supplierModel->updateSupplier($supplierId, $_POST);

            if ($result['status'] === 'success') {
                $after = array_merge($before, $this->supplierModel->getSupplierById($supplierId) ?: []);
                $details = MasterDataAuditHelper::buildUpdateDetails(
                    $before,
                    $after,
                    MasterDataAuditHelper::SUPPLIER_FIELDS
                );

                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'supplier_updated', $supplierId, $details);

                $_SESSION['success'] = $result['message'];
            } else {
                $_SESSION['error'] = $result['message'];
            }
        }
        $this->redirect('supplier/index');
    }

    public function toggle($id = null) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
            $this->validateCSRF();

            $supplier = $this->supplierModel->getSupplierById($id);
            if (!$supplier) {
                echo json_encode(['status' => 'error', 'message' => 'Supplier not found.']);
                exit;
            }

            $isCurrentlyActive = (int)$supplier['is_active'] === 1;

            if ($isCurrentlyActive) {
                $safety = $this->supplierModel->getDeactivationSafetyStatus((int)$id);
                if (!$safety['can_deactivate']) {
                    echo json_encode([
                        'status'  => 'error',
                        'message' => $this->supplierModel->getDeactivationMessage((int)$id),
                    ]);
                    exit;
                }
            }

            if ($this->supplierModel->toggleStatus($id)) {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'supplier_status_changed', (int)$id);

                echo json_encode(['status' => 'success', 'message' => 'Supplier status updated successfully!']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to update status!']);
            }
            exit;
        }
        $this->redirect('supplier/index');
    }

    public function delete($id = null) {
        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
            exit;
        }

        $this->validateCSRF();

        $safety = $this->supplierModel->getDeactivationSafetyStatus((int)$id);

        if (!$safety['can_deactivate']) {
            echo json_encode([
                'status'  => 'error',
                'message' => $this->supplierModel->getDeactivationMessage((int)$id)
                    . ' Please clear dues or review related purchase records before archiving.',
                'safety'  => $safety,
            ]);
            exit;
        }

        if ($this->supplierModel->softDeleteSupplier($id)) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'supplier_deactivated', (int)$id);

            echo json_encode(['status' => 'success', 'message' => 'Supplier deactivated successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to deactivate supplier.']);
        }
        exit;
    }

    public function restore($id = null) {
        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
            exit;
        }

        $this->validateCSRF();

        if ($this->supplierModel->restoreSupplier($id)) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'supplier_restored', (int)$id);

            echo json_encode(['status' => 'success', 'message' => 'Supplier restored successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to restore supplier.']);
        }
        exit;
    }

    public function audit() {
        $logs = $this->userAudit->getRecentLogs(300, 'supplier_');
        $logs = MasterDataAuditHelper::enrichLogsWithUserNames($logs);

        $data = [
            'title' => 'Supplier Audit Logs',
            'logs'  => $logs,
        ];

        $this->view('supplier/audit', $data);
    }
}
