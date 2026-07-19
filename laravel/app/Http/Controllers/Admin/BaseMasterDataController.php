<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Export\CsvExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Base admin controller for master-data CRUD modules.
 * Phase 4: provides shared helpers for resource controllers.
 */
abstract class BaseMasterDataController extends Controller
{
    /**
     * The Eloquent model class (set in subclass).
     */
    protected string $modelClass;

    /**
     * The module label for titles/flash messages (e.g. 'Product', 'Customer').
     */
    protected string $label;

    /**
     * The route name prefix (e.g. 'admin.products').
     */
    protected string $routePrefix;

    /**
     * The view directory (e.g. 'admin.products').
     */
    protected string $viewDir;

    /**
     * Fields to search in the index DataTables query.
     */
    protected array $searchFields = [];

    /**
     * Get stats for the index page hero (override in subclass).
     */
    protected function indexStats(): array
    {
        return [];
    }

    /**
     * Phase 18: Columns to export for the CSV export.
     *
     * Override in subclass to customize which columns are exported and
     * their human-readable labels. Returns an associative array
     * `[key => label]`. The `key` may be either a direct attribute on
     * the model OR a dotted relation path (e.g. 'branch.branch_name').
     *
     * Default behavior: derive columns from the model's $fillable array,
     * with labels converted from snake_case to Title Case. If the model
     * has no $fillable, falls back to ['id' => 'ID', 'is_active' => 'Active'].
     *
     * @return array<string,string>
     */
    protected function exportColumns(): array
    {
        $model = ($this->modelClass)::make();
        $fillable = $model->getFillable();

        if (empty($fillable)) {
            return [
                'id'        => 'ID',
                'is_active' => 'Active',
                'created_at' => 'Created At',
                'updated_at' => 'Updated At',
            ];
        }

        $columns = [];
        foreach ($fillable as $field) {
            $columns[$field] = self::humanizeLabel($field);
        }

        // Always include is_active + timestamps at the end if not already present.
        if (!isset($columns['is_active'])) {
            $columns['is_active'] = 'Active';
        }
        if (!isset($columns['created_at'])) {
            $columns['created_at'] = 'Created At';
        }

        return $columns;
    }

    /**
     * Convert snake_case field name to a human-readable label.
     * e.g. 'branch_code' → 'Branch Code', 'is_active' → 'Is Active'.
     */
    protected static function humanizeLabel(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * Eager-load relationships for the index query (override in subclass).
     */
    protected function indexWith(): array
    {
        return [];
    }

    /**
     * Eager-load relationships for the show/edit query (override in subclass).
     */
    protected function detailWith(): array
    {
        return [];
    }

    /**
     * Extra data to pass to create/edit views (override in subclass).
     * e.g. ['categories' => ProductCategory::active()->orderBy('category_name')->get()]
     */
    protected function formData(): array
    {
        return [];
    }

    /**
     * Validation rules for store/update (override in subclass).
     */
    protected function validationRules(?int $id = null): array
    {
        return [];
    }

    // ===================== CRUD METHODS =====================

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $showDeleted = $request->boolean('deleted');
        $query = ($this->modelClass)::query()->with($this->indexWith());

        if ($showDeleted) {
            $query->onlyTrashed();
        } else {
            $query->whereNull('deleted_at');
        }

        // DataTables server-side (if draw param present)
        if ($request->has('draw')) {
            return $this->dataTablesResponse($query, $request);
        }

        $items = $query->orderBy('id', 'desc')->paginate(25);
        $stats = $this->indexStats();

        return view("{$this->viewDir}.index", [
            'title' => $showDeleted ? "Inactive {$this->label}s" : "{$this->label} directory",
            'items' => $items,
            'showDeleted' => $showDeleted,
            'stats' => $stats,
            'routePrefix' => $this->routePrefix,
            'label' => $this->label,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view("{$this->viewDir}.create", array_merge([
            'title' => "New {$this->label}",
            'routePrefix' => $this->routePrefix,
            'label' => $this->label,
        ], $this->formData()));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate($this->validationRules());

        // Set created_by from the authenticated user (if the table has the column).
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing(($this->modelClass)::make()->getTable());
        if (in_array('created_by', $columns)) {
            $validated['created_by'] = Auth::id();
        }

        try {
            $model = ($this->modelClass)::create($validated);
            return redirect()->route("{$this->routePrefix}.show", $model)
                ->with('success', "{$this->label} created successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', "Failed to create {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id)
    {
        $item = ($this->modelClass)::with($this->detailWith())->withTrashed()->findOrFail($id);

        return view("{$this->viewDir}.show", [
            'title' => "{$this->label} details",
            'item' => $item,
            'routePrefix' => $this->routePrefix,
            'label' => $this->label,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(int $id)
    {
        $item = ($this->modelClass)::with($this->detailWith())->findOrFail($id);

        return view("{$this->viewDir}.edit", array_merge([
            'title' => "Edit {$this->label}",
            'item' => $item,
            'routePrefix' => $this->routePrefix,
            'label' => $this->label,
        ], $this->formData()));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $id)
    {
        $item = ($this->modelClass)::findOrFail($id);
        $validated = $request->validate($this->validationRules($id));

        try {
            $item->update($validated);
            return redirect()->route("{$this->routePrefix}.show", $item)
                ->with('success', "{$this->label} updated successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', "Failed to update {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Soft-delete the specified resource.
     * Phase 3: calls canDeactivate() safety check before deactivating.
     */
    public function destroy(Request $request, int $id)
    {
        $item = ($this->modelClass)::findOrFail($id);

        // Phase 3: Safety check — can this entity be deactivated?
        if ($item->is_active) {
            $deactivationCheck = $this->canDeactivate($item);
            if (!$deactivationCheck['ok']) {
                return back()->with('error', $deactivationCheck['message']);
            }
        }

        try {
            $item->deleted_by = Auth::id();
            $item->is_active = false;
            $item->save();
            $item->delete();

            return redirect()->route("{$this->routePrefix}.index")
                ->with('success', "{$this->label} deactivated.");
        } catch (\Throwable $e) {
            return back()->with('error', "Failed to deactivate {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Phase 3: Toggle active/inactive status (legacy 'toggle' action).
     * Activates if inactive (or soft-deleted), deactivates if active (with safety check).
     *
     * Note: We check trashed() first because soft-deletion sets deleted_at
     * but does NOT change is_active. A soft-deleted branch with is_active=true
     * should be treated as "inactive" and re-activated on toggle.
     */
    public function toggle(int $id)
    {
        $item = ($this->modelClass)::withTrashed()->findOrFail($id);

        try {
            if (!$item->is_active || $item->trashed()) {
                // Activating — restore if soft-deleted.
                if ($item->trashed()) {
                    $item->restore();
                }
                $item->is_active = true;
                $item->deleted_by = null;
                $item->save();

                return redirect()->route("{$this->routePrefix}.index")
                    ->with('success', "{$this->label} activated.");
            } else {
                // Deactivating — run safety check.
                $deactivationCheck = $this->canDeactivate($item);
                if (!$deactivationCheck['ok']) {
                    return back()->with('error', $deactivationCheck['message']);
                }

                $item->is_active = false;
                $item->deleted_by = Auth::id();
                $item->save();
                $item->delete();

                return redirect()->route("{$this->routePrefix}.index")
                    ->with('success', "{$this->label} deactivated.");
            }
        } catch (\Throwable $e) {
            return back()->with('error', "Failed to toggle {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Phase 3: Safety check before deactivation.
     * Override in subclass to add entity-specific checks (e.g. outstanding
     * balances, dependent records). Default: always allow.
     *
     * @param mixed $item The model instance being deactivated
     * @return array{ok: bool, message: string}
     */
    protected function canDeactivate($item): array
    {
        return ['ok' => true, 'message' => ''];
    }

    /**
     * Restore a soft-deleted resource.
     */
    public function restore(int $id)
    {
        $item = ($this->modelClass)::onlyTrashed()->findOrFail($id);

        try {
            $item->restore();
            $item->deleted_by = null;
            $item->save();

            return redirect()->route("{$this->routePrefix}.show", $item)
                ->with('success', "{$this->label} restored.");
        } catch (\Throwable $e) {
            return back()->with('error', "Failed to restore {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Show audit history for the module.
     * Phase 4: Fixed query to join users→employees for performer name,
     * and extract target_id from JSON details.
     */
    public function audit(Request $request)
    {
        $tableName = (new ($this->modelClass))->getTable();

        $auditLogs = DB::table('user_audit_log as ual')
            ->leftJoin('users as u', 'u.id', '=', 'ual.user_id')
            ->leftJoin('employees as e', 'e.id', '=', 'u.employee_id')
            ->whereRaw("ual.details::jsonb->>'table' = ?", [$tableName])
            ->select(
                'ual.id',
                'ual.user_id',
                'ual.action',
                'ual.details',
                'ual.ip_address',
                'ual.created_at',
                'e.name as performed_by_name',
                DB::raw("ual.details::jsonb->>'record_id' as target_id")
            )
            ->orderBy('ual.created_at', 'desc')
            ->paginate(50);

        return view("{$this->viewDir}.audit", [
            'title' => "{$this->label} audit log",
            'auditLogs' => $auditLogs,
            'routePrefix' => $this->routePrefix,
            'label' => $this->label,
        ]);
    }

    // ===================== DATATABLES HELPER =====================

    /**
     * Generate a DataTables server-side JSON response.
     * Override in subclass for custom column formatting.
     */
    protected function dataTablesResponse($query, Request $request)
    {
        $draw = (int) $request->input('draw', 1);
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);
        $search = $request->input('search.value', '');

        $total = $query->count();

        if ($search !== '' && $this->searchFields !== []) {
            $query->where(function ($q) use ($search) {
                foreach ($this->searchFields as $field) {
                    $q->orWhere($field, 'ILIKE', "%{$search}%");
                }
            });
        }

        $filtered = $query->count();
        $items = $query->skip($start)->take($length)->get();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $items,
        ]);
    }

    // ===================== CSV EXPORT (Phase 18) =====================

    /**
     * Stream a CSV export of the current listing.
     *
     * Reuses the same query logic as dataTablesResponse() — eager-loads
     * the same relations ($this->indexWith()), applies the same global
     * search term (?search= or ?q=), and respects the ?deleted=1 toggle
     * for inactive records.
     *
     * Subclasses override exportColumns() to choose which columns are
     * exported and their labels. Subclasses that override
     * dataTablesResponse() to add custom filters should also override
     * export() to apply those same filters (or call applyExportFilters()
     * from within).
     *
     * Route: GET /admin/{module}/export
     * Middleware: role:admin,manager (set per-route in routes/web.php).
     */
    public function export(Request $request)
    {
        $showDeleted = $request->boolean('deleted');
        $query = ($this->modelClass)::query()->with($this->indexWith());

        if ($showDeleted) {
            $query->onlyTrashed();
        } else {
            $query->whereNull('deleted_at');
        }

        $this->applyExportSearch($query, $request);

        $columns = $this->exportColumns();
        $label   = $this->label;

        return CsvExporter::export("{$label}s", $columns, $query);
    }

    /**
     * Apply the same search filter used by dataTablesResponse() to the
     * export query. Honours both `?search=` and `?q=` query params.
     *
     * Override in subclass if the dataTablesResponse() uses different
     * filter logic (e.g. ProductController applies ?filterCategory=…).
     */
    protected function applyExportSearch($query, Request $request): void
    {
        if ($this->searchFields === []) {
            return;
        }

        $search = trim((string) ($request->input('search') ?? $request->input('q') ?? ''));

        if ($search === '') {
            return;
        }

        $query->where(function ($q) use ($search) {
            foreach ($this->searchFields as $field) {
                $q->orWhere($field, 'ILIKE', "%{$search}%");
            }
        });
    }
}
