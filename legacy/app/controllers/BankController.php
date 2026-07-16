<?php
// app/controllers/BankController.php

require_once '../core/BaseController.php';
require_once '../app/models/BankModel.php';
require_once '../app/models/BankLedgerMappingModel.php';
require_once '../app/models/LedgerModel.php';
require_once '../core/UserAudit.php';
require_once __DIR__ . '/../helpers/MasterDataAuditHelper.php';

class BankController extends BaseController {

    private $bankModel;
    private $userAudit;

    public function __construct() {
        $this->requireLogin();
        $this->bankModel = new BankModel();
        $this->userAudit = new UserAudit();
    }

    public function index() {
        if (isset($_GET['draw'])) {
            $params = $_GET;
            $params['includeDeleted'] = isset($_GET['deleted']) && $_GET['deleted'] == '1';

            $response = $this->bankModel->getBanksForDataTable($params);
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }

        $showDeleted = isset($_GET['deleted']) && $_GET['deleted'] == '1';

        $data = [
            'title'       => $showDeleted ? 'Inactive bank accounts' : 'Bank accounts',
            'showDeleted' => $showDeleted,
            'stats'       => $this->bankModel->getBankIndexStats(),
        ];
        $this->view('bank/index', $data);
    }

    public function show($id = null) {
        if (!$id) {
            $this->redirect('bank/index');
        }

        $bankId = (int)$id;
        $bank = $this->bankModel->getById($bankId);
        if (!$bank) {
            $_SESSION['error'] = 'Bank account not found!';
            $this->redirect('bank/index');
        }

        $mappingModel = new BankLedgerMappingModel();
        $ledgerModel = new LedgerModel();
        $glLedgerId = $mappingModel->getLedgerIdForBank($bankId);
        $glLedger = null;
        if ($glLedgerId > 0) {
            $glLedger = $ledgerModel->getLedgerById($glLedgerId);
        }

        $this->view('bank/show', [
            'title'              => ($bank['bank_name'] ?? 'Bank') . ' — Hub',
            'bank'               => $bank,
            'summary'            => $this->bankModel->getBankHubSummary($bankId),
            'gl_ledger'          => $glLedger,
            'customer_payments'  => $this->bankModel->getRecentCustomerPayments($bankId),
            'supplier_payments'  => $this->bankModel->getRecentSupplierPayments($bankId),
            'transfers'          => $this->bankModel->getRecentMoneyTransfers($bankId),
            'other_movements'    => $this->bankModel->getRecentOtherMovements($bankId),
        ]);
    }

    public function create() {
        $mappingModel = new BankLedgerMappingModel();
        $ledgerModel = new LedgerModel();

        $data = [
            'title'                => 'New bank account',
            'bank_gl_ledgers'      => $ledgerModel->getLedgersByNature('cash_bank'),
            'bank_mapping_enabled' => $mappingModel->tableExists(),
        ];
        $this->view('bank/create', $data);
    }

    public function store() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCSRF();

            $result = $this->bankModel->createBank($_POST);

            if ($result['status'] === 'success') {
                $bankId = (int)($result['id'] ?? 0);
                $this->saveGlMapping($bankId);

                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'bank_created', $bankId, [
                    'bank_name'      => $_POST['bank_name'] ?? '',
                    'account_number' => $_POST['account_number'] ?? '',
                ]);

                $_SESSION['success'] = $result['message'];
                $this->redirect('bank/index');
            } else {
                $_SESSION['error'] = $result['message'];
                $this->redirect('bank/create');
            }
        }
    }

    public function edit($id = null) {
        if (!$id) $this->redirect('bank/index');

        $bank = $this->bankModel->getById($id);
        if (!$bank) {
            $_SESSION['error'] = "Bank account not found!";
            $this->redirect('bank/index');
        }

        $mappingModel = new BankLedgerMappingModel();
        $ledgerModel = new LedgerModel();

        $data = [
            'title'                => 'Edit Bank Account',
            'bank'                 => $bank,
            'usage'                => $this->bankModel->getBankUsage((int)$id),
            'gl_ledger_id'         => $mappingModel->getLedgerIdForBank((int)$id),
            'bank_gl_ledgers'      => $ledgerModel->getLedgersByNature('cash_bank'),
            'bank_mapping_enabled' => $mappingModel->tableExists(),
        ];
        $this->view('bank/edit', $data);
    }

    public function update($id = null) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
            $this->validateCSRF();

            $bankId = (int)$id;
            $before = $this->bankModel->getById($bankId);
            if (!$before) {
                $_SESSION['error'] = 'Bank account not found!';
                $this->redirect('bank/index');
            }

            $result = $this->bankModel->updateBank($bankId, $_POST);

            if ($result['status'] === 'success') {
                $this->saveGlMapping($bankId);

                $after = array_merge($before, $this->bankModel->getById($bankId) ?: []);
                $details = MasterDataAuditHelper::buildUpdateDetails(
                    $before,
                    $after,
                    MasterDataAuditHelper::BANK_FIELDS
                );

                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'bank_updated', $bankId, $details);

                $_SESSION['success'] = $result['message'];
            } else {
                $_SESSION['error'] = $result['message'];
            }
        }
        $this->redirect('bank/index');
    }

    public function toggle($id = null) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
            $this->validateCSRF();

            $bank = $this->bankModel->getById($id);
            if (!$bank) {
                echo json_encode(['status' => 'error', 'message' => 'Bank account not found.']);
                exit;
            }

            $isCurrentlyActive = (int)$bank['is_active'] === 1;

            if ($isCurrentlyActive) {
                $safety = $this->bankModel->getDeactivationSafetyStatus((int)$id);
                if (!$safety['can_deactivate']) {
                    echo json_encode([
                        'status'  => 'error',
                        'message' => $this->bankModel->getDeactivationMessage((int)$id),
                    ]);
                    exit;
                }
            }

            if ($this->bankModel->toggleStatus($id)) {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'bank_status_changed', (int)$id);

                echo json_encode(['status' => 'success', 'message' => 'Bank status updated successfully!']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to update status!']);
            }
            exit;
        }
        $this->redirect('bank/index');
    }

    public function delete($id = null) {
        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
            exit;
        }

        $this->validateCSRF();

        $safety = $this->bankModel->getDeactivationSafetyStatus((int)$id);

        if (!$safety['can_deactivate']) {
            echo json_encode([
                'status'  => 'error',
                'message' => $this->bankModel->getDeactivationMessage((int)$id)
                    . ' Please review balance and linked transactions before archiving.',
                'safety'  => $safety,
            ]);
            exit;
        }

        if ($this->bankModel->softDeleteBank($id)) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'bank_deactivated', (int)$id);

            echo json_encode(['status' => 'success', 'message' => 'Bank deactivated successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to deactivate bank.']);
        }
        exit;
    }

    public function restore($id = null) {
        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
            exit;
        }

        $this->validateCSRF();

        if ($this->bankModel->restoreBank($id)) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'bank_restored', (int)$id);

            echo json_encode(['status' => 'success', 'message' => 'Bank restored successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to restore bank.']);
        }
        exit;
    }

    public function audit() {
        $logs = $this->userAudit->getRecentLogs(300, 'bank_');
        $logs = MasterDataAuditHelper::enrichLogsWithUserNames($logs);

        $data = [
            'title' => 'Bank Audit Logs',
            'logs'  => $logs,
        ];

        $this->view('bank/audit', $data);
    }

    private function saveGlMapping(int $bankId): void
    {
        if ($bankId <= 0 || !isset($_POST['gl_ledger_id'])) {
            return;
        }

        $mappingModel = new BankLedgerMappingModel();
        if (!$mappingModel->tableExists()) {
            return;
        }

        $glId = (int)$_POST['gl_ledger_id'];
        if ($glId > 0) {
            $mappingModel->saveMapping($bankId, $glId);
        }
    }
}
