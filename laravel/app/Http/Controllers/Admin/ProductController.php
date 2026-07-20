<?php

namespace App\Http\Controllers\Admin;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductGroup;
use App\Models\ProductPriceHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ProductController — Phase 4-A.
 *
 * Reproduces the legacy product catalog UI in Blade.
 * Extends BaseMasterDataController for shared CRUD + audit + DataTables.
 *
 * Adds product-specific concerns:
 *  - Image upload (store + update)
 *  - Price history (list, add, delete)
 *  - Filtered DataTables response (by category / group / unit)
 */
class ProductController extends BaseMasterDataController
{
    protected string $modelClass = Product::class;
    protected string $label      = 'Product';
    protected string $routePrefix = 'admin.products';
    protected string $viewDir    = 'admin.products';

    protected array $searchFields = ['product_code', 'product_name'];

    /** Enable PostgreSQL full-text search (tsvector + GIN) for product search. */
    protected bool $useFullTextSearch = true;

    /** Units enumerated in legacy app/views/products/_form_fields.php */
    protected array $units = ['Pcs', 'Carton', 'KG', 'Bag', 'Dobe', 'Set'];

    /**
     * Stats for the index hero.
     */
    protected function indexStats(): array
    {
        return [
            'total'      => Product::count(),
            'active'     => Product::whereNull('deleted_at')->where('is_active', true)->count(),
            'inactive'   => Product::onlyTrashed()->count(),
            // Stock tracking is Phase 7+ — placeholder for now.
            'low_stock'  => 0,
            'categories' => ProductCategory::whereNull('deleted_at')->count(),
            'groups'     => ProductGroup::whereNull('deleted_at')->count(),
        ];
    }

    protected function indexWith(): array
    {
        return ['category', 'group'];
    }

    protected function detailWith(): array
    {
        return ['category', 'group', 'priceHistory'];
    }

    /**
     * Phase 18: Columns to export for the CSV download.
     */
    protected function exportColumns(): array
    {
        return [
            'product_code'  => 'Code',
            'product_name'  => 'Product Name',
            'unit'          => 'Unit',
            'purchase_rate' => 'Purchase Rate',
            'sales_rate'    => 'Sales Rate',
            'is_active'     => 'Active',
        ];
    }

    protected function formData(): array
    {
        return [
            'categories' => ProductCategory::active()->orderBy('category_name')->get(),
            'groups'     => ProductGroup::active()->orderBy('sort_order')->get(),
            'units'      => $this->units,
        ];
    }

    protected function validationRules(?int $id = null): array
    {
        // Phase 9: default $id to 0 (instead of 'NULL') to match the
        // Branch/Warehouse pattern. This avoids any potential PostgreSQL
        // "invalid input syntax for type integer" edge cases on store.
        $id = $id ?? 0;

        return [
            'product_code'    => 'required|string|max:50|unique:products,product_code,' . $id,
            'product_name'    => 'required|string|max:200',
            'category_id'     => 'nullable|exists:product_categories,id',
            'group_id'        => 'nullable|exists:product_groups,id',
            'unit'            => 'required|in:' . implode(',', $this->units),
            'purchase_rate'   => 'nullable|numeric|min:0',
            'sales_rate'      => 'nullable|numeric|min:0',
            'min_stock'       => 'nullable|numeric',
            'max_stock'       => 'nullable|numeric',
            'reorder_level'   => 'nullable|numeric',
            'is_active'       => 'boolean',
            'condition_state' => 'nullable|in:Good,Damage',
            'image'           => 'nullable|image|mimes:jpeg,png,webp,gif|max:2048',
        ];
    }

    // ===================== STORE / UPDATE (image upload) =====================

    public function store(Request $request)
    {
        $validated = $request->validate($this->validationRules());

        // Handle image upload
        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            $validated['product_image'] = $this->storeImage($request->file('image'));
        }
        unset($validated['image']);

        // Phase 9: Set created_by from authenticated user when the column exists.
        // (products table does NOT have created_by, so this is a no-op — kept for
        // symmetry with Branch/Warehouse controllers and future schema changes.)
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing(($this->modelClass)::make()->getTable());
        if (in_array('created_by', $columns) && !array_key_exists('created_by', $validated)) {
            $validated['created_by'] = Auth::id();
        }

        // Only set is_active when the request explicitly provides it; otherwise
        // let the DB default (true) apply. This matches Branch/Warehouse behavior
        // and keeps the 'defaults to active' contract for new products.
        if ($request->has('is_active')) {
            $validated['is_active'] = $request->boolean('is_active');
        } else {
            unset($validated['is_active']);
        }

        // Only set condition_state when the request explicitly provides it;
        // otherwise let the DB default ('Good') apply. This prevents a null
        // value from overriding the NOT NULL DEFAULT 'Good' column constraint.
        if ($request->filled('condition_state')) {
            $validated['condition_state'] = $request->input('condition_state');
        } else {
            unset($validated['condition_state']);
        }

        try {
            $product = Product::create($validated);
            return redirect()->route("{$this->routePrefix}.show", $product)
                ->with('success', "{$this->label} created successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', "Failed to create {$this->label}: {$e->getMessage()}");
        }
    }

    public function update(Request $request, int $id)
    {
        $product = Product::findOrFail($id);
        $validated = $request->validate($this->validationRules($id));

        // Handle image upload — replace existing
        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            $newPath = $this->storeImage($request->file('image'));
            // Delete previous image if present
            if ($product->product_image) {
                $this->deleteImage($product->product_image);
            }
            $validated['product_image'] = $newPath;
        }
        unset($validated['image']);

        // Phase 9: Only set is_active when the request explicitly provides it.
        // This prevents an omitted is_active from silently flipping the value
        // to false on update (matches Branch/Warehouse pattern).
        if ($request->has('is_active')) {
            $validated['is_active'] = $request->boolean('is_active');
        } else {
            unset($validated['is_active']);
        }

        // Only set condition_state when the request explicitly provides it;
        // otherwise preserve the existing value (or let DB default apply).
        if ($request->filled('condition_state')) {
            $validated['condition_state'] = $request->input('condition_state');
        } else {
            unset($validated['condition_state']);
        }

        // Phase 9: If is_active is being set to false, run deactivation safety check.
        if (isset($validated['is_active']) && !$validated['is_active'] && $product->is_active) {
            $deactivationCheck = $this->canDeactivate($product);
            if (!$deactivationCheck['ok']) {
                return back()->withInput()->with('error', $deactivationCheck['message']);
            }
        }

        try {
            $product->update($validated);
            return redirect()->route("{$this->routePrefix}.show", $product)
                ->with('success', "{$this->label} updated successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', "Failed to update {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Soft-delete (Phase 9 override to enforce safety check + is_active=false).
     *
     * Runs the canDeactivate() safety check before soft-deleting. Sets
     * is_active=false + deleted_by before the delete() call so the audit
     * trait captures the final deactivated state.
     */
    public function destroy(Request $request, int $id)
    {
        $item = Product::findOrFail($id);

        // Phase 9: Safety check — can this product be deactivated?
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
     * Phase 9: Safety check before product deactivation.
     *
     * Mirrors legacy product-master safety rules — a product must not be
     * deactivated while it is referenced by live stock or open transactions.
     *
     * Checks:
     *   1. No stock-on-hand (qty > 0) in warehouse_stock for this product
     *   2. No sales_invoice_items for this product on non-reversed,
     *      non-cancelled invoices
     *   3. No purchase_order_items for this product on pending purchase
     *      orders (status in draft, sent, partial)
     *
     * @param  \App\Models\Product  $item
     * @return array{ok: bool, message: string}
     */
    protected function canDeactivate($item): array
    {
        $productId = $item->id;

        // 1. Stock-on-hand in any warehouse.
        $stockQty = (float) DB::table('warehouse_stock')
            ->where('product_id', $productId)
            ->sum('qty');

        if ($stockQty > 0.0001) {
            return [
                'ok' => false,
                'message' => 'Cannot deactivate this product. It still has '
                    . number_format($stockQty, 2)
                    . ' units of stock in warehouses. Please move or adjust the stock first.',
            ];
        }

        // 2. Open sales invoices referencing this product.
        $openSalesItems = DB::table('sales_invoice_items as sii')
            ->join('sales_invoices as si', 'si.id', '=', 'sii.sales_invoice_id')
            ->where('sii.product_id', $productId)
            ->where('si.is_reversed', false)
            ->whereNotIn('si.status', ['cancelled', 'reversed'])
            ->count();

        if ($openSalesItems > 0) {
            return [
                'ok' => false,
                'message' => 'Cannot deactivate this product. It appears on '
                    . $openSalesItems
                    . ' open sales invoice item(s). Please resolve them first.',
            ];
        }

        // 3. Pending purchase orders referencing this product.
        $pendingPoItems = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('poi.product_id', $productId)
            ->whereIn('po.status', ['draft', 'sent', 'partial'])
            ->count();

        if ($pendingPoItems > 0) {
            return [
                'ok' => false,
                'message' => 'Cannot deactivate this product. It appears on '
                    . $pendingPoItems
                    . ' pending purchase order item(s). Please resolve them first.',
            ];
        }

        return ['ok' => true, 'message' => ''];
    }

    // ===================== PRICE HISTORY =====================

    /**
     * Show price-history page for a product.
     */
    public function priceHistory(int $id)
    {
        $product = Product::with(['priceHistory' => function ($q) {
            $q->orderBy('effective_from', 'desc');
        }])->withTrashed()->findOrFail($id);

        $currentPrice = $product->currentPrice();

        return view("{$this->viewDir}.price_history", [
            'title'        => 'Price history — ' . $product->product_name,
            'product'      => $product,
            'history'      => $product->priceHistory,
            'currentPrice' => $currentPrice,
            'routePrefix'  => $this->routePrefix,
            'label'        => $this->label,
        ]);
    }

    /**
     * Append a new price-history entry.
     */
    public function addPrice(Request $request, int $id)
    {
        $product = Product::withTrashed()->findOrFail($id);

        $validated = $request->validate([
            'min_rate'      => 'required|numeric|min:0',
            'max_rate'      => 'required|numeric|min:0|gte:min_rate',
            'default_rate'  => 'required|numeric|min:0|gte:min_rate|lte:max_rate',
            'effective_from'=> 'nullable|date',
        ]);

        // Close out the previous current price (set effective_to = new effective_from)
        $effectiveFrom = $validated['effective_from'] ?? now()->toDateString();
        $previousCurrent = $product->currentPrice();
        if ($previousCurrent && $previousCurrent->effective_to === null) {
            $previousCurrent->effective_to = $effectiveFrom;
            $previousCurrent->save();
        }

        try {
            ProductPriceHistory::create([
                'product_id'    => $product->id,
                'min_rate'      => $validated['min_rate'],
                'max_rate'      => $validated['max_rate'],
                'default_rate'  => $validated['default_rate'],
                'effective_from'=> $effectiveFrom,
                'effective_to'  => null,
                'created_by'    => Auth::id(),
                'created_at'    => now(),
            ]);

            return redirect()
                ->route("{$this->routePrefix}.priceHistory", $product)
                ->with('success', 'Price range added.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', "Failed to add price: {$e->getMessage()}");
        }
    }

    /**
     * Delete a price-history entry (admin override — legacy marks these append-only,
     * but the task requires a delete endpoint for testing/cleanup).
     */
    public function deletePrice(int $id, int $priceId)
    {
        $price = ProductPriceHistory::where('product_id', $id)->findOrFail($priceId);

        try {
            $price->delete();
            return redirect()
                ->route("{$this->routePrefix}.priceHistory", $id)
                ->with('success', 'Price entry deleted.');
        } catch (\Throwable $e) {
            return back()->with('error', "Failed to delete price: {$e->getMessage()}");
        }
    }

    // ===================== DATATABLES (with filters) =====================

    /**
     * Override to:
     *  - Apply ?filterCategory=, ?filterGroup=, ?filterUnit= filters from the index page.
     *  - Flatten category/group names into the row for the JS renderer.
     */
    protected function dataTablesResponse($query, Request $request)
    {
        $draw   = (int) $request->input('draw', 1);
        $start  = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 25);
        $search = $request->input('search.value', '');

        // Apply filters
        if ($catId = $request->input('filterCategory')) {
            $query->where('category_id', $catId);
        }
        if ($grpId = $request->input('filterGroup')) {
            $query->where('group_id', $grpId);
        }
        if ($unit = $request->input('filterUnit')) {
            $query->where('unit', $unit);
        }

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

        // Flatten for the JS renderer
        $data = $items->map(function (Product $p) {
            return [
                'id'            => $p->id,
                'product_code'  => $p->product_code,
                'product_name'  => $p->product_name,
                'category_id'   => $p->category_id,
                'category_name' => $p->category?->category_name,
                'group_id'      => $p->group_id,
                'group_name'    => $p->group?->group_name,
                'unit'          => $p->unit,
                'purchase_rate' => $p->purchase_rate,
                'sales_rate'    => $p->sales_rate,
                'reorder_level' => $p->reorder_level,
                'is_active'     => (bool) $p->is_active,
                'image'         => $p->product_image,
                'condition_state' => $p->condition_state,
                'deleted_at'    => $p->deleted_at,
            ];
        })->values();

        return response()->json([
            'draw'            => $draw,
            'recordsTotal'    => $total,
            'recordsFiltered' => $filtered,
            'data'            => $data,
        ]);
    }

    // ===================== IMAGE HELPERS =====================

    /**
     * Store an uploaded product image on the 'public' disk under products/.
     * Returns the relative path (e.g. "products/abcd1234ef5678ab.jpg") suitable
     * for use with Storage::url() or asset('storage/'.$path).
     */
    private function storeImage($file): string
    {
        $ext      = $file->getClientOriginalExtension() ?: 'jpg';
        $filename = bin2hex(random_bytes(8)) . '.' . $ext;
        return $file->storeAs('products', $filename, 'public');
    }

    private function deleteImage(string $path): void
    {
        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
