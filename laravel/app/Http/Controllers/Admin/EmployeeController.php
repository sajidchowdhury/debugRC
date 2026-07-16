<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Employee master-data controller — Phase 4-C.
 *
 * Reproduces the legacy /employee/* UI in Blade on top of the Laravel 11
 * scaffold. Inherits full CRUD from BaseMasterDataController and adds:
 *  - photo upload (random filename, public/employees disk)
 *  - auto employee_code generation (EMP-NNNNNN) when not supplied
 *  - read-only account view (linked user + salary/advance summary)
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

    protected function validationRules(?int $id = null): array
    {
        return [
            'employee_code' => 'required|string|max:30|unique:employees,employee_code,' . $id,
            'name'          => 'required|string|max:100',
            'role'          => 'required|in:superadmin,admin,manager,accountant,salesman,warehouse_manager,dispatcher,hr,user,other',
            'branch_id'     => 'required|exists:branches,id',
            'phone'         => 'nullable|string|max:30',
            'email'         => 'nullable|email|max:100',
            'address'       => 'nullable|string',
            'salary'        => 'nullable|numeric|min:0',
            'joining_date'  => 'nullable|date',
            'is_active'     => 'boolean',
            'photo'         => 'nullable|image|mimes:jpeg,png,webp,gif|max:2048',
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
     *  - handle photo upload (random bin2hex filename, public/employees disk)
     *  - coerce is_active checkbox to boolean (form pre-checks it)
     */
    public function store(Request $request)
    {
        // Relax employee_code: not required on input, but still unique if provided
        $rules = $this->validationRules();
        $rules['employee_code'] = 'nullable|string|max:30|unique:employees,employee_code';
        $validated = $request->validate($rules);

        // Auto-generate employee_code if not provided
        if (empty($validated['employee_code'])) {
            $validated['employee_code'] = $this->generateEmployeeCode();
        }

        // Photo upload — random filename, public/employees disk
        if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
            $validated['photo'] = $this->storePhoto($request->file('photo'));
        }

        // HTML checkbox is absent when unchecked → coerce to bool, default false.
        // (The create form pre-checks the "Active" switch, so a normal submit
        // arrives with is_active=1; unchecking explicitly deactivates.)
        $validated['is_active'] = (bool) ($validated['is_active'] ?? false);

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
     *  - handle new photo upload (delete old photo if replaced)
     *  - honor remove_photo checkbox
     *  - coerce is_active to boolean (checkbox may be absent)
     */
    public function update(Request $request, int $id)
    {
        $item = Employee::findOrFail($id);
        $validated = $request->validate($this->validationRules($id));

        // Honor "remove current photo" checkbox
        if ($request->boolean('remove_photo') && $item->photo) {
            Storage::disk('public')->delete($item->photo);
            $validated['photo'] = null;
        }

        // New photo upload — delete old photo if being replaced
        if ($request->hasFile('photo') && $request->file('photo')->isValid()) {
            if ($item->photo) {
                Storage::disk('public')->delete($item->photo);
            }
            $validated['photo'] = $this->storePhoto($request->file('photo'));
        }

        // Checkbox absent = unchecked → false
        $validated['is_active'] = (bool) ($validated['is_active'] ?? false);

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
}
