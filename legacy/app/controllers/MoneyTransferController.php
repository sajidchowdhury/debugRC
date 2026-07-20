<?php
// controllers/MoneyTransferController.php

require_once '../core/BaseController.php';
require_once '../app/models/MoneyTransferModel.php';
require_once '../app/helpers/Helper.php';
require_once '../app/services/Branch/BranchIntercompanyService.php';
require_once '../core/UserAudit.php';

class MoneyTransferController extends BaseController {

    private MoneyTransferModel $model;
    private UserAudit $userAudit;

    public function __construct() {
        $this->requireLogin();
        $this->model = new MoneyTransferModel();
        $this->userAudit = new UserAudit();
    }

    public function index() {
        if (isset($_GET['draw'])) {
            $params = $_GET;
            if (isset($_GET['reversed']) && $_GET['reversed'] == '1') {
                $params['reversedMode'] = 'only_reversed';
            }
            header('Content-Type: application/json');
            echo json_encode($this->model->getTransfersForDataTable($params));
            exit;
        }

        $showReversed = isset($_GET['reversed']) && $_GET['reversed'] == '1';
        $today = date('Y-m-d');
        $branchId = Helper::sessionBranchId() ?: (int)($_SESSION['branch_id'] ?? 0);
        $listBranchId = ($this->model->canOverrideBranch() && $branchId <= 0) ? null : ($branchId ?: null);

        $this->view('Accounting/MoneyTransfer/index', [
            'title'        => 'Money Transfers',
            'banks'        => $this->model->getBanks(),
            'branches'     => $this->model->getAllBranches(),
            'showReversed' => $showReversed,
            'fromDate'     => $today,
            'toDate'       => $today,
            'branch_name'  => $_SESSION['branch_name'] ?? 'Branch',
            'stats'        => $this->model->getMoneyTransferIndexStats($listBranchId),
        ]);
    }

    public function create() {
        $currentBranchId = Helper::sessionBranchId() ?: (int)($_SESSION['branch_id'] ?? 0);
        $currentBranchName = $currentBranchId ? $this->model->getBranchName($currentBranchId) : null;

        $this->view('Accounting/MoneyTransfer/create', [
            'title'               => 'New Money Transfer',
            'banks'               => $this->model->getBanks(),
            'branches'            => $this->model->getAllBranches(),
            'today'               => date('Y-m-d'),
            'current_branch_id'   => $currentBranchId,
            'current_branch_name' => $currentBranchName ?? 'Branch',
            'branch_name'         => $_SESSION['branch_name'] ?? 'Branch',
        ]);
    }

    public function store() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request'], 405);
            return;
        }

        try {
            $this->validateCSRF();
            $result = $this->model->createTransfer($_POST);

            if (($result['status'] ?? '') === 'success') {
                $tid = (int)($result['transfer_id'] ?? 0);
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'money_transfer_created', $tid, [
                    'journal_entry_id' => $result['journal_entry_id'] ?? null,
                    'transfer_code'    => $result['transfer_code'] ?? '',
                    'transfer_type'    => $result['transfer_type'] ?? $_POST['transfer_type'] ?? '',
                    'amount'           => $result['amount'] ?? $_POST['amount'] ?? 0,
                    'branch_id'        => Helper::sessionBranchId(),
                ]);
                $result['redirect_url'] = BASE_URL . 'MoneyTransfer/details/' . $tid;
            }

            $this->sendJson($result);
        } catch (Throwable $e) {
            error_log('MoneyTransfer store: ' . $e->getMessage());
            $this->sendJson([
                'status'  => 'error',
                'message' => $this->safeClientMessage($e, 'Could not save transfer. Please try again.'),
            ], 500);
        }
    }

    /**
     * Preview FIFO branch-demand settlement for inter-branch cash transfers (create form).
     */
    public function preview_settlement() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request'], 405);
            return;
        }

        $fromBranch = Helper::sessionBranchId() ?: (int)($_SESSION['branch_id'] ?? 0);
        $toBranch = (int)($_POST['to_branch_id'] ?? 0);
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $type = strtolower(trim((string)($_POST['transfer_type'] ?? '')));

        if (!in_array($type, ['cash_to_cash', 'cash_to_bank'], true)) {
            $this->sendJson(['status' => 'skipped', 'message' => 'No demand preview for this transfer type.']);
            return;
        }

        if ($fromBranch <= 0 || $toBranch <= 0 || $fromBranch === $toBranch || $amount <= 0) {
            $this->sendJson(['status' => 'skipped', 'demands' => [], 'preview_allocations' => []]);
            return;
        }

        $svc = new BranchIntercompanyService();
        $preview = $svc->previewDemandSettlement($fromBranch, $toBranch, $amount);
        $this->sendJson($preview);
    }

    public function details($id = null) {
        if (!$id) {
            $this->redirect('MoneyTransfer');
            return;
        }

        $transfer = $this->model->getTransferById((int)$id);
        if (!$transfer || !$this->model->userCanAccessTransfer($transfer)) {
            $_SESSION['error'] = 'Transfer not found!';
            $this->redirect('MoneyTransfer');
            return;
        }

        $transferId = (int)$id;

        $this->view('Accounting/MoneyTransfer/details', [
            'title'        => 'Transfer — ' . ($transfer['transfer_code'] ?? ''),
            'transfer'     => $transfer,
            'journal_entry'=> $this->model->getJournalEntryForTransfer($transferId),
            'settlements'  => $this->model->getTransferSettlements($transferId),
            'cash_ledger'  => $this->model->getCashLedgerForTransfer($transferId),
            'branch_ledger'=> $this->model->getBranchLedgerForTransfer($transferId),
            'accounts'     => $this->model->getTransferAccountsSummary($transfer),
            'can_reverse'  => $this->model->canUserReverseTransfer($transfer),
        ]);
    }

    public function slip($id = null) {
        if (!$id) {
            $this->redirect('MoneyTransfer');
            return;
        }

        $transfer = $this->model->getTransferById((int)$id);
        if (!$transfer || !$this->model->userCanAccessTransfer($transfer)) {
            $_SESSION['error'] = 'Transfer not found!';
            $this->redirect('MoneyTransfer');
            return;
        }

        $this->view('Accounting/MoneyTransfer/slip', [
            'title'       => 'Transfer slip',
            'transfer'    => $transfer,
            'accounts'    => $this->model->getTransferAccountsSummary($transfer),
            'settlements' => $this->model->getTransferSettlements((int)$id),
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
                $this->sendJson(['status' => 'error', 'message' => 'Transfer id is required']);
                return;
            }

            $result = $this->model->reverseTransfer($id, $reason);

            if (($result['status'] ?? '') === 'success') {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'money_transfer_reversed', $id, [
                    'reason'        => $reason,
                    'transfer_code' => $result['transfer_code'] ?? null,
                    'transfer_type' => $result['transfer_type'] ?? null,
                    'amount'        => $result['amount'] ?? null,
                ]);
                if (empty($result['redirect_url'])) {
                    $result['redirect_url'] = BASE_URL . 'MoneyTransfer/details/' . $id;
                }
            }

            $this->sendJson($result);
        } catch (Throwable $e) {
            error_log('MoneyTransfer reverse: ' . $e->getMessage());
            $this->sendJson([
                'status'  => 'error',
                'message' => $this->safeClientMessage($e, 'Could not reverse transfer. Please try again.'),
            ], 500);
        }
    }

    public function audit() {
        $this->view('Accounting/MoneyTransfer/audit', [
            'title' => 'Money Transfer Audit Logs',
            'logs'  => $this->userAudit->getRecentLogs(300, 'money_transfer_'),
        ]);
    }

    protected function sendJson($data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}