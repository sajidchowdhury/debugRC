<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use App\Models\Supplier;
use App\Services\MasterData\CodeGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Supplier master-data controller — Phase 4 + Phase 11 hardening.
 *
 * Replicates the legacy /supplier/* PHP UI in Blade, riding on the shared
 * BaseMasterDataController CRUD skeleton. Behaviour overrides:
 *   - store(): auto-generate supplier_code (SUP-NNNNNN) when blank via the
 *     centralized CodeGenerator service (Phase 17 refactor — the
 *     per-controller generateSupplierCode() method was removed).
 *   - store()/update(): pre-normalize supplier_code (uppercase + trim)
 *     BEFORE validation so the unique rule is case-insensitive.
 *   - update(): runs canDeactivate() when is_active is being flipped to
 *     false (so admins can't accidentally lock out suppliers with AP).
 *   - canDeactivate(): 2 safety checks — outstanding AP balance in
 *     supplier_ledger + open (non-cancelled, non-received) purchase orders.
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
     * Phase 18: Columns to export for the CSV download.
     */
    protected function exportColumns(): array
    {
        return [
            'supplier_code' => 'Code',
            'supplier_name' => 'Supplier Name',
            'phone'         => 'Phone',
            'email'         => 'Email',
            'is_active'     => 'Active',
        ];
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
     * The $id parameter is forwarded by the base controller on update so the
     * unique rule excludes the current row.
     *
     * Phase 11: default $id to 0 when null (matches Branch/Warehouse/Customer
     * pattern and avoids "invalid input syntax for type integer" on store).
     */
    protected function validationRules(?int $id = null): array
    {
        $id = $id ?? 0;

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
     *
     * Phase 17 refactor: now uses the centralized CodeGenerator service
     * instead of a per-controller generateSupplierCode() method.
     *
     * Phase 11: pre-normalize supplier_code BEFORE validation for
     * case-insensitive unique check (same fix as Phase 8/9/10). Also
     * normalize free-text fields and only set is_active when explicitly
     * provided (matches Branch/Warehouse/Customer pattern — preserves DB
     * default of true).
     */
    public function store(Request $request)
    {
        // Pre-fill the code so the unique rule sees it. We generate the next
        // sequence before validation so a collision can't sneak in.
        if (empty(trim((string) $request->input('supplier_code')))) {
            $request->merge(['supplier_code' => CodeGenerator::supplierCode()]);
        }

        // Phase 11: normalize supplier_code BEFORE validation for
        // case-insensitive unique check (same fix as Phase 8/9/10).
        if ($request->has('supplier_code')) {
            $request->merge(['supplier_code' => strtoupper(trim((string) $request->input('supplier_code')))]);
        }
        if ($request->has('supplier_name')) {
            $request->merge(['supplier_name' => trim((string) $request->input('supplier_name'))]);
        }

        $rules = $this->validationRules();
        $validated = $request->validate($rules);

        // Normalize remaining free-text fields (legacy behavior).
        if (isset($validated['phone']))          $validated['phone']         = trim($validated['phone']);
        if (isset($validated['mobile']))         $validated['mobile']        = trim($validated['mobile']);
        if (isset($validated['email']))          $validated['email']         = trim($validated['email']);
        if (isset($validated['address']))        $validated['address']       = trim($validated['address']);
        if (isset($validated['contact_person'])) $validated['contact_person'] = trim($validated['contact_person']);

        // Phase 11: only set is_active when the request explicitly provides
        // it. Otherwise let the DB default (true) apply — matches the
        // Branch/Warehouse/Customer pattern.
        if (!$request->has('is_active')) {
            unset($validated['is_active']);
        }

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
     * Override update(): pre-normalize supplier_code BEFORE validation,
     * run deactivation safety check if is_active is being flipped false,
     * and avoid silently flipping is_active when the checkbox is omitted.
     */
    public function update(Request $request, int $id)
    {
        $item = Supplier::findOrFail($id);

        // Phase 11: normalize supplier_code BEFORE validation.
        if ($request->has('supplier_code')) {
            $request->merge(['supplier_code' => strtoupper(trim((string) $request->input('supplier_code')))]);
        }
        if ($request->has('supplier_name')) {
            $request->merge(['supplier_name' => trim((string) $request->input('supplier_name'))]);
        }

        $validated = $request->validate($this->validationRules($id));

        // Normalize remaining free-text fields.
        if (isset($validated['phone']))          $validated['phone']         = trim($validated['phone']);
        if (isset($validated['mobile']))         $validated['mobile']        = trim($validated['mobile']);
        if (isset($validated['email']))          $validated['email']         = trim($validated['email']);
        if (isset($validated['address']))        $validated['address']       = trim($validated['address']);
        if (isset($validated['contact_person'])) $validated['contact_person'] = trim($validated['contact_person']);

        // Phase 11: only flip is_active when explicitly provided.
        if (!$request->has('is_active')) {
            unset($validated['is_active']);
        }

        // Deactivation safety check — runs when is_active is being
        // explicitly set to false on an active supplier.
        if (isset($validated['is_active']) && !$validated['is_active'] && $item->is_active) {
            $deactivationCheck = $this->canDeactivate($item);
            if (!$deactivationCheck['ok']) {
                return back()->withInput()->with('error', $deactivationCheck['message']);
            }
        }

        try {
            $item->update($validated);

            return redirect()
                ->route("{$this->routePrefix}.show", $item)
                ->with('success', "{$this->label} updated successfully.");
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->with('error', "Failed to update {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Phase 11: Can this supplier be safely deactivated?
     * Mirrors the legacy canDeactivateSupplier() checks:
     *   1. Outstanding AP balance in supplier_ledger (sum of credit - debit).
     *      For AP, the supplier is CREDITED when we owe them money (e.g.
     *      GRN posted) and DEBITED when we pay them.
     *   2. Open (non-cancelled, non-received) purchase orders referencing
     *      this supplier.
     *
     * @return array{ok: bool, message: string}
     */
    protected function canDeactivate($item): array
    {
        $supplierId = $item->id;

        // 1. Outstanding AP balance — sum of credit minus sum of debit.
        // Any non-zero balance means there's an unsettled supplier payable.
        $balance = DB::table('supplier_ledger')
            ->where('supplier_id', $supplierId)
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS balance')
            ->value('balance');

        $balance = (float) $balance;

        // 2. Open purchase orders — not cancelled, not fully received.
        $openPurchaseOrders = DB::table('purchase_orders')
            ->where('supplier_id', $supplierId)
            ->whereNotIn('status', ['cancelled', 'received'])
            ->count();

        $parts = [];
        if (abs($balance) > 0.005) {
            $parts[] = "outstanding AP balance of " . number_format($balance, 2);
        }
        if ($openPurchaseOrders > 0) {
            $parts[] = "{$openPurchaseOrders} open purchase order(s)";
        }

        if (!empty($parts)) {
            return [
                'ok' => false,
                'message' => "Cannot deactivate this supplier. It has " . implode(', ', $parts)
                    . ". Please resolve them first.",
            ];
        }

        return ['ok' => true, 'message' => ''];
    }
}
