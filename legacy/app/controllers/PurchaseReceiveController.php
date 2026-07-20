<?php
// app/controllers/PurchaseReceiveController.php

require_once '../core/BaseController.php';
require_once '../app/models/PurchaseReceiveModel.php';
require_once '../core/UserAudit.php';
require_once '../app/helpers/PurchaseGlAuditHelper.php';

class PurchaseReceiveController extends BaseController {

    private $model;
    private $userAudit;

    public function __construct() {
        $this->requireLogin();
        $this->model = new PurchaseReceiveModel();
        $this->userAudit = new UserAudit();
    }

public function index() {
    // Handle DataTables server-side request
    if (isset($_GET['draw'])) {
        $params = $_GET;

        // Support "Show Returned" mode
        if (isset($_GET['returned']) && $_GET['returned'] == '1') {
            $params['showReturned'] = true;
        }

        $response = $this->model->getPurchaseReceivesForDataTable($params);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    // Normal page load
    $showReturned = isset($_GET['returned']) && $_GET['returned'] == '1';

    $data = [
        'title'        => $showReturned ? 'Returned Purchase Receives' : 'Purchase Receives',
        'showReturned' => $showReturned,
    ];

    $this->view('PurchaseReceive/index', $data);
}

    public function create() {
        $pos = $this->model->getPendingPOs();
        $warehouses = $this->model->getBranchWarehouses();
        $suppliers = $this->model->getActiveSuppliers(); // For Direct Purchase mode

        $data = [
            'title' => 'New Purchase Receive',
            'pos' => $pos,
            'warehouses' => $warehouses,
            'suppliers' => $suppliers
        ];
        $this->view('PurchaseReceive/create', $data);
    }

    public function get_po_details() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();
            // CSRF Protection
            $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            $sessionToken = $_SESSION['csrf_token'] ?? '';
            if (empty($csrf) || !hash_equals($sessionToken, $csrf)) {
                $this->sendJson(['status' => 'error', 'message' => 'Invalid request (CSRF)']);
            }

            $po_id = $_POST['po_id'] ?? 0;
            $this->sendJson($this->model->getPODetailsForReceive($po_id));
        }
    }


public function store() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $this->sendJson(['status' => 'error', 'message' => 'Invalid request method']);
    }

    $this->validateCSRF();

    // CSRF Protection (Phase 1 - Purchase Modernization)
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (empty($csrf) || !hash_equals($sessionToken, $csrf)) {
        $this->sendJson(['status' => 'error', 'message' => 'Invalid request (CSRF)']);
    }

    $items = json_decode($_POST['items'] ?? '[]', true);
    $po_id = $_POST['purchase_order_id'] ?? 0;

    $data = [
        'purchase_order_id' => $po_id ?: null,   // Support Direct Purchase (NULL)
        'supplier_id'       => $_POST['supplier_id'] ?? null, // For Direct Purchase
        'branch_id'         => $_SESSION['branch_id'] ?? 1,
        'receive_date'      => $_POST['receive_date'] ?? date('Y-m-d'),
        'remarks'           => $_POST['remarks'] ?? '',
        'total_amount'      => $_POST['total_amount'] ?? 0
    ];

    $receive_id = $this->model->createReceive($data, $items);

    if ($receive_id) {
        // Phase 4: Rich audit logging — critical for future GL (Dr Inventory / Cr AP)
        $isDirect = empty($po_id);
        $this->userAudit->log($_SESSION['user_id'] ?? 0, 'purchase_receive_created', $receive_id, [
            'purchase_order_id' => $po_id ?: null,
            'is_direct_purchase' => $isDirect,
            'supplier_id'       => $data['supplier_id'] ?? null,
            'receive_date'      => $data['receive_date'],
            'total_amount'      => $data['total_amount'],
            'item_count'        => count($items),
            'journal_entry_id'  => null,   // Populated in Phase 5 by JournalPostingService
            'accounting_impact' => 'Dr Inventory @ moving avg, Cr Supplier Payable',
        ]);

        $this->sendJson([
            'status' => 'success',
            'receive_id' => $receive_id,
            'message' => 'GRN created successfully!'
        ]);
    } else {
        $this->sendJson([
            'status' => 'error',
            'message' => 'Failed to save GRN. Check browser console for details.'
        ]);
    }
}

    public function details($id = null) {
    if (!$id) {
        $_SESSION['error'] = "Invalid GRN ID";
        $this->redirect('PurchaseReceive');
    }

    $receive = $this->model->getReceiveDetails($id);

    if (!$receive) {
        $_SESSION['error'] = "GRN not found!";
        $this->redirect('PurchaseReceive');
    }

    $receiveId = (int)$id;
    $data = [
        'title'          => 'GRN Details #' . $receive['receive_code'],
        'receive'        => $receive,
        'journal_blocks' => PurchaseGlAuditHelper::grnJournalBlocks($receive),
        'returns'        => PurchaseGlAuditHelper::getReceiveReturns($receiveId),
    ];

    $this->view('PurchaseReceive/details', $data);
}

public function export() {
    $filters = [
        'date_from' => $_GET['date_from'] ?? null,
        'date_to'   => $_GET['date_to'] ?? null,
        'search'    => trim($_GET['search'] ?? '')
    ];

    $receives = $this->model->getFilteredReceives($filters);

    if (empty($receives)) {
        $_SESSION['error'] = "No records found!";
        $this->redirect('PurchaseReceive');
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Purchase_Receives_' . date('Y-m-d_H-i') . '.csv"');
    header('Pragma: no-cache');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    fputcsv($output, [
        'GRN Code', 'PO Code', 'Supplier', 'Branch', 
        'Receive Date', 'Total Amount', 'Created By', 'Remarks'
    ]);

    foreach ($receives as $r) {
        fputcsv($output, [
            $r['receive_code'],
            $r['po_code'] ?? 'Direct',
            $r['supplier_name'],
            $r['branch_name'],
            $r['receive_date'],
            $r['total_amount'],
            $r['created_by_name'] ?? 'System',
            $r['remarks'] ?? ''
        ]);
    }

    fclose($output);
    exit;
}

    /**
     * Phase 4: Dedicated Audit Log page for Purchase Receives (GRNs)
     * Filters by 'purchase_receive_' actions
     */
    public function audit() {
        $logs = $this->userAudit->getRecentLogs(300, 'purchase_receive_');

        $data = [
            'title' => 'Purchase Receive (GRN) Audit Logs',
            'logs'  => $logs
        ];

        $this->view('PurchaseReceive/audit', $data);
    }

    /**
     * Phase 4: Cancel a Receive (POST, CSRF + reason)
     */
    public function cancel() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid method']);
        }

        $this->validateCSRF();

        $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (empty($csrf) || !hash_equals($sessionToken, $csrf)) {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request (CSRF)']);
        }

        $id = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($id <= 0 || strlen($reason) < 5) {
            $this->sendJson(['status' => 'error', 'message' => 'ID and reason (min 5 chars) required']);
        }

        $result = $this->model->cancelReceive($id, $reason);

        if ($result['status'] === 'success') {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'purchase_receive_cancelled', $id, [
                'reason'             => $reason,
                'purchase_order_id'  => $result['purchase_order_id'] ?? null,
                'journal_entry_id'   => null,
                'accounting_note'    => 'Stock OUT, supplier ledger debit, GL reversed, PO received_qty refreshed.',
            ]);
        }

        $this->sendJson($result);
    }

}