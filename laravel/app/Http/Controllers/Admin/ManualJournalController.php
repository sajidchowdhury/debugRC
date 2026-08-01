<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreManualJournalRequest;
use App\Models\ManualJournal;
use App\Services\Accounting\ManualJournalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Manual Journal Controller — Phase 1.1 (Core Foundation Hardening).
 *
 * Handles manual journal entries: list, create, show, post, reverse, audit.
 *
 * Lifecycle: draft → posted → reversed
 *   - create with status=draft: saves header + lines (no GL posting)
 *   - create with status=post:  saves + posts GL immediately
 *   - post (draft → posted):    reads draft lines, validates, posts to GL
 *   - reverse: reverses the linked GL entry + marks manual journal reversed
 *
 * Phase 1.1 Changes:
 *   - Added post() method for draft-to-post workflow
 *   - show() now loads manual_journal_lines for draft journals
 *   - Draft journals show their lines + a "Post" button
 *
 * Dr = Cr is enforced by the service. Period validation on posting.
 * No entity_type/entity_id on lines (accountant's choice).
 */
class ManualJournalController extends Controller
{
    public function __construct(
        private ManualJournalService $service
    ) {}

    /**
     * List manual journals with filters and stats.
     */
    public function index(Request $request)
    {
        $filters = [
            'date_from' => $request->input('date_from'),
            'date_to'   => $request->input('date_to'),
            'status'    => $request->input('status', 'all'),
            'branch_id' => $request->input('branch_id'),
            'search'    => $request->input('search'),
        ];

        $listBranchId = $this->resolveListBranchId();
        $journals = $this->service->getFilteredJournals($filters, $listBranchId);
        $stats = $this->service->getStats($listBranchId);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.manual-journals.index', [
            'title'    => 'Manual Journals',
            'journals' => $journals,
            'stats'    => $stats,
            'branches' => $branches,
            'filters'  => $filters,
        ]);
    }

    /**
     * Show the create form.
     */
    public function create(Request $request)
    {
        // Load active ledgers for the line picker (grouped by account type).
        $ledgers = DB::table('ledgers')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('account_type')
            ->orderBy('sort_order')
            ->orderBy('ledger_name')
            ->get(['id', 'ledger_code', 'ledger_name', 'account_type']);

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        // Branch restriction: non-admin users get their own branch auto-selected.
        $user = auth()->user();
        $userBranchId = (int) (session('branch_id') ?? ($user ? $user->getBranchId() : 0));
        $isAdmin = $user && $user->isAdmin();

        return view('admin.manual-journals.create', [
            'title'        => 'New Manual Journal',
            'ledgers'      => $ledgers,
            'branches'     => $branches,
            'today'        => now()->format('Y-m-d'),
            'isAdmin'      => $isAdmin,
            'userBranchId' => $userBranchId,
        ]);
    }

    /**
     * Store a new manual journal (draft or posted).
     *
     * Validation is handled by StoreManualJournalRequest.
     */
    public function store(StoreManualJournalRequest $request)
    {
        $payload = $request->toServicePayload();

        try {
            $journal = $this->service->createJournal($payload);

            $statusLabel = $journal->status === 'posted' ? 'posted to GL' : 'saved as draft';
            $successMessage = "Manual journal {$journal->journal_code} {$statusLabel}.";

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'       => 'success',
                    'journal_id'   => $journal->id,
                    'journal_code' => $journal->journal_code,
                    'message'      => $successMessage,
                    'redirect_url' => route('admin.manual-journals.show', ['id' => $journal->id]),
                ]);
            }

            return redirect()->route('admin.manual-journals.show', ['id' => $journal->id])
                ->with('success', $successMessage);
        } catch (\Throwable $e) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], 400);
            }
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Show journal details with GL lines (for posted) or draft lines (for draft).
     */
    public function show(int $id)
    {
        $journal = ManualJournal::with([
            'branch', 'createdBy',
            'journalEntry.lines.ledger',
            'lines.ledger',  // Phase 1.1: draft lines
        ])->findOrFail($id);

        return view('admin.manual-journals.show', [
            'title'   => 'Manual Journal — ' . $journal->journal_code,
            'journal' => $journal,
            'canReverse' => $journal->isPosted(),
            'canPost'    => $journal->isDraft(),  // Phase 1.1: show Post button for drafts
        ]);
    }

    /**
     * Post a draft manual journal to the GL.
     *
     * Phase 1.1: Draft-to-post workflow. Reads draft lines from manual_journal_lines,
     * validates Dr=Cr, posts to GL, marks lines as posted.
     */
    public function post(Request $request, int $id)
    {
        try {
            $journal = $this->service->postJournal($id, auth()->id());

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'       => 'success',
                    'message'      => "Manual journal {$journal->journal_code} posted to GL successfully.",
                    'journal_id'   => $journal->id,
                    'journal_code' => $journal->journal_code,
                ]);
            }

            return redirect()->route('admin.manual-journals.show', ['id' => $journal->id])
                ->with('success', "Manual journal {$journal->journal_code} posted to GL successfully.");
        } catch (\Throwable $e) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], 400);
            }
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Reverse a posted manual journal.
     */
    public function reverse(Request $request, int $id)
    {
        $request->validate([
            'reverse_reason' => 'required|string|min:3|max:500',
        ]);

        try {
            $journal = $this->service->reverseJournal(
                $id,
                auth()->id(),
                $request->input('reverse_reason')
            );

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'       => 'success',
                    'message'      => "Manual journal {$journal->journal_code} reversed. GL entry reversed.",
                    'journal_id'   => $journal->id,
                    'journal_code' => $journal->journal_code,
                ]);
            }

            return redirect()->route('admin.manual-journals.show', ['id' => $journal->id])
                ->with('success', "Manual journal {$journal->journal_code} reversed. GL entry reversed.");
        } catch (\Throwable $e) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], 400);
            }
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Show audit logs for manual journals.
     */
    public function audit()
    {
        $logs = DB::table('user_audit_log')
            ->where('action', 'LIKE', 'manual_journal_%')
            ->orderBy('created_at', 'desc')
            ->limit(300)
            ->get();

        return view('admin.manual-journals.audit', [
            'title' => 'Manual Journal Audit Logs',
            'logs'  => $logs,
        ]);
    }

    // ============================================================
    // PRIVATE HELPERS
    // ============================================================

    private function resolveListBranchId(): ?int
    {
        $user = auth()->user();
        if ($user && $user->isAdmin()) {
            return null;
        }
        return (int) (session('branch_id') ?? ($user ? $user->getBranchId() : 0)) ?: null;
    }
}
