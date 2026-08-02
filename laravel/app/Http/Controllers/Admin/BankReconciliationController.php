<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Services\Accounting\BankReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * BankReconciliationController — Phase 9.3: Bank Reconciliation
 *
 * Handles:
 *   - List all reconciliations (dashboard)
 *   - Create new reconciliation (select bank + period)
 *   - Import bank statement (CSV upload)
 *   - View reconciliation (match/unmatch items)
 *   - Auto-match statement lines against system entries
 *   - Manual match/unmatch
 *   - Complete reconciliation (lock and post adjustments)
 *   - Reverse reconciliation
 *   - Unreconciled entries report
 */
class BankReconciliationController extends Controller
{
    public function __construct(
        private BankReconciliationService $reconciliationService,
    ) {}

    // ── Reconciliation List ─────────────────────────────────────────

    public function index(Request $request)
    {
        $query = BankReconciliation::with(['bank', 'creator', 'completer']);

        if ($request->filled('bank_id')) {
            $query->where('bank_id', $request->bank_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $reconciliations = $query->orderByDesc('created_at')->paginate(20);
        $banks = Bank::active()->orderBy('bank_name')->get();

        return view('admin.bank_reconciliation.index', [
            'title' => 'Bank Reconciliation — Remote Center ERP',
            'reconciliations' => $reconciliations,
            'banks' => $banks,
        ]);
    }

    // ── Create Reconciliation ───────────────────────────────────────

    public function create()
    {
        $banks = Bank::active()->with('ledgerMapping.ledger')->orderBy('bank_name')->get();

        return view('admin.bank_reconciliation.create', [
            'title' => 'New Bank Reconciliation — Remote Center ERP',
            'banks' => $banks,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'bank_id' => 'required|exists:banks,id',
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
            'statement_opening_balance' => 'nullable|numeric',
            'statement_closing_balance' => 'nullable|numeric',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $reconciliation = $this->reconciliationService->createReconciliation($validated);

            return redirect()
                ->route('admin.bank-reconciliation.show', $reconciliation)
                ->with('success', "Reconciliation {$reconciliation->reconciliation_code} created. Import your bank statement to begin.");
        } catch (\Throwable $e) {
            Log::error('Bank reconciliation creation failed', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return back()->withInput()->with('error', 'Failed to create reconciliation: ' . $e->getMessage());
        }
    }

    // ── View Reconciliation ─────────────────────────────────────────

    public function show(BankReconciliation $bankReconciliation)
    {
        $bankReconciliation->load([
            'bank.ledgerMapping.ledger',
            'statementLines' => fn($q) => $q->orderBy('transaction_date')->orderBy('line_number'),
            'reconciliationItems.statementLine',
            'reconciliationItems.journalLine.journalEntry',
            'creator',
            'completer',
        ]);

        // Get unmatched system entries for this bank
        $bank = $bankReconciliation->bank;
        $ledgerMapping = $bank?->ledgerMapping;
        $unreconciledSystemEntries = collect();

        if ($ledgerMapping) {
            $unreconciledSystemEntries = $this->reconciliationService->getUnreconciledSystemEntries(
                $ledgerMapping->ledger_id,
                $bankReconciliation->period_from,
                $bankReconciliation->period_to,
                $bank->branch_id
            );
        }

        // Separate matched and unmatched statement lines
        $matchedLines = $bankReconciliation->statementLines->where('match_status', 'matched');
        $suggestedLines = $bankReconciliation->statementLines->where('match_status', 'suggested');
        $unmatchedLines = $bankReconciliation->statementLines->where('match_status', 'unmatched');
        $excludedLines = $bankReconciliation->statementLines->where('match_status', 'excluded');

        return view('admin.bank_reconciliation.show', [
            'title' => "Reconciliation {$bankReconciliation->reconciliation_code} — Remote Center ERP",
            'reconciliation' => $bankReconciliation,
            'matchedLines' => $matchedLines,
            'suggestedLines' => $suggestedLines,
            'unmatchedLines' => $unmatchedLines,
            'excludedLines' => $excludedLines,
            'unreconciledSystemEntries' => $unreconciledSystemEntries,
        ]);
    }

    // ── Import Statement ────────────────────────────────────────────

    public function importStatement(Request $request, BankReconciliation $bankReconciliation)
    {
        if (!$bankReconciliation->isEditable()) {
            return back()->with('error', "Cannot import into a {$bankReconciliation->status} reconciliation.");
        }

        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        try {
            $csv = $request->file('csv_file');
            $path = $csv->getRealPath();
            $handle = fopen($path, 'r');

            $headers = fgetcsv($handle); // Skip header row
            $lines = [];

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 3) {
                    continue; // Skip malformed rows
                }

                // Normalize headers to associative array
                $lineData = [];
                foreach ($headers as $idx => $header) {
                    $key = trim($header);
                    $lineData[$key] = isset($row[$idx]) ? trim($row[$idx]) : null;
                }

                $lines[] = $lineData;
            }

            fclose($handle);

            $count = $this->reconciliationService->importStatementLines($bankReconciliation, $lines);

            return back()->with('success', "Imported {$count} statement lines. Auto-matching has been applied.");
        } catch (\Throwable $e) {
            Log::error('Bank statement import failed', [
                'reconciliation_id' => $bankReconciliation->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    // ── Import Statement Page ───────────────────────────────────────

    public function importStatementPage()
    {
        $banks = Bank::active()->with('ledgerMapping.ledger')->orderBy('bank_name')->get();
        $reconciliations = BankReconciliation::whereIn('status', ['draft', 'in_progress'])
            ->with('bank')
            ->orderByDesc('created_at')
            ->get();

        return view('admin.bank_reconciliation.import', [
            'title' => 'Import Bank Statement — Remote Center ERP',
            'banks' => $banks,
            'reconciliations' => $reconciliations,
        ]);
    }

    // ── Auto-Match ──────────────────────────────────────────────────

    public function autoMatch(BankReconciliation $bankReconciliation)
    {
        if (!$bankReconciliation->isEditable()) {
            return back()->with('error', "Cannot auto-match a {$bankReconciliation->status} reconciliation.");
        }

        try {
            $count = $this->reconciliationService->autoMatch($bankReconciliation);

            return back()->with('success', "Auto-matched {$count} statement lines.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Auto-match failed: ' . $e->getMessage());
        }
    }

    // ── Manual Match ────────────────────────────────────────────────

    public function manualMatch(Request $request, BankReconciliation $bankReconciliation)
    {
        $validated = $request->validate([
            'statement_line_id' => 'required|exists:bank_statement_lines,id',
            'journal_line_id' => 'required|exists:journal_lines,id',
        ]);

        try {
            $this->reconciliationService->manualMatch(
                $bankReconciliation,
                $validated['statement_line_id'],
                $validated['journal_line_id']
            );

            return back()->with('success', 'Items matched successfully.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Match failed: ' . $e->getMessage());
        }
    }

    // ── Unmatch ─────────────────────────────────────────────────────

    public function unmatch(Request $request, BankReconciliation $bankReconciliation)
    {
        $validated = $request->validate([
            'statement_line_id' => 'required|exists:bank_statement_lines,id',
        ]);

        try {
            $this->reconciliationService->unmatch($bankReconciliation, $validated['statement_line_id']);

            return back()->with('success', 'Items unmatched successfully.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Unmatch failed: ' . $e->getMessage());
        }
    }

    // ── Complete Reconciliation ─────────────────────────────────────

    public function complete(BankReconciliation $bankReconciliation)
    {
        try {
            $this->reconciliationService->completeReconciliation($bankReconciliation, Auth::id());

            return redirect()
                ->route('admin.bank-reconciliation.show', $bankReconciliation)
                ->with('success', "Reconciliation {$bankReconciliation->reconciliation_code} completed successfully.");
        } catch (\Throwable $e) {
            Log::error('Bank reconciliation completion failed', [
                'reconciliation_id' => $bankReconciliation->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to complete: ' . $e->getMessage());
        }
    }

    // ── Reverse Reconciliation ──────────────────────────────────────

    public function reverse(Request $request, BankReconciliation $bankReconciliation)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $this->reconciliationService->reverseReconciliation(
                $bankReconciliation,
                Auth::id(),
                $validated['reason']
            );

            return redirect()
                ->route('admin.bank-reconciliation.show', $bankReconciliation)
                ->with('success', "Reconciliation {$bankReconciliation->reconciliation_code} reversed successfully.");
        } catch (\Throwable $e) {
            Log::error('Bank reconciliation reversal failed', [
                'reconciliation_id' => $bankReconciliation->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to reverse: ' . $e->getMessage());
        }
    }

    // ── Unreconciled Entries Report ─────────────────────────────────

    public function unreconciled(Request $request)
    {
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;

        $entries = $this->reconciliationService->getAllUnreconciledEntries($branchId);

        // Group by bank
        $grouped = $entries->groupBy('bank_name');

        $banks = Bank::active()->orderBy('bank_name')->get();

        return view('admin.bank_reconciliation.unreconciled', [
            'title' => 'Unreconciled Bank Entries — Remote Center ERP',
            'entries' => $entries,
            'grouped' => $grouped,
            'banks' => $banks,
        ]);
    }
}
