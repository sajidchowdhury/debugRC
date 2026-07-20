<?php
// app/controllers/PurchaseReturnController.php

require_once '../core/BaseController.php';
require_once '../app/models/PurchaseReturnModel.php';
require_once '../app/models/BranchModel.php';
require_once '../app/helpers/Helper.php';
require_once '../core/UserAudit.php';
require_once '../app/helpers/PurchaseGlAuditHelper.php';

class PurchaseReturnController extends BaseController {

    private $model;
    private $userAudit;

    public function __construct() {
        $this->requireLogin();
        $this->model = new PurchaseReturnModel();
        $this->userAudit = new UserAudit();
    }

public function index() {
    if (isset($_GET['draw'])) {
        $params = $_GET;
        if (isset($_GET['reversed']) && $_GET['reversed'] == '1') {
            $params['showReversed'] = true;
        }
        $response = $this->model->getPurchaseReturnsForDataTable($params);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    $filters = [
        'date_from'   => $_GET['date_from'] ?? date('Y-m-d'),
        'date_to'     => $_GET['date_to'] ?? date('Y-m-d'),
        'status'      => $_GET['status'] ?? 'active',
        'date_preset' => $_GET['preset'] ?? 'today',
        'search'      => trim($_GET['q'] ?? ''),
        'smart_sort'  => ($_GET['smart_sort'] ?? '1') !== '0',
    ];

    $branchId = Helper::sessionBranchId();
    $branch = (new BranchModel())->getBranchById($branchId);

    $data = [
        'title'               => 'Purchase Returns',
        'filters'             => $filters,
        'session_branch_name' => $branch['branch_name'] ?? ($_SESSION['branch_name'] ?? 'Branch'),
        'csrf'                => $_SESSION['csrf_token'] ?? '',
    ];

    $this->view('PurchaseReturn/index', $data);
}

    public function return_filter_summary() {
        $filters = [
            'date_from' => $_GET['date_from'] ?? date('Y-m-d'),
            'date_to'   => $_GET['date_to'] ?? date('Y-m-d'),
            'search'    => trim($_GET['search'] ?? ''),
        ];
        $this->sendJson($this->model->getReturnFilterSummary($filters));
    }
    public function create() {
        $data = ['title' => 'Create Purchase Return'];
        $this->view('PurchaseReturn/create', $data);
    }

    public function search_receive() {
        // CSRF for consistency (Phase 1/4)
        $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (empty($csrf) || !hash_equals($sessionToken, $csrf)) {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request (CSRF)']);
        }

        $term = $_POST['term'] ?? '';
        $this->sendJson($this->model->searchReceives($term));
    }

public function get_receive_for_return() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $this->sendJson(['status' => 'error', 'message' => 'Invalid request method']);
    }

    $this->validateCSRF();

    // CSRF Protection (Phase 1)
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (empty($csrf) || !hash_equals($sessionToken, $csrf)) {
        $this->sendJson(['status' => 'error', 'message' => 'Invalid request (CSRF)']);
    }

    $receive_code = trim($_POST['receive_code'] ?? '');

    if (empty($receive_code)) {
        $this->sendJson(['status' => 'error', 'message' => 'Receive code is required']);
    }

    try {
        $data = $this->model->getReceiveForReturn($receive_code);

        if ($data && !empty($data['items'])) {
            $this->sendJson(['status' => 'success', 'receive' => $data]);
        } else {
            $this->sendJson(['status' => 'error', 'message' => 'GRN not found or no returnable items.' ]);
        }
    } catch (Exception $e) {
        error_log("GRN Load Error: " . $e->getMessage());
        $this->sendJson(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
    }
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
                $this->sendJson(['status' => 'error', 'message' => 'No items selected']);
            }

            foreach ($items as $item) {
    if ((float)$item['return_qty'] > 0 && empty($item['warehouse_id'])) {
        $this->sendJson(['status' => 'error', 'message' => 'Warehouse is required for returned items']);
        return;
    }
}


            $data = [
                'purchase_receive_id' => $_POST['purchase_receive_id'],
                'supplier_id'         => $_POST['supplier_id'],
                'return_date'         => $_POST['return_date'] ?? date('Y-m-d'),
                'total_amount'        => $_POST['total_amount'],
                'reason'              => $_POST['reason'] ?? ''
            ];

            $return_id = $this->model->createReturn($data, $items);

            if ($return_id) {
                // Phase 4 rich audit (Purchase Return reverses inventory + future AP)
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'purchase_return_created', $return_id, [
                    'purchase_receive_id' => $data['purchase_receive_id'],
                    'total_amount'        => $data['total_amount'],
                    'item_count'          => count($items),
                    'reason'              => $data['reason'] ?? '',
                    'journal_entry_id'    => null,   // Phase 5: Dr AP / Cr Inventory @ current avg
                    'accounting_impact'   => 'Reverses prior GRN: Dr Supplier Payable, Cr Inventory (moving avg)',
                ]);

                $this->sendJson([
                    'status' => 'success',
                    'return_id' => $return_id,
                    'message' => 'Purchase Return created successfully!'
                ]);
            } else {
                $this->sendJson(['status' => 'error', 'message' => 'Failed to create return']);
            }
        }
    }

    /**
     * Phase 4: Reverse a Purchase Return (POST only, CSRF protected)
     * Body: id + reason
     * On success: rich UserAudit log + stock/returned_qty already rolled back in model.
     */
    public function reverse() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request method']);
        }

        $this->validateCSRF();

        // CSRF (consistent with other reversal endpoints)
        $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (empty($csrf) || !hash_equals($sessionToken, $csrf)) {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request (CSRF)']);
        }

        $id     = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($id <= 0 || $reason === '') {
            $this->sendJson(['status' => 'error', 'message' => 'Return ID and reversal reason are required']);
        }

        $result = $this->model->reversePurchaseReturn($id, $reason);

        if ($result['status'] === 'success') {
            // Phase 4 rich audit log
            $this->userAudit->log(
                $_SESSION['user_id'] ?? 0,
                'purchase_return_reversed',
                $id,
                [
                    'reason'             => $reason,
                    'return_code'        => $result['return_code'] ?? null,
                    'receive_code'       => $result['receive_code'] ?? null,
                    'supplier_name'      => $result['supplier_name'] ?? null,
                    'total_amount'       => $result['total_amount'] ?? 0,
                    'items_reversed'     => $result['items_reversed'] ?? 0,
                    'journal_entry_id'   => $result['journal_entry_id'] ?? null,
                    'accounting_note'    => 'Stock restored; returned_qty decremented; supplier ledger and GL reversed.',
                ]
            );
        }

        $this->sendJson($result);
    }

    /**
     * Purchase return GL audit detail (Phase 5B).
     * URL: PurchaseReturn/details/{id}
     */
    public function details($id = null) {
        if (!$id) {
            $this->redirect('PurchaseReturn');
            return;
        }

        $return = $this->model->getReturnForSlip((int)$id);
        if (!$return) {
            $_SESSION['error'] = 'Return not found or access denied.';
            $this->redirect('PurchaseReturn');
            return;
        }

        $this->view('PurchaseReturn/details', [
            'title'          => 'Purchase Return — ' . ($return['return_code'] ?? ''),
            'return'         => $return,
            'journal_blocks' => PurchaseGlAuditHelper::returnJournalBlocks($return),
        ]);
    }

    public function slip($id = null) {
        if (!$id) $this->redirect('PurchaseReturn');

        $returnData = $this->model->getReturnForSlip($id);
        if (!$returnData) {
            $_SESSION['error'] = "Return slip not found!";
            $this->redirect('PurchaseReturn');
        }

        $data = ['title' => 'Purchase Return Slip', 'return' => $returnData];
        $this->view('PurchaseReturn/slip', $data);
    }

    public function export() {
    $filters = [
        'date_from' => $_GET['date_from'] ?? null,
        'date_to'   => $_GET['date_to'] ?? null,
        'search'    => trim($_GET['search'] ?? '')
    ];

    $returns = $this->model->getFilteredReturns($filters);

    if (empty($returns)) {
        $_SESSION['error'] = "No records found to export!";
        $this->redirect('PurchaseReturn');
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Purchase_Returns_' . date('Y-m-d_H-i') . '.csv"');
    header('Pragma: no-cache');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, [
        'Return Code', 'GRN Code', 'Supplier', 'Branch', 
        'Return Date', 'Total Amount', 'Created By', 'Reason'
    ]);

    foreach ($returns as $r) {
        fputcsv($output, [
            $r['return_code'],
            $r['receive_code'],
            $r['supplier_name'],
            $r['branch_name'],
            $r['return_date'],
            $r['total_amount'],
            $r['created_by_name'] ?? 'System',
            $r['reason'] ?? ''
        ]);
    }

    fclose($output);
    exit;
}

    /**
     * Phase 4: Dedicated Audit Log page for Purchase Returns
     * Filters logs by action prefix 'purchase_return_'
     */
    public function audit() {
        $logs = $this->userAudit->getRecentLogs(300, 'purchase_return_');

        $data = [
            'title' => 'Purchase Return Audit Logs',
            'logs'  => $logs
        ];

        $this->view('PurchaseReturn/audit', $data);
    }
}