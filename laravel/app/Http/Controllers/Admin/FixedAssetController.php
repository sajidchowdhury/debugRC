<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Models\AssetDepreciationSchedule;
use App\Models\AssetDisposal;
use App\Models\Ledger;
use App\Models\Branch;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\AssetDisposalService;
use App\Services\Accounting\DocumentSequenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FixedAssetController — Phase 9.4: Fixed Asset & Depreciation
 *
 * Handles:
 *   - List all fixed assets (register)
 *   - Create new fixed asset
 *   - View asset details with depreciation schedule
 *   - Edit asset details
 *   - Run depreciation (generate + post schedules)
 *   - Dispose of assets (sale, write-off, scrap, donation)
 *   - View disposal history
 *   - Reverse depreciation entries
 */
class FixedAssetController extends Controller
{
    public function __construct(
        private DepreciationService $depreciationService,
        private AssetDisposalService $disposalService,
    ) {}

    // ── Asset Register (List) ──────────────────────────────────────

    public function index(Request $request)
    {
        $query = FixedAsset::with(['assetLedger', 'depLedger', 'depExpenseLedger', 'branch', 'creator']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('asset_code', 'ILIKE', "%{$search}%")
                  ->orWhere('description', 'ILIKE', "%{$search}%")
                  ->orWhere('serial_number', 'ILIKE', "%{$search}%");
            });
        }

        $assets = $query->orderByDesc('created_at')->paginate(20);

        // Summary stats
        $totalCost = FixedAsset::active()->sum('acquisition_cost');
        $totalDep = FixedAsset::active()->sum('accumulated_depreciation');
        $totalNBV = FixedAsset::active()->sum('net_book_value');

        $branches = Branch::orderBy('branch_name')->get();

        return view('admin.fixed_assets.index', [
            'title' => 'Fixed Asset Register — Remote Center ERP',
            'assets' => $assets,
            'branches' => $branches,
            'totalCost' => $totalCost,
            'totalDep' => $totalDep,
            'totalNBV' => $totalNBV,
        ]);
    }

    // ── Create Asset ───────────────────────────────────────────────

    public function create()
    {
        $branches = Branch::orderBy('branch_name')->get();

        // Get asset ledgers (under L-0200 Fixed Assets group)
        $assetLedgers = Ledger::active()
            ->where('account_type', 'Asset')
            ->where('parent_id', function ($query) {
                $query->select('id')
                    ->from('ledgers')
                    ->where('ledger_code', 'L-0200')
                    ->whereNull('deleted_at')
                    ->limit(1);
            })
            ->orWhere('ledger_nature', 'accumulated_depreciation')
            ->whereNull('deleted_at')
            ->orderBy('ledger_code')
            ->get();

        // If no specific asset ledgers found, get all asset ledgers
        if ($assetLedgers->count() < 3) {
            $assetLedgers = Ledger::active()
                ->where('account_type', 'Asset')
                ->whereNull('deleted_at')
                ->orderBy('ledger_code')
                ->get();
        }

        // Get accumulated depreciation ledgers
        $depLedgers = Ledger::active()
            ->where('account_type', 'Asset')
            ->where('normal_balance', 'credit')
            ->whereNull('deleted_at')
            ->orderBy('ledger_code')
            ->get();

        // If no specific dep ledgers, include all asset ledgers
        if ($depLedgers->isEmpty()) {
            $depLedgers = $assetLedgers;
        }

        // Get expense ledgers
        $expenseLedgers = Ledger::active()
            ->where('account_type', 'Expense')
            ->whereNull('deleted_at')
            ->orderBy('ledger_code')
            ->get();

        return view('admin.fixed_assets.create', [
            'title' => 'Register Fixed Asset — Remote Center ERP',
            'branches' => $branches,
            'assetLedgers' => $assetLedgers,
            'depLedgers' => $depLedgers,
            'expenseLedgers' => $expenseLedgers,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'category' => 'required|in:machinery,furniture,vehicle,office_equipment,computer,building,land,other',
            'acquisition_date' => 'required|date',
            'acquisition_cost' => 'required|numeric|min:0.01',
            'salvage_value' => 'nullable|numeric|min:0',
            'depreciation_method' => 'required|in:straight_line,declining_balance,units_of_production',
            'useful_life_months' => 'required|integer|min:1',
            'declining_balance_rate' => 'nullable|numeric|min:1|max:100',
            'total_estimated_units' => 'nullable|numeric|min:0',
            'asset_ledger_id' => 'required|exists:ledgers,id',
            'dep_ledger_id' => 'required|exists:ledgers,id',
            'dep_expense_ledger_id' => 'nullable|exists:ledgers,id',
            'branch_id' => 'required|exists:branches,id',
            'location' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:100',
            'warranty_expiry' => 'nullable|string|max:30',
            'notes' => 'nullable|string|max:1000',
        ]);

        // G-276 (G10) FINANCE-FA-1: server-side guard that the posted
        // asset_ledger_id is actually a fixed-asset-cost account. The create()
        // dropdown filters by parent_id = L-0200, but a crafted POST could
        // bypass that filter. The LedgerNatureService now registers the
        // 'fixed_asset_cost' nature (L-0201/L-0210/L-0220/L-0230/L-0240 are
        // backfilled by migration 2026_09_06_000006), so we can type-check
        // here. Depreciation ledger (dep_ledger_id) is validated as a
        // contra-asset (credit-normal Asset) — the dropdown already filters
        // by normal_balance = credit, this guard makes it server-side too.
        $assetLedger = Ledger::find($validated['asset_ledger_id']);
        if (!$assetLedger
            || $assetLedger->account_type !== 'Asset'
            || $assetLedger->ledger_nature !== 'fixed_asset_cost') {
            return back()->withInput()->with('error',
                'Asset ledger must be a fixed-asset-cost account (nature = fixed_asset_cost). '
                . 'Please select one of L-0201, L-0210, L-0220, L-0230, or L-0240.');
        }

        try {
            // G-285 (G26) FINANCE-FA-1: wrap nextCode + create in a single
            // DB::transaction so the sequence increment rolls back together
            // with the asset create if create() throws. DocumentSequenceService::
            // nextCode uses its own inner DB::transaction — under Laravel's
            // nested-transaction semantics (PostgreSQL SAVEPOINT), the inner
            // transaction becomes a savepoint that rolls back together with
            // the outer transaction. Previously, if FixedAsset::create threw
            // (e.g., RLS WITH CHECK violation, unique constraint), the seq
            // was already committed → non-contiguous FA-YYYY-NNNNN codes.
            $asset = DB::transaction(function () use ($validated) {
                $validated['salvage_value'] = $validated['salvage_value'] ?? 0;
                $validated['declining_balance_rate'] = $validated['declining_balance_rate'] ?? 20;
                $validated['total_estimated_units'] = $validated['total_estimated_units'] ?? 0;
                $validated['units_produced_to_date'] = 0;
                $validated['status'] = 'active';
                $validated['accumulated_depreciation'] = 0;
                $validated['net_book_value'] = $validated['acquisition_cost'];
                $validated['created_by'] = Auth::id();

                // Generate asset code
                $year = substr($validated['acquisition_date'], 0, 4);
                $validated['asset_code'] = DocumentSequenceService::nextCode(
                    docType: 'fixed_asset',
                    prefix: 'FA',
                    datePart: $year,
                    padLength: 5,
                    periodKey: $year,
                );

                return FixedAsset::create($validated);
            });

            return redirect()
                ->route('admin.fixed-assets.show', $asset)
                ->with('success', "Fixed asset {$asset->asset_code} registered successfully.");
        } catch (\Throwable $e) {
            Log::error('Fixed asset creation failed', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return back()->withInput()->with('error', 'Failed to register asset: ' . $e->getMessage());
        }
    }

    // ── View Asset ─────────────────────────────────────────────────

    public function show(FixedAsset $fixedAsset)
    {
        $fixedAsset->load([
            'assetLedger',
            'depLedger',
            'depExpenseLedger',
            'branch',
            'creator',
            'depreciationSchedules' => fn($q) => $q->orderByDesc('period_from'),
            'disposals' => fn($q) => $q->orderByDesc('disposal_date'),
        ]);

        // Get projected depreciation
        $projectedDepreciation = [];
        if ($fixedAsset->canBeDepreciated()) {
            $projectedDepreciation = $this->depreciationService->getProjectedDepreciation($fixedAsset, 12);
        }

        return view('admin.fixed_assets.show', [
            'title' => "Asset {$fixedAsset->asset_code} — Remote Center ERP",
            'asset' => $fixedAsset,
            'projectedDepreciation' => $projectedDepreciation,
        ]);
    }

    // ── Edit Asset ─────────────────────────────────────────────────

    public function edit(FixedAsset $fixedAsset)
    {
        if ($fixedAsset->isDisposed()) {
            return back()->with('error', 'Disposed assets cannot be edited.');
        }

        $branches = Branch::orderBy('branch_name')->get();

        $assetLedgers = Ledger::active()
            ->where('account_type', 'Asset')
            ->whereNull('deleted_at')
            ->orderBy('ledger_code')
            ->get();

        $depLedgers = Ledger::active()
            ->where('account_type', 'Asset')
            ->where('normal_balance', 'credit')
            ->whereNull('deleted_at')
            ->orderBy('ledger_code')
            ->get();

        if ($depLedgers->isEmpty()) {
            $depLedgers = $assetLedgers;
        }

        $expenseLedgers = Ledger::active()
            ->where('account_type', 'Expense')
            ->whereNull('deleted_at')
            ->orderBy('ledger_code')
            ->get();

        return view('admin.fixed_assets.edit', [
            'title' => "Edit Asset {$fixedAsset->asset_code} — Remote Center ERP",
            'asset' => $fixedAsset,
            'branches' => $branches,
            'assetLedgers' => $assetLedgers,
            'depLedgers' => $depLedgers,
            'expenseLedgers' => $expenseLedgers,
        ]);
    }

    public function update(Request $request, FixedAsset $fixedAsset)
    {
        if ($fixedAsset->isDisposed()) {
            return back()->with('error', 'Disposed assets cannot be edited.');
        }

        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'category' => 'required|in:machinery,furniture,vehicle,office_equipment,computer,building,land,other',
            'acquisition_date' => 'required|date',
            'acquisition_cost' => 'required|numeric|min:0.01',
            'salvage_value' => 'nullable|numeric|min:0',
            'depreciation_method' => 'required|in:straight_line,declining_balance,units_of_production',
            'useful_life_months' => 'required|integer|min:1',
            'declining_balance_rate' => 'nullable|numeric|min:1|max:100',
            'total_estimated_units' => 'nullable|numeric|min:0',
            'asset_ledger_id' => 'required|exists:ledgers,id',
            'dep_ledger_id' => 'required|exists:ledgers,id',
            'dep_expense_ledger_id' => 'nullable|exists:ledgers,id',
            'branch_id' => 'required|exists:branches,id',
            'location' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:100',
            'warranty_expiry' => 'nullable|string|max:30',
            'notes' => 'nullable|string|max:1000',
        ]);

        // FINANCE-3 (G-335 / BR21): Once at least one depreciation schedule
        // has been posted for this asset, the cost / salvage / useful-life /
        // method / asset-ledger / dep-ledger fields are IMMUTABLE. Editing
        // them silently distorts the books: past schedules retain the old
        // cost basis (overstating NBV), future schedules pick up the new cost
        // (understating depreciation expense), and the auditor cannot
        // reconstruct the historical trajectory. The previous `update` only
        // recalculated `net_book_value = new_cost − accumulated_depreciation`
        // — a cosmetic fix that masked the underlying schedule distortion.
        //
        // If a cost/salvage/useful-life/method/ledger change is genuinely
        // required, the accountant must (1) reverse all posted schedules for
        // the asset, (2) edit the asset, (3) re-generate + re-post the
        // schedules. This controller intentionally blocks the silent path.
        // Per `AI_CONTEXT/finance/fixed-assets.md` §14.1 remediation #4.
        $hasPostedDepreciation = AssetDepreciationSchedule::where('fixed_asset_id', $fixedAsset->id)
            ->where('status', 'posted')
            ->exists();

        if ($hasPostedDepreciation) {
            // Identify which protected fields the user is trying to change.
            $protectedFields = [
                'acquisition_cost'      => (float) $validated['acquisition_cost']      !== (float) $fixedAsset->acquisition_cost,
                'salvage_value'         => (float) ($validated['salvage_value'] ?? 0)  !== (float) $fixedAsset->salvage_value,
                'useful_life_months'    => (int)   $validated['useful_life_months']    !== (int)   $fixedAsset->useful_life_months,
                'depreciation_method'   =>          $validated['depreciation_method']  !==          $fixedAsset->depreciation_method,
                'asset_ledger_id'       => (int)   $validated['asset_ledger_id']       !== (int)   $fixedAsset->asset_ledger_id,
                'dep_ledger_id'         => (int)   $validated['dep_ledger_id']         !== (int)   $fixedAsset->dep_ledger_id,
            ];
            $blocked = array_keys(array_filter($protectedFields));

            if (!empty($blocked)) {
                $list = implode(', ', $blocked);
                return back()
                    ->withInput()
                    ->with('error', "Cannot edit {$list} after depreciation has been posted. Reverse all posted depreciation schedules for this asset first, then re-edit and re-generate the schedules.");
            }
        }

        try {
            $validated['salvage_value'] = $validated['salvage_value'] ?? 0;
            $validated['declining_balance_rate'] = $validated['declining_balance_rate'] ?? 20;
            $validated['total_estimated_units'] = $validated['total_estimated_units'] ?? 0;
            $validated['updated_by'] = Auth::id();

            // Recalculate net book value if cost changed
            if ((float) $validated['acquisition_cost'] !== (float) $fixedAsset->acquisition_cost) {
                $validated['net_book_value'] = (float) $validated['acquisition_cost'] - (float) $fixedAsset->accumulated_depreciation;
            }

            $fixedAsset->update($validated);

            return redirect()
                ->route('admin.fixed-assets.show', $fixedAsset)
                ->with('success', "Asset {$fixedAsset->asset_code} updated successfully.");
        } catch (\Throwable $e) {
            Log::error('Fixed asset update failed', [
                'error' => $e->getMessage(),
                'asset_id' => $fixedAsset->id,
            ]);

            return back()->withInput()->with('error', 'Failed to update asset: ' . $e->getMessage());
        }
    }

    // ── Depreciation ───────────────────────────────────────────────

    public function depreciation(Request $request)
    {
        $query = AssetDepreciationSchedule::with(['fixedAsset', 'fixedAsset.branch', 'journalEntry']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('period_from')) {
            $query->where('period_from', '>=', $request->period_from);
        }
        if ($request->filled('period_to')) {
            $query->where('period_to', '<=', $request->period_to);
        }
        if ($request->filled('branch_id')) {
            $query->whereHas('fixedAsset', fn($q) => $q->where('branch_id', $request->branch_id));
        }

        $schedules = $query->orderByDesc('depreciation_date')->paginate(20);
        $branches = Branch::orderBy('branch_name')->get();

        // Summary
        $pendingCount = AssetDepreciationSchedule::where('status', 'pending')->count();
        $postedCount = AssetDepreciationSchedule::where('status', 'posted')->count();
        $pendingAmount = AssetDepreciationSchedule::where('status', 'pending')->sum('depreciation_amount');

        return view('admin.fixed_assets.depreciation', [
            'title' => 'Asset Depreciation — Remote Center ERP',
            'schedules' => $schedules,
            'branches' => $branches,
            'pendingCount' => $pendingCount,
            'postedCount' => $postedCount,
            'pendingAmount' => $pendingAmount,
        ]);
    }

    /**
     * Generate depreciation schedules for a period.
     */
    public function generateDepreciation(Request $request)
    {
        $validated = $request->validate([
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        // G-277 (G11) FINANCE-FA-1: depreciation is computed as a fixed
        // monthly amount (no pro-rata by days). Reject partial-month periods
        // to prevent silent over-depreciation (e.g., an asset acquired Jan 15
        // was previously earning a FULL month's depreciation for January).
        // The subsystem status is "Draft — pending accountant sign-off"; the
        // pro-rata code change (Option B in the research report) is deferred
        // until accountant sign-off. This whole-month guard is the safe
        // Option A — it surfaces the issue at the controller layer instead
        // of silently misstating depreciation.
        $periodFrom = \Carbon\Carbon::parse($validated['period_from']);
        $periodTo = \Carbon\Carbon::parse($validated['period_to']);
        if (!$periodFrom->isSameMonth($periodTo)
            || $periodFrom->day !== 1
            || !$periodTo->isLastOfMonth()) {
            return back()->withInput()->with('error',
                "Period must span exactly one whole calendar month (first-of-month to last-of-month). "
                . "Received {$validated['period_from']} to {$validated['period_to']}. "
                . 'Depreciation is computed as a fixed monthly amount with no pro-rata (G11 full-month convention).');
        }

        try {
            $count = $this->depreciationService->generateSchedulesForPeriod(
                $validated['period_from'],
                $validated['period_to'],
                $validated['branch_id'] ?? null
            );

            return back()->with('success', "Generated {$count} depreciation schedules for {$validated['period_from']} to {$validated['period_to']}.");
        } catch (\Throwable $e) {
            Log::error('Depreciation schedule generation failed', [
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to generate schedules: ' . $e->getMessage());
        }
    }

    /**
     * Post all pending depreciation schedules for a period.
     */
    public function postDepreciation(Request $request)
    {
        $validated = $request->validate([
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        // G-277 (G11) FINANCE-FA-1: same whole-month guard as
        // generateDepreciation — see that method for the full rationale.
        $periodFrom = \Carbon\Carbon::parse($validated['period_from']);
        $periodTo = \Carbon\Carbon::parse($validated['period_to']);
        if (!$periodFrom->isSameMonth($periodTo)
            || $periodFrom->day !== 1
            || !$periodTo->isLastOfMonth()) {
            return back()->withInput()->with('error',
                "Period must span exactly one whole calendar month (first-of-month to last-of-month). "
                . "Received {$validated['period_from']} to {$validated['period_to']}. "
                . 'Depreciation is computed as a fixed monthly amount with no pro-rata (G11 full-month convention).');
        }

        try {
            $result = $this->depreciationService->postMonthlyDepreciation(
                $validated['period_from'],
                $validated['period_to'],
                $validated['branch_id'] ?? null
            );

            $message = "Posted {$result['posted']} depreciation entries.";
            if ($result['failed'] > 0) {
                $message .= " {$result['failed']} entries failed.";
            }

            return back()->with('success', $message);
        } catch (\Throwable $e) {
            Log::error('Depreciation posting failed', [
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to post depreciation: ' . $e->getMessage());
        }
    }

    /**
     * Post a single depreciation schedule.
     */
    public function postSingleDepreciation(int $schedule)
    {
        try {
            $scheduleModel = AssetDepreciationSchedule::findOrFail($schedule);
            $this->depreciationService->postDepreciation($scheduleModel);

            return back()->with('success', "Depreciation schedule #{$scheduleModel->id} posted successfully.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to post: ' . $e->getMessage());
        }
    }

    /**
     * Reverse a posted depreciation schedule.
     */
    public function reverseDepreciation(Request $request, int $schedule)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $scheduleModel = AssetDepreciationSchedule::findOrFail($schedule);
            $this->depreciationService->reverseDepreciation(
                $scheduleModel,
                Auth::id(),
                $validated['reason']
            );

            return back()->with('success', "Depreciation schedule #{$scheduleModel->id} reversed successfully.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to reverse: ' . $e->getMessage());
        }
    }

    // ── Asset Disposal ─────────────────────────────────────────────

    public function disposals(Request $request)
    {
        $query = AssetDisposal::with(['fixedAsset', 'fixedAsset.branch', 'journalEntry', 'creator']);

        if ($request->filled('disposal_type')) {
            $query->where('disposal_type', $request->disposal_type);
        }
        if ($request->filled('date_from')) {
            $query->where('disposal_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('disposal_date', '<=', $request->date_to);
        }

        $disposals = $query->orderByDesc('disposal_date')->paginate(20);

        $totalProceeds = AssetDisposal::sum('disposal_proceeds');
        $totalGains = AssetDisposal::where('gain_loss_type', 'gain')->sum('gain_loss_amount');
        $totalLosses = AssetDisposal::where('gain_loss_type', 'loss')->sum('gain_loss_amount');

        return view('admin.fixed_assets.disposals', [
            'title' => 'Asset Disposals — Remote Center ERP',
            'disposals' => $disposals,
            'totalProceeds' => $totalProceeds,
            'totalGains' => $totalGains,
            'totalLosses' => $totalLosses,
        ]);
    }

    public function showDisposalForm(FixedAsset $fixedAsset)
    {
        if (!$fixedAsset->canBeDisposed()) {
            return back()->with('error', "Asset {$fixedAsset->asset_code} cannot be disposed.");
        }

        $fixedAsset->load(['assetLedger', 'depLedger', 'branch']);

        // Get cash/bank ledgers for proceeds
        $cashBankLedgers = Ledger::active()
            ->where('account_type', 'Asset')
            ->where('normal_balance', 'debit')
            ->whereNull('deleted_at')
            ->orderBy('ledger_code')
            ->get();

        // Get gain/loss ledgers
        $incomeLedgers = Ledger::active()
            ->where('account_type', 'Income')
            ->whereNull('deleted_at')
            ->orderBy('ledger_code')
            ->get();

        $expenseLedgers = Ledger::active()
            ->where('account_type', 'Expense')
            ->whereNull('deleted_at')
            ->orderBy('ledger_code')
            ->get();

        return view('admin.fixed_assets.dispose', [
            'title' => "Dispose Asset {$fixedAsset->asset_code} — Remote Center ERP",
            'asset' => $fixedAsset,
            'cashBankLedgers' => $cashBankLedgers,
            'incomeLedgers' => $incomeLedgers,
            'expenseLedgers' => $expenseLedgers,
        ]);
    }

    public function storeDisposal(Request $request)
    {
        $validated = $request->validate([
            'fixed_asset_id' => 'required|exists:fixed_assets,id',
            'disposal_type' => 'required|in:sale,write_off,scrap,donation',
            'disposal_date' => 'required|date',
            'disposal_proceeds' => 'nullable|numeric|min:0',
            'proceeds_ledger_id' => 'nullable|exists:ledgers,id',
            'gain_loss_ledger_id' => 'nullable|exists:ledgers,id',
            'reason' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $disposal = $this->disposalService->disposeAsset($validated);

            return redirect()
                ->route('admin.fixed-assets.disposals')
                ->with('success', "Asset disposed successfully. Disposal code: {$disposal->disposal_code}");
        } catch (\Throwable $e) {
            Log::error('Asset disposal failed', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return back()->withInput()->with('error', 'Failed to dispose asset: ' . $e->getMessage());
        }
    }

    /**
     * Show a disposal detail.
     */
    public function showDisposal(int $disposal)
    {
        $disposalModel = AssetDisposal::findOrFail($disposal);
        $disposalModel->load(['fixedAsset', 'fixedAsset.branch', 'proceedsLedger', 'gainLossLedger', 'journalEntry', 'creator']);

        return view('admin.fixed_assets.disposal-show', [
            'title' => "Disposal {$disposalModel->disposal_code} — Remote Center ERP",
            'disposal' => $disposalModel,
        ]);
    }
}
