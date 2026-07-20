<?php
// app/controllers/LedgerController.php

require_once '../core/BaseController.php';
require_once '../app/models/LedgerModel.php';
require_once '../core/UserAudit.php';
require_once '../app/helpers/MasterDataAuditHelper.php';

class LedgerController extends BaseController {

    private $ledgerModel;
    private $userAudit;

    public function __construct() {
        $this->requireLogin();
        $this->ledgerModel = new LedgerModel();
        $this->userAudit = new UserAudit();
    }

    public function index() {
        if (isset($_GET['draw'])) {
            $params = $_GET;

            if (isset($_GET['inactive']) && $_GET['inactive'] == '1') {
                $params['showInactive'] = true;
            }
            if (!empty($_GET['viewHierarchy']) && $_GET['viewHierarchy'] === '1') {
                $params['viewHierarchy'] = true;
            }

            $response = $this->ledgerModel->getLedgersForDataTable($params);
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }

        $showInactive = isset($_GET['inactive']) && $_GET['inactive'] == '1';

        $data = [
            'title'        => $showInactive ? 'Inactive ledgers' : 'Chart of Accounts',
            'showInactive' => $showInactive,
            'stats'        => $this->ledgerModel->getLedgerIndexStats(),
            'natureGroups' => LedgerModel::getNatureOptionGroups(),
        ];

        $this->view('ledger/index', $data);
    }

    public function show($id = null) {
        if (!$id) {
            $this->redirect('ledger/index');
            return;
        }

        $ledgerId = (int)$id;
        $ledger = $this->ledgerModel->getLedgerWithParent($ledgerId);
        if (!$ledger) {
            $_SESSION['error'] = 'Ledger not found!';
            $this->redirect('ledger/index');
            return;
        }

        $this->view('ledger/show', [
            'title'         => ($ledger['ledger_name'] ?? 'Ledger') . ' — Account hub',
            'ledger'        => $ledger,
            'balance'       => $this->ledgerModel->getLedgerGlBalance($ledgerId),
            'usage'         => $this->ledgerModel->getLedgerUsage($ledgerId),
            'journal_lines' => $this->ledgerModel->getRecentJournalLinesForLedger($ledgerId, 20),
            'child_ledgers' => $this->ledgerModel->getChildLedgers($ledgerId),
            'bank_accounts' => $this->ledgerModel->getBankAccountsForLedger($ledgerId),
        ]);
    }

    public function create() {
        $data = [
            'title'         => 'Create New Ledger Head',
            'parentOptions' => $this->ledgerModel->getHierarchicalParentOptions(),
        ];
        $this->view('ledger/create', $data);
    }

    public function store() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('ledger/create');
            return;
        }

        $this->validateCSRF();

        $validated = $this->ledgerModel->validateLedgerPayload($_POST);
        if (!$validated['ok']) {
            $_SESSION['error'] = $validated['error'];
            $this->redirect('ledger/create');
            return;
        }

        $newId = $this->ledgerModel->createLedger($validated['data']);
        if ($newId) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'ledger_created', $newId, [
                'ledger_name'    => $validated['data']['ledger_name'],
                'ledger_nature'  => $validated['data']['ledger_nature'],
                'account_type'   => $validated['data']['account_type'],
                'normal_balance' => $validated['data']['normal_balance'],
                'is_control_account' => $validated['data']['is_control_account'] ?? 0,
            ]);
            $_SESSION['success'] = 'Ledger created successfully!';
            $this->redirect('ledger/show/' . $newId);
            return;
        }

        $_SESSION['error'] = 'Failed to create ledger!';
        $this->redirect('ledger/create');
    }

    public function edit($id = null) {
        if (!$id) {
            $this->redirect('ledger/index');
            return;
        }

        $ledger = $this->ledgerModel->getLedgerById($id);
        if (!$ledger) {
            $_SESSION['error'] = 'Ledger not found!';
            $this->redirect('ledger/index');
            return;
        }

        $isSystem = !empty($ledger['is_system']);

        $data = [
            'title'         => $isSystem ? 'View system ledger' : 'Edit ledger',
            'ledger'        => $ledger,
            'parentOptions' => $this->ledgerModel->getHierarchicalParentOptions((int)$id),
            'isSystem'      => $isSystem,
            'usage'         => $this->ledgerModel->getLedgerUsage((int)$id),
        ];
        $this->view('ledger/edit', $data);
    }

    public function update($id = null) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) {
            $this->redirect('ledger/index');
            return;
        }

        $this->validateCSRF();
        $ledgerId = (int)$id;
        $before = $this->ledgerModel->getLedgerById($ledgerId);
        if (!$before) {
            $_SESSION['error'] = 'Ledger not found!';
            $this->redirect('ledger/index');
            return;
        }

        if ($this->ledgerModel->isSystemLedger($ledgerId)) {
            $metadata = [
                'description' => trim((string)($_POST['description'] ?? '')),
            ];

            if ($this->ledgerModel->updateSystemLedgerMetadata($ledgerId, $metadata)) {
                $after = array_merge($before, [
                    'description' => $metadata['description'] !== '' ? $metadata['description'] : null,
                ]);
                $details = MasterDataAuditHelper::buildUpdateDetails(
                    $before,
                    $after,
                    MasterDataAuditHelper::LEDGER_FIELDS
                );
                $details['system_metadata_only'] = true;
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'ledger_updated', $ledgerId, $details);
                $_SESSION['success'] = 'System ledger description updated.';
                $this->redirect('ledger/show/' . $ledgerId);
                return;
            }

            $_SESSION['error'] = 'Failed to update system ledger metadata.';
            $this->redirect('ledger/edit/' . $ledgerId);
            return;
        }

        $validated = $this->ledgerModel->validateLedgerPayload($_POST, $ledgerId);
        if (!$validated['ok']) {
            $_SESSION['error'] = $validated['error'];
            $this->redirect('ledger/edit/' . $ledgerId);
            return;
        }

        if ($this->ledgerModel->updateLedger($ledgerId, $validated['data'])) {
            $after = array_merge($before, $validated['data']);
            $details = MasterDataAuditHelper::buildUpdateDetails(
                $before,
                $after,
                MasterDataAuditHelper::LEDGER_FIELDS
            );
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'ledger_updated', $ledgerId, $details);
            $_SESSION['success'] = 'Ledger updated successfully!';
            $this->redirect('ledger/show/' . $ledgerId);
            return;
        }

        $_SESSION['error'] = 'Failed to update ledger!';
        $this->redirect('ledger/edit/' . $ledgerId);
    }

    public function toggle($id = null) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
            $this->validateCSRF();
            header('Content-Type: application/json');

            $ledgerId = (int)$id;
            $ledger = $this->ledgerModel->getLedgerById($ledgerId);
            if (!$ledger) {
                echo json_encode(['status' => 'error', 'message' => 'Ledger not found.']);
                exit;
            }

            $wasActive = !empty($ledger['is_active']);

            if ($wasActive) {
                $block = $this->ledgerModel->getToggleBlockReason($ledgerId);
                if ($block !== null) {
                    echo json_encode(['status' => 'error', 'message' => $block]);
                    exit;
                }
            }

            if ($this->ledgerModel->toggleStatus($ledgerId)) {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'ledger_status_changed', $ledgerId, [
                    'ledger_code' => $ledger['ledger_code'] ?? '',
                    'ledger_name' => $ledger['ledger_name'] ?? '',
                    'from'        => $wasActive ? 'active' : 'inactive',
                    'to'          => $wasActive ? 'inactive' : 'active',
                    'via'         => 'toggle',
                ]);

                echo json_encode([
                    'status'  => 'success',
                    'message' => $wasActive ? 'Ledger deactivated.' : 'Ledger activated.',
                ]);
            } else {
                $msg = $wasActive
                    ? ($this->ledgerModel->getToggleBlockReason($ledgerId) ?: 'Failed to deactivate ledger.')
                    : 'Failed to activate ledger — another active account may already use this nature.';
                echo json_encode(['status' => 'error', 'message' => $msg]);
            }
            exit;
        }

        $this->redirect('ledger/index');
    }

    public function delete($id = null) {
        if (!$id) {
            $_SESSION['error'] = 'Invalid ledger ID.';
            $this->redirect('ledger/index');
            return;
        }

        $ledgerId = (int)$id;
        $ledger = $this->ledgerModel->getLedgerById($ledgerId);

        if ($this->ledgerModel->isSystemLedger($ledgerId)) {
            $msg = 'System ledgers cannot be deleted. They are required for accounting integrity.';
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => $msg]);
                exit;
            }
            $_SESSION['error'] = $msg;
            $this->redirect('ledger/index');
            return;
        }

        $block = $this->ledgerModel->getToggleBlockReason($ledgerId);
        if ($block !== null && !empty($ledger['is_active'])) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => $block]);
                exit;
            }
            $_SESSION['error'] = $block;
            $this->redirect('ledger/index');
            return;
        }

        if ($this->ledgerModel->softDeleteLedger($ledgerId)) {
            $this->userAudit->log($_SESSION['user_id'] ?? 0, 'ledger_deleted', $ledgerId, [
                'ledger_code' => $ledger['ledger_code'] ?? '',
                'ledger_name' => $ledger['ledger_name'] ?? '',
                'via'         => 'delete_action',
            ]);
            $msg = 'Ledger deactivated (soft delete).';
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => $msg]);
                exit;
            }
            $_SESSION['success'] = $msg;
        } else {
            $failMsg = $this->ledgerModel->getToggleBlockReason($ledgerId) ?: 'Failed to delete ledger.';
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => $failMsg]);
                exit;
            }
            $_SESSION['error'] = $failMsg;
        }

        $this->redirect('ledger/index');
    }

    public function audit() {
        $logs = $this->userAudit->getRecentLogs(300, 'ledger_');
        $logs = MasterDataAuditHelper::enrichLogsWithUserNames($logs);

        $ledgerNames = [];
        foreach ($logs as $log) {
            $targetId = (int)($log['target_user_id'] ?? 0);
            if ($targetId > 0 && !isset($ledgerNames[$targetId])) {
                $row = $this->ledgerModel->getLedgerById($targetId);
                $ledgerNames[$targetId] = $row['ledger_name'] ?? ('Ledger #' . $targetId);
            }
        }

        $this->view('ledger/audit', [
            'title'       => 'Ledger Audit Logs',
            'logs'        => $logs,
            'ledgerNames' => $ledgerNames,
        ]);
    }
}
