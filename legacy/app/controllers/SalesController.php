<?php
// app/controllers/SalesController.php

require_once '../core/BaseController.php';
require_once '../app/models/SalesModel.php';
require_once '../core/UserAudit.php';
require_once '../core/Logger.php';
require_once '../app/helpers/Helper.php';
require_once __DIR__ . '/../services/Sales/SalesCartService.php';
require_once __DIR__ . '/../services/Sales/SalesInvoiceService.php';
require_once __DIR__ . '/../services/Sales/SalesPaymentService.php';
require_once __DIR__ . '/../services/Notification/FcmTokenService.php';
require_once __DIR__ . '/../services/Notification/SalesNotificationService.php';
require_once __DIR__ . '/../services/Notification/SalesTelegramNotifier.php';
require_once __DIR__ . '/../services/Accounting/ReconciliationService.php';
require_once __DIR__ . '/../helpers/SalesGlAuditHelper.php';

class SalesController extends BaseController {

    private SalesModel $model;
    private SalesCartService $cartService;
    private SalesInvoiceService $invoiceService;
    private SalesPaymentService $paymentService;
    private UserAudit $userAudit;
    private SalesNotificationService $salesNotifications;
    private FcmTokenService $fcmTokens;

    public function __construct() {
        $this->requireLogin();
        $this->model = new SalesModel();
        $this->cartService = new SalesCartService($this->model->getDatabase());
        $this->invoiceService = new SalesInvoiceService($this->model->getDatabase());
        $this->paymentService = new SalesPaymentService($this->model->getDatabase());
        $this->userAudit = new UserAudit();
        $this->salesNotifications = new SalesNotificationService();
        $this->fcmTokens = new FcmTokenService();
    }

    private function auditLog(string $action, ?int $entityId, array $details = []): void
    {
        $this->userAudit->log($_SESSION['user_id'] ?? 0, $action, $entityId, $details);
    }

    private function filterSalesAuditLogs(int $limit = 300): array
    {
        $all = $this->userAudit->getRecentLogs(500);
        $prefixes = ['sale_', 'godown_', 'challan_', 'payment_'];
        $filtered = [];
        foreach ($all as $entry) {
            $action = $entry['action'] ?? '';
            foreach ($prefixes as $p) {
                if (stripos($action, $p) === 0) {
                    $filtered[] = $entry;
                    break;
                }
            }
            if (count($filtered) >= $limit) {
                break;
            }
        }
        return $filtered;
    }

 
    // ====================== SALES INVOICE ======================

    public function create() {
        $sessionBranchId = Helper::sessionBranchId();
        $sessionBranch = $this->model->getBranch($sessionBranchId);

        $data = [
            'title' => 'Create Sales Invoice',
            'can_override_branch' => $this->model->canOverrideBranch(),
            'session_branch_id' => $sessionBranchId,
            'session_branch_name' => $sessionBranch['branch_name'] ?? 'Branch',
        ];
        $this->view('sales/create', $data);
    }

    // Search endpoints
    public function search_customer() {
        $this->guardJsonApi('sales.search_customer', 90, 60);
        $term = $_GET['term'] ?? '';
        $this->sendJson($this->model->searchCustomers($term));
    }

    public function search_product() {
        $this->guardJsonApi('sales.search_product', 90, 60);
        $term = $_GET['term'] ?? '';
        $branch_id = $this->model->resolveBranchIdForRead((int)($_GET['branch_id'] ?? 0));
        $this->sendJson($this->model->searchProductsWithStock($term, $branch_id));
    }

    /**
     * Exact product_code match for barcode scanners.
     */
    public function product_by_code() {
        $this->guardJsonApi('sales.product_by_code', 120, 60);
        $code = trim((string)($_GET['code'] ?? ''));
        $branch_id = $this->model->resolveBranchIdForRead((int)($_GET['branch_id'] ?? 0));

        if ($code === '') {
            $this->sendJson(['status' => 'error', 'message' => 'Product code required']);
            return;
        }

        $product = $this->model->getProductByExactCode($code, $branch_id);
        if (!$product) {
            $this->sendJson(['status' => 'not_found', 'message' => 'No product with this code']);
            return;
        }

        $this->sendJson(['status' => 'success', 'data' => $product]);
    }



    public function get_branch() {
        $this->guardJsonApi('sales.get_branch', 60, 60);
        if (!empty($_GET['for_stock'])) {
            $this->sendJson($this->model->getAllBranches());
            return;
        }

        $showAll = isset($_GET['all']) && $_GET['all'] == 1;

        if ($showAll && $this->model->canOverrideBranch()) {
            $this->sendJson($this->model->getAllBranches());
        } else {
            $branch_id = Helper::sessionBranchId();
            $branch = $this->model->getBranch($branch_id);
            $this->sendJson($branch ? [$branch] : []);
        }
    }

    /**
     * Branch-level available stock for a product (logged-in / selected branch).
     */
    public function product_stock_at_branch() {
        $this->guardJsonApi('sales.product_stock', 120, 60);
        $product_id = (int)($_GET['product_id'] ?? 0);
        $branch_id  = (int)($_GET['branch_id'] ?? 0);

        if ($product_id <= 0) {
            $this->sendJson(['status' => 'error', 'message' => 'Product required']);
            return;
        }

        $this->sendJson($this->model->getProductAvailableAtBranch($product_id, $branch_id));
    }

    public function get_warehouse_stock() {
        $this->guardJsonApi('sales.warehouse_stock', 120, 60);
        $product_id = (int)($_GET['product_id'] ?? 0);
        $branch_id  = $this->model->resolveBranchIdForRead((int)($_GET['branch_id'] ?? 0));

        $this->sendJson(
            $this->model->getWarehouseStockForProduct($product_id, $branch_id)
        );
    }
    public function get_employees() {
        $this->guardJsonApi('sales.get_employees', 60, 60);
        $this->sendJson($this->model->getSalesEmployees());
    }

    // Customer Due Details
    public function customer_details() {
        $this->guardJsonApi('sales.customer_details', 120, 60);
        $customer_id = $_GET['customer_id'] ?? 0;
        if (empty($customer_id)) {
            $this->sendJson([]);
        }

        $data = $this->model->getCustomerDetails($customer_id);
        $this->sendJson($data ?: [
            'customer_name' => '', 'shop_name' => '', 'address' => '',
            'mobile' => '', 'credit_limit' => 0, 'recent_due' => 0, 'due_left' => 0
        ]);
    }

    // Cart Operations
    public function add_to_cart() {
        $this->validateCSRF();
        $this->sendJson($this->cartService->addToCart($_POST));
    }

    public function load_cart() {
        $this->validateCSRF();
        $customer_id = $_POST['customer_id'] ?? 0;
        $branch_id = (int)($_POST['branch_id'] ?? $_SESSION['branch_id'] ?? 0);
        $excludeInvoiceId = !empty($_POST['exclude_invoice_id']) ? (int)$_POST['exclude_invoice_id'] : null;
        $this->sendJson($this->cartService->loadCart($customer_id, $branch_id, $excludeInvoiceId));
    }

    public function validate_cart() {
        $this->validateCSRF();
        $this->sendJson($this->cartService->validateCartForSubmit($_POST));
    }

    public function hydrate_edit_cart() {
        $this->validateCSRF();
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $customerId = (int)($_POST['customer_id'] ?? 0);

        if ($invoiceId <= 0 || $customerId <= 0) {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid invoice or customer.']);
        }

        $invoice = $this->invoiceService->getInvoiceForEdit($invoiceId);
        if (!$invoice) {
            $this->sendJson(['status' => 'error', 'message' => 'Invoice not found or cannot be edited']);
        }

        $blockReason = $this->invoiceService->getSalesEditBlockReason($invoice);
        if ($blockReason !== '') {
            $this->sendJson(['status' => 'error', 'message' => $blockReason]);
        }

        if ((int)($invoice['customer_id'] ?? 0) !== $customerId) {
            $this->sendJson(['status' => 'error', 'message' => 'Customer does not match this invoice.']);
        }

        $this->sendJson($this->cartService->hydrateEditCart($customerId, $invoice['items'] ?? []));
    }

    public function list_draft_carts() {
        $this->guardJsonApi('sales.list_draft_carts', 60, 60);
        $this->sendJson($this->cartService->listDraftCarts());
    }

    public function clear_tab_cart() {
        $this->validateCSRF();
        $this->sendJson($this->cartService->clearTabCart($_POST));
    }

    public function delete_tab_cart() {
        $this->validateCSRF();
        $this->sendJson($this->cartService->deleteTabCart($_POST));
    }

    public function delete_from_cart() {
        $this->validateCSRF();
        $this->sendJson($this->cartService->deleteFromCart($_POST));
    }

    public function update_cart_item() {
        $this->validateCSRF();
        $this->sendJson($this->cartService->updateCartItem($_POST));
    }

    public function save_fcm_token() {
        $this->validateCSRF();
        $this->guardJsonApi('sales.save_fcm_token', 30, 60);

        try {
            $input = $this->getJsonInput();
            $token = trim($input['token'] ?? $_POST['token'] ?? '');
            $deviceInfo = trim($input['device_info'] ?? '');
            $userId = (int)($_SESSION['user_id'] ?? 0);

            $this->sendJson($this->fcmTokens->saveToken($userId, $token, $deviceInfo));
        } catch (Throwable $e) {
            Logger::error('FCM token save error', ['error' => $e->getMessage()]);
            $this->sendJson([
                'status'  => 'error',
                'message' => $this->safeClientMessage($e, 'Could not save device token.'),
            ], 500);
        }
    }

    // Finalize / Update
    public function final_sales() {
        $this->requireRouteAccess();
        $this->validateCSRF();
        try {
            $result = $this->invoiceService->finalizeSales($_POST);

            if ($result['status'] === 'success') {
                $this->auditLog('sale_created', (int)($result['invoice_id'] ?? 0), [
                    'invoice_code'     => $result['invoice_code'] ?? null,
                    'customer_id'      => (int)($_POST['customer_id'] ?? 0),
                    'total_amount'     => $_POST['total_amount'] ?? 0,
                    'journal_entry_id' => $result['journal_entry_id'] ?? null,
                ]);

                $this->salesNotifications->notifyNewSalesInvoice(
                    (int)$result['invoice_id'],
                    $result['invoice_code'] ?? '',
                    (float)($_POST['total_amount'] ?? 0)
                );

                SalesTelegramNotifier::safe(function () use ($result) {
                    (new SalesTelegramNotifier())->notifyInvoiceCreated((int)$result['invoice_id']);
                });
            }

            if ($result['status'] === 'success' && !empty($result['credit_limit_override_used'])) {
                $this->auditLog('sale_credit_limit_override', (int)($_POST['customer_id'] ?? 0), [
                    'invoice_code'    => $result['invoice_code'] ?? null,
                    'invoice_id'      => (int)($result['invoice_id'] ?? 0),
                    'override_reason' => $result['override_reason'] ?? '',
                    'total_amount'    => $_POST['total_amount'] ?? 0,
                ]);
            }

            $this->sendJson($result);
        } catch (Throwable $e) {
            $this->sendJson([
                'status'  => 'error',
                'message' => $this->safeClientMessage($e, 'Could not finalize invoice.'),
            ], 500);
        }
    }

    public function update($id = null) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
            $this->requireRouteAccess();
            $this->validateCSRF();
            $result = $this->invoiceService->updateExistingInvoice($id, $_POST);

            if ($result['status'] === 'success') {
                $this->auditLog('sale_updated', (int)$id, [
                    'total_amount' => $_POST['total_amount'] ?? null,
                ]);
            }

            if ($result['status'] === 'success' && !empty($result['credit_limit_override_used'])) {
                $this->auditLog('sale_credit_limit_override', (int)($_POST['customer_id'] ?? 0), [
                    'invoice_id'      => (int)$id,
                    'action'          => 'invoice_edit',
                    'override_reason' => $result['override_reason'] ?? '',
                ]);
            }

            $this->sendJson($result);
        } else {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request']);
        }
    }

    // Today & Management
public function today() {
    $defaultTo = date('Y-m-d');
    $defaultFrom = date('Y-m-d', strtotime('-6 days'));

    $filters = [
        'date_from'      => $_GET['date_from'] ?? $defaultFrom,
        'date_to'        => $_GET['date_to'] ?? $defaultTo,
        'challan_status' => $_GET['challan_status'] ?? 'all',
        'date_preset'    => $_GET['preset'] ?? 'week',
        'search'         => trim($_GET['q'] ?? ''),
        'smart_sort'     => ($_GET['smart_sort'] ?? '1') !== '0',
    ];

    $sessionBranchId = Helper::sessionBranchId();
    $sessionBranch = $this->model->getBranch($sessionBranchId);

    $data = [
        'title'               => "Today's Sales",
        'filters'             => $filters,
        'session_branch_name' => $sessionBranch['branch_name'] ?? 'Branch',
    ];

    $this->view('sales/today', $data);
}

    public function today_filter_summary() {
        $this->guardJsonApi('sales.today_filter_summary', 120, 60);
        $defaultTo = date('Y-m-d');
        $defaultFrom = date('Y-m-d', strtotime('-6 days'));
        $filters = [
            'date_from' => $_GET['date_from'] ?? $defaultFrom,
            'date_to'   => $_GET['date_to'] ?? $defaultTo,
            'search'    => trim($_GET['search'] ?? ''),
            'skip_default_today' => true,
        ];
        $this->sendJson($this->invoiceService->getTodayFilterSummary($filters));
    }

    /**
     * Cancel stale draft invoices (releases pipeline). Admin/manager only — optional cron; not used on Today's Sales UI.
     */
    public function cancel_stale_drafts()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'POST required']);
            return;
        }
        $this->guardJsonApi('sales.cancel_stale_drafts', 30, 60);
        $days = isset($_POST['days']) ? max(1, (int)$_POST['days']) : null;
        $branchId = Helper::sessionBranchId() ?: null;
        $result = $this->model->cancelStaleDraftInvoices($days, $branchId);
        $result['remaining'] = $this->model->countStaleDraftInvoices($days, $branchId);
        $this->sendJson($result);
    }

    /**
     * DataTables server-side JSON for sales/today (Phase 6).
     */
    public function datatable_invoices() {
        $this->guardJsonApi('sales.datatable_invoices', 180, 60);
        $draw   = (int)($_GET['draw'] ?? 1);
        $start  = (int)($_GET['start'] ?? 0);
        $length = (int)($_GET['length'] ?? 25);
        if ($length < 1 || $length > 200) {
            $length = 25;
        }

        $orderColumnIndex = (int)($_GET['order'][0]['column'] ?? 2);
        $orderDir         = $_GET['order'][0]['dir'] ?? 'desc';
        $searchValue      = trim($_GET['search']['value'] ?? '');

        $defaultTo = date('Y-m-d');
        $defaultFrom = date('Y-m-d', strtotime('-6 days'));

        $filters = [
            'date_from'      => $_GET['date_from'] ?? $defaultFrom,
            'date_to'        => $_GET['date_to'] ?? $defaultTo,
            'challan_status' => $_GET['challan_status'] ?? 'all',
            'smart_sort'     => ($_GET['smart_sort'] ?? '1') !== '0',
            'skip_default_today' => true,
        ];

        header('Content-Type: application/json; charset=utf-8');

        try {
            $result = $this->invoiceService->getTodayInvoicesDatatable(
                $filters,
                $start,
                $length,
                $orderColumnIndex,
                $orderDir,
                $searchValue
            );

            echo json_encode([
                'draw'            => $draw,
                'recordsTotal'    => $result['total'],
                'recordsFiltered' => $result['filtered'],
                'data'            => $result['data'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            Logger::error('datatable_invoices failed', ['message' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode([
                'draw'            => $draw,
                'recordsTotal'    => 0,
                'recordsFiltered' => 0,
                'data'            => [],
                'error'           => $this->safeClientMessage($e, 'Could not load invoices.'),
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    public function call_it_a_day() {
        $this->validateCSRF();
        $invoice_ids = $_POST['invoice_ids'] ?? [];
        if (!is_array($invoice_ids)) $invoice_ids = [$invoice_ids];

        $branch_id = Helper::sessionBranchId();
        $result = $this->invoiceService->callItADay($invoice_ids, $branch_id);
        if (($result['status'] ?? '') === 'success') {
            $this->auditLog('sale_call_a_day', null, [
                'invoice_ids' => $invoice_ids,
                'count'       => count($invoice_ids),
            ]);
        }
        $this->sendJson($result);
    }

    public function delete_invoice() {
        $this->requireRouteAccess();
        $this->validateCSRF();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid invoice ID']);
        }
        $result = $this->invoiceService->deleteInvoice($id);
        if (($result['status'] ?? '') === 'success') {
            $this->auditLog('sale_deleted', $id, [
                'message' => $result['message'] ?? '',
            ]);
        }
        $this->sendJson($result);
    }

    // Edit Mode
    public function edit($id = null) {
        if (!$id) $this->redirect('sales/today');

        $invoice = $this->invoiceService->getInvoiceForEdit($id);
        if (!$invoice || $invoice['status'] !== 'draft') {
            $this->abortPage('Only draft invoices can be edited.');
        }

        $editBlockedReason = $this->invoiceService->getSalesEditBlockReason($invoice);

        $sessionBranchId = Helper::sessionBranchId();
        $sessionBranch = $this->model->getBranch($sessionBranchId);

        $data = [
            'title'                 => 'Edit Invoice #' . $invoice['invoice_code'],
            'invoice'               => $invoice,
            'items'                 => $invoice['items'] ?? [],
            'edit_blocked_reason'   => $editBlockedReason,
            'can_override_branch'   => $this->model->canOverrideBranch(),
            'session_branch_id'     => $sessionBranchId,
            'session_branch_name'   => $sessionBranch['branch_name'] ?? 'Branch',
        ];
        $this->view('sales/edit', $data);
    }

    public function get_invoice_for_edit($id = null) {
        $this->guardJsonApi('sales.get_invoice_for_edit', 120, 60);
        if (!$id) {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid ID']);
        }

        $invoice = $this->invoiceService->getInvoiceForEdit($id);
        if (!$invoice) {
            $this->sendJson(['status' => 'error', 'message' => 'Invoice not found or cannot be edited']);
        }

        $blockReason = $this->invoiceService->getSalesEditBlockReason($invoice);
        if ($blockReason !== '') {
            $this->sendJson(['status' => 'error', 'message' => $blockReason]);
        }

        $this->sendJson([
            'status'          => 'success',
            'customer_id'     => (int)$invoice['customer_id'],
            'branch_id'       => (int)$invoice['branch_id'],
            'salesman_id'     => (int)$invoice['salesman_id'],
            'sales_person'    => (int)($invoice['sales_person'] ?? 0),
            'invoice_date'    => $invoice['invoice_date'] ?? null,
            'narration'       => $invoice['narration'] ?? '',
            'transport_cost'  => (float)($invoice['transport_cost'] ?? 0),
            'discount'        => (float)($invoice['discount'] ?? 0),
            'invoice_code'    => $invoice['invoice_code'] ?? '',
            'items'           => $invoice['items'] ?? [],
        ]);
    }

    // Receipt & Payment
    public function receive_modal($id = null) {
        if (!$id) {
            echo "<p class='text-danger p-4'>Invalid Invoice ID</p>";
            exit;
        }

        $ctx = $this->paymentService->getPaymentReceiptData((int)$id);
        if (!$ctx) {
            echo "<p class='text-danger p-4'>Invoice not found!</p>";
            exit;
        }

        $invoice = $ctx['invoice'];
        $invoice['receive_amount'] = $ctx['paid_total'];
        $payments = $ctx['payments'] ?? [];
        $paidTotal = $ctx['paid_total'];
        $balanceDue = $ctx['balance_due'];

        require_once '../app/models/BankModel.php';
        $bankModel = new BankModel();
        $banks = $bankModel->getAllActiveBanks();

        include '../app/views/sales/receive_modal.php';
        exit;
    }

    public function save_payment() {
        $this->requireRouteAccess();
        try {
            $this->validateCSRF();
            $result = $this->paymentService->recordCustomerPayment($_POST);
            if (($result['status'] ?? '') === 'success') {
                $this->auditLog('payment_received', (int)($result['payment_id'] ?? 0), [
                    'payment_code'     => $result['payment_code'] ?? null,
                    'invoice_id'       => (int)($_POST['invoice_id'] ?? 0),
                    'customer_id'      => (int)($_POST['customer_id'] ?? 0),
                    'amount'           => (float)($_POST['receive_amount'] ?? 0),
                    'payment_mode'     => $_POST['payment_mode'] ?? null,
                    'journal_entry_id' => $result['journal_entry_id'] ?? null,
                ]);

                $paymentId = (int)($result['payment_id'] ?? 0);
                $invoiceId = (int)($result['invoice_id'] ?? $_POST['invoice_id'] ?? 0);
                SalesTelegramNotifier::safe(function () use ($paymentId, $invoiceId) {
                    (new SalesTelegramNotifier())->notifyTodayInvoicePayment($paymentId, $invoiceId);
                });

                $result['success'] = true;
            }
            $this->sendJson($result);
        } catch (Throwable $e) {
            Logger::error('save_payment failed', ['message' => $e->getMessage()]);
            $this->sendJson([
                'status'  => 'error',
                'message' => $this->safeClientMessage($e, 'Payment could not be saved.'),
            ], 500);
        }
    }

    public function reverse_payment() {
        $this->requireRouteAccess();
        $this->validateCSRF();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request method.']);
            return;
        }

        $payment_id = (int)($_POST['payment_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        $result = $this->paymentService->reverseCustomerPayment($payment_id, $reason);
        if (($result['status'] ?? '') === 'success') {
            $this->auditLog('payment_reversed', $payment_id, [
                'payment_code' => $result['payment_code'] ?? null,
                'amount'       => $result['amount'] ?? null,
                'invoice_ids'  => $result['invoice_ids'] ?? [],
                'reason'       => $reason,
            ]);
            $result['success'] = true;
        }
        $this->sendJson($result);
    }

    /**
     * Bangla end-user guideline for the sales ecosystem (menu names in English).
     * URL: sales/guide — shareable link for all logged-in users.
     */
    public function guide() {
        $this->view('sales/guide', [
            'title' => 'Sales Guideline',
        ]);
    }

    /**
     * Go-live checklist for managers, finance, warehouse, and IT.
     * URL: sales/go_live_checklist
     */
    public function go_live_checklist() {
        $this->view('sales/go_live_checklist', [
            'title' => 'Sales Go-Live Checklist',
        ]);
    }

    public function audit() {
        $this->view('sales/audit', [
            'title' => 'Sales Audit Logs',
            'logs'  => $this->filterSalesAuditLogs(300),
        ]);
    }

    /**
     * Legacy URL — redirects to Reconciliation hub (Phase 4B).
     * URL: sales/reconcile
     */
    public function reconcile() {
        $this->requireRouteAccess();
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        $target = BASE_URL . 'Reconciliation/index' . ($qs !== '' ? '?' . $qs : '');
        header('Location: ' . $target);
        exit;
    }

    public function print_receipt($id = null) {
        if (!$id) {
            $this->abortPage('Invalid invoice.');
        }

        $ctx = $this->paymentService->getPaymentReceiptData((int)$id);
        if (!$ctx) {
            $this->abortPage('Invoice not found or access denied.');
        }

        $invoice = $ctx['invoice'];
        $this->view('sales/print_receipt', [
            'title'              => 'Payment Receipt — ' . ($invoice['invoice_code'] ?? ''),
            'invoice'            => $invoice,
            'payments'           => $ctx['payments'],
            'paid_total'         => $ctx['paid_total'],
            'balance_due'        => $ctx['balance_due'],
            'is_fully_paid'      => $ctx['is_fully_paid'],
            'branch_id'          => (int)($invoice['branch_id'] ?? 1),
            'highlight_payment_id' => (int)($_GET['payment_id'] ?? 0),
        ]);
    }

    public function invoice_copy($id = null) {
        if (!$id) {
            $this->abortPage('Invoice ID required.');
        }

        $invoice = $this->invoiceService->getInvoiceById($id);
        if (!$invoice) {
            $this->abortPage('Invoice not found.');
        }

        $items = $this->invoiceService->getInvoiceItems($id);
        $due   = $this->paymentService->getCustomerDueBreakdown($invoice['customer_id'], $id);

        $itemsPerPage = 17;
        $itemPages = array_chunk($items, $itemsPerPage);
        if (empty($itemPages)) {
            $itemPages = [[]];
        }

        $subTotal = 0.0;
        foreach ($items as $item) {
            $subTotal += (float)($item['qty'] ?? 0) * (float)($item['rate'] ?? 0);
        }

        $data = [
            'title'         => 'Invoice #' . $invoice['invoice_code'],
            'invoice'       => $invoice,
            'items'         => $items,
            'item_pages'    => $itemPages,
            'items_per_page'=> $itemsPerPage,
            'due'           => $due,
            'subtotal'      => $subTotal,
            'transport'     => (float)($invoice['transport_cost'] ?? 0),
            'discount'      => (float)($invoice['discount'] ?? 0),
            'grand_total'   => (float)($invoice['total_amount'] ?? 0),
            'branch_id'     => (int)($invoice['branch_id'] ?? 1),
        ];
        $this->view('sales/invoice_copy', $data);
    }

    /**
     * Posted invoice GL audit detail (Phase 5A).
     * URL: sales/show/{id}
     */
    public function show($id = null)
    {
        if (!$id) {
            $this->abortPage('Invoice ID required.');
        }

        $invoice = $this->invoiceService->getInvoiceById((int)$id);
        if (!$invoice) {
            $this->abortPage('Invoice not found.');
        }

        $invoiceId = (int)$invoice['id'];
        $this->view('sales/show', [
            'title'          => 'Invoice — ' . ($invoice['invoice_code'] ?? ''),
            'invoice'        => $invoice,
            'journal_blocks' => SalesGlAuditHelper::invoiceJournalBlocks($invoice),
            'challans'       => SalesGlAuditHelper::getInvoiceChallans($invoiceId),
            'payments'       => SalesGlAuditHelper::getInvoicePayments($invoiceId),
        ]);
    }

    public function export() {
    $this->requireRouteAccess();
    $filters = [
        'date_from'       => $_GET['date_from'] ?? null,
        'date_to'         => $_GET['date_to'] ?? null,
        'search'          => trim($_GET['search'] ?? ''),
        'challan_status'  => $_GET['challan_status'] ?? 'all',
        'smart_sort'      => ($_GET['smart_sort'] ?? '1') !== '0',
        'skip_default_today' => true,
    ];

    $invoices = $this->invoiceService->getFilteredTodayInvoices($filters);

    if (empty($invoices)) {
        $_SESSION['error'] = "No records found!";
        $this->redirect('sales/today');
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Today_Invoices_' . date('Y-m-d_H-i') . '.csv"');
    header('Pragma: no-cache');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, [
        'Invoice Code', 'Date', 'Customer', 'Mobile', 'Branch', 
        'Sales Person', 'Total Amount', 'Status', 'Challan Status'
    ]);

    foreach ($invoices as $inv) {
        fputcsv($output, [
            $inv['invoice_code'],
            $inv['invoice_date'],
            $inv['shop_name'] ?? $inv['customer_name'],
            $inv['mobile'],
            $inv['branch_name'],
            $inv['salesman_name'],
            $inv['total_amount'],
            $inv['status'],
            $inv['call_a_day'] ? 'Called' : 'Today'
        ]);
    }

    fclose($output);
    exit;
}


}