<?php
// app/controllers/ManualJournalController.php — Phase 6A manual GL journals

require_once '../core/BaseController.php';
require_once '../app/models/ManualJournalModel.php';
require_once '../app/models/JournalEntryModel.php';
require_once '../app/models/LedgerModel.php';
require_once '../app/helpers/Helper.php';
require_once '../app/helpers/AccountingModuleHelper.php';
require_once '../core/UserAudit.php';

class ManualJournalController extends BaseController
{
    private ManualJournalModel $model;
    private UserAudit $userAudit;

    public function __construct()
    {
        $this->requireLogin();
        $this->model = new ManualJournalModel();
        $this->userAudit = new UserAudit();
    }

    /**
     * URL: ManualJournal or ManualJournal/index
     */
    public function index()
    {
        $helper = new Helper();
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from_date'] ?? ''))
            ? $_GET['from_date']
            : date('Y-m-01');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to_date'] ?? ''))
            ? $_GET['to_date']
            : date('Y-m-d');

        $branchId = null;
        if ($this->model->canOverrideBranch() && isset($_GET['branch_id']) && $_GET['branch_id'] !== '') {
            $branchId = (int)$_GET['branch_id'];
        }

        $showReversed = isset($_GET['reversed']) && $_GET['reversed'] === '1';
        $listBranchId = ($this->model->canOverrideBranch() && $branchId > 0) ? $branchId : (
            $this->model->canOverrideBranch() ? null : (Helper::sessionBranchId() ?: null)
        );

        $filters = [
            'from_date'     => $from,
            'to_date'       => $to,
            'branch_id'     => $branchId,
            'search'        => trim((string)($_GET['search'] ?? '')),
            'reversed_only' => $showReversed,
            'active_only'   => !$showReversed,
        ];

        $this->view('Accounting/ManualJournal/index', [
            'title'         => $showReversed ? 'Reversed Manual Journals' : 'Manual Journals',
            'entries'       => $this->model->listManualJournals($filters),
            'from_date'     => $from,
            'to_date'       => $to,
            'branch_id'     => $branchId,
            'branches'      => $helper->Get_All_Active_Branches(),
            'can_override'  => $this->model->canOverrideBranch(),
            'show_reversed' => $showReversed,
            'search'        => $filters['search'],
            'stats'         => $this->model->getIndexStats($listBranchId),
            'branch_name'   => $_SESSION['branch_name'] ?? 'Branch',
        ]);
    }

    public function create()
    {
        $helper = new Helper();
        $ledgerModel = new LedgerModel();

        $this->view('Accounting/ManualJournal/create', [
            'title'           => 'Post Manual Journal',
            'ledgers'         => $ledgerModel->getLedgersForDropdown(),
            'today'           => date('Y-m-d'),
            'branch_id'       => Helper::sessionBranchId(),
            'branches'        => $helper->Get_All_Active_Branches(),
            'can_override_branch' => $this->model->canOverrideBranch(),
            'branch_name'     => $_SESSION['branch_name'] ?? 'Branch',
            'min_posting_date'=> AccountingModuleHelper::minPostingDateForSession(),
        ]);
    }

    public function store()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request'], 405);
            return;
        }

        try {
            $this->validateCSRF();

            $linesRaw = $_POST['lines'] ?? [];
            if (is_string($linesRaw)) {
                $decoded = json_decode($linesRaw, true);
                $linesRaw = is_array($decoded) ? $decoded : [];
            }

            $result = $this->model->createManualJournal(
                $_POST,
                is_array($linesRaw) ? $linesRaw : [],
                $_FILES['attachment'] ?? null
            );

            if (($result['status'] ?? '') === 'success') {
                $mid = (int)($result['manual_journal_id'] ?? 0);
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'manual_journal_created', $mid, [
                    'entry_no'         => $result['entry_no'] ?? '',
                    'journal_entry_id' => $result['journal_entry_id'] ?? null,
                    'entry_date'       => $_POST['entry_date'] ?? '',
                ]);
                $result['redirect_url'] = BASE_URL . 'ManualJournal/details/' . $mid;
            }

            $this->sendJson($result);
        } catch (Throwable $e) {
            error_log('ManualJournal store: ' . $e->getMessage());
            $this->sendJson([
                'status'  => 'error',
                'message' => $this->safeClientMessage($e, 'Could not post manual journal. Please try again.'),
            ], 500);
        }
    }

    public function details($id = null)
    {
        if (!$id) {
            $this->redirect('ManualJournal');
            return;
        }

        $row = $this->model->getById((int)$id);
        if (!$row || !$this->model->userCanAccess($row)) {
            $_SESSION['error'] = 'Manual journal not found.';
            $this->redirect('ManualJournal');
            return;
        }

        $journalEntry = (new JournalEntryModel())->getEntryWithLines((int)$row['journal_entry_id']);

        $this->view('Accounting/ManualJournal/details', [
            'title'         => 'Manual Journal — ' . ($row['entry_no'] ?? ''),
            'manual'        => $row,
            'journal_entry' => $journalEntry,
            'can_reverse'   => $this->model->canUserReverse($row),
        ]);
    }

    public function reverse()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJson(['status' => 'error', 'message' => 'Invalid request'], 405);
            return;
        }

        try {
            $this->validateCSRF();
            $id = (int)($_POST['id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));

            $result = $this->model->reverseManualJournal($id, $reason);

            if (($result['status'] ?? '') === 'success') {
                $this->userAudit->log($_SESSION['user_id'] ?? 0, 'manual_journal_reversed', $id, [
                    'reason'           => $reason,
                    'journal_entry_id' => $result['journal_entry_id'] ?? null,
                ]);
                $result['redirect_url'] = BASE_URL . 'ManualJournal/details/' . $id;
            }

            $this->sendJson($result);
        } catch (Throwable $e) {
            error_log('ManualJournal reverse: ' . $e->getMessage());
            $this->sendJson([
                'status'  => 'error',
                'message' => $this->safeClientMessage($e, 'Could not reverse manual journal.'),
            ], 500);
        }
    }

    public function audit()
    {
        $this->view('Accounting/ManualJournal/audit', [
            'title' => 'Manual Journal Audit Logs',
            'logs'  => $this->userAudit->getRecentLogs(300, 'manual_journal_'),
        ]);
    }
}
