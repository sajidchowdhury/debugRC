<?php
// app/controllers/ChallanController.php

require_once '../core/BaseController.php';
require_once '../app/models/ChallanModel.php';
require_once '../core/UserAudit.php';
require_once '../app/helpers/Helper.php';
require_once '../app/services/Notification/SalesTelegramNotifier.php';
require_once '../app/helpers/SalesGlAuditHelper.php';

class ChallanController extends BaseController {

    private $model;
    private $userAudit;

    public function __construct() {
        $this->requireLogin();
        $this->model = new ChallanModel();
        $this->userAudit = new UserAudit();
    }



public function index() {
    $filters = [
        'date_from'  => $_GET['date_from'] ?? date('Y-m-d'),
        'date_to'    => $_GET['date_to'] ?? date('Y-m-d'),
        'status'     => $_GET['status'] ?? 'open',
        'date_preset'=> $_GET['preset'] ?? 'today',
        'search'     => trim($_GET['q'] ?? ''),
        'smart_sort' => ($_GET['smart_sort'] ?? '1') !== '0',
    ];

    $sessionBranchId = Helper::sessionBranchId();
    $branch = $this->model->getBranch($sessionBranchId);
    $queueSummary = $this->model->getChallanFilterSummary(['skip_default_today' => true]);

    $data = [
        'title'               => 'Warehouse — Godown & Challan',
        'filters'             => $filters,
        'session_branch_name' => $branch['branch_name'] ?? 'Branch',
        'open_queue_count'    => (int)($queueSummary['open'] ?? 0),
        'needs_challan_count' => (int)($queueSummary['needs_challan'] ?? 0),
    ];

    $this->view('challan/index', $data);
}

    public function filter_summary() {
        $filters = [
            'date_from' => $_GET['date_from'] ?? date('Y-m-d'),
            'date_to'   => $_GET['date_to'] ?? date('Y-m-d'),
            'search'    => trim($_GET['search'] ?? ''),
            'skip_default_today' => true,
        ];
        $this->sendJson($this->model->getChallanFilterSummary($filters));
    }

    public function datatable_challans() {
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
            'date_from'   => $_GET['date_from'] ?? date('Y-m-d'),
            'date_to'     => $_GET['date_to'] ?? date('Y-m-d'),
            'status'      => $_GET['status'] ?? 'open',
            'smart_sort'  => ($_GET['smart_sort'] ?? '1') !== '0',
            'skip_default_today' => true,
        ];

        $result = $this->model->getChallansDatatable(
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

    
    public function create($id = null) {
        if (!$id) {
            $_SESSION['error'] = 'Invalid invoice.';
            $this->redirect('challan');
            return;
        }
        $invoice = $this->model->getInvoiceForGodownCopy($id);
        if (!$invoice) {
            $_SESSION['error'] = 'Invoice not found or access denied.';
            $this->redirect('challan');
            return;
        }
        $role = $_SESSION['role'] ?? '';
        $this->view('challan/create', [
            'invoice' => $invoice,
            'can_reverse_challan' => in_array($role, ['admin', 'manager'], true)
                && ($invoice['status'] ?? '') === 'challan_completed',
        ]);
    }

    public function details($id = null) {
        if (!$id) {
            $this->abortPage('Invalid challan.', 'challan');
        }

        $challan = $this->model->getChallanForDetail((int)$id);
        if (!$challan) {
            $this->abortPage('Challan not found or access denied.', 'challan');
        }

        $this->view('challan/details', [
            'title'          => 'Challan — ' . ($challan['challan_code'] ?? ''),
            'challan'        => $challan,
            'journal_blocks' => SalesGlAuditHelper::challanJournalBlocks($challan),
        ]);
    }

    public function challan_copy($id) {
        if (!$id) {
            $this->abortPage('Invalid invoice.', 'challan');
        }
        $invoice = $this->model->getInvoiceForGodownCopy($id);
        if (!$invoice) {
            $this->abortPage('Invoice not found or access denied.', 'challan');
        }
        $items = $invoice['items'] ?? [];
        $itemsPerPage = 17;
        $itemPages = $items !== [] ? array_chunk($items, $itemsPerPage) : [[]];

        $this->view('challan/challan_copy', [
            'invoice'          => $invoice,
            'item_pages'       => $itemPages,
            'items_per_page'   => $itemsPerPage,
            'branch_id'        => (int)($invoice['branch_id'] ?? 1),
        ]);
    }

    public function godown_copy($id) {
        if (!$id) {
            $this->abortPage('Invalid invoice.', 'challan');
        }
        $invoice = $this->model->getInvoiceForGodownCopy($id);
        if (!$invoice) {
            $this->abortPage('Invoice not found or access denied.', 'challan');
        }
        $items = $invoice['items'] ?? [];
        $itemsPerPage = 17;
        $itemPages = $items !== [] ? array_chunk($items, $itemsPerPage) : [[]];

        $this->view('challan/godown_copy', [
            'invoice'          => $invoice,
            'item_pages'     => $itemPages,
            'items_per_page' => $itemsPerPage,
            'branch_id'      => (int)($invoice['branch_id'] ?? 1),
        ]);
    }

    public function print_blank_godown_copy($id) {
        if (!$id) {
            $this->abortPage('Invalid invoice.', 'challan');
        }
        $invoice = $this->model->getInvoiceForGodownCopy($id);
        if (!$invoice) {
            $this->abortPage('Invoice not found or access denied.', 'challan');
        }
        $items = $invoice['items'] ?? [];
        $itemsPerPage = 17;
        $itemPages = $items !== [] ? array_chunk($items, $itemsPerPage) : [[]];

        $this->view('challan/print_blank_godown_copy', [
            'invoice'          => $invoice,
            'item_pages'     => $itemPages,
            'items_per_page' => $itemsPerPage,
            'branch_id'      => (int)($invoice['branch_id'] ?? 1),
        ]);
    }

    public function prepare_godown() {
        $this->requireRouteAccess();
        $this->validateCSRF();
        $result = $this->model->prepareGodown($_POST);
        if (($result['status'] ?? '') === 'success') {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'godown_prepared', (int)($_POST['invoice_id'] ?? 0), [
                'invoice_id' => (int)($_POST['invoice_id'] ?? 0),
            ]);
        }
        $this->sendJson($result);
    }

    public function create_final_challan() {
        $this->requireRouteAccess();
        $this->validateCSRF();
        $result = $this->model->finalizeChallan($_POST);
        if (($result['status'] ?? '') === 'success') {
            $challanId = (int)($result['challan_id'] ?? 0);
            $invoiceId = (int)($result['invoice_id'] ?? $_POST['invoice_id'] ?? 0);
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'challan_completed', $challanId, [
                'challan_code'          => $result['challan_code'] ?? null,
                'invoice_id'            => $invoiceId,
                'transport_adjustment'  => $result['transport_adjustment'] ?? 0,
                'new_total'             => $result['new_total'] ?? null,
                'journal_entry_id'      => $result['journal_entry_id'] ?? null,
                'cogs_amount'           => $result['cogs_amount'] ?? null,
            ]);

            // Telegram: salesman, sales-by, and branch warehouse managers (non-blocking).
            SalesTelegramNotifier::safe(function () use ($challanId, $invoiceId, $result) {
                $payload = $this->model->getChallanTelegramPayload(
                    $challanId,
                    $invoiceId,
                    (string)($result['challan_code'] ?? ''),
                    isset($result['new_total']) ? (float)$result['new_total'] : null
                );
                if ($payload) {
                    (new SalesTelegramNotifier())->notifyChallanCreated($payload);
                }
            });
        }
        $this->sendJson($result);
    }

    public function reverse_challan() {
        $this->requireRouteAccess();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(ApiResponse::error('Invalid request method.', ApiResponse::CODE_VALIDATION));
        }
        $this->validateCSRF();
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $result = $this->model->reverseChallan($invoiceId, $reason);
        if (($result['status'] ?? '') === 'success') {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'challan_reversed', (int)($result['challan_id'] ?? 0), [
                'invoice_id'       => $invoiceId,
                'invoice_code'     => $result['invoice_code'] ?? null,
                'challan_code'     => $result['challan_code'] ?? null,
                'items_reversed'   => $result['items_reversed'] ?? 0,
                'reason'           => $reason,
                'journal_entry_id' => $result['journal_entry_id'] ?? null,
            ]);
        }
        $this->sendJson($result);
    }
    public function get_warehouses_for_product() {
        $this->guardJsonApi('challan.warehouses_for_product', 120, 60);
        $productId = (int)($_GET['product_id'] ?? 0);
        if ($productId <= 0) {
            $this->sendJson([]);
            return;
        }
        // Warehouses are always scoped to the logged-in user's session branch.
        $branchId = Helper::sessionBranchId();
        $excludeInvoiceId = (int)($_GET['invoice_id'] ?? 0) ?: null;
        $this->sendJson($this->model->getWarehousesForProduct($productId, $branchId, $excludeInvoiceId));
    }

    public function get_dispatchers() {
        $this->guardJsonApi('challan.get_dispatchers', 60, 60);
        $this->sendJson($this->model->getDispatchers());
    }

    public function export() {
    $filters = [
        'date_from'    => $_GET['date_from'] ?? null,
        'date_to'      => $_GET['date_to'] ?? null,
        'search'       => trim($_GET['search'] ?? ''),
        'status'       => $_GET['status'] ?? 'open',
        'smart_sort'   => ($_GET['smart_sort'] ?? '1') !== '0',
        'skip_default_today' => true,
    ];

    $invoices = $this->model->getFilteredChallans($filters);

    if (empty($invoices)) {
        $_SESSION['error'] = "No records found!";
        $this->redirect('challan');
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Challan_List_' . date('Y-m-d_H-i') . '.csv"');
    header('Pragma: no-cache');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['Invoice No', 'Date', 'Customer', 'Mobile', 'Sales Person', 'Status', 'Total Amount']);

    foreach ($invoices as $inv) {
        fputcsv($output, [
            $inv['invoice_code'],
            $inv['invoice_date'],
            $inv['shop_name'],
            $inv['mobile'],
            $inv['salesman_name'],
            $inv['status'],
            $inv['total_amount']
        ]);
    }

    fclose($output);
    exit;
}
}