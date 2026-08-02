<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ConsolidationRun;
use App\Models\EliminationRule;
use App\Models\EliminationEntry;
use App\Models\Ledger;
use App\Services\Consolidation\ConsolidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ConsolidationController — Phase 8: Intercompany & Consolidation
 *
 * Handles:
 *   - Consolidation dashboard (list of runs, status overview)
 *   - Run consolidation (create a new consolidation run)
 *   - Post consolidation (post elimination journal entries)
 *   - Reverse consolidation (reverse elimination entries)
 *   - Consolidated financial statements (TB, BS, P&L)
 *   - Intercompany reconciliation
 *   - Elimination rules management (CRUD)
 *   - Company management (CRUD)
 */
class ConsolidationController extends Controller
{
    public function __construct(
        private ConsolidationService $consolidationService,
    ) {}

    // ── Consolidation Runs ─────────────────────────────────────────

    /**
     * Display the consolidation dashboard — list of all runs.
     */
    public function index(Request $request)
    {
        $runs = ConsolidationRun::with(['creator', 'poster', 'fiscalYear'])
            ->orderByDesc('created_at')
            ->paginate(20);

        $activeRules = EliminationRule::active()->count();
        $draftRuns = ConsolidationRun::where('status', 'draft')->count();

        return view('admin.consolidation.index', [
            'title' => 'Consolidation — Remote Center ERP',
            'runs' => $runs,
            'activeRules' => $activeRules,
            'draftRuns' => $draftRuns,
        ]);
    }

    /**
     * Show the form to create a new consolidation run.
     */
    public function create()
    {
        $companies = Company::active()->orderBy('company_name')->get();
        $fiscalYears = \App\Models\FiscalYear::where('status', 'active')->orderByDesc('start_date')->get();
        $rules = EliminationRule::active()->orderBy('sort_order')->get();

        return view('admin.consolidation.create', [
            'title' => 'Run Consolidation — Remote Center ERP',
            'companies' => $companies,
            'fiscalYears' => $fiscalYears,
            'rules' => $rules,
        ]);
    }

    /**
     * Store a new consolidation run.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
            'fiscal_year_id' => 'nullable|exists:fiscal_years,id',
            'company_ids' => 'nullable|array',
            'company_ids.*' => 'exists:companies,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $validated['created_by'] = Auth::id();

        try {
            $run = $this->consolidationService->runConsolidation($validated);

            return redirect()
                ->route('admin.consolidation.show', $run)
                ->with('success', "Consolidation run '{$run->run_code}' created successfully. "
                    . $run->getEntryCount() . " elimination entries calculated.");
        } catch (\Throwable $e) {
            Log::error('Consolidation run failed', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return back()
                ->withInput()
                ->with('error', 'Failed to create consolidation run: ' . $e->getMessage());
        }
    }

    /**
     * Display a consolidation run with its elimination entries.
     */
    public function show(ConsolidationRun $consolidationRun)
    {
        $consolidationRun->load([
            'eliminationEntries.eliminationRule',
            'eliminationEntries.debitLedger',
            'eliminationEntries.creditLedger',
            'eliminationEntries.fromBranch',
            'eliminationEntries.toBranch',
            'eliminationEntries.journalEntry',
            'creator',
            'poster',
            'reverser',
            'fiscalYear',
        ]);

        return view('admin.consolidation.show', [
            'title' => "Consolidation Run {$consolidationRun->run_code} — Remote Center ERP",
            'run' => $consolidationRun,
        ]);
    }

    /**
     * Post a draft consolidation run.
     */
    public function post(Request $request, ConsolidationRun $consolidationRun)
    {
        try {
            $run = $this->consolidationService->postConsolidation($consolidationRun, Auth::id());

            return redirect()
                ->route('admin.consolidation.show', $run)
                ->with('success', "Consolidation run '{$run->run_code}' posted successfully. "
                    . "Elimination journal entries have been created.");
        } catch (\Throwable $e) {
            Log::error('Consolidation post failed', [
                'run_id' => $consolidationRun->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to post consolidation: ' . $e->getMessage());
        }
    }

    /**
     * Reverse a posted consolidation run.
     */
    public function reverse(Request $request, ConsolidationRun $consolidationRun)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $run = $this->consolidationService->reverseConsolidation(
                $consolidationRun,
                Auth::id(),
                $validated['reason']
            );

            return redirect()
                ->route('admin.consolidation.show', $run)
                ->with('success', "Consolidation run '{$run->run_code}' reversed successfully.");
        } catch (\Throwable $e) {
            Log::error('Consolidation reversal failed', [
                'run_id' => $consolidationRun->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to reverse consolidation: ' . $e->getMessage());
        }
    }

    /**
     * Delete a draft consolidation run.
     */
    public function destroy(ConsolidationRun $consolidationRun)
    {
        try {
            $this->consolidationService->deleteDraftRun($consolidationRun);

            return redirect()
                ->route('admin.consolidation.index')
                ->with('success', 'Draft consolidation run deleted.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to delete: ' . $e->getMessage());
        }
    }

    // ── Consolidated Financial Statements ──────────────────────────

    /**
     * Consolidated Trial Balance.
     */
    public function consolidatedTrialBalance(Request $request)
    {
        $toDate = $request->input('to_date', now()->format('Y-m-d'));
        $fromDate = $request->input('from_date', now()->startOfYear()->format('Y-m-d'));
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;

        $report = $this->consolidationService->getConsolidatedTrialBalance($fromDate, $toDate, $companyId);
        $companies = Company::active()->orderBy('company_name')->get();

        return view('admin.consolidation.trial_balance', array_merge($report, [
            'companies' => $companies,
        ]));
    }

    /**
     * Consolidated Balance Sheet.
     */
    public function consolidatedBalanceSheet(Request $request)
    {
        $asOfDate = $request->input('as_of_date', now()->format('Y-m-d'));
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;

        $report = $this->consolidationService->getConsolidatedBalanceSheet($asOfDate, $companyId);
        $companies = Company::active()->orderBy('company_name')->get();

        return view('admin.consolidation.balance_sheet', array_merge($report, [
            'companies' => $companies,
        ]));
    }

    /**
     * Consolidated Profit & Loss.
     */
    public function consolidatedProfitAndLoss(Request $request)
    {
        $toDate = $request->input('to_date', now()->format('Y-m-d'));
        $fromDate = $request->input('from_date', now()->startOfYear()->format('Y-m-d'));
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;

        $report = $this->consolidationService->getConsolidatedProfitAndLoss($fromDate, $toDate, $companyId);
        $companies = Company::active()->orderBy('company_name')->get();

        return view('admin.consolidation.profit_and_loss', array_merge($report, [
            'companies' => $companies,
        ]));
    }

    // ── Intercompany Reconciliation ─────────────────────────────────

    /**
     * Intercompany reconciliation report.
     */
    public function intercompanyReconciliation(Request $request)
    {
        $asOfDate = $request->input('as_of_date', now()->format('Y-m-d'));

        $report = $this->consolidationService->getIntercompanyReconciliation($asOfDate);

        return view('admin.consolidation.reconciliation', $report);
    }

    // ── Elimination Rules ──────────────────────────────────────────

    /**
     * List all elimination rules.
     */
    public function rulesIndex()
    {
        $rules = EliminationRule::with(['debitLedger', 'creditLedger', 'eliminationDebitLedger', 'eliminationCreditLedger'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('admin.consolidation.rules', [
            'title' => 'Elimination Rules — Remote Center ERP',
            'rules' => $rules,
        ]);
    }

    /**
     * Store a new elimination rule.
     */
    public function rulesStore(Request $request)
    {
        $validated = $request->validate([
            'rule_code' => 'required|string|max:30|unique:elimination_rules,rule_code',
            'rule_name' => 'required|string|max:100',
            'rule_type' => 'required|in:balance,revenue,investment,dividend,custom',
            'description' => 'nullable|string|max:255',
            'debit_ledger_id' => 'required|exists:ledgers,id',
            'credit_ledger_id' => 'required|exists:ledgers,id|different:debit_ledger_id',
            'elimination_debit_ledger_id' => 'nullable|exists:ledgers,id',
            'elimination_credit_ledger_id' => 'nullable|exists:ledgers,id',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $validated['created_by'] = Auth::id();
        $validated['is_active'] = $request->boolean('is_active', true);

        EliminationRule::create($validated);

        return redirect()
            ->route('admin.consolidation.rules')
            ->with('success', "Elimination rule '{$validated['rule_code']}' created successfully.");
    }

    /**
     * Toggle an elimination rule's active status.
     */
    public function rulesToggle(EliminationRule $rule)
    {
        $rule->update(['is_active' => !$rule->is_active]);

        $status = $rule->is_active ? 'activated' : 'deactivated';
        return back()->with('success', "Rule '{$rule->rule_code}' {$status}.");
    }

    // ── Companies ──────────────────────────────────────────────────

    /**
     * List all companies.
     */
    public function companiesIndex()
    {
        $companies = Company::withCount('branches')->orderBy('company_name')->get();

        return view('admin.consolidation.companies', [
            'title' => 'Companies — Remote Center ERP',
            'companies' => $companies,
        ]);
    }

    /**
     * Store a new company.
     */
    public function companiesStore(Request $request)
    {
        $validated = $request->validate([
            'company_code' => 'required|string|max:20|unique:companies,company_code',
            'company_name' => 'required|string|max:100',
            'legal_name' => 'nullable|string|max:150',
            'tax_id' => 'nullable|string|max:50',
            'registration_no' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:100',
            'currency' => 'string|max:3|in:BDT,USD,EUR,GBP,INR',
            'is_consolidation_parent' => 'boolean',
            'ownership_pct' => 'numeric|min:0|max:100',
            'status' => 'in:active,inactive,dormant',
            'description' => 'nullable|string',
        ]);

        $validated['created_by'] = Auth::id();
        $validated['is_consolidation_parent'] = $request->boolean('is_consolidation_parent', false);
        $validated['ownership_pct'] = $validated['ownership_pct'] ?? 100.00;
        $validated['currency'] = $validated['currency'] ?? 'BDT';
        $validated['status'] = $validated['status'] ?? 'active';

        Company::create($validated);

        return redirect()
            ->route('admin.consolidation.companies')
            ->with('success', "Company '{$validated['company_name']}' created successfully.");
    }
}
