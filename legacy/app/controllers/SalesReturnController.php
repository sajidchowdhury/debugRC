<?php
// app/controllers/SalesReturnController.php

require_once '../core/BaseController.php';
require_once '../app/models/SalesReturnModel.php';
require_once '../app/models/WarehouseModel.php';
require_once '../app/models/BranchModel.php';
require_once '../app/helpers/Helper.php';
require_once '../core/UserAudit.php';
require_once '../app/services/Notification/SalesTelegramNotifier.php';
require_once '../app/helpers/SalesGlAuditHelper.php';

class SalesReturnController extends BaseController {

    private $returnModel;
    private $warehouseModel;
    private $userAudit;

    public function __construct() {
        $this->requireLogin();
        $this->returnModel = new SalesReturnModel();
        $this->warehouseModel = new WarehouseModel();
        $this->userAudit = new UserAudit();
    }

    public function index() {
        $filters = [
            'date_from'   => $_GET['date_from'] ?? date('Y-m-d'),
            'date_to'     => $_GET['date_to'] ?? date('Y-m-d'),
            'status'      => $_GET['status'] ?? 'all',
            'date_preset' => $_GET['preset'] ?? 'today',
            'search'      => trim($_GET['q'] ?? ''),
            'smart_sort'  => ($_GET['smart_sort'] ?? '1') !== '0',
        ];

        $branchId = Helper::sessionBranchId();
        $branch = (new BranchModel())->getBranchById($branchId);

        $pendingReturns = $this->returnModel->getPendingReturns();

        $data = [
            'title'               => 'Sales Returns',
            'pendingReturns'      => $pendingReturns,
            'pending_count'       => count($pendingReturns),
            'filters'             => $filters,
            'session_branch_name' => $branch['branch_name'] ?? 'Branch',
        ];

        $this->view('SalesReturn/index', $data);
    }

    public function return_filter_summary() {
        $filters = [
            'date_from' => $_GET['date_from'] ?? date('Y-m-d'),
            'date_to'   => $_GET['date_to'] ?? date('Y-m-d'),
            'search'    => trim($_GET['search'] ?? ''),
            'skip_default_today' => true,
        ];
        $this->sendJson($this->returnModel->getReturnFilterSummary($filters));
    }

    public function datatable_returns() {
        $draw   = (int)($_GET['draw'] ?? 1);
        $start  = (int)($_GET['start'] ?? 0);
        $length = (int)($_GET['length'] ?? 25);
        if ($length < 1 || $length > 200) {
            $length = 25;
        }

        $orderColumnIndex = (int)($_GET['order'][0]['column'] ?? 0);
        $orderDir         = $_GET['order'][0]['dir'] ?? 'desc';
        $searchValue      = trim($_GET['search']['value'] ?? '');

        $filters = [
            'date_from' => $_GET['date_from'] ?? date('Y-m-d'),
            'date_to'   => $_GET['date_to'] ?? date('Y-m-d'),
            'status'    => $_GET['status'] ?? 'all',
            'smart_sort' => ($_GET['smart_sort'] ?? '1') !== '0',
            'skip_default_today' => true,
        ];

        $result = $this->returnModel->getReturnsDatatable(
            $filters,
            $start,
            $length,
            $orderColumnIndex,
            $orderDir,
            $searchValue
        );

        header('Content-Type: application/json');
        echo json_encode([
            'draw'            => $draw,
            'recordsTotal'    => $result['total'],
            'recordsFiltered' => $result['filtered'],
            'data'            => $result['data'],
        ]);
        exit;
    }

    public function create() {
        $data = ['title' => 'Create Sales Return'];
        $this->view('SalesReturn/create', $data);
    }

    public function search_invoice() {
        $this->validateCSRF();
        $term = $_POST['term'] ?? $_POST['invoice_code'] ?? '';
        $this->sendJson($this->returnModel->searchInvoices($term));
    }

    public function get_invoice_for_return() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request']);
        }

        $this->validateCSRF();
        $invoice_code = trim($_POST['invoice_code'] ?? '');
        $result = $this->returnModel->resolveInvoiceForReturn($invoice_code);
        $this->sendJson($result);
    }

    public function warehouse_stock_for_receive() {
        $this->guardJsonApi('salesreturn.warehouse_stock', 120, 60);
        $productId = (int)($_GET['product_id'] ?? 0);
        if ($productId <= 0) {
            $this->sendJson([]);
            return;
        }
        $this->sendJson($this->returnModel->getWarehouseStockForReceive($productId));
    }

    // ================= PHASE 1: Create Return =================
    public function store() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('SalesReturn/create');
            return;
        }

        $this->requireRouteAccess();
        $this->validateCSRF();
        $rawItems = $_POST['items'] ?? '[]';
        $items = is_string($rawItems) ? json_decode($rawItems, true) : $rawItems;

        if (empty($items) || !is_array($items)) {
            $_SESSION['error'] = "No items selected for return!";
            $this->redirect('SalesReturn/create');
            return;
        }

        $data = [
            'sales_invoice_id' => (int)($_POST['sales_invoice_id'] ?? 0),
            'customer_id'      => (int)($_POST['customer_id'] ?? 0),
            'return_date'      => $_POST['return_date'] ?? date('Y-m-d'),
            'total_amount'     => (float)($_POST['total_amount'] ?? 0),
            'reason'           => trim($_POST['reason'] ?? '')
        ];

        if ($data['sales_invoice_id'] <= 0 || $data['customer_id'] <= 0) {
            $_SESSION['error'] = "Invalid invoice or customer!";
            $this->redirect('SalesReturn/create');
            return;
        }

        $return_id = $this->returnModel->createReturn($data, $items);

        if ($return_id) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'return_created', (int)$return_id, [
                'sales_invoice_id' => $data['sales_invoice_id'],
                'total_amount'     => $data['total_amount'],
                'item_count'       => count($items),
                'reason'           => $data['reason'],
            ]);

            SalesTelegramNotifier::safe(function () use ($return_id) {
                (new SalesTelegramNotifier())->notifyReturnCreated((int)$return_id);
            });

            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($isAjax) {
                $slipBase = defined('PUBLIC_URL') ? PUBLIC_URL : BASE_URL;
                $this->sendJson([
                    'status'      => 'success',
                    'message'     => 'Sales return created. Pending warehouse confirmation.',
                    'return_id'   => (int)$return_id,
                    'slip_url'    => $slipBase . 'SalesReturn/slip/' . (int)$return_id,
                    'confirm_url' => $slipBase . 'SalesReturn/confirm/' . (int)$return_id,
                ]);
            }
            $_SESSION['success'] = "Sales Return created successfully! Pending warehouse confirmation.";
            $this->redirect('SalesReturn/slip/' . $return_id);
        } else {
            $msg = $this->returnModel->getLastError() ?: 'Failed to create sales return.';
            $_SESSION['error'] = $msg;
            $this->redirect('SalesReturn/create');
        }
    }

    // ================= PHASE 2: Confirm Return (Warehouse) =================
    public function confirm($id = null) {
        if (!$id) {
            $this->redirect('SalesReturn');
            return;
        }

        $return = $this->returnModel->getReturnById($id);
        if (!$return || $return['status'] !== 'pending') {
            $_SESSION['error'] = "Return not found or already processed.";
            $this->redirect('SalesReturn');
            return;
        }

        require_once __DIR__ . '/../helpers/Helper.php';
        $currentBranchId = Helper::sessionBranchId();
        $branch = (new BranchModel())->getBranchById($currentBranchId);
        $warehouses = $this->warehouseModel->getWarehousesByBranch($currentBranchId);

        $data = [
            'title'               => 'Confirm Sales Return',
            'return'              => $return,
            'warehouses'          => $warehouses,
            'session_branch_name' => $branch['branch_name'] ?? 'Branch',
        ];

        $this->view('SalesReturn/confirm', $data);
    }



    public function confirm_store() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $this->redirect('SalesReturn');
        return;
    }

    $this->requireRouteAccess();
    $this->validateCSRF();
    $return_id = (int)($_POST['return_id'] ?? 0);
    $itemsData = $_POST['items'] ?? [];

    if ($return_id <= 0 || empty($itemsData)) {
        $_SESSION['error'] = "Invalid or missing data!";
        $this->redirect('SalesReturn/confirm/' . $return_id);
        return;
    }

    $items = [];
    foreach ($itemsData as $key => $val) {
        $items[] = [
            'return_item_id' => (int)$key,
            'product_id'     => (int)($val['product_id'] ?? 0),
            'warehouse_id'   => (int)($val['warehouse_id'] ?? 0),
            'rate'           => (float)($val['rate'] ?? 0),
            'condition'      => $val['condition'] ?? 'Good',
            'return_qty'     => (float)($val['return_qty'] ?? 0)
        ];
    }

    $result = $this->returnModel->confirmReturn($return_id, $items);
    if (!empty($result['success'])) {
        $this->userAudit->log($_SESSION['user_id'] ?? 0, 'return_confirmed', $return_id, [
            'return_id'        => $return_id,
            'item_count'       => count($items),
            'journal_entry_id' => $result['journal_entry_id'] ?? null,
            'cogs_amount'      => $result['cogs_amount'] ?? null,
            'linked_damage_ids'=> $result['linked_damage_ids'] ?? [],
        ]);

        $confirmedBy = (int)($_SESSION['user_id'] ?? 0);
        SalesTelegramNotifier::safe(function () use ($return_id, $confirmedBy) {
            (new SalesTelegramNotifier())->notifyReturnReceived((int)$return_id, $confirmedBy);
        });

        $msg = 'Return confirmed. Stock updated and customer credit note posted.';
        if (!empty($result['linked_damages'])) {
            $codes = array_map(static fn($d) => $d['damage_code'] ?? '', $result['linked_damages']);
            $codes = array_filter($codes);
            if ($codes !== []) {
                $msg .= ' Linked damage write-off: ' . implode(', ', $codes) . '.';
            }
            $_SESSION['return_damage_links'] = $result['linked_damages'];
        }
        $_SESSION['success'] = $msg;
        $this->redirect('SalesReturn');
    } else {
        $_SESSION['error'] = $result['message'] ?? $this->returnModel->getLastError() ?: 'Failed to confirm return.';
        $this->redirect('SalesReturn/confirm/' . $return_id);
    }
}


    /**
     * Sales return GL audit detail (Phase 5A).
     * URL: SalesReturn/details/{id}
     */
    public function details($id = null) {
        if (!$id) {
            $this->redirect('SalesReturn');
            return;
        }

        $return = $this->returnModel->getReturnById((int)$id);
        if (!$return) {
            $_SESSION['error'] = 'Return not found or access denied.';
            $this->redirect('SalesReturn');
            return;
        }

        $this->view('SalesReturn/details', [
            'title'          => 'Return — ' . ($return['return_code'] ?? ''),
            'return'         => $return,
            'journal_blocks' => SalesGlAuditHelper::returnJournalBlocks($return),
        ]);
    }

    public function slip($id = null) {
        if (!$id) {
            $this->redirect('SalesReturn');
            return;
        }

        $returnData = $this->returnModel->getReturnSlipData($id); // Better to move query to model

        if (!$returnData) {
            $_SESSION['error'] = "Return slip not found!";
            $this->redirect('SalesReturn');
            return;
        }

        $items = $returnData['items'] ?? [];
        $itemsPerPage = 17;
        $itemPages = $items !== [] ? array_chunk($items, $itemsPerPage) : [[]];

        $this->view('SalesReturn/slip', [
            'title'          => 'Sales Return Slip — ' . ($returnData['return_code'] ?? ''),
            'return'         => $returnData,
            'item_pages'     => $itemPages,
            'items_per_page' => $itemsPerPage,
            'branch_id'      => (int)($returnData['branch_id'] ?? 1),
        ]);
    }

    public function reverse($id = null) {
        if (!$id) {
            $this->redirect('SalesReturn');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->requireRouteAccess();
            $this->validateCSRF();
            $reason = trim($_POST['reason'] ?? '');
            $preview = $this->returnModel->getReturnForReversal((int)$id);
            if (!$preview || empty($preview['reversal']['can_reverse'])) {
                $_SESSION['error'] = $preview['reversal']['block_reason']
                    ?? 'This return cannot be reversed.';
                $this->redirect('SalesReturn');
                return;
            }
            $result = $this->returnModel->reverseReturn($id, $reason);
            if (($result['status'] ?? '') === 'success') {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'return_reversed', $id, [
                    'return_code'          => $result['return_code'] ?? null,
                    'total_amount'         => $result['total_amount'] ?? 0,
                    'was_completed'        => $result['was_completed'] ?? false,
                    'stock_lines_reversed' => $result['stock_lines_reversed'] ?? 0,
                    'journal_entry_id'     => $result['journal_entry_id'] ?? null,
                    'reason'               => $reason,
                ]);
                $_SESSION['success'] = "Sales Return reversed successfully!";
            } else {
                $_SESSION['error'] = $result['message'] ?? 'Failed to reverse return!';
            }
            $this->redirect('SalesReturn');
            return;
        }

        $bundle = $this->returnModel->getReturnForReversal((int)$id);
        if (!$bundle) {
            $_SESSION['error'] = 'Return not found or you do not have access.';
            $this->redirect('SalesReturn');
            return;
        }

        $branchId = Helper::sessionBranchId();
        $branch = (new BranchModel())->getBranchById($branchId);

        $this->view('SalesReturn/reverse', [
            'title'               => 'Reverse Sales Return',
            'return'              => $bundle['return'] ?? [],
            'reversal'            => $bundle['reversal'] ?? [],
            'session_branch_name' => $branch['branch_name'] ?? 'Branch',
        ]);
    }

    public function audit() {
        $branchId = Helper::sessionBranchId();
        $branch = (new BranchModel())->getBranchById($branchId);
        $this->view('SalesReturn/audit', [
            'title'               => 'Sales Return Audit Logs',
            'logs'                => $this->userAudit->getRecentLogs(300, 'return_'),
            'session_branch_name' => $branch['branch_name'] ?? 'Branch',
        ]);
    }

    public function export() {
        // ... your export logic (already good)
    }
}