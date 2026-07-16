<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Supplier master-data controller — Phase 4.
 *
 * Replicates the legacy /supplier/* PHP UI in Blade on top of the shared
 * BaseMasterDataController. Overrides store() to auto-generate supplier_code
 * in the SUP-NNNNNN format when the user leaves it blank.
 */
class SupplierController extends BaseMasterDataController
{
    protected string $modelClass  = Supplier::class;
    protected string $label       = 'Supplier';
    protected string $routePrefix = 'admin.suppliers';
    protected string $viewDir     = 'admin.suppliers';

    protected array $searchFields = [
        'supplier_code',
        'supplier_name',
        'mobile',
        'phone',
    ];

    /**
     * Stats cards shown on the index hero.
     */
    protected function indexStats(): array
    {
        return [
            'active'   => Supplier::active()->count(),
            'inactive' => Supplier::onlyTrashed()->count(),
            'total'    => Supplier::withTrashed()->count(),
        ];
    }

    /**
     * Eager-load branch for the index listing.
     */
    protected function indexWith(): array
    {
        return ['branch'];
    }

    /**
     * detailWith defaults to no relations — branch is loaded via indexWith
     * already and only the show view uses detailWith, so we re-use branch.
     */
    protected function detailWith(): array
    {
        return ['branch'];
    }

    /**
     * Select-dropdown data for create/edit views.
     */
    protected function formData(): array
    {
        return [
            'branches' => Branch::active()->orderBy('branch_name')->get(),
        ];
    }

    /**
     * Validation rules — used for both store and update.
     */
    protected function validationRules(?int $id = null): array
    {
        return [
            'supplier_code'   => ['nullable', 'string', 'max:30', "unique:suppliers,supplier_code,{$id}"],
            'supplier_name'   => ['required', 'string', 'max:200'],
            'phone'           => ['nullable', 'string', 'max:30'],
            'mobile'          => ['nullable', 'string', 'max:30'],
            'email'           => ['nullable', 'email', 'max:100'],
            'address'         => ['nullable', 'string'],
            'branch_id'       => ['nullable', 'exists:branches,id'],
            'contact_person'  => ['nullable', 'string', 'max:100'],
            'opening_balance' => ['nullable', 'numeric'],
            'balance_type'    => ['nullable', 'in:debit,credit'],
            'is_active'       => ['boolean'],
        ];
    }

    /**
     * Override store(): auto-generate supplier_code (SUP-NNNNNN) when the
     * user didn't supply one.
     */
    public function store(Request $request)
    {
        if (empty(trim((string) $request->input('supplier_code')))) {
            $request->merge(['supplier_code' => $this->generateSupplierCode()]);
        }

        $validated = $request->validate($this->validationRules());
        $validated['is_active'] = $request->boolean('is_active', true);

        try {
            $supplier = Supplier::create($validated);

            return redirect()
                ->route("{$this->routePrefix}.show", $supplier)
                ->with('success', "{$this->label} created successfully.");
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->with('error', "Failed to create {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Generate the next supplier code in the SUP-NNNNNN format.
     */
    protected function generateSupplierCode(): string
    {
        $prefix = 'SUP-';

        $last = DB::table('suppliers')
            ->where('supplier_code', 'LIKE', "{$prefix}%")
            ->selectRaw("MAX(SUBSTRING(supplier_code FROM LENGTH('{$prefix}') + 1)) AS seq")
            ->value('seq');

        $next = ((int) $last) + 1;

        return $prefix . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
