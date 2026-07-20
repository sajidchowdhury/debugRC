<?php
// app/controllers/EmployeeTransactionController.php

require_once '../core/BaseController.php';
require_once '../app/models/EmployeeTransactionModel.php';
require_once '../app/helpers/Helper.php';
require_once '../app/services/Accounting/JournalPostingService.php';
require_once '../core/UserAudit.php';

class EmployeeTransactionController extends BaseController {

    private EmployeeTransactionModel $model;
    private UserAudit $userAudit;

    public function __construct() {
        $this->requireLogin();
        $this->model = new EmployeeTransactionModel();
        $this->userAudit = new UserAudit();
    }

    public function index() {
        $showReversed = isset($_GET['reversed']) && $_GET['reversed'] == '1';
        $filters = $this->resolveIndexFilters($showReversed);

        $listBranchId = $this->resolveListBranchId();
        $transactions = $this->model->getFilteredTransactions($filters, $listBranchId);

        foreach ($transactions as &$row) {
            $row['can_reverse'] = !$showReversed && $this->model->canUserReverseTransaction($row);
        }
        unset($row);

        $this->view('Accounting/employee/index', [
            'title'        => $showReversed ? 'Reversed employee transactions' : 'Employee transactions',
            'transactions' => $transactions,
            'branch_name'  => $_SESSION['branch_name'] ?? 'Branch',
            'filters'      => $filters,
            'stats'        => $this->model->getEmployeeTransactionIndexStats($listBranchId),
            'employees'    => $this->model->getEmployees(),
            'showReversed' => $showReversed,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveIndexFilters(bool $showReversed): array
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

        return [
            'date_from'        => $dateFrom,
            'date_to'          => $dateTo,
            'transaction_type' => $_GET['transaction_type'] ?? 'all',
            'status'           => $showReversed ? 'reversed' : ($_GET['status'] ?? 'all'),
            'payment_mode'     => $_GET['payment_mode'] ?? 'all',
            'employee_id'      => $_GET['employee_id'] ?? null,
        ];
    }

    private function resolveListBranchId(): ?int
    {
        $branchId = Helper::sessionBranchId() ?: (int)($_SESSION['branch_id'] ?? 0);

        return ($this->model->canOverrideBranch() && $branchId <= 0) ? null : ($branchId ?: null);
    }

    public function create() {
        $posting = new JournalPostingService();

        $this->view('Accounting/employee/create', [
            'title'                    => 'New employee transaction',
            'employees'                => $this->model->getEmployees(),
            'banks'                    => $this->model->getBanks(),
            'today'                    => date('Y-m-d'),
            'branch_name'              => $_SESSION['branch_name'] ?? 'Branch',
            'gl_preview_labels'        => $posting->getEmployeeTransactionGlPreviewLabels(),
            'employee_payable_status'  => $posting->getEmployeePayableLedgerStatus(),
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
                $tid = (int)($result['transaction_id'] ?? $result['payment_id'] ?? 0);
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'employee_transaction_created', $tid, [
                    'transaction_code' => $result['transaction_code'] ?? '',
                    'payment_code'     => $result['transaction_code'] ?? '',
                    'transaction_type' => $_POST['transaction_type'] ?? '',
                    'amount'           => $_POST['amount'] ?? 0,
                    'employee_id'      => $_POST['employee_id'] ?? 0,
                    'journal_entry_id' => $result['journal_entry_id'] ?? null,
                ]);
            }

            $this->sendJson($result);
        } catch (Throwable $e) {
            error_log('EmployeeTransaction store: ' . $e->getMessage());
            $this->sendJson([
                'status'  => 'error',
                'message' => $this->safeClientMessage($e, 'Could not save transaction. Please try again.'),
            ], 500);
        }
    }

    public function get_due() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request'], 405);
            return;
        }

        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $due = $this->model->getEmployeeDue($employee_id);

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
                $this->sendJson(['status' => 'error', 'message' => 'Transaction id is required']);
                return;
            }

            $result = $this->model->reverseTransaction($id, $reason);

            if (($result['status'] ?? '') === 'success') {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'employee_transaction_reversed', $id, ['reason' => $reason]);
                $result['redirect_url'] = BASE_URL . 'EmployeeTransaction/details/' . $id;
            }

            $this->sendJson($result);
        } catch (Throwable $e) {
            error_log('EmployeeTransaction reverse: ' . $e->getMessage());
            $this->sendJson([
                'status'  => 'error',
                'message' => $this->safeClientMessage($e, 'Could not reverse transaction. Please try again.'),
            ], 500);
        }
    }

    public function slip($id = null) {
        if (!$id) {
            $this->redirect('EmployeeTransaction');
            return;
        }

        $transaction = $this->model->getTransactionById((int)$id);
        if (!$transaction || !$this->model->userCanAccessTransaction($transaction)) {
            $_SESSION['error'] = 'Transaction not found!';
            $this->redirect('EmployeeTransaction');
            return;
        }

        $this->view('Accounting/employee/slip', [
            'title'       => 'Employee transaction slip',
            'transaction' => $transaction,
        ]);
    }

    public function details($id = null) {
        if (!$id) {
            $this->redirect('EmployeeTransaction');
            return;
        }

        $transaction = $this->model->getTransactionById((int)$id);
        if (!$transaction || !$this->model->userCanAccessTransaction($transaction)) {
            $_SESSION['error'] = 'Transaction not found!';
            $this->redirect('EmployeeTransaction');
            return;
        }

        $tid = (int)$id;

        $this->view('Accounting/employee/details', [
            'title'         => 'Transaction — ' . ($transaction['transaction_code'] ?? ''),
            'transaction'   => $transaction,
            'ledger'        => $this->model->getLedgerEntriesForTransaction($tid),
            'journal_entry' => $this->model->getJournalEntryForTransaction($tid),
            'employee_due'  => $this->model->getEmployeeDue((int)$transaction['employee_id']),
            'can_reverse'   => $this->model->canUserReverseTransaction($transaction),
        ]);
    }

    public function audit() {
        $this->view('Accounting/employee/audit', [
            'title' => 'Employee Transaction Audit Logs',
            'logs'  => $this->userAudit->getRecentLogs(300, 'employee_transaction_'),
        ]);
    }
}