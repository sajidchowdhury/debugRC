<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DamageAttachment;
use App\Models\DamageInvoice;
use App\Models\DamageReason;
use App\Models\Employee;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\Branch;
use App\Services\Reports\DamageReportService;
use App\Services\Stock\DamageIntegrityService;
use App\Services\Stock\DamageService;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Damage Controller — Phase 6.6.
 *
 * Two-phase flow (same as 6.3/6.4/6.5):
 *   - create / store: create a draft damage (no stock, no GL)
 *   - show: detail with items + stock movements + GL journal
 *   - confirm: apply stock OUT + post GL (Dr Damage Loss / Cr Inventory)
 *   - cancel: reverse if confirmed, or mark draft as cancelled
 */
class DamageController extends Controller
{
    public function __construct(
        private DamageService $damageService,
        private StockService $stockService,
        private DamageIntegrityService $integrityService,
        private DamageReportService $reportService
    ) {}

    public function index(Request $request)
    {
        // Phase 0 (Damage plan): defense-in-depth policy check behind the
        // role:admin,manager,warehouse_manager route middleware.
        $this->authorize('viewAny', DamageInvoice::class);

        // Phase 7 — quick-filter date range. ?range=today|week|month|year
        // resolves to a concrete [from, to] window and takes precedence over
        // explicit from_date/to_date (so the quick-filter buttons are
        // deterministic). When NO range AND no from_date/to_date are supplied,
        // default to month-to-date (the legacy "today only" default was too
        // narrow — operators kept seeing an empty list on the 2nd of the
        // month). Explicit from_date/to_date (the manual date pickers) still
        // win when range is absent.
        [$fromDate, $toDate, $range] = $this->resolveDateRange($request);

        $query = DamageInvoice::with(['warehouse.branch', 'items', 'accountableEmployee'])
            ->when($fromDate, fn($q) => $q->where('damage_date', '>=', $fromDate))
            ->when($toDate, fn($q) => $q->where('damage_date', '<=', $toDate))
            ->when($request->input('warehouse_id'), fn($q, $wid) => $q->where('warehouse_id', $wid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('damage_type'), fn($q, $t) => $q->where('damage_type', $t))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            // Phase 4 — filter by accountable employee ("show all damages
            // where employee X is accountable" — for HR / manager review of
            // an employee's accumulated damage responsibility).
            ->when($request->input('accountable_employee_id'), fn($q, $eid) => $q->where('accountable_employee_id', $eid))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('damage_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('damage_date', 'desc')
            ->orderBy('id', 'desc');

        $damages = $query->paginate(25);

        $warehouses = Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $branches = Branch::active()->orderBy('branch_name')->get();
        // Phase 4 — active employees for the accountable filter dropdown.
        // RLS scopes this to the user's branch (admins see all). Ordered by
        // name for a stable dropdown.
        $employees = Employee::active()->orderBy('name')->get(['id', 'employee_code', 'name', 'branch_id']);

        $stats = [
            'total' => DamageInvoice::count(),
            'draft' => DamageInvoice::where('status', 'draft')->count(),
            'confirmed' => DamageInvoice::where('status', 'confirmed')->count(),
            'cancelled' => DamageInvoice::where('status', 'cancelled')->count(),
            'total_value' => DamageInvoice::where('status', 'confirmed')->sum('total_value'),
            // Phase 1 — per-type counts for the accountability dashboard.
            'missing_count' => DamageInvoice::where('damage_type', 'missing')->count(),
            'theft_count' => DamageInvoice::where('damage_type', 'theft')->count(),
            // Phase 4 — recoverable: confirmed damages with an accountable
            // employee and no recovery posted yet. Uses the partial index
            // idx_dmg_recovery (confirmed + accountable + recovery_amount=0).
            'recoverable_count' => DamageInvoice::where('status', 'confirmed')
                ->whereNotNull('accountable_employee_id')
                ->where('recovery_amount', 0)
                ->count(),
            'recoverable_value' => (float) DamageInvoice::where('status', 'confirmed')
                ->whereNotNull('accountable_employee_id')
                ->where('recovery_amount', 0)
                ->sum('total_value'),
            // Phase 4 — total recovered to date (from employee deductions).
            'recovered_total' => (float) DamageInvoice::where('recovery_amount', '>', 0)->sum('recovery_amount'),
            // Phase 5 — approval-workflow counts. `submitted` = awaiting a
            // manager decision (the worklist). `approved` = ready to post
            // (pre-confirm). `rejected` = terminal denials. The awaiting-
            // approval value is the sum of submitted damages' total_value —
            // the exposure sitting in the approval queue.
            'submitted' => DamageInvoice::where('status', 'submitted')->count(),
            'approved' => DamageInvoice::where('status', 'approved')->count(),
            'rejected' => DamageInvoice::where('status', 'rejected')->count(),
            'awaiting_value' => (float) DamageInvoice::where('status', 'submitted')->sum('total_value'),
        ];

        // Reflect the resolved date window back into the filter inputs so the
        // date pickers + quick-filter buttons stay in sync with what's shown.
        $filters = array_merge(
            $request->only(['warehouse_id', 'status', 'damage_type', 'branch_id', 'accountable_employee_id', 'search']),
            [
                'from_date' => $fromDate ?? '',
                'to_date'   => $toDate ?? '',
                'range'     => $range,
            ]
        );

        return view('admin.damages.index', [
            'title' => 'Damages',
            'damages' => $damages,
            'warehouses' => $warehouses,
            'branches' => $branches,
            'employees' => $employees,
            'damageTypes' => DamageInvoice::DAMAGE_TYPES,
            'damageTypeLabels' => DamageInvoice::DAMAGE_TYPE_LABELS,
            'stats' => $stats,
            'filters' => $filters,
        ]);
    }

    /**
     * Phase 7 — resolve the index date window from ?range or from_date/to_date.
     *
     * Precedence: explicit `range` param → manual from_date/to_date → default
     * month-to-date. Returns [from, to, range] where `range` is the active
     * quick-filter key (or '' when using a custom/MTD window) so the view can
     * highlight the correct button.
     *
     * @return array{0:?string,1:?string,2:string}
     */
    private function resolveDateRange(Request $request): array
    {
        $range = (string) $request->input('range', '');
        $today = Carbon::today();

        switch ($range) {
            case 'today':
                return [$today->toDateString(), $today->toDateString(), 'today'];
            case 'week':
                // ISO week (Mon–Sun) containing today.
                return [
                    $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
                    $today->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
                    'week',
                ];
            case 'month':
                return [
                    $today->copy()->startOfMonth()->toDateString(),
                    $today->copy()->endOfMonth()->toDateString(),
                    'month',
                ];
            case 'year':
                return [
                    $today->copy()->startOfYear()->toDateString(),
                    $today->copy()->endOfYear()->toDateString(),
                    'year',
                ];
        }

        $from = $request->input('from_date') ?: null;
        $to   = $request->input('to_date') ?: null;

        // No range, no manual dates → default to month-to-date. This is the
        // Phase 7 fix for the legacy "today only" default that left the list
        // empty on any day after the 1st.
        if (!$from && !$to) {
            return [
                $today->copy()->startOfMonth()->toDateString(),
                $today->toDateString(),
                '',  // '' = "This month (MTD)" default — highlighted as active
            ];
        }

        return [$from, $to, ''];
    }

    public function create()
    {
        // Phase 0 (Damage plan): defense-in-depth policy check.
        $this->authorize('create', DamageInvoice::class);

        $warehouses = Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $products = Product::active()->orderBy('product_name')->limit(500)->get();

        // Phase 1 — load the reason taxonomy grouped by damage_type for the
        // type-filtered dropdown on the create form.
        $damageReasons = DamageReason::groupedByType();

        // Phase 4 — active employees for the witness / accountable dropdowns.
        // RLS scopes this to the user's branch (admins see all branches). The
        // create form's JS cascades these by the selected warehouse's branch
        // (an admin picking a cross-branch warehouse needs that branch's
        // employees). We load branch_id so the JS filter works; we limit the
        // column set to keep the payload small.
        $employees = Employee::active()
            ->orderBy('name')
            ->limit(1000)
            ->get(['id', 'employee_code', 'name', 'role', 'branch_id']);

        return view('admin.damages.create', [
            'title' => 'New Damage Invoice',
            'warehouses' => $warehouses,
            'products' => $products,
            'employees' => $employees,
            'damageTypes' => DamageInvoice::DAMAGE_TYPES,
            'damageTypeLabels' => DamageInvoice::DAMAGE_TYPE_LABELS,
            'damageReasons' => $damageReasons,
        ]);
    }

    public function store(Request $request)
    {
        // Phase 0 (Damage plan): defense-in-depth policy check.
        $this->authorize('create', DamageInvoice::class);

        $validated = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'damage_date' => 'required|date',
            // Phase 1 — damage_type is required and must be a known enum.
            'damage_type' => ['required', 'string', Rule::in(DamageInvoice::DAMAGE_TYPES)],
            // reason_code is optional but if supplied MUST be an active reason
            // belonging to the chosen damage_type (so the dropdown filter is
            // authoritative). DamageService re-validates this as a backstop.
            'reason_code' => [
                'nullable', 'string', 'max:50',
                Rule::exists('damage_reasons', 'reason_code')->where(function ($q) use ($request) {
                    $q->where('damage_type', $request->input('damage_type'))
                      ->where('is_active', true);
                }),
            ],
            'reason_detail' => 'nullable|string|max:2000',
            'reason' => 'nullable|string|max:1000',
            // Phase 4 — witness / accountable employees. Both nullable here;
            // the type-conditional requirement (missing→accountable,
            // theft→witness) is enforced server-side in DamageService::
            // createDamage (which also verifies the employee is active +
            // same-branch). The frontend JS hints at the requirement but the
            // service is the real gate.
            'witness_employee_id' => 'nullable|integer|exists:employees,id',
            'accountable_employee_id' => 'nullable|integer|exists:employees,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.rate' => 'nullable|numeric|min:0',
        ]);

        try {
            $damage = $this->damageService->createDamage([
                'warehouse_id' => $validated['warehouse_id'],
                'damage_date' => $validated['damage_date'],
                'damage_type' => $validated['damage_type'],
                'reason_code' => $validated['reason_code'] ?? '',
                'reason_detail' => $validated['reason_detail'] ?? '',
                'reason' => $validated['reason'] ?? '',
                // Phase 4 — named responsible parties.
                'witness_employee_id' => $validated['witness_employee_id'] ?? null,
                'accountable_employee_id' => $validated['accountable_employee_id'] ?? null,
                'items' => $validated['items'],
                'created_by' => auth()->id(),
            ]);
            return redirect()->route('admin.damages.show', $damage)
                ->with('success', "Draft damage {$damage->damage_code} created. Submit for approval to proceed.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(int $id)
    {
        $damage = DamageInvoice::with([
            'items.product', 'warehouse.branch', 'branch',
            'journalEntry.lines.ledger',
            // Phase 1 — eager-load the structured reason label for display.
            'reasonTaxonomy',
            // Phase 3 — eager-load evidence attachments + uploader for the
            // Evidence card. Avoids N+1 on the gallery render.
            'attachments.uploadedBy',
            // Phase 4 — eager-load the named responsible employees + their
            // branches (for the Accountability card + employee links), plus
            // the recovery sub-ledger row + GL JE (if a recovery was posted).
            'witnessEmployee.branch',
            'accountableEmployee.branch',
            'employeeLedgerEntry.employee',
            'recoveryJournalEntry',
            // Phase 5 — eager-load the approval-workflow users (submitter /
            // approver / rejecter) for the Approval Timeline card. Integer
            // IDs resolve to User models (no FK on the columns — mirrors
            // reversed_by / created_by on this table).
            'submitter',
            'approver',
            'rejecter',
        ])->findOrFail($id);

        // Phase 0 (Damage plan): defense-in-depth policy check (same-branch
        // for non-admins). branch.isolation middleware already gated the
        // request; this re-confirms on the loaded model.
        $this->authorize('view', $damage);

        $stockMovements = [];
        if ($damage->isConfirmed() || $damage->is_reversed) {
            $stockMovements = DB::table('stock_transactions as st')
                ->join('products as p', 'p.id', '=', 'st.product_id')
                ->where('st.reference_type', 'damage')
                ->where('st.reference_id', $id)
                ->select('st.*', 'p.product_code', 'p.product_name')
                ->orderBy('st.id')
                ->get();
        }

        // Phase 2 — live-computed integrity panel (ports legacy
        // DamageAuditModel::runDamageChecks). Read-only, indexed lookups,
        // safe to run on every detail-page render. Surfaces drift between
        // the damage header, its items, stock_transactions and GL journal
        // so reconciliation issues are visible at a glance instead of
        // silently accumulating. Passes the already-eager-loaded $damage
        // model so the service doesn't re-query the header.
        $integrity = $this->integrityService->runChecks($damage);

        return view('admin.damages.show', [
            'title' => 'Damage ' . $damage->damage_code,
            'damage' => $damage,
            'stockMovements' => $stockMovements,
            'integrity' => $integrity,
        ]);
    }

    public function confirm(Request $request, int $id)
    {
        // Phase 0 (Damage plan): defense-in-depth policy check. Loads the
        // model first so the policy can verify same-branch for non-admins.
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('confirm', $damage);

        $request->validate([
            'confirm_reason' => 'nullable|string|max:500',
        ]);

        try {
            $damage = $this->damageService->confirmDamage($id, auth()->id());
            return redirect()->route('admin.damages.show', $damage)
                ->with('success', "Damage {$damage->damage_code} confirmed. Stock written off + GL posted.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, int $id)
    {
        // Phase 0 (Damage plan): defense-in-depth policy check.
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('cancel', $damage);

        $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        try {
            $damage = $this->damageService->cancelDamage($id, auth()->id(), $request->input('cancel_reason'));
            return redirect()->route('admin.damages.show', $damage)
                ->with('success', "Damage {$damage->damage_code} cancelled.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 5 — Approval Workflow (Maker-Checker + Threshold Escalation)
    |--------------------------------------------------------------------------
    | submit / approve / reject implement the state-machine gate between draft
    | and confirm. The auto-approve shortcut (admin/manager + total ≤
    | threshold) collapses submit+approve into one step — the service handles
    | it; the controller just forwards. Segregation of duties (approver ≠
    | submitter) is enforced in both the policy (403) and the service (throw).
    */

    /**
     * Submit a draft damage for approval (draft → submitted/approved).
     *
     * Route: POST admin/damages/{id}/submit — role:admin,manager,
     * warehouse_manager + branch.isolation.
     *
     * If the auto-approve rule fires (submitter ∈ admin/manager AND total ≤
     * config('damage.approval.threshold')), the damage transitions straight
     * to `approved` and can be confirmed immediately. Otherwise it lands in
     * `submitted` and waits for a manager's approve/reject decision.
     */
    public function submit(Request $request, int $id)
    {
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('submit', $damage);

        try {
            $damage = $this->damageService->submitForApproval($id, (int) auth()->id());

            $msg = $damage->isApproved()
                ? "Damage {$damage->damage_code} auto-approved (within threshold) — ready to confirm."
                : "Damage {$damage->damage_code} submitted — awaiting manager approval.";

            return redirect()->route('admin.damages.show', $damage)
                ->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Approve a submitted damage (submitted → approved).
     *
     * Route: POST admin/damages/{id}/approve — role:admin,manager +
     * branch.isolation. Segregation of duties: the approver cannot be the
     * same user who submitted (submitted_by) — enforced by the policy +
     * the service.
     */
    public function approve(Request $request, int $id)
    {
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('approve', $damage);

        $validated = $request->validate([
            'approval_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $damage = $this->damageService->approve(
                $id,
                (int) auth()->id(),
                $validated['approval_notes'] ?? ''
            );
            return redirect()->route('admin.damages.show', $damage)
                ->with('success', "Damage {$damage->damage_code} approved — ready to confirm.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Reject a submitted damage (submitted → rejected, terminal).
     *
     * Route: POST admin/damages/{id}/reject — role:admin,manager +
     * branch.isolation. A rejection reason is REQUIRED (the submitter needs
     * to know why). A rejected damage is terminal — it cannot be
     * re-submitted; create a new damage instead.
     */
    public function reject(Request $request, int $id)
    {
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('reject', $damage);

        $validated = $request->validate([
            'approval_notes' => 'required|string|max:1000',
        ]);

        try {
            $damage = $this->damageService->reject(
                $id,
                (int) auth()->id(),
                $validated['approval_notes']
            );
            return redirect()->route('admin.damages.show', $damage)
                ->with('success', "Damage {$damage->damage_code} rejected.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 4 — Witness & Accountable Employee: recovery
    |--------------------------------------------------------------------------
    */

    /**
     * Post an employee recovery against a confirmed damage.
     *
     * Debits the accountable employee's employee_ledger (they owe the company)
     * and credits the original loss ledger (nets the recovery against the
     * write-off). One-shot: a damage may have at most one recovery — to undo
     * it, cancel the damage (which reverses both the recovery and the main
     * write-off).
     *
     * Route: POST admin/damages/{id}/recover — role:admin,manager + branch.isolation.
     */
    public function recoverFromEmployee(Request $request, int $id)
    {
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('recoverFromEmployee', $damage);

        $validated = $request->validate([
            // Amount must be > 0 and ≤ the damage total value. The service
            // re-checks this under a row lock; this validator gives a clean
            // 422 with a field error instead of a 500.
            'recovery_amount' => [
                'required', 'numeric', 'min:0.01',
                'max:' . number_format((float) $damage->total_value, 2, '.', ''),
            ],
        ]);

        try {
            $damage = $this->damageService->postEmployeeRecovery(
                $id,
                (float) $validated['recovery_amount'],
                (int) auth()->id()
            );
            return redirect()->route('admin.damages.show', $damage)
                ->with('success', "Recovery of Tk " . number_format((float) $validated['recovery_amount'], 2)
                    . " posted against " . ($damage->accountableEmployee?->name ?? 'employee') . ".");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * AJAX: get product stock + rate for a warehouse.
     */
    public function getProductStock(Request $request)
    {
        // Phase 0 (Damage plan): defense-in-depth policy check.
        $this->authorize('viewProductStock', DamageInvoice::class);

        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
        ]);

        $rate = $this->stockService->getWarehouseAvgCost(
            (int) $request->input('warehouse_id'),
            (int) $request->input('product_id')
        );
        $qty = $this->stockService->getWarehouseQty(
            (int) $request->input('warehouse_id'),
            (int) $request->input('product_id')
        );

        return response()->json([
            'rate' => round($rate, 2),
            'available_qty' => round($qty, 4),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 7 — UX Polish: AJAX product search, CSV export, printable slip
    |--------------------------------------------------------------------------
    */

    /**
     * AJAX: search products for the create-form Select2 (replaces the 500-cap
     * server-rendered dropdown). Searches product_code + product_name (ILIKE).
     *
     * When `warehouse_id` is supplied, results are filtered to products that
     * have stock (qty > 0) in that warehouse — you can only damage what's in
     * stock — which also enforces branch scope indirectly (a warehouse belongs
     * to one branch). Without a warehouse, all active products are searchable
     * (master data is global; RLS on warehouse_stock is the backstop).
     *
     * Route: GET admin/damages/products/search — role:admin,manager,
     * warehouse_manager (same gate as product-stock). Returns Select2-shaped
     * JSON: { results: [{ id, text, product_code, product_name }] }.
     */
    public function searchProducts(Request $request)
    {
        $this->authorize('viewProductStock', DamageInvoice::class);

        $request->validate([
            'q'            => ['required', 'string', 'min:1', 'max:100'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);

        $term = trim((string) $request->input('q'));
        $warehouseId = $request->input('warehouse_id');

        $q = Product::active()
            ->where(function ($qb) use ($term) {
                $qb->where('product_name', 'ILIKE', "%{$term}%")
                   ->orWhere('product_code', 'ILIKE', "%{$term}%");
            });

        if ($warehouseId) {
            // Restrict to products with stock in the selected warehouse — the
            // damage form is meaningless for out-of-stock products, and this
            // ties results to the warehouse's branch.
            $q->whereExists(function ($sub) use ($warehouseId) {
                $sub->select(DB::raw(1))
                    ->from('warehouse_stock')
                    ->whereColumn('warehouse_stock.product_id', 'products.id')
                    ->where('warehouse_stock.warehouse_id', (int) $warehouseId)
                    ->where('warehouse_stock.qty', '>', 0);
            });
        }

        $rows = $q->orderBy('product_name')
            ->limit(30)
            ->get(['id', 'product_code', 'product_name']);

        $results = $rows->map(fn ($p) => [
            'id'            => $p->id,
            'text'          => $p->product_code . ' — ' . $p->product_name,
            'product_code'  => $p->product_code,
            'product_name'  => $p->product_name,
        ])->values();

        return response()->json(['results' => $results]);
    }

    /**
     * CSV export of the current index filter selection.
     *
     * Reuses DamageReportService::getDetailLines + exportCsv so the exported
     * columns stay in lock-step with the dedicated damage report (Phase 6).
     * The date window is resolved the same way as index() (range param →
     * manual dates → MTD default) so the CSV always matches what's on screen.
     *
     * Route: GET admin/damages/export — role:admin,manager,warehouse_manager.
     */
    public function export(Request $request)
    {
        $this->authorize('viewAny', DamageInvoice::class);

        [$fromDate, $toDate] = $this->resolveDateRange($request);

        $rows = $this->reportService->getDetailLines([
            'from'                     => $fromDate,
            'to'                       => $toDate,
            'warehouse_id'             => $request->input('warehouse_id'),
            'status'                   => $request->input('status'),
            'damage_type'              => $request->input('damage_type'),
            'branch_id'                => $request->input('branch_id'),
            'accountable_employee_id'  => $request->input('accountable_employee_id'),
        ]);

        return $this->reportService->exportCsv($rows);
    }

    /**
     * Printable damage slip (A5-ish) — opens in a new tab using the
     * layouts/print.blade.php chrome (branch-colored toolbar + auto-print).
     *
     * Loads the same relations as show() so the slip can render items, the
     * reason taxonomy, witness/accountable employees, the approval timeline,
     * evidence thumbnails, and the GL journal summary. Authorization mirrors
     * show() (view policy + branch.isolation middleware on the route).
     *
     * Route: GET admin/damages/{id}/print — role:admin,manager,
     * warehouse_manager + branch.isolation.
     */
    public function print(int $id)
    {
        $damage = DamageInvoice::with([
            'items.product', 'warehouse.branch', 'branch',
            'journalEntry.lines.ledger',
            'reasonTaxonomy',
            'attachments.uploadedBy',
            'witnessEmployee.branch',
            'accountableEmployee.branch',
            'submitter', 'approver', 'rejecter',
        ])->findOrFail($id);

        $this->authorize('view', $damage);

        // Resolve a branch code for the print layout's color theme. Prefer the
        // warehouse's branch (where the loss occurred); fall back to the
        // damage's own branch; finally null (HO red default).
        $branchCode = $damage->warehouse?->branch?->branch_code
            ?? $damage->branch?->branch_code
            ?? null;

        return view('admin.damages.print', [
            'title'      => 'Damage Slip ' . $damage->damage_code,
            'damage'     => $damage,
            'branchCode' => $branchCode,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 3 — Photo / Evidence Attachments
    |--------------------------------------------------------------------------
    */

    /**
     * Upload an evidence attachment to a draft damage.
     *
     * Stores the file on the configured `local` (private) disk — NOT the
     * public disk — because evidence is sensitive (theft scenes, damaged
     * inventory, possibly identifying employees). Files are served only via
     * the authorized viewAttachment() route, so RLS actually means something.
     */
    public function uploadAttachment(Request $request, int $id)
    {
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('uploadAttachment', $damage);

        $maxKb    = (int) config('damage.attachment_max_size_kb', DamageAttachment::MAX_FILE_SIZE_KB);
        $maxCount = (int) config('damage.attachment_max_per_damage', DamageAttachment::MAX_PER_DAMAGE);
        $diskName = (string) config('damage.attachment_disk', 'local');
        $folder   = (string) config('damage.attachment_folder', 'damage-evidence');

        $request->validate([
            'file'    => ['required', 'file', 'max:' . $maxKb, 'mimes:jpg,jpeg,png,webp,pdf'],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        if (!$file->isValid()) {
            return back()->with('error', 'Uploaded file is not valid.');
        }

        // Enforce the per-damage count limit BEFORE storing (avoids orphaned
        // files when the limit is hit). RLS + the draft-only policy gate
        // already prevent cross-branch / post-confirm uploads.
        $currentCount = $damage->attachments()->count();
        if ($currentCount >= $maxCount) {
            return back()->with('error', "Attachment limit reached ({$maxCount} per damage). Remove one before adding another.");
        }

        $mime     = $file->getMimeType() ?: 'application/octet-stream';
        $origName = $file->getClientOriginalName() ?: 'evidence';
        $size     = (int) $file->getSize();

        // Store under damage-evidence/{damage_id}/ so a future cleanup job
        // (orphaned-file sweep) can prune by directory. Random filename to
        // avoid collisions + path-traversal via user-supplied names.
        $storedPath = $file->storeAs(
            $folder . '/' . $id,
            bin2hex(random_bytes(16)) . '.' . ($file->getClientOriginalExtension() ?: 'bin'),
            $diskName
        );

        if ($storedPath === false) {
            return back()->with('error', 'Could not store the uploaded file. Check disk permissions.');
        }

        DamageAttachment::create([
            'damage_invoice_id' => $damage->id,
            'file_path'         => $storedPath,
            'file_name'         => $origName,
            'mime_type'         => $mime,
            'file_size'         => $size,
            'caption'           => trim((string) $request->input('caption')) ?: null,
            'uploaded_by'       => auth()->id(),
            'created_at'        => now(),
        ]);

        return redirect()->route('admin.damages.show', $damage)
            ->with('success', 'Evidence uploaded.');
    }

    /**
     * Delete an evidence attachment (draft only — policy enforces).
     *
     * Removes the physical file FIRST, then the DB row. If the file delete
     * fails (disk error), the DB row is still removed — the row is the
     * source of truth for the UI, and a stale orphaned file is preferable
     * to a dangling DB row pointing at nothing (which would 404 on view).
     * A scheduled cleanup job can sweep orphans later.
     */
    public function deleteAttachment(int $id, int $attachmentId)
    {
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('deleteAttachment', $damage);

        /** @var DamageAttachment|null $attachment */
        $attachment = $damage->attachments()->where('id', $attachmentId)->first();
        if (!$attachment) {
            return back()->with('error', 'Attachment not found on this damage.');
        }

        $diskName = (string) config('damage.attachment_disk', 'local');
        try {
            if (Storage::disk($diskName)->exists($attachment->file_path)) {
                Storage::disk($diskName)->delete($attachment->file_path);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Damage attachment file delete failed (DB row will still be removed)', [
                'attachment_id' => $attachment->id,
                'file_path'     => $attachment->file_path,
                'error'         => $e->getMessage(),
            ]);
        }

        $attachment->delete();

        return redirect()->route('admin.damages.show', $damage)
            ->with('success', 'Evidence removed.');
    }

    /**
     * Stream an evidence attachment inline (for the gallery / lightbox <img>).
     *
     * Authorization: DamagePolicy::viewAttachment (role + same-branch) +
     * branch.isolation middleware + RLS on damage_attachments. The file is
     * read from the `local` (private) disk and streamed with the correct
     * Content-Type so the browser renders it inline.
     */
    public function viewAttachment(int $id, int $attachmentId)
    {
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('viewAttachment', $damage);

        /** @var DamageAttachment|null $attachment */
        $attachment = $damage->attachments()->where('id', $attachmentId)->first();
        if (!$attachment) {
            abort(404, 'Attachment not found.');
        }

        return $this->streamAttachment($attachment, inline: true);
    }

    /**
     * Force-download an evidence attachment (Content-Disposition: attachment).
     */
    public function downloadAttachment(int $id, int $attachmentId)
    {
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('viewAttachment', $damage);

        /** @var DamageAttachment|null $attachment */
        $attachment = $damage->attachments()->where('id', $attachmentId)->first();
        if (!$attachment) {
            abort(404, 'Attachment not found.');
        }

        return $this->streamAttachment($attachment, inline: false);
    }

    /**
     * Stream a damage attachment file from the private disk with the right
     * headers. Centralizes the disk read so view + download share the same
     * authorization + 404 handling.
     */
    private function streamAttachment(DamageAttachment $attachment, bool $inline)
    {
        $diskName = (string) config('damage.attachment_disk', 'local');
        $disk     = Storage::disk($diskName);

        if (!$disk->exists($attachment->file_path)) {
            abort(404, 'Evidence file is missing from storage.');
        }

        $disposition = $inline ? 'inline' : 'attachment';
        // Sanitize filename for the Content-Disposition header (RFC 5987
        // fallback for non-ASCII names — avoids header injection).
        $safeName = str_replace(['"', "\r", "\n"], '', $attachment->file_name);

        return response($disk->get($attachment->file_path), 200, [
            'Content-Type'        => $attachment->mime_type,
            'Content-Length'      => (string) $attachment->file_size,
            'Content-Disposition' => $disposition . '; filename="' . $safeName . '"',
            'Cache-Control'       => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
