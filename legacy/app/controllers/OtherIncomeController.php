<?php
// app/controllers/OtherIncomeController.php

require_once '../core/BaseController.php';
require_once '../app/models/OtherIncomeModel.php';
require_once '../app/helpers/Helper.php';
require_once '../core/UserAudit.php';

class OtherIncomeController extends BaseController {

    private OtherIncomeModel $model;
    private UserAudit $userAudit;

    public function __construct() {
        $this->requireLogin();
        $this->model = new OtherIncomeModel();
        $this->userAudit = new UserAudit();
    }

    public function index() {
        if (isset($_GET['draw'])) {
            $params = $_GET;
            if (isset($_GET['reversed']) && $_GET['reversed'] == '1') {
                $params['reversedMode'] = 'only_reversed';
            }
            header('Content-Type: application/json');
            echo json_encode($this->model->getIncomesForDataTable($params));
            exit;
        }

        $showReversed = isset($_GET['reversed']) && $_GET['reversed'] == '1';
        $today = date('Y-m-d');
        $branchId = Helper::sessionBranchId() ?: (int)($_SESSION['branch_id'] ?? 0);
        $listBranchId = ($this->model->canOverrideBranch() && $branchId <= 0) ? null : ($branchId ?: null);

        $this->view('Accounting/OtherIncome/index', [
            'title'        => $showReversed ? 'Reversed Other Income' : 'Other Income',
            'ledgers'      => $this->model->getIncomeLedgers(),
            'showReversed' => $showReversed,
            'fromDate'     => $today,
            'toDate'       => $today,
            'branch_name'  => $_SESSION['branch_name'] ?? 'Branch',
            'stats'        => $this->model->getOtherIncomeIndexStats($listBranchId),
        ]);
    }

    public function create() {
        $this->view('Accounting/OtherIncome/create', [
            'title'       => 'Record Other Income',
            'ledgers'     => $this->model->getIncomeLedgers(),
            'banks'       => $this->model->getBanks(),
            'today'       => date('Y-m-d'),
            'branch_name' => $_SESSION['branch_name'] ?? 'Branch',
        ]);
    }

    public function store() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request'], 405);
            return;
        }

        try {
            $this->validateCSRF();
            $result = $this->model->createIncome($_POST);

            if (($result['status'] ?? '') === 'success') {
                $iid = (int)($result['income_id'] ?? 0);
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'other_income_created', $iid, [
                    'income_code'      => $result['income_code'] ?? '',
                    'amount'           => $result['amount'] ?? $_POST['amount'] ?? 0,
                    'journal_entry_id' => $result['journal_entry_id'] ?? null,
                ]);
                $result['redirect_url'] = BASE_URL . 'OtherIncome/details/' . $iid;
            }

            $this->sendJson($result);
        } catch (Throwable $e) {
            error_log('OtherIncome store: ' . $e->getMessage());
            $this->sendJson([
                'status'  => 'error',
                'message' => $this->safeClientMessage($e, 'Could not save income. Please try again.'),
            ], 500);
        }
    }

    public function details($id = null) {
        if (!$id) {
            $this->redirect('OtherIncome');
            return;
        }

        $income = $this->model->getIncomeById((int)$id);
        if (!$income || !$this->model->userCanAccessIncome($income)) {
            $_SESSION['error'] = 'Income not found!';
            $this->redirect('OtherIncome');
            return;
        }

        $incomeId = (int)$id;
        $this->view('Accounting/OtherIncome/details', [
            'title'            => 'Income — ' . ($income['income_code'] ?? ''),
            'income'           => $income,
            'journal_entry'    => $this->model->getJournalEntryForIncome($incomeId),
            'reversing_journal'=> !empty($income['is_reversed']) ? $this->model->getReversingJournalForIncome($incomeId) : null,
            'cash_ledger'      => $this->model->getCashLedgerForIncome($incomeId),
            'can_reverse'      => $this->model->canUserReverseIncome($income),
        ]);
    }

    public function slip($id = null) {
        if (!$id) {
            $this->redirect('OtherIncome');
            return;
        }
        $income = $this->model->getIncomeById((int)$id);
        if (!$income || !$this->model->userCanAccessIncome($income)) {
            $_SESSION['error'] = 'Income not found!';
            $this->redirect('OtherIncome');
            return;
        }
        $this->view('Accounting/OtherIncome/slip', ['title' => 'Income slip', 'income' => $income]);
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
                $this->sendJson(['status' => 'error', 'message' => 'Income id is required']);
                return;
            }

            $result = $this->model->reverseIncome($id, $reason);

            if (($result['status'] ?? '') === 'success') {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'other_income_reversed', $id, ['reason' => $reason]);
                if (empty($result['redirect_url'])) {
                    $result['redirect_url'] = BASE_URL . 'OtherIncome/details/' . $id;
                }
            }

            $this->sendJson($result);
        } catch (Throwable $e) {
            error_log('OtherIncome reverse: ' . $e->getMessage());
            $this->sendJson([
                'status'  => 'error',
                'message' => $this->safeClientMessage($e, 'Could not reverse income. Please try again.'),
            ], 500);
        }
    }

    public function audit() {
        $this->view('Accounting/OtherIncome/audit', [
            'title' => 'Other Income Audit Logs',
            'logs'  => $this->userAudit->getRecentLogs(300, 'other_income_'),
        ]);
    }

    protected function sendJson($data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}