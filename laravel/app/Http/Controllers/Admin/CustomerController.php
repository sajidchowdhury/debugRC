<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer master-data controller — Phase 4.
 *
 * Replicates the legacy /customer/* PHP UI in Blade, riding on the shared
 * BaseMasterDataController CRUD skeleton. The only behaviour that needed a
 * real override is store(): auto-generate customer_code when the user leaves
 * it blank, matching the legacy "code is assigned automatically" UX.
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
     */
    protected function validationRules(?int $id = null): array
    {
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
     */
    public function store(Request $request)
    {
        $rules = $this->validationRules();

        // Pre-fill the code so the unique rule sees it. We generate the next
        // sequence before validation so a collision can't sneak in.
        if (empty(trim((string) $request->input('customer_code')))) {
            $request->merge(['customer_code' => $this->generateCustomerCode()]);
        }

        $validated = $request->validate($rules);

        // Force sane defaults for the boolean + numeric fields so mass-assign
        // does not choke on nulls from unchecked checkboxes.
        $validated['is_active'] = $request->boolean('is_active', true);

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
     * Generate the next customer code in the CUS-YYYY-NNNNNN format.
     * Looks at the highest numeric suffix in the table and increments it,
     * scoped inside the current year. This is intentionally a single
     * table-scan + str_pad — race conditions are bounded by a unique index
     * on customer_code (callers will get a validation error and retry).
     */
    protected function generateCustomerCode(): string
    {
        $year  = now()->format('Y');
        $prefix = "CUS-{$year}-";

        $last = DB::table('customers')
            ->where('customer_code', 'LIKE', "{$prefix}%")
            ->selectRaw("MAX(SUBSTRING(customer_code FROM LENGTH('{$prefix}') + 1)) AS seq")
            ->value('seq');

        $next = ((int) $last) + 1;

        return $prefix . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
