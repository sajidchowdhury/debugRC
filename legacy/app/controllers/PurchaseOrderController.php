<?php
// app/controllers/PurchaseOrderController.php

require_once '../core/BaseController.php';
require_once '../app/models/PurchaseOrderModel.php';
require_once '../core/UserAudit.php';

class PurchaseOrderController extends BaseController {

    private $model;
    private $userAudit;

    public function __construct() {
        $this->requireLogin();
        $this->model = new PurchaseOrderModel();
        $this->userAudit = new UserAudit();
    }

public function index() {
    // Handle DataTables server-side request
    if (isset($_GET['draw'])) {
        $params = $_GET;

        // Support "Show Cancelled" mode
        if (isset($_GET['cancelled']) && $_GET['cancelled'] == '1') {
            $params['showCancelled'] = true;
        }

        $response = $this->model->getPurchaseOrdersForDataTable($params);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    // Normal page load
    $showCancelled = isset($_GET['cancelled']) && $_GET['cancelled'] == '1';

    $data = [
        'title'         => $showCancelled ? 'Cancelled Purchase Orders' : 'Purchase Orders',
        'showCancelled' => $showCancelled,
    ];

    $this->view('PurchaseOrder/index', $data);
}

public function Details($id = null) {
    if (!$id) {
        $_SESSION['error'] = "Invalid Purchase Order ID";
        $this->redirect('PurchaseOrder');
        return;
    }

    $po = $this->model->getPOForView($id);  // We'll add this in Model

    if (!$po) {
        $_SESSION['error'] = "Purchase Order not found!";
        $this->redirect('PurchaseOrder');
        return;
    }

    $data = [
        'title' => 'View Purchase Order #' . $po['po_code'],
        'po'    => $po
    ];

    $this->view('PurchaseOrder/details', $data);
}

    public function create() {
        $suppliers = $this->model->getActiveSuppliers();
        $products = $this->model->getActiveProducts();
        $data = [
            'title' => 'Create Purchase Order',
            'suppliers' => $suppliers,
            'products' => $products
        ];
        $this->view('PurchaseOrder/create', $data);
    }

    public function store() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            // CSRF Protection (Phase 1 - Purchase Modernization)
            $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            $sessionToken = $_SESSION['csrf_token'] ?? '';
            if (empty($csrf) || !hash_equals($sessionToken, $csrf)) {
                $this->sendJson(['status' => 'error', 'message' => 'Invalid request (CSRF)']);
            }

            $items = json_decode($_POST['items'] ?? '[]', true);

            if (empty($items)) {
                $this->sendJson(['status' => 'error', 'message' => 'No items added']);
            }

            $data = [
                'supplier_id'   => $_POST['supplier_id'],
                'branch_id'     => $_SESSION['branch_id'] ?? 1,
                'po_date'       => $_POST['po_date'] ?? date('Y-m-d'),
                'expected_date' => $_POST['expected_date'] ?? null,
                'remarks'       => $_POST['remarks'] ?? '',
                'total_amount'  => $_POST['total_amount']
            ];

            $po_id = $this->model->createPO($data, $items);

            if ($po_id) {
                // Phase 4: Rich audit logging (prepares for journal_entry_id in Phase 5)
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'purchase_order_created', $po_id, [
                    'supplier_id'   => $data['supplier_id'],
                    'po_date'       => $data['po_date'],
                    'total_amount'  => $data['total_amount'],
                    'expected_date' => $data['expected_date'],
                    'item_count'    => count($items),
                    'journal_entry_id' => null,   // Will be populated after Phase 5 GL integration
                ]);

                $this->sendJson([
                    'status' => 'success',
                    'po_id' => $po_id,
                    'message' => 'Purchase Order created successfully'
                ]);
            } else {
                $this->sendJson([
                    'status' => 'error',
                    'message' => 'Failed to create Purchase Order'
                ]);
            }
        }
    }

    public function delete($id = null) {
        if (!$id) {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid ID']);
        }

        // CSRF Protection
        $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (empty($csrf) || !hash_equals($sessionToken, $csrf)) {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request (CSRF)']);
        }

        // Support both hard delete (legacy) and proper cancel with reason
        $reason = trim($_POST['reason'] ?? '');

        if (!empty($reason)) {
            // Proper cancellation with reason (preferred for Phase 4)
            $result = $this->model->cancelPO($id, $reason);

            // Audit logging
            if ($result['status'] === 'success') {
                // Phase 4 rich audit
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'purchase_order_cancelled', $id, [
                    'reason'           => $reason,
                    'cancelled_at'     => date('Y-m-d H:i:s'),
                    'journal_entry_id' => null,   // future
                ]);
            }
        } else {
            // Fallback to old delete behavior
            $result = $this->model->deletePO($id);
        }

        $this->sendJson($result);
    }

        // Add this method
    public function search_products() {
        $term = trim($_POST['term'] ?? '');
        if (strlen($term) < 1) {
            $this->sendJson([]);
            return;
        }

        $this->sendJson($this->model->searchProducts($term));
    }

    public function edit($id = null) {
    if (!$id) $this->redirect('PurchaseOrder');

    $po = $this->model->getPOForEdit($id);
    if (!$po || $po['status'] !== 'draft') {
        $_SESSION['error'] = "Only draft Purchase Orders can be edited!";
        $this->redirect('PurchaseOrder');
    }

    $suppliers = $this->model->getActiveSuppliers();
    $products = $this->model->getActiveProducts();

    $data = [
        'title' => 'Edit Purchase Order',
        'po' => $po,
        'suppliers' => $suppliers,
        'products' => $products
    ];
    $this->view('PurchaseOrder/edit', $data);
}

public function update($id = null) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) {
        $this->sendJson(['status' => 'error', 'message' => 'Invalid request']);
    }

    $this->validateCSRF();

    $items = json_decode($_POST['items'] ?? '[]', true);
    if (empty($items)) {
        $this->sendJson(['status' => 'error', 'message' => 'No items']);
    }

    $data = [
        'supplier_id'   => $_POST['supplier_id'],
        'po_date'       => $_POST['po_date'],
        'expected_date' => $_POST['expected_date'] ?? null,
        'remarks'       => $_POST['remarks'] ?? '',
        'total_amount'  => $_POST['total_amount']
    ];

    $result = $this->model->updatePO($id, $data, $items);

    if ($result) {
        // Phase 4: Audit update (only draft POs are editable)
        $this->userAudit->log($_SESSION['user_id'] ?? 0, 'purchase_order_updated', $id, [
            'supplier_id'   => $data['supplier_id'],
            'total_amount'  => $data['total_amount'],
            'item_count'    => count($items),
            'journal_entry_id' => null,
        ]);
    }

    $this->sendJson($result ? 
        ['status' => 'success', 'message' => 'Purchase Order updated successfully'] : 
        ['status' => 'error', 'message' => 'Failed to update PO']
    );
}

protected function getStatusBadge($status) {
    return match($status) {
        'draft' => '<span class="badge bg-secondary">Draft</span>',
        'pending' => '<span class="badge bg-warning">Pending</span>',
        'partially_received' => '<span class="badge bg-info">Partially Received</span>',
        'received' => '<span class="badge bg-success">Received</span>',
        'cancelled' => '<span class="badge bg-danger">Cancelled</span>',
        default => '<span class="badge bg-secondary">' . ucfirst($status) . '</span>'
    };
}

public function export() {
    $filters = [
        'date_from' => $_GET['date_from'] ?? null,
        'date_to'   => $_GET['date_to'] ?? null,
        'search'    => trim($_GET['search'] ?? ''),
        'status'    => $_GET['status'] ?? 'all'
    ];

    $pos = $this->model->getFilteredPOs($filters);

    if (empty($pos)) {
        $_SESSION['error'] = "No records found to export!";
        $this->redirect('PurchaseOrder');
        return;
    }

    // Set headers for Excel download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Purchase_Orders_' . date('Y-m-d_H-i') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // BOM for UTF-8 Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Header Row
    fputcsv($output, [
        'PO Code',
        'Supplier',
        'Branch',
        'PO Date',
        'Expected Date',
        'Total Amount',
        'Status',
        'Created By',
        'Remarks'
    ]);

    // Data Rows
    foreach ($pos as $po) {
        $status = match($po['status']) {
            'draft' => 'Draft',
            'pending' => 'Pending',
            'partially_received' => 'Partially Received',
            'received' => 'Received',
            'cancelled' => 'Cancelled',
            default => ucfirst($po['status'])
        };

        fputcsv($output, [
            $po['po_code'],
            $po['supplier_name'],
            $po['branch_name'],
            $po['po_date'],
            $po['expected_date'] ?? '',
            $po['total_amount'],
            $status,
            $po['created_by_name'] ?? 'System',
            $po['remarks'] ?? ''
        ]);
    }

    fclose($output);
    exit;
}

    /**
     * Phase 4: Dedicated Audit Log page for Purchase Orders
     * Filters by 'purchase_order_' action prefix
     */
    public function audit() {
        $logs = $this->userAudit->getRecentLogs(300, 'purchase_order_');

        $data = [
            'title' => 'Purchase Order Audit Logs',
            'logs'  => $logs
        ];

        $this->view('PurchaseOrder/audit', $data);
    }

}