<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dimension;
use App\Models\DimensionValue;
use App\Services\Budgeting\DimensionReportingService;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dimension Controller — Phase 6: Cost Center / Dimension Tagging
 *
 * Manages dimensions (cost_center, profit_center, department, project, location)
 * and their values. Also provides segment reporting (P&L by department, etc.)
 */
class DimensionController extends Controller
{
    public function __construct(
        private DimensionReportingService $reportingService
    ) {}

    /**
     * List all dimensions.
     */
    public function index(Request $request)
    {
        $query = Dimension::with(['values', 'creator']);

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $dimensions = $query->orderBy('name')->paginate(20);

        return view('admin.dimensions.index', [
            'title'      => 'Dimensions & Cost Centers',
            'dimensions' => $dimensions,
            'typeOptions' => Dimension::typeOptions(),
        ]);
    }

    /**
     * Create a new dimension.
     */
    public function create()
    {
        return view('admin.dimensions.create', [
            'title'      => 'Create Dimension',
            'typeOptions' => Dimension::typeOptions(),
        ]);
    }

    /**
     * Store a new dimension.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'type'        => 'required|in:cost_center,profit_center,department,project,location',
            // G-332 (G13) FINANCE-DIM-1: partial-unique validation — allows
            // code reuse after soft-delete. The DB constraint was changed from
            // a plain UNIQUE to a partial UNIQUE INDEX ... WHERE deleted_at IS
            // NULL by migration 2026_09_06_000008. The Laravel unique rule's
            // 4th+ args add a WHERE clause: deleted_at IS NULL means the rule
            // only considers non-deleted rows when checking uniqueness.
            'code'        => 'required|string|max:20|unique:dimensions,code,NULL,id,deleted_at,NULL',
            'description' => 'nullable|string',
        ]);

        try {
            $dimension = Dimension::create([
                ...$validated,
                'is_active'  => true,
                'created_by' => auth()->id(),
            ]);

            return redirect()->route('admin.dimensions.show', $dimension)
                ->with('success', "Dimension '{$dimension->name}' created successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Show a dimension with its values and usage stats.
     */
    public function show(Dimension $dimension, Request $request)
    {
        $dimension->load(['values.branch', 'creator']);

        $fromDate = $request->input('from_date', now()->startOfYear()->format('Y-m-d'));
        $toDate = $request->input('to_date', now()->format('Y-m-d'));

        $usageSummary = $this->reportingService->getDimensionUsageSummary(
            $dimension->id, $fromDate, $toDate
        );

        return view('admin.dimensions.show', [
            'title'      => $dimension->name,
            'dimension'  => $dimension,
            'usageSummary' => $usageSummary,
            'fromDate'   => $fromDate,
            'toDate'     => $toDate,
        ]);
    }

    /**
     * Edit a dimension.
     */
    public function edit(Dimension $dimension)
    {
        return view('admin.dimensions.edit', [
            'title'      => "Edit Dimension: {$dimension->name}",
            'dimension'  => $dimension,
            'typeOptions' => Dimension::typeOptions(),
        ]);
    }

    /**
     * Update a dimension.
     */
    public function update(Request $request, Dimension $dimension)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'type'        => 'required|in:cost_center,profit_center,department,project,location',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
        ]);

        $dimension->update($validated);

        return redirect()->route('admin.dimensions.show', $dimension)
            ->with('success', "Dimension '{$dimension->name}' updated.");
    }

    /**
     * Store a new dimension value.
     */
    public function storeValue(Request $request, Dimension $dimension)
    {
        $validated = $request->validate([
            'code'      => 'required|string|max:30',
            'name'      => 'required|string|max:150',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        DimensionValue::create([
            'dimension_id' => $dimension->id,
            'code'         => $validated['code'],
            'name'         => $validated['name'],
            'branch_id'    => $validated['branch_id'] ?? null,
            'is_active'    => true,
            'created_by'   => auth()->id(),
        ]);

        return redirect()->route('admin.dimensions.show', $dimension)
            ->with('success', "Dimension value '{$validated['name']}' added.");
    }

    /**
     * Toggle a dimension value's active status.
     */
    public function toggleValue(Dimension $dimension, DimensionValue $value)
    {
        $value->update(['is_active' => !$value->is_active]);

        return redirect()->route('admin.dimensions.show', $dimension)
            ->with('success', "Dimension value '{$value->name}' " . ($value->is_active ? 'activated' : 'deactivated') . '.');
    }

    /**
     * Soft-delete a dimension and its values (G-343 / G19).
     *
     * Refuses if any journal_lines are tagged to the dimension's values —
     * the dimension must remain visible in the segment-report dropdown for
     * historical reporting. The dimension + its values are soft-deleted in
     * a single DB::transaction (atomic). Use deactivate (toggle is_active)
     * for the non-destructive path; use destroy only for mistakenly-created
     * dimensions that have never been used.
     */
    public function destroy(Dimension $dimension)
    {
        // Pre-check: refuse if any journal_lines reference this dimension's values.
        $taggedCount = DB::table('journal_lines')
            ->whereIn('dimension_value_id', function ($q) use ($dimension) {
                $q->select('id')->from('dimension_values')
                  ->where('dimension_id', $dimension->id);
            })
            ->whereNotNull('dimension_value_id')
            ->count();

        if ($taggedCount > 0) {
            return back()->with('error',
                "Cannot delete dimension '{$dimension->name}': {$taggedCount} journal line(s) are tagged to its values. "
                . 'Deactivate the dimension instead (toggle is_active) to hide it from new entries while preserving historical reporting.');
        }

        try {
            DB::transaction(function () use ($dimension) {
                // Soft-delete values first, then the dimension (preserves the FK).
                $dimension->values()->each(fn ($v) => $v->delete());
                $dimension->delete();
            });

            return redirect()->route('admin.dimensions.index')
                ->with('success', "Dimension '{$dimension->name}' deleted.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to delete dimension: ' . $e->getMessage());
        }
    }

    /**
     * Segment P&L report for a specific dimension value.
     */
    public function segmentPnl(Request $request)
    {
        $dimensionId = $request->input('dimension_id');
        $dimensionValueId = $request->input('dimension_value_id');
        $fromDate = $request->input('from_date', now()->startOfYear()->format('Y-m-d'));
        $toDate = $request->input('to_date', now()->format('Y-m-d'));
        $branchId = $request->input('branch_id');

        $dimensions = Dimension::with('values')->active()->orderBy('name')->get();
        $branches = Branch::active()->orderBy('branch_name')->get();

        $segmentData = null;
        $comparisonData = null;

        if ($dimensionValueId) {
            $segmentData = $this->reportingService->segmentProfitAndLoss(
                (int) $dimensionValueId, $fromDate, $toDate, $branchId ? (int) $branchId : null
            );
        } elseif ($dimensionId) {
            $comparisonData = $this->reportingService->dimensionComparison(
                (int) $dimensionId, $fromDate, $toDate, $branchId ? (int) $branchId : null
            );
        }

        return view('admin.dimensions.segment-pnl', [
            'title'          => 'Segment P&L Report',
            'dimensions'     => $dimensions,
            'branches'       => $branches,
            'segmentData'    => $segmentData,
            'comparisonData' => $comparisonData,
            'selectedDimension' => $dimensionId,
            'selectedValue'     => $dimensionValueId,
            'fromDate'       => $fromDate,
            'toDate'         => $toDate,
            'selectedBranch' => $branchId,
        ]);
    }

    /**
     * Segment Balance Sheet for a specific dimension value.
     */
    public function segmentBs(Request $request)
    {
        $dimensionValueId = $request->input('dimension_value_id');
        $asOfDate = $request->input('as_of_date', now()->format('Y-m-d'));
        $branchId = $request->input('branch_id');

        $dimensions = Dimension::with('values')->active()->orderBy('name')->get();
        $branches = Branch::active()->orderBy('branch_name')->get();

        $segmentData = null;
        if ($dimensionValueId) {
            $segmentData = $this->reportingService->segmentBalanceSheet(
                (int) $dimensionValueId, $asOfDate, $branchId ? (int) $branchId : null
            );
        }

        return view('admin.dimensions.segment-bs', [
            'title'          => 'Segment Balance Sheet',
            'dimensions'     => $dimensions,
            'branches'       => $branches,
            'segmentData'    => $segmentData,
            'selectedValue'  => $dimensionValueId,
            'asOfDate'       => $asOfDate,
            'selectedBranch' => $branchId,
        ]);
    }
}
