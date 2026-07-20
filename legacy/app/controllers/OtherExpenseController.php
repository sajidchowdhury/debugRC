<?php
// app/controllers/OtherExpenseController.php

require_once '../core/BaseController.php';
require_once '../app/models/OtherExpenseModel.php';
require_once '../app/helpers/Helper.php';
require_once '../app/helpers/AccountingModuleHelper.php';
require_once '../core/UserAudit.php';

class OtherExpenseController extends BaseController {

    private OtherExpenseModel $model;
    private UserAudit $userAudit;

    public function __construct() {
        $this->requireLogin();
        $this->model = new OtherExpenseModel();
        $this->userAudit = new UserAudit();
    }

    public function index() {
        if (isset($_GET['draw'])) {
            $params = $_GET;
            if (isset($_GET['reversed']) && $_GET['reversed'] == '1') {
                $params['reversedMode'] = 'only_reversed';
            }
            header('Content-Type: application/json');
            echo json_encode($this->model->getExpensesForDataTable($params));
            exit;
        }

        $showReversed = isset($_GET['reversed']) && $_GET['reversed'] == '1';
        $today = date('Y-m-d');
        $branchId = Helper::sessionBranchId() ?: (int)($_SESSION['branch_id'] ?? 0);
        $listBranchId = ($this->model->canOverrideBranch() && $branchId <= 0) ? null : ($branchId ?: null);

        $this->view('Accounting/OtherExpense/index', [
            'title'        => $showReversed ? 'Reversed Other Expenses' : 'Other Expenses',
            'ledgers'      => $this->model->getExpenseLedgers(),
            'showReversed' => $showReversed,
            'fromDate'     => $today,
            'toDate'       => $today,
            'branch_name'  => $_SESSION['branch_name'] ?? 'Branch',
            'stats'        => $this->model->getOtherExpenseIndexStats($listBranchId),
        ]);
    }

    public function create() {
        $this->view('Accounting/OtherExpense/create', [
            'title'            => 'Record Other Expense',
            'ledgers'          => $this->model->getExpenseLedgers(),
            'banks'            => $this->model->getBanks(),
            'today'            => date('Y-m-d'),
            'branch_name'      => $_SESSION['branch_name'] ?? 'Branch',
            'min_posting_date' => AccountingModuleHelper::minPostingDateForSession(),
        ]);
    }

    public function store() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request'], 405);
            return;
        }

        try {
            $this->validateCSRF();
            $result = $this->model->createExpense($_POST);

            if (($result['status'] ?? '') === 'success') {
                $eid = (int)($result['expense_id'] ?? 0);
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'other_expense_created', $eid, [
                    'expense_code'     => $result['expense_code'] ?? '',
                    'amount'           => $result['amount'] ?? $_POST['amount'] ?? 0,
                    'journal_entry_id' => $result['journal_entry_id'] ?? null,
                ]);
                $result['redirect_url'] = BASE_URL . 'OtherExpense/details/' . $eid;
            }

            $this->sendJson($result);
        } catch (Throwable $e) {
            error_log('OtherExpense store: ' . $e->getMessage());
            $this->sendJson([
                'status'  => 'error',
                'message' => $this->safeClientMessage($e, 'Could not save expense. Please try again.'),
            ], 500);
        }
    }

    public function details($id = null) {
        if (!$id) {
            $this->redirect('OtherExpense');
            return;
        }

        $expense = $this->model->getExpenseById((int)$id);
        if (!$expense || !$this->model->userCanAccessExpense($expense)) {
            $_SESSION['error'] = 'Expense not found!';
            $this->redirect('OtherExpense');
            return;
        }

        $expenseId = (int)$id;
        $this->view('Accounting/OtherExpense/details', [
            'title'             => 'Expense — ' . ($expense['expense_code'] ?? ''),
            'expense'           => $expense,
            'journal_entry'     => $this->model->getJournalEntryForExpense($expenseId),
            'reversing_journal' => !empty($expense['is_reversed']) ? $this->model->getReversingJournalForExpense($expenseId) : null,
            'cash_ledger'       => $this->model->getCashLedgerForExpense($expenseId),
            'can_reverse'       => $this->model->canUserReverseExpense($expense),
        ]);
    }

    public function slip($id = null) {
        if (!$id) {
            $this->redirect('OtherExpense');
            return;
        }
        $expense = $this->model->getExpenseById((int)$id);
        if (!$expense || !$this->model->userCanAccessExpense($expense)) {
            $_SESSION['error'] = 'Expense not found!';
            $this->redirect('OtherExpense');
            return;
        }
        $this->view('Accounting/OtherExpense/slip', ['title' => 'Expense slip', 'expense' => $expense]);
    }

    public function reverse() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request'], 405);
            return;
        }

        try {
            $this->validateCSRF();
            $id = (int)($_POST['id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));

            if ($id <= 0) {
                $this->sendJson(['status' => 'error', 'message' => 'Expense id is required']);
                return;
            }

            $result = $this->model->reverseExpense($id, $reason);

            if (($result['status'] ?? '') === 'success') {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'other_expense_reversed', $id, ['reason' => $reason]);
                if (empty($result['redirect_url'])) {
                    $result['redirect_url'] = BASE_URL . 'OtherExpense/details/' . $id;
                }
            }

            $this->sendJson($result);
        } catch (Throwable $e) {
            error_log('OtherExpense reverse: ' . $e->getMessage());
            $this->sendJson([
                'status'  => 'error',
                'message' => $this->safeClientMessage($e, 'Could not reverse expense. Please try again.'),
            ], 500);
        }
    }

    public function audit() {
        $this->view('Accounting/OtherExpense/audit', [
            'title' => 'Other Expense Audit Logs',
            'logs'  => $this->userAudit->getRecentLogs(300, 'other_expense_'),
        ]);
    }

    protected function sendJson($data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}