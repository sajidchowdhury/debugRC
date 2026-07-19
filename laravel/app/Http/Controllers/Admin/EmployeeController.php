<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Employee master-data controller — Phase 4-C + Phase 12 hardening.
 *
 * Reproduces the legacy /employee/* UI in Blade on top of the Laravel 11
 * scaffold. Inherits full CRUD from BaseMasterDataController and adds:
 *  - photo upload (random filename, public/employees disk)
 *  - auto employee_code generation (EMP-NNNNNN) when not supplied
 *  - read-only account view (linked user + salary/advance summary)
 *
 * Phase 12 hardening (mirrors Branch/Warehouse/Product/Customer/Supplier):
 *  - store()/update(): pre-normalize employee_code (uppercase + trim)
 *    BEFORE validation for case-insensitive unique check.
 *  - store()/update(): trim name BEFORE validation.
 *  - validationRules(): default $id to 0 when null (matches the rest of the
 *    module suite — avoids "invalid input syntax for type integer" on store).
 *  - store()/update(): only set is_active when explicitly provided; otherwise
 *    let the DB default (true) apply on store, and preserve existing value
 *    on update.
 *  - update(): runs canDeactivate() safety check before flipping is_active=false.
 *  - canDeactivate(): 2 safety checks — outstanding employee ledger balance
 *    AND active linked user account (legacy `hasActiveUserAccount()` guard).
 */
class EmployeeController extends BaseMasterDataController
{
    protected string $modelClass = Employee::class;
    protected string $label = 'Employee';
    protected string $routePrefix = 'admin.employees';
    protected string $viewDir = 'admin.employees';

    protected array $searchFields = ['employee_code', 'name', 'phone', 'email'];

    // ===================== OVERRIDES =====================

    protected function indexWith(): array
    {
        return ['branch'];
    }

    protected function detailWith(): array
    {
        return ['branch', 'user'];
    }

    protected function formData(): array
    {
        return [
            'branches' => Branch::active()->orderBy('branch_name')->get(),
            'roles'    => config('roles'),
        ];
    }

    /**
     * Validation rules — used for both store and update.
     * The $id parameter is forwarded by the base controller on update so the
     * unique rule excludes the current row.
     *
     * Phase 12: default $id to 0 when null (matches Branch/Warehouse/Customer/
     * Supplier pattern and avoids "invalid input syntax for type integer" on
     * store when validationRules() is called without an argument).
     */
    protected function validationRules(?int $id = null): array
    {
        $id = $id ?? 0;

        return [
            'employee_code' => ['nullable', 'string', 'max:30', "unique:employees,employee_code,{$id}"],
            'name'          => ['required', 'string', 'max:100'],
            'role'          => ['required', 'in:superadmin,admin,manager,accountant,salesman,warehouse_manager,dispatcher,hr,user,other'],
            'branch_id'     => ['required', 'exists:branches,id'],
            'phone'         => ['nullable', 'string', 'max:30'],
            'email'         => ['nullable', 'email', 'max:100'],
            'address'       => ['nullable', 'string'],
            'salary'        => ['nullable', 'numeric', 'min:0'],
            'joining_date'  => ['nullable', 'date'],
            'is_active'     => ['boolean'],
            'photo'         => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:2048'],
        ];
    }

    protected function indexStats(): array
    {
        return [
            'active'    => Employee::active()->count(),
            'inactive'  => Employee::onlyTrashed()->count(),
            'total'     => Employee::withTrashed()->count(),
            'by_branch' => Employee::active()
                ->with('branch')
                ->selectRaw('branch_id, count(*) as count')
                ->groupBy('branch_id')
                ->pluck('count', 'branch_id'),
        ];
    }

    // ===================== STORE =====================

    /**
     * Override store() to:
     *  - relax employee_code to nullable on input (auto-generated when absent)
     *  - auto-generate employee_code in EMP-NNNNNN format
     *  - Phase 12: pre-normalize employee_code (uppercase + trim) + name (trim)
     *    BEFORE validation so the unique rule is case-insensitive.
     *  - Phase 12: only set is_active when the request explicitly provides it
     *    (matches Branch/Warehouse/Customer/Supplier — preserves DB default true).
     *  - handle photo upload (random bin2hex filename, public/employees disk)
     */
    public function store(Request $request)
    {
        // Phase 12: pre-normalize employee_code + name BEFORE validation.
        if ($request->has('employee_code')) {
            $request->merge(['employee_code' => strtoupper(trim((string) $request->input('employee_code')))]);
        }
        if ($request->has('name')) {
            $request->merge(['name' => trim((string) $request->input('name'))]);
        }

        // Relax employee_code: not required on input, but still unique if provided.
        $rules = $this->validationRules();
        $rules['employee_code'] = ['nullable', 'string', 'max:30', 'unique:employees,employee_code'];
        $validated = $request->validate($rules);

        // Auto-generate employee_code if not provided.
        if (empty($validated['employee_code'])) {
            $validated['employee_code'] = $this->generateEmployeeCode();
        }

        // Photo upload — random filename, public/employees disk.
        if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
            $validated['photo'] = $this->storePhoto($request->file('photo'));
        }

        // Phase 12: only set is_active when the request explicitly provides it.
        // Otherwise let the DB default (true) apply — matches the
        // Branch/Warehouse/Customer/Supplier pattern.
        if (!$request->has('is_active')) {
            unset($validated['is_active']);
        }

        try {
            $model = Employee::create($validated);

            return redirect()->route("{$this->routePrefix}.show", $model)
                ->with('success', "{$this->label} created successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()
                ->with('error', "Failed to create {$this->label}: {$e->getMessage()}");
        }
    }

    // ===================== UPDATE =====================

    /**
     * Override update() to:
     *  - Phase 12: pre-normalize employee_code (uppercase + trim) + name (trim)
     *    BEFORE validation.
     *  - Phase 12: run canDeactivate() safety check when is_active is being
     *    flipped from true → false.
     *  - Phase 12: only flip is_active when explicitly provided (so omitting
     *    the checkbox on update doesn't silently deactivate).
     *  - handle new photo upload (delete old photo if replaced)
     *  - honor remove_photo checkbox
     */
    public function update(Request $request, int $id)
    {
        $item = Employee::findOrFail($id);

        // Phase 12: pre-normalize employee_code + name BEFORE validation.
        if ($request->has('employee_code')) {
            $request->merge(['employee_code' => strtoupper(trim((string) $request->input('employee_code')))]);
        }
        if ($request->has('name')) {
            $request->merge(['name' => trim((string) $request->input('name'))]);
        }

        $validated = $request->validate($this->validationRules($id));

        // Honor "remove current photo" checkbox.
        if ($request->boolean('remove_photo') && $item->photo) {
            Storage::disk('public')->delete($item->photo);
            $validated['photo'] = null;
        }

        // New photo upload — delete old photo if being replaced.
        if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
            if ($item->photo) {
                Storage::disk('public')->delete($item->photo);
            }
            $validated['photo'] = $this->storePhoto($request->file('photo'));
        }

        // Phase 12: only flip is_active when explicitly provided.
        if (!$request->has('is_active')) {
            unset($validated['is_active']);
        }

        // Deactivation safety check — runs when is_active is being
        // explicitly set to false on an active employee.
        if (isset($validated['is_active']) && !$validated['is_active'] && $item->is_active) {
            $deactivationCheck = $this->canDeactivate($item);
            if (!$deactivationCheck['ok']) {
                return back()->withInput()->with('error', $deactivationCheck['message']);
            }
        }

        try {
            $item->update($validated);

            return redirect()->route("{$this->routePrefix}.show", $item)
                ->with('success', "{$this->label} updated successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()
                ->with('error', "Failed to update {$this->label}: {$e->getMessage()}");
        }
    }

    // ===================== ACCOUNT (read-only) =====================

    /**
     * Show the employee's linked user account + salary/advance summary.
     * Read-only for Phase 4. Transaction history will be added in Phase 9.
     */
    public function account(int $id)
    {
        $item = Employee::with(['branch', 'user'])->withTrashed()->findOrFail($id);

        // Salary/advance summary placeholder — Phase 9 will wire up payroll +
        // advance tables. For now we surface the base salary and zeroed totals.
        $salarySummary = [
            'base_salary'           => $item->salary,
            'paid_this_month'       => 0,
            'advances_outstanding'  => 0,
            'last_paid_date'        => null,
        ];

        return view("{$this->viewDir}.account", [
            'title'         => "{$item->name} — account",
            'item'          => $item,
            'salarySummary' => $salarySummary,
            'routePrefix'   => $this->routePrefix,
            'label'         => $this->label,
        ]);
    }

    // ===================== HELPERS =====================

    /**
     * Generate the next employee_code in EMP-NNNNNN format.
     * Looks at the highest numeric suffix across all (incl. trashed) records.
     */
    protected function generateEmployeeCode(): string
    {
        $last = Employee::withTrashed()
            ->where('employee_code', 'LIKE', 'EMP-%')
            ->orderByRaw("LENGTH(employee_code) DESC")
            ->orderBy('employee_code', 'desc')
            ->first();

        $next = 1;
        if ($last && preg_match('/^EMP-(\d+)$/', $last->employee_code, $m)) {
            $next = ((int) $m[1]) + 1;
        }

        return 'EMP-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Store an uploaded photo on the public disk under employees/.
     * Returns the relative path (e.g. "employees/1a2b3c4d5e6f7f8f.jpg").
     */
    protected function storePhoto(\Illuminate\Http\UploadedFile $file): string
    {
        $ext  = $file->getClientOriginalExtension();
        $name = bin2hex(random_bytes(8));
        $filename = $ext !== '' ? "{$name}.{$ext}" : $name;

        return $file->storeAs('employees', $filename, 'public');
    }

    /**
     * Phase 12: Can this employee be safely deactivated?
     * Mirrors the legacy EmployeeModel::hasActiveUserAccount() +
     * hasHistoricalReferences() guards:
     *   1. Active user account linked — deactivating an employee with an
     *      active login account would orphan the user. Legacy blocked the
     *      toggle / soft-delete outright when `users.is_active=true AND
     *      users.deleted_at IS NULL`.
     *   2. Outstanding employee ledger balance — sum of debit (advances/
     *      salary paid) minus credit (repayments/salary credit). A non-zero
     *      balance means there's an unsettled advance the employee owes.
     *
     * @param  Employee  $item
     * @return array{ok: bool, message: string}
     */
    protected function canDeactivate($item): array
    {
        $employeeId = $item->id;

        // 1. Active user account — login would be orphaned.
        $activeUserCount = DB::table('users')
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->count();

        // 2. Outstanding employee ledger balance — sum of debit (advances,
        //    deductions, salary paid) minus credit (repayments, salary credit).
        $balance = DB::table('employee_ledger')
            ->where('employee_id', $employeeId)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS balance')
            ->value('balance');

        $balance = (float) $balance;

        $parts = [];
        if ($activeUserCount > 0) {
            $parts[] = "{$activeUserCount} active linked user account(s)";
        }
        if (abs($balance) > 0.005) {
            $parts[] = "outstanding employee balance of " . number_format($balance, 2);
        }

        if (!empty($parts)) {
            return [
                'ok' => false,
                'message' => "Cannot deactivate this employee. It has " . implode(', ', $parts)
                    . ". Please resolve them first.",
            ];
        }

        return ['ok' => true, 'message' => ''];
    }
}
