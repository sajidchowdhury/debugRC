<?php

namespace App\Http\Controllers\Admin;

use App\Models\ProductGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ProductGroupController — Phase 4-A.
 *
 * Extends BaseMasterDataController for shared CRUD + audit + DataTables.
 * Like categories, groups are few — index uses a simple paginated table.
 */
class ProductGroupController extends BaseMasterDataController
{
    protected string $modelClass  = ProductGroup::class;
    protected string $label       = 'Product group';
    protected string $routePrefix = 'admin.product-groups';
    protected string $viewDir     = 'admin.product-groups';

    protected array $searchFields = ['group_name'];

    protected function indexStats(): array
    {
        return [
            'total'    => ProductGroup::count(),
            'active'   => ProductGroup::whereNull('deleted_at')->where('is_active', true)->count(),
            'inactive' => ProductGroup::onlyTrashed()->count(),
        ];
    }

    protected function indexWith(): array
    {
        return ['products'];
    }

    protected function validationRules(?int $id = null): array
    {
        $id = $id ?? 'NULL';

        return [
            'group_name' => 'required|string|max:100|unique:product_groups,group_name,' . $id,
            'description'=> 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_active'  => 'boolean',
        ];
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->validationRules());
        $validated['is_active'] = $request->boolean('is_active', true);

        try {
            $group = ProductGroup::create($validated);
            return redirect()->route("{$this->routePrefix}.show", $group)
                ->with('success', "{$this->label} created successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', "Failed to create {$this->label}: {$e->getMessage()}");
        }
    }

    public function update(Request $request, int $id)
    {
        $group = ProductGroup::findOrFail($id);
        $validated = $request->validate($this->validationRules($id));
        $validated['is_active'] = $request->boolean('is_active', false);

        try {
            $group->update($validated);
            return redirect()->route("{$this->routePrefix}.show", $group)
                ->with('success', "{$this->label} updated successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', "Failed to update {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Soft-delete (override base to ensure Auth facade is properly resolved).
     */
    public function destroy(Request $request, int $id)
    {
        $item = ProductGroup::findOrFail($id);

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
}
