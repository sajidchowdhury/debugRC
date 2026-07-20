<?php

namespace App\Http\Controllers\Admin;

use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ProductCategoryController — Phase 4-A.
 *
 * Extends BaseMasterDataController for shared CRUD + audit + DataTables.
 * Categories are few in number, so the index view uses a simple paginated
 * table (no DataTables).
 */
class ProductCategoryController extends BaseMasterDataController
{
    protected string $modelClass  = ProductCategory::class;
    protected string $label       = 'Product category';
    protected string $routePrefix = 'admin.product-categories';
    protected string $viewDir     = 'admin.product-categories';

    protected array $searchFields = ['category_name'];

    protected function indexStats(): array
    {
        return [
            'total'    => ProductCategory::count(),
            'active'   => ProductCategory::whereNull('deleted_at')->where('is_active', true)->count(),
            'inactive' => ProductCategory::onlyTrashed()->count(),
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
            'category_name' => 'required|string|max:100|unique:product_categories,category_name,' . $id,
            'description'   => 'nullable|string',
            'is_active'     => 'boolean',
        ];
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->validationRules());
        $validated['is_active'] = $request->boolean('is_active', true);

        try {
            $category = ProductCategory::create($validated);
            return redirect()->route("{$this->routePrefix}.show", $category)
                ->with('success', "{$this->label} created successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', "Failed to create {$this->label}: {$e->getMessage()}");
        }
    }

    public function update(Request $request, int $id)
    {
        $category = ProductCategory::findOrFail($id);
        $validated = $request->validate($this->validationRules($id));
        $validated['is_active'] = $request->boolean('is_active', false);

        try {
            $category->update($validated);
            return redirect()->route("{$this->routePrefix}.show", $category)
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
        $item = ProductCategory::findOrFail($id);

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
