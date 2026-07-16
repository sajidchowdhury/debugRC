<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
     */
    public function destroy(Request $request, int $id)
    {
        $item = ($this->modelClass)::findOrFail($id);

        try {
            $item->deleted_by = Auth::id();
            $item->save();
            $item->delete();

            return redirect()->route("{$this->routePrefix}.index")
                ->with('success', "{$this->label} deactivated.");
        } catch (\Throwable $e) {
            return back()->with('error', "Failed to deactivate {$this->label}: {$e->getMessage()}");
        }
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
     */
    public function audit(Request $request)
    {
        $tableName = (new ($this->modelClass))->getTable();
        $auditLogs = DB::table('user_audit_log')
            ->whereRaw("details::jsonb->>'table' = ?", [$tableName])
            ->orderBy('created_at', 'desc')
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
}
