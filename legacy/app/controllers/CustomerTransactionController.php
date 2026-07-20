<?php
// app/controllers/CustomerTransactionController.php

require_once '../core/BaseController.php';
require_once '../app/models/CustomerTransactionModel.php';
require_once '../app/services/Accounting/JournalPostingService.php';

require_once '../app/helpers/Helper.php';
require_once '../core/UserAudit.php';

class CustomerTransactionController extends BaseController {

    private CustomerTransactionModel $model;
    private UserAudit $userAudit;

    public function __construct() {
        $this->requireLogin();
        $this->model = new CustomerTransactionModel();
        $this->userAudit = new UserAudit();
    }

    public function index() {
        if (isset($_GET['draw'])) {
            $filters = $this->resolveIndexFilters();
            $response = $this->model->getPaymentsForDataTable(
                $_GET,
                $filters,
                $this->resolveListBranchId()
            );
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }

        $filters = $this->resolveIndexFilters();
        $listBranchId = $this->resolveListBranchId();
        $showReversed = isset($_GET['reversed']) && $_GET['reversed'] == '1';

        $filterCustomer = null;
        $customerId = (int)($filters['customer_id'] ?? 0);
        if ($customerId > 0) {
            $filterCustomer = $this->model->Get_Customer_By_Id($customerId);
        }

        $this->view('Accounting/customer/index', [
            'title'          => $showReversed ? 'Reversed customer payments' : 'Customer payments',
            'branch_name'    => $_SESSION['branch_name'] ?? 'Branch',
            'filters'        => $filters,
            'filterCustomer' => $filterCustomer,
            'stats'          => $this->model->getCustomerTransactionIndexStats($listBranchId),
            'showReversed'   => $showReversed,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveIndexFilters(): array
    {
        $today = date('Y-m-d');

        if (!isset($_GET['date_from']) && !isset($_GET['date_to'])) {
            $dateFrom = $today;
            $dateTo = $today;
        } else {
            $dateFrom = trim((string)($_GET['date_from'] ?? '')) ?: null;
            $dateTo = trim((string)($_GET['date_to'] ?? '')) ?: null;
            if ($dateFrom === null && $dateTo === null) {
                $dateFrom = $today;
                $dateTo = $today;
            }
        }

        $customerId = trim((string)($_GET['customer_id'] ?? ''));

        return [
            'date_from'        => $dateFrom,
            'date_to'          => $dateTo,
            'transaction_type' => $_GET['transaction_type'] ?? 'all',
            'status'           => $_GET['status'] ?? 'all',
            'payment_mode'     => $_GET['payment_mode'] ?? 'all',
            'customer_id'      => $customerId !== '' ? (int)$customerId : null,
        ];
    }

    private function resolveListBranchId(): ?int
    {
        $branchId = Helper::sessionBranchId() ?: (int)($_SESSION['branch_id'] ?? 0);

        return ($this->model->canOverrideBranch() && $branchId <= 0) ? null : ($branchId ?: null);
    }

    public function search_customer() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request'], 405);
            return;
        }

        $term = trim((string)($_GET['term'] ?? ''));
        $this->sendJson($this->model->Search_Customers($term));
    }

    public function create() {
        $preselectCustomer = null;
        $preselectId = (int)($_GET['customer_id'] ?? 0);
        if ($preselectId > 0) {
            $row = $this->model->Get_Customer_By_Id($preselectId);
            if ($row && !empty($row['is_active'])) {
                $preselectCustomer = $row;
            }
        }

        $posting = new JournalPostingService();

        $this->view('Accounting/customer/create', [
            'title'             => 'New customer payment',
            'preselectCustomer' => $preselectCustomer,
            'banks'             => $this->model->getBanks(),
            'employees'         => $this->model->getEmployeesForUser(),
            'today'             => date('Y-m-d'),
            'branch_name'       => $_SESSION['branch_name'] ?? 'Branch',
            'gl_preview_labels' => $posting->getCustomerTransactionGlPreviewLabels(),
        ]);
    }

    public function store() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request'], 405);
            return;
        }

        try {
            $this->validateCSRF();
            $result = $this->model->createTransaction($_POST);

            if (($result['status'] ?? '') === 'success') {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'customer_payment_created', (int)($result['payment_id'] ?? 0), [
                    'payment_code'       => $result['payment_code'] ?? '',
                    'transaction_type'   => $_POST['transaction_type'] ?? 'receive',
                    'amount'             => $_POST['amount'] ?? 0,
                    'customer_id'        => $_POST['customer_id'] ?? 0,
                    'journal_entry_id'   => $result['journal_entry_id'] ?? null,
                ]);
            }

            $this->sendJson($result);
        } catch (Throwable $e) {
            error_log('CustomerTransaction store: ' . $e->getMessage());
            $this->sendJson([
                'status'  => 'error',
                'message' => $this->safeClientMessage($e, 'Could not save payment. Please try again.'),
            ], 500);
        }
    }

    public function get_due() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request'], 405);
            return;
        }

        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $due = $this->model->getCustomerDue($customer_id);

        $this->sendJson([
            'status'        => 'success',
            'due_balance'   => $due,
            'due_formatted' => number_format($due, 2),
        ]);
    }

    public function reverse() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request'], 405);
            return;
        }

        try {
            $this->validateCSRF();
            $id = (int)($_POST['id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? $_POST['reverse_reason'] ?? ''));

            if ($id <= 0) {
                $this->sendJson(['status' => 'error', 'message' => 'Payment id is required']);
                return;
            }

            $result = $this->model->reverseTransaction($id, $reason);

            if (($result['status'] ?? '') === 'success') {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'customer_payment_reversed', $id, ['reason' => $reason]);
                $result['redirect_url'] = BASE_URL . 'CustomerTransaction/details/' . $id;
            }

            $this->sendJson($result);
        } catch (Throwable $e) {
            error_log('CustomerTransaction reverse: ' . $e->getMessage());
            $this->sendJson([
                'status'  => 'error',
                'message' => $this->safeClientMessage($e, 'Could not reverse payment. Please try again.'),
            ], 500);
        }
    }

    public function slip($id = null) {
        if (!$id) {
            $this->redirect('CustomerTransaction');
            return;
        }

        $transaction = $this->model->getTransactionById((int)$id);
        if (!$transaction || !$this->model->userCanAccessPayment($transaction)) {
            $_SESSION['error'] = 'Transaction not found!';
            $this->redirect('CustomerTransaction');
            return;
        }

        $this->view('Accounting/customer/slip', [
            'title'       => 'Payment slip',
            'transaction' => $transaction,
        ]);
    }

    public function details($id = null) {
        if (!$id) {
            $this->redirect('CustomerTransaction');
            return;
        }

        $transaction = $this->model->getTransactionById((int)$id);
        if (!$transaction || !$this->model->userCanAccessPayment($transaction)) {
            $_SESSION['error'] = 'Transaction not found!';
            $this->redirect('CustomerTransaction');
            return;
        }

        $paymentId = (int)$id;

        $this->view('Accounting/customer/details', [
            'title'        => 'Payment — ' . ($transaction['payment_code'] ?? ''),
            'transaction'  => $transaction,
            'ledger'       => $this->model->getLedgerEntriesForPayment($paymentId),
            'journal_entry'=> $this->model->getJournalEntryForPayment($paymentId),
            'settlements'  => $this->model->getPaymentSettlements($paymentId),
            'customer_due' => $this->model->getCustomerDue((int)$transaction['customer_id']),
            'can_reverse'  => $this->model->canUserReversePayment($transaction),
        ]);
    }

    public function audit() {
        $this->view('Accounting/customer/audit', [
            'title' => 'Customer Payment Audit Logs',
            'logs'  => $this->userAudit->getRecentLogs(300, 'customer_payment_'),
        ]);
    }
}
