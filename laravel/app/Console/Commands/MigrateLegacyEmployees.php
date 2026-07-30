<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migrate legacy employees + users from the MySQL archive into PostgreSQL.
 *
 * Usage:
 *   php artisan migrate:legacy-employees           # Dry run (preview only)
 *   php artisan migrate:legacy-employees --execute  # Actually insert data
 *   php artisan migrate:legacy-employees --force    # Skip confirmation prompt
 *
 * Prerequisites:
 *   1. The MySQL archive container must be running:
 *      docker compose --profile archive up -d rcerp_mysql_archive
 *   2. The HR columns migration must have been run:
 *      php artisan migrate
 *   3. The legacy MySQL database must contain `employees` and `users` tables
 *      with the original data.
 *
 * Column mapping (MySQL → PostgreSQL):
 *
 *   employees table:
 *     id               → id (OVERRIDING SYSTEM VALUE, preserves FK refs)
 *     employee_code    → employee_code
 *     name             → name
 *     father_name      → father_name (new HR column)
 *     mother_name      → mother_name (new HR column)
 *     date_of_birth    → date_of_birth (new HR column)
 *     nid              → nid (new HR column)
 *     mobile           → mobile (new HR column)
 *     email            → email
 *     address          → address
 *     branch_id        → branch_id (FK → branches.id must exist)
 *     designation      → designation (new HR column)
 *     role             → role (must match PG CHECK constraint)
 *     joining_date     → joining_date
 *     department       → department (new HR column)
 *     salary           → salary
 *     bank_account     → bank_account (new HR column)
 *     blood_group      → blood_group (new HR column)
 *     photo            → photo
 *     is_active        → is_active (tinyint → boolean)
 *     created_by       → created_by
 *     created_at       → created_at
 *     updated_at       → updated_at
 *     deleted_at       → deleted_at (soft delete)
 *     deleted_by       → deleted_by
 *
 *   users table:
 *     id               → id (OVERRIDING SYSTEM VALUE)
 *     employee_id      → employee_id (FK → employees.id must exist)
 *     username         → username
 *     password_hash    → password_hash (bcrypt — compatible)
 *     is_active        → is_active (tinyint → boolean)
 *     last_login       → last_login
 *     last_login_ip    → last_login_ip
 *     last_login_user_agent → last_login_user_agent (if column exists)
 *     failed_login_count → failed_login_count
 *     locked_until     → locked_until
 *     credential_version → credential_version
 *     created_by       → created_by
 *     created_at       → created_at
 *     updated_at       → updated_at
 *     deleted_at       → deleted_at
 *     deleted_by       → deleted_by
 *
 * Data integrity guarantees:
 *   - Employees are inserted BEFORE users (FK dependency)
 *   - Existing PG rows are NOT overwritten (ON CONFLICT DO NOTHING)
 *   - Sequences are synced after migration (MAX(id) + 1)
 *   - All operations are wrapped in a transaction (--execute mode)
 *   - Branch FK integrity is checked before insert
 */
class MigrateLegacyEmployees extends Command
{
    protected $signature = 'migrate:legacy-employees
                            {--execute : Actually insert data (default: dry-run)}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Migrate employees + users from the MySQL archive into PostgreSQL';

    // Canonical roles from the PG CHECK constraint
    private const VALID_ROLES = [
        'admin', 'salesman', 'warehouse_manager', 'dispatcher',
        'accountant', 'hr', 'manager', 'other', 'superadmin', 'user',
    ];

    private int $employeeCount = 0;
    private int $userCount = 0;
    private int $employeeSkipped = 0;
    private int $userSkipped = 0;
    private int $employeeRoleFixed = 0;
    private int $employeeBranchMissing = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->info('=== Legacy Employee & User Migration ===');
        $this->newLine();

        // Step 1: Check prerequisites
        if (! $this->checkPrerequisites()) {
            return self::FAILURE;
        }

        // Step 2: Connect to MySQL archive
        $mysqlRows = $this->readLegacyData();
        if ($mysqlRows === null) {
            return self::FAILURE;
        }

        if ($mysqlRows['employees']->isEmpty() && $mysqlRows['users']->isEmpty()) {
            $this->warn('No data found in the MySQL archive tables.');
            return self::SUCCESS;
        }

        // Step 3: Preview
        $this->previewData($mysqlRows);

        // Step 4: Confirm
        if (! $this->option('execute')) {
            $this->newLine();
            $this->info('This is a DRY RUN. Use --execute to actually insert data.');
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Proceed with migration? This will insert data into PostgreSQL.', false)) {
            $this->info('Migration cancelled.');
            return self::SUCCESS;
        }

        // Step 5: Execute migration
        $this->executeMigration($mysqlRows);

        // Step 6: Sync sequences
        $this->syncSequences();

        // Step 7: Summary
        $this->printSummary();

        return self::SUCCESS;
    }

    // ──────────────────────────────────────────────────────────────
    // Prerequisites
    // ──────────────────────────────────────────────────────────────

    private function checkPrerequisites(): bool
    {
        $ok = true;

        // Check HR columns exist
        $requiredColumns = ['father_name', 'mother_name', 'date_of_birth', 'nid',
                           'designation', 'department', 'bank_account', 'blood_group', 'mobile'];
        $missing = [];
        foreach ($requiredColumns as $col) {
            if (! Schema::hasColumn('employees', $col)) {
                $missing[] = $col;
            }
        }
        if (! empty($missing)) {
            $this->error('Missing HR columns in employees table: ' . implode(', ', $missing));
            $this->error('Run: php artisan migrate');
            $ok = false;
        }

        // Check MySQL archive connection
        try {
            DB::connection('mysql_archive')->getPdo();
            $this->info('✓ MySQL archive connection OK');
        } catch (\Throwable $e) {
            $this->error('Cannot connect to MySQL archive: ' . $e->getMessage());
            $this->error('Start it: docker compose --profile archive up -d rcerp_mysql_archive');
            $ok = false;
        }

        // Check PG connection
        try {
            DB::connection('pgsql')->getPdo();
            $this->info('✓ PostgreSQL connection OK');
        } catch (\Throwable $e) {
            $this->error('Cannot connect to PostgreSQL: ' . $e->getMessage());
            $ok = false;
        }

        return $ok;
    }

    // ──────────────────────────────────────────────────────────────
    // Read legacy data from MySQL
    // ──────────────────────────────────────────────────────────────

    private function readLegacyData(): ?array
    {
        try {
            $this->info('Reading legacy data from MySQL archive...');

            $employees = DB::connection('mysql_archive')
                ->table('employees')
                ->orderBy('id')
                ->get();

            $users = DB::connection('mysql_archive')
                ->table('users')
                ->orderBy('id')
                ->get();

            $this->info("✓ Found {$employees->count()} employees, {$users->count()} users");

            return ['employees' => $employees, 'users' => $users];
        } catch (\Throwable $e) {
            $this->error('Failed to read legacy data: ' . $e->getMessage());
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────────
    // Preview data
    // ──────────────────────────────────────────────────────────────

    private function previewData(array $data): void
    {
        $this->newLine();
        $this->info('--- Employee Preview ---');

        $employees = $data['employees'];
        $existingEmployeeIds = DB::table('employees')->pluck('id')->toArray();
        $existingEmployeeCodes = DB::table('employees')->pluck('employee_code')->toArray();
        $pgBranchIds = DB::table('branches')->pluck('id')->toArray();

        $newCount = 0;
        $duplicateCount = 0;

        foreach ($employees as $emp) {
            if (in_array($emp->id, $existingEmployeeIds) || in_array($emp->employee_code, $existingEmployeeCodes)) {
                $duplicateCount++;
            } else {
                $newCount++;
            }

            // Check role validity
            if (! in_array($emp->role, self::VALID_ROLES)) {
                $this->warn("  ⚠ Employee #{$emp->id} ({$emp->employee_code}): invalid role '{$emp->role}' → will be set to 'other'");
            }

            // Check branch FK
            if (! in_array($emp->branch_id, $pgBranchIds)) {
                $this->warn("  ⚠ Employee #{$emp->id} ({$emp->employee_code}): branch_id={$emp->branch_id} does not exist in PG → will be skipped");
            }
        }

        $this->line("  New employees to insert: {$newCount}");
        $this->line("  Already existing (will skip): {$duplicateCount}");

        $this->newLine();
        $this->info('--- User Preview ---');

        $users = $data['users'];
        $existingUserIds = DB::table('users')->pluck('id')->toArray();
        $existingUsernames = DB::table('users')->pluck('username')->toArray();

        $newUserCount = 0;
        $duplicateUserCount = 0;

        foreach ($users as $user) {
            if (in_array($user->id, $existingUserIds) || in_array($user->username, $existingUsernames)) {
                $duplicateUserCount++;
            } else {
                $newUserCount++;
            }
        }

        $this->line("  New users to insert: {$newUserCount}");
        $this->line("  Already existing (will skip): {$duplicateUserCount}");
    }

    // ──────────────────────────────────────────────────────────────
    // Execute migration
    // ──────────────────────────────────────────────────────────────

    private function executeMigration(array $data): void
    {
        $this->newLine();
        $this->info('Executing migration...');

        $pgBranchIds = DB::table('branches')->pluck('id')->toArray();
        $existingEmployeeIds = DB::table('employees')->pluck('id')->toArray();
        $existingEmployeeCodes = DB::table('employees')->pluck('employee_code')->toArray();

        DB::transaction(function () use ($data, $pgBranchIds, $existingEmployeeIds, $existingEmployeeCodes) {
            // ── Insert employees ──
            $bar = $this->output->createProgressBar(count($data['employees']));
            $bar->start();

            foreach ($data['employees'] as $emp) {
                // Skip if already exists
                if (in_array($emp->id, $existingEmployeeIds) || in_array($emp->employee_code, $existingEmployeeCodes)) {
                    $this->employeeSkipped++;
                    $bar->advance();
                    continue;
                }

                // Skip if branch_id doesn't exist in PG
                if (! in_array($emp->branch_id, $pgBranchIds)) {
                    $this->employeeBranchMissing++;
                    $this->warn("  Skipping employee #{$emp->id} ({$emp->employee_code}): branch_id={$emp->branch_id} not found");
                    $bar->advance();
                    continue;
                }

                // Fix invalid role
                $role = $emp->role;
                if (! in_array($role, self::VALID_ROLES)) {
                    $role = 'other';
                    $this->employeeRoleFixed++;
                }

                // Map MySQL row → PG row
                $row = [
                    'id'               => (int) $emp->id,
                    'employee_code'    => trim($emp->employee_code),
                    'name'             => trim($emp->name),
                    'father_name'      => $this->nullableString($emp->father_name ?? null),
                    'mother_name'      => $this->nullableString($emp->mother_name ?? null),
                    'date_of_birth'    => $this->nullableDate($emp->date_of_birth ?? null),
                    'nid'              => $this->nullableString($emp->nid ?? null),
                    'role'             => $role,
                    'branch_id'        => (int) $emp->branch_id,
                    'phone'            => $this->nullableString($emp->phone ?? null),
                    'mobile'           => $this->nullableString($emp->mobile ?? null),
                    'email'            => $this->nullableString($emp->email ?? null),
                    'photo'            => $this->nullableString($emp->photo ?? null),
                    'address'          => $this->nullableString($emp->address ?? null),
                    'designation'      => $this->nullableString($emp->designation ?? null),
                    'department'       => $this->nullableString($emp->department ?? null),
                    'salary'           => $emp->salary ?? 0,
                    'bank_account'     => $this->nullableString($emp->bank_account ?? null),
                    'blood_group'      => $this->nullableString($emp->blood_group ?? null),
                    'joining_date'     => $this->nullableDate($emp->joining_date ?? null),
                    'is_active'        => $this->mysqlBoolToPg($emp->is_active ?? 1),
                    'created_by'       => $this->nullableInt($emp->created_by ?? null),
                    'created_at'       => $this->nullableTimestamp($emp->created_at ?? null),
                    'updated_at'       => $this->nullableTimestamp($emp->updated_at ?? null),
                    'deleted_at'       => $this->nullableTimestamp($emp->deleted_at ?? null),
                    'deleted_by'       => $this->nullableInt($emp->deleted_by ?? null),
                ];

                DB::statement(
                    'INSERT INTO employees (id, employee_code, name, father_name, mother_name,
                        date_of_birth, nid, role, branch_id, phone, mobile, email, photo, address,
                        designation, department, salary, bank_account, blood_group, joining_date,
                        is_active, created_by, created_at, updated_at, deleted_at, deleted_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT (id) DO NOTHING
                     ON CONFLICT (employee_code) DO NOTHING',
                    array_values($row)
                );

                $this->employeeCount++;
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            // ── Insert users ──
            $existingUserIds = DB::table('users')->pluck('id')->toArray();
            $existingUsernames = DB::table('users')->pluck('username')->toArray();
            $pgEmployeeIds = DB::table('employees')->pluck('id')->toArray();

            $bar = $this->output->createProgressBar(count($data['users']));
            $bar->start();

            foreach ($data['users'] as $user) {
                // Skip if already exists
                if (in_array($user->id, $existingUserIds) || in_array($user->username, $existingUsernames)) {
                    $this->userSkipped++;
                    $bar->advance();
                    continue;
                }

                // Skip if employee_id doesn't exist in PG
                if (! in_array($user->employee_id, $pgEmployeeIds)) {
                    $this->warn("  Skipping user #{$user->id} ({$user->username}): employee_id={$user->employee_id} not found");
                    $bar->advance();
                    continue;
                }

                $row = [
                    'id'                  => (int) $user->id,
                    'employee_id'         => (int) $user->employee_id,
                    'username'            => trim($user->username),
                    'password_hash'       => $user->password_hash,
                    'is_active'           => $this->mysqlBoolToPg($user->is_active ?? 1),
                    'last_login'          => $this->nullableTimestamp($user->last_login ?? null),
                    'last_login_ip'       => $this->nullableString($user->last_login_ip ?? null),
                    'failed_login_count'  => (int) ($user->failed_login_count ?? 0),
                    'locked_until'        => $this->nullableTimestamp($user->locked_until ?? null),
                    'credential_version'  => (int) ($user->credential_version ?? 1),
                    'created_by'          => $this->nullableInt($user->created_by ?? null),
                    'created_at'          => $this->nullableTimestamp($user->created_at ?? null),
                    'updated_at'          => $this->nullableTimestamp($user->updated_at ?? null),
                    'deleted_at'          => $this->nullableTimestamp($user->deleted_at ?? null),
                    'deleted_by'          => $this->nullableInt($user->deleted_by ?? null),
                ];

                // Add last_login_user_agent if the column exists
                if (Schema::hasColumn('users', 'last_login_user_agent')) {
                    $row['last_login_user_agent'] = $this->nullableString($user->last_login_user_agent ?? null);
                }

                // Build dynamic INSERT
                $columns = implode(', ', array_keys($row));
                $placeholders = implode(', ', array_fill(0, count($row), '?'));
                $params = array_values($row);

                DB::statement(
                    "INSERT INTO users ({$columns}) VALUES ({$placeholders})
                     ON CONFLICT (id) DO NOTHING
                     ON CONFLICT (username) DO NOTHING",
                    $params
                );

                $this->userCount++;
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
        });
    }

    // ──────────────────────────────────────────────────────────────
    // Sync PG sequences
    // ──────────────────────────────────────────────────────────────

    private function syncSequences(): void
    {
        $this->info('Syncing PostgreSQL sequences...');

        $tables = [
            'employees' => 'id',
            'users'     => 'id',
        ];

        foreach ($tables as $table => $column) {
            $maxId = DB::table($table)->max($column) ?? 0;
            if ($maxId > 0) {
                $seqName = pg_get_serial_sequence($table, $column);
                if ($seqName) {
                    DB::statement("SELECT setval('{$seqName}', {$maxId})");
                    $this->line("  ✓ {$table}.{$column} sequence set to {$maxId}");
                }
            }
        }
    }

    // ──────────────────────────────────────────────────────────────
    // Summary
    // ──────────────────────────────────────────────────────────────

    private function printSummary(): void
    {
        $this->newLine();
        $this->info('=== Migration Summary ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Employees inserted', $this->employeeCount],
                ['Employees skipped (existing)', $this->employeeSkipped],
                ['Employees skipped (missing branch)', $this->employeeBranchMissing],
                ['Employees with role fixed', $this->employeeRoleFixed],
                ['Users inserted', $this->userCount],
                ['Users skipped (existing)', $this->userSkipped],
            ]
        );
        $this->newLine();
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function nullableString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return trim($value);
    }

    private function nullableDate(?string $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00') {
            return null;
        }
        return $value;
    }

    private function nullableTimestamp(?string $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        return $value;
    }

    private function nullableInt(?string $value): ?int
    {
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }
        return (int) $value;
    }

    private function mysqlBoolToPg($value): bool
    {
        // MySQL stores booleans as tinyint(1): 0 or 1
        return (bool) (int) $value;
    }
}
