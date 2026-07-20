<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * User admin controller — Phase 14.
 *
 * Manages the `users` table (login accounts tied to Employees). Inherits the
 * full CRUD scaffolding from BaseMasterDataController and adds User-specific
 * security actions:
 *
 *  - store(): username pre-normalization (lowercase + trim) + password
 *    hashing + created_by + credential_version=1. Defense-in-depth check
 *    that the employee doesn't already have a user (UNIQUE constraint on
 *    users.employee_id also catches this at the DB level).
 *  - update(): username normalization; if a new password is provided, hash
 *    it and bump credential_version (invalidates existing sessions via the
 *    CheckCredentialVersion middleware). Runs canDeactivate() before
 *    flipping is_active=false.
 *  - canDeactivate(): blocks deactivation of a user with an active session
 *    (last_login within 5 minutes).
 *  - unlock(): clears locked_until + failed_login_count for a locked user.
 *  - resetPassword(): generates a random password, hashes it, bumps
 *    credential_version, flashes the plain password back to the admin.
 *  - securityAudit(): shows the user's login history + failed attempts +
 *    lockout events from user_audit_log.
 *
 * Phase 14 commit.
 */
class UserController extends BaseMasterDataController
{
    public function __construct()
    {
        $this->modelClass  = User::class;
        $this->label       = 'User';
        $this->routePrefix = 'admin.users';
        $this->viewDir     = 'admin.users';
        $this->searchFields = ['username'];
    }

    // ===================== OVERRIDES =====================

    /**
     * Eager-load employee.branch on the index listing.
     */
    protected function indexWith(): array
    {
        return ['employee.branch'];
    }

    /**
     * Eager-load employee.branch on the detail / edit screens.
     */
    protected function detailWith(): array
    {
        return ['employee.branch'];
    }

    /**
     * Phase 18: Columns to export for the CSV download.
     * Uses dotted relation paths 'employee.name' and 'employee.role' to
     * pull the linked employee's name and role (role is stored on
     * Employee, not on User — matches legacy schema).
     */
    protected function exportColumns(): array
    {
        return [
            'username'           => 'Username',
            'employee.name'      => 'Employee Name',
            'employee.role'      => 'Role',
            'is_active'          => 'Active',
            'last_login'         => 'Last Login',
            'telegram_user_id'   => 'Telegram User ID',
        ];
    }

    /**
     * Form dropdown data: employees without an existing user account.
     * Used by the create form so admins can only assign logins to employees
     * that don't already have one (users.employee_id is UNIQUE).
     */
    protected function formData(): array
    {
        $existingUserEmployeeIds = User::withTrashed()
            ->whereNotNull('employee_id')
            ->pluck('employee_id')
            ->all();

        $employees = Employee::active()
            ->whereNotIn('id', $existingUserEmployeeIds)
            ->orderBy('name')
            ->get();

        return [
            'employees' => $employees,
        ];
    }

    /**
     * Validation rules — used for both store and update.
     *
     * The $id parameter is forwarded by the base controller on update so the
     * unique rule excludes the current row. Default $id to 0 when null
     * (matches Branch/Warehouse/Customer/Supplier/Employee/Bank pattern).
     *
     * Note: password is NOT in the shared rules — it's required on store
     * (added by store() override) and optional on update (added by update()
     * override).
     */
    protected function validationRules(?int $id = null): array
    {
        $id = $id ?? 0;

        return [
            'username'         => ['required', 'string', 'max:50', "unique:users,username,{$id}"],
            'employee_id'      => ['required', 'exists:employees,id'],
            'is_active'        => ['boolean'],
            'telegram_user_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * Hero stats for the index page.
     */
    protected function indexStats(): array
    {
        return [
            'active'       => User::active()->count(),
            'locked'       => User::locked()->count(),
            'total'        => User::withTrashed()->count(),
            'telegram'     => User::whereNotNull('telegram_user_id')->count(),
        ];
    }

    // ===================== STORE =====================

    /**
     * Override store() to:
     *  - pre-normalize username (strtolower + trim) BEFORE validation so the
     *    unique check is case-insensitive.
     *  - require password on store (admin sets the initial password).
     *  - hash password → password_hash column.
     *  - set credential_version = 1 (matches DB default; explicit for clarity).
     *  - set created_by from Auth::id().
     *  - defense-in-depth: check the employee doesn't already have a user
     *    (UNIQUE constraint on users.employee_id catches this at DB level too).
     */
    public function store(Request $request)
    {
        // Phase 14: pre-normalize username BEFORE validation.
        if ($request->has('username')) {
            $request->merge(['username' => strtolower(trim((string) $request->input('username')))]);
        }

        // Add password to the rules (required on store only).
        $rules = $this->validationRules();
        $rules['password'] = ['required', 'string', 'min:6', 'max:128'];

        $validated = $request->validate($rules);

        // Defense-in-depth: check that the employee doesn't already have a user.
        // The DB UNIQUE constraint on users.employee_id would also catch this,
        // but we want a friendly error message before hashing the password.
        $existingUser = User::withTrashed()
            ->where('employee_id', $validated['employee_id'])
            ->exists();
        if ($existingUser) {
            return back()->withInput()
                ->with('error', 'Failed to create User: this employee already has a login account.');
        }

        // Hash the password → password_hash column.
        $validated['password_hash'] = Hash::make($validated['password']);
        unset($validated['password']);

        // Set credential_version = 1 (matches DB default; explicit for clarity).
        $validated['credential_version'] = 1;

        // Set created_by from the authenticated admin.
        $validated['created_by'] = Auth::id();

        // Only set is_active when explicitly provided (preserves DB default true).
        if (!$request->has('is_active')) {
            unset($validated['is_active']);
        }

        try {
            $user = User::create($validated);

            return redirect()->route("{$this->routePrefix}.show", $user)
                ->with('success', "{$this->label} created successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()
                ->with('error', "Failed to create {$this->label}: {$e->getMessage()}");
        }
    }

    // ===================== UPDATE =====================

    /**
     * Override update() to:
     *  - pre-normalize username (strtolower + trim) BEFORE validation.
     *  - accept an optional new password; if provided, hash it and bump
     *    credential_version (invalidates existing sessions via the
     *    CheckCredentialVersion middleware).
     *  - run canDeactivate() safety check when is_active is being flipped
     *    from true → false.
     *  - only flip is_active when explicitly provided (so omitting the
     *    checkbox on update doesn't silently deactivate).
     *
     * Note: on update, employee_id is NOT required (it's set at creation
     * and not editable from the edit form). If provided, it must still
     * exist in the employees table.
     */
    public function update(Request $request, int $id)
    {
        $user = User::findOrFail($id);

        // Phase 14: pre-normalize username BEFORE validation.
        if ($request->has('username')) {
            $request->merge(['username' => strtolower(trim((string) $request->input('username')))]);
        }

        // Add optional password rule (nullable on update).
        $rules = $this->validationRules($id);
        $rules['password'] = ['nullable', 'string', 'min:6', 'max:128'];
        // On update, employee_id is not required (set at creation, not editable).
        $rules['employee_id'] = ['nullable', 'exists:employees,id'];

        $validated = $request->validate($rules);

        // Handle optional password reset.
        $newPasswordProvided = !empty($validated['password']);
        if ($newPasswordProvided) {
            $validated['password_hash'] = Hash::make($validated['password']);
            // Bump credential_version — invalidates existing sessions via
            // CheckCredentialVersion middleware.
            $validated['credential_version'] = ($user->credential_version ?? 1) + 1;
        }
        unset($validated['password']);

        // Only flip is_active when explicitly provided.
        if (!$request->has('is_active')) {
            unset($validated['is_active']);
        }

        // Deactivation safety check — runs when is_active is being
        // explicitly set to false on an active user.
        if (isset($validated['is_active']) && !$validated['is_active'] && $user->is_active) {
            $deactivationCheck = $this->canDeactivate($user);
            if (!$deactivationCheck['ok']) {
                return back()->withInput()->with('error', $deactivationCheck['message']);
            }
        }

        try {
            $user->update($validated);

            return redirect()->route("{$this->routePrefix}.show", $user)
                ->with('success', "{$this->label} updated successfully.");
        } catch (\Throwable $e) {
            return back()->withInput()
                ->with('error', "Failed to update {$this->label}: {$e->getMessage()}");
        }
    }

    // ===================== CAN DEACTIVATE =====================

    /**
     * Phase 14: Can this user be safely deactivated?
     *
     * Mirrors the legacy guard against deactivating a user with an active
     * login session. If the user's last_login is within the last 5 minutes,
     * they likely have an active session — deactivating mid-session would
     * leave orphaned sessions and confuse the user.
     *
     * @param  User  $item
     * @return array{ok: bool, message: string}
     */
    protected function canDeactivate($item): array
    {
        $lastLogin = $item->last_login;

        if ($lastLogin !== null && $lastLogin->gt(now()->subMinutes(5))) {
            return [
                'ok' => false,
                'message' => 'Cannot deactivate this user. They have an active login session '
                    . '(last login less than 5 minutes ago). Please wait for the session to expire or have the user log out first.',
            ];
        }

        return ['ok' => true, 'message' => ''];
    }

    // ===================== UNLOCK =====================

    /**
     * Clear locked_until + failed_login_count for a locked user.
     * Used by admins to manually unlock an account that was auto-locked
     * by too many failed login attempts.
     */
    public function unlock(int $id)
    {
        $user = User::withTrashed()->findOrFail($id);

        try {
            $user->locked_until = null;
            $user->failed_login_count = 0;
            $user->save();

            return redirect()->route("{$this->routePrefix}.show", $user)
                ->with('success', "{$this->label} unlocked successfully.");
        } catch (\Throwable $e) {
            return back()->with('error', "Failed to unlock {$this->label}: {$e->getMessage()}");
        }
    }

    // ===================== RESET PASSWORD =====================

    /**
     * Generate a new random password, hash it, bump credential_version,
     * and flash the plain password back to the admin (for manual
     * communication to the user).
     *
     * The plain password is NEVER persisted — only the bcrypt hash lands
     * in users.password_hash. The admin sees the plain password exactly
     * once via session flash data on the show page.
     */
    public function resetPassword(int $id)
    {
        $user = User::withTrashed()->findOrFail($id);

        try {
            // Generate a 16-character random password (alphanumeric).
            $plainPassword = substr(bin2hex(random_bytes(12)), 0, 16);

            $user->password_hash = Hash::make($plainPassword);
            $user->credential_version = ($user->credential_version ?? 1) + 1;
            $user->locked_until = null;
            $user->failed_login_count = 0;
            $user->save();

            return redirect()->route("{$this->routePrefix}.show", $user)
                ->with('success', "{$this->label} password reset successfully.")
                ->with('new_password', $plainPassword);
        } catch (\Throwable $e) {
            return back()->with('error', "Failed to reset password: {$e->getMessage()}");
        }
    }

    // ===================== SECURITY AUDIT =====================

    /**
     * Show the user's login history, failed attempts, and lockout events
     * pulled from user_audit_log. Includes both events performed BY the
     * user (login_success, login_failed, logout) and events performed ON
     * the user by an admin (account_locked, password_change, master_data_*).
     */
    public function securityAudit(int $id)
    {
        $user = User::withTrashed()->with(['employee.branch'])->findOrFail($id);

        // Pull login-related audit events for this user.
        $securityEvents = DB::table('user_audit_log')
            ->where(function ($q) use ($id) {
                $q->where('user_id', $id)
                  ->orWhere('target_user_id', $id);
            })
            ->whereIn('action', [
                'login_success',
                'login_failed',
                'logout',
                'account_locked',
                'password_change',
                'role_change',
                'user_created',
                'user_updated',
                'user_deleted',
            ])
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        // Summary counts.
        $summary = [
            'logins'         => $securityEvents->where('action', 'login_success')->count(),
            'failed_logins'  => $securityEvents->where('action', 'login_failed')->count(),
            'lockouts'       => $securityEvents->where('action', 'account_locked')->count(),
            'password_changes' => $securityEvents->where('action', 'password_change')->count(),
        ];

        return view("{$this->viewDir}.security", [
            'title'          => "{$user->username} — security audit",
            'item'           => $user,
            'securityEvents' => $securityEvents,
            'summary'        => $summary,
            'routePrefix'    => $this->routePrefix,
            'label'          => $this->label,
        ]);
    }
}
