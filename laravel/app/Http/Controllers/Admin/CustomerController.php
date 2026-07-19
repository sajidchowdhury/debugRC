<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Services\MasterData\CodeGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Customer master-data controller — Phase 4 + Phase 10 hardening.
 *
 * Replicates the legacy /customer/* PHP UI in Blade, riding on the shared
 * BaseMasterDataController CRUD skeleton. Behaviour overrides:
 *   - store(): auto-generate customer_code (CUS-YYYY-NNNNNN) when blank
 *     via the centralized CodeGenerator service (Phase 17 refactor — the
 *     per-controller generateCustomerCode() method was removed).
 *   - store()/update(): pre-normalize customer_code (uppercase + trim)
 *     BEFORE validation so the unique rule is case-insensitive.
 *   - update(): runs canDeactivate() when is_active is being flipped to
 *     false (so admins can't accidentally lock out customers with AR).
 *   - canDeactivate(): 2 safety checks — outstanding AR balance in
 *     customer_ledger + open (non-cancelled, non-reversed) sales invoices.
 */
class CustomerController extends BaseMasterDataController
{
    protected string $modelClass = Customer::class;
    protected string $label      = 'Customer';
    protected string $routePrefix = 'admin.customers';
    protected string $viewDir     = 'admin.customers';

    protected array $searchFields = [
        'customer_code',
        'customer_name',
        'mobile',
        'phone',
    ];

    /**
     * Stats cards shown on the index hero.
     */
    protected function indexStats(): array
    {
        return [
            'active'   => Customer::active()->count(),
            'inactive' => Customer::onlyTrashed()->count(),
            'total'    => Customer::withTrashed()->count(),
        ];
    }

    /**
     * Eager-load branch + salesPerson for the index listing.
     */
    protected function indexWith(): array
    {
        return ['branch', 'salesPerson'];
    }

    /**
     * Eager-load branch + salesPerson for the detail/show page.
     */
    protected function detailWith(): array
    {
        return ['branch', 'salesPerson'];
    }

    /**
     * Phase 18: Columns to export for the CSV download.
     */
    protected function exportColumns(): array
    {
        return [
            'customer_code'      => 'Code',
            'customer_name'      => 'Customer Name',
            'phone'              => 'Phone',
            'mobile'             => 'Mobile',
            'email'              => 'Email',
            'branch.branch_name' => 'Branch Name',
            'is_active'          => 'Active',
        ];
    }

    /**
     * Select-dropdown data for create/edit views.
     */
    protected function formData(): array
    {
        return [
            'branches' => Branch::active()->orderBy('branch_name')->get(),
            'salesPersons' => Employee::active()
                ->whereIn('role', ['salesman', 'manager'])
                ->orderBy('name')
                ->get(),
        ];
    }

    /**
     * Validation rules — used for both store and update.
     * The $id parameter is forwarded by the base controller on update so the
     * unique rule excludes the current row.
     *
     * Phase 10: default $id to 0 when null (matches Branch/Warehouse pattern
     * and avoids "invalid input syntax for type integer" on store).
     */
    protected function validationRules(?int $id = null): array
    {
        $id = $id ?? 0;

        return [
            'customer_code'   => ['nullable', 'string', 'max:30', "unique:customers,customer_code,{$id}"],
            'customer_name'   => ['required', 'string', 'max:200'],
            'phone'           => ['nullable', 'string', 'max:30'],
            'mobile'          => ['nullable', 'string', 'max:30'],
            'email'           => ['nullable', 'email', 'max:100'],
            'address'         => ['nullable', 'string'],
            'branch_id'       => ['nullable', 'exists:branches,id'],
            'sales_person_id' => ['nullable', 'exists:employees,id'],
            'credit_limit'    => ['nullable', 'numeric', 'min:0'],
            'opening_balance' => ['nullable', 'numeric'],
            'balance_type'    => ['nullable', 'in:debit,credit'],
            'is_active'       => ['boolean'],
        ];
    }

    /**
     * Override store(): auto-generate customer_code (CUS-YYYY-NNNNNN) when
     * the user didn't supply one. Format mirrors the legacy helper.
     *
     * Phase 17 refactor: now uses the centralized CodeGenerator service
     * instead of a per-controller generateCustomerCode() method.
     *
     * Phase 10: pre-normalize customer_code (uppercase + trim) BEFORE
     * validation so the unique rule is case-insensitive. Also normalize
     * free-text fields and only set is_active when explicitly provided
     * (matches Branch/Warehouse pattern — preserves DB default of true).
     */
    public function store(Request $request)
    {
        // Pre-fill the code so the unique rule sees it. We generate the next
        // sequence before validation so a collision can't sneak in.
        if (empty(trim((string) $request->input('customer_code')))) {
            $request->merge(['customer_code' => CodeGenerator::customerCode()]);
        }

        // Phase 10: normalize customer_code BEFORE validation for
        // case-insensitive unique check (same fix as Phase 8/9).
        if ($request->has('customer_code')) {
            $request->merge(['customer_code' => strtoupper(trim((string) $request->input('customer_code')))]);
        }
        if ($request->has('customer_name')) {
            $request->merge(['customer_name' => trim((string) $request->input('customer_name'))]);
        }

        $rules = $this->validationRules();
        $validated = $request->validate($rules);

        // Normalize remaining free-text fields (legacy behavior).
        if (isset($validated['phone']))   $validated['phone']   = trim($validated['phone']);
        if (isset($validated['mobile']))  $validated['mobile']  = trim($validated['mobile']);
        if (isset($validated['email']))   $validated['email']   = trim($validated['email']);
        if (isset($validated['address'])) $validated['address'] = trim($validated['address']);

        // Phase 10: only set is_active when the request explicitly provides
        // it. Otherwise let the DB default (true) apply — matches the
        // Branch/Warehouse pattern.
        if (!$request->has('is_active')) {
            unset($validated['is_active']);
        }

        try {
            $customer = Customer::create($validated);

            return redirect()
                ->route("{$this->routePrefix}.show", $customer)
                ->with('success', "{$this->label} created successfully.");
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->with('error', "Failed to create {$this->label}: {$e->getMessage()}");
        }
    }

    /**
     * Override update(): pre-normalize customer_code BEFORE validation,
     * run deactivation safety check if is_active is being flipped false,
     * and avoid silently flipping is_active when the checkbox is omitted.
     */
    public function update(Request $request, int $id)
    {
        $item = Customer::findOrFail($id);

        // Phase 10: normalize customer_code BEFORE validation.
        if ($request->has('customer_code')) {
            $request->merge(['customer_code' => strtoupper(trim((string) $request->input('customer_code')))]);
        }
        if ($request->has('customer_name')) {
            $request->merge(['customer_name' => trim((string) $request->input('customer_name'))]);
        }

        $validated = $request->validate($this->validationRules($id));

        // Normalize remaining free-text fields.
        if (isset($validated['phone']))   $validated['phone']   = trim($validated['phone']);
        if (isset($validated['mobile']))  $validated['mobile']  = trim($validated['mobile']);
        if (isset($validated['email']))   $validated['email']   = trim($validated['email']);
        if (isset($validated['address'])) $validated['address'] = trim($validated['address']);

        // Phase 10: only flip is_active when explicitly provided.
        if (!$request->has('is_active')) {
            unset($validated['is_active']);
        }

        // Deactivation safety check — runs when is_active is being
        // explicitly set to false on an active customer.
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
     * Phase 10: Can this customer be safely deactivated?
     * Mirrors the legacy canDeactivateCustomer() checks:
     *   1. Outstanding AR balance in customer_ledger (sum of debit - credit).
     *   2. Open (non-cancelled, non-reversed) sales invoices referencing
     *      this customer.
     *
     * @return array{ok: bool, message: string}
     */
    protected function canDeactivate($item): array
    {
        $customerId = $item->id;

        // 1. Outstanding AR balance — sum of debit minus sum of credit.
        // Any non-zero balance means there's an unsettled customer debt.
        $balance = DB::table('customer_ledger')
            ->where('customer_id', $customerId)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS balance')
            ->value('balance');

        $balance = (float) $balance;

        // 2. Open sales invoices — not reversed, not cancelled.
        $openInvoices = DB::table('sales_invoices')
            ->where('customer_id', $customerId)
            ->where('is_reversed', false)
            ->whereNotIn('status', ['cancelled', 'reversed'])
            ->count();

        $parts = [];
        if (abs($balance) > 0.005) {
            $parts[] = "outstanding AR balance of " . number_format($balance, 2);
        }
        if ($openInvoices > 0) {
            $parts[] = "{$openInvoices} open sales invoice(s)";
        }

        if (!empty($parts)) {
            return [
                'ok' => false,
                'message' => "Cannot deactivate this customer. It has " . implode(', ', $parts)
                    . ". Please resolve them first.",
            ];
        }

        return ['ok' => true, 'message' => ''];
    }
}
