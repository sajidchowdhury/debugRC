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
 *   php artisan migrate:legacy-employees --execute  # Actually insert/update data
 *   php artisan migrate:legacy-employees --force    # Skip confirmation prompt
 *
 * Prerequisites:
 *   1. The MySQL archive container must be running and loaded with the legacy
 *      dump: docker compose --profile archive up -d rcerp_mysql_archive
 *   2. Load the legacy SQL dump into the archive:
 *      Get-Content legacy/osudlagb_remotecenter.sql | docker exec -i rcerp_mysql_archive mysql -u root -parchive_root_secret rcerp_legacy
 *   3. The HR columns migration must have been run: php artisan migrate
 *
 * Strategy:
 *   - Employees that already exist in PG (by id or employee_code):
 *     → UPDATE the HR columns (father_name, mother_name, date_of_birth, nid,
 *       mobile, designation, department, bank_account, blood_group) that are
 *       currently NULL because they were dropped during Phase 2 ETL.
 *   - Employees that don't exist in PG:
 *     → INSERT them (preserving the original id for FK integrity).
 *   - Users that already exist in PG (by id or username):
 *     → UPDATE password_hash and other fields from legacy.
 *   - Users that don't exist in PG:
 *     → INSERT them.
 *   - All operations are wrapped in a transaction.
 *   - Sequences are synced after migration.
 */
class MigrateLegacyEmployees extends Command
{
    protected $signature = 'migrate:legacy-employees
                            {--execute : Actually insert/update data (default: dry-run)}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Migrate employees + users from the MySQL archive into PostgreSQL (upsert)';

    // Canonical roles from the PG CHECK constraint
    private const VALID_ROLES = [
        'admin', 'salesman', 'warehouse_manager', 'dispatcher',
        'accountant', 'hr', 'manager', 'other', 'superadmin', 'user',
    ];

    // HR columns that were dropped during Phase 2 ETL and re-added
    private const HR_COLUMNS = [
        'father_name', 'mother_name', 'date_of_birth', 'nid',
        'designation', 'department', 'bank_account', 'blood_group', 'mobile',
    ];

    private int $employeeInserted = 0;
    private int $employeeUpdated = 0;
    private int $employeeSkipped = 0;
    private int $userInserted = 0;
    private int $userUpdated = 0;
    private int $userSkipped = 0;
    private int $employeeRoleFixed = 0;
    private int $employeeBranchMissing = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->info('=== Legacy Employee & User Migration (Upsert) ===');
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
            $this->info('This is a DRY RUN. Use --execute to actually insert/update data.');
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Proceed with migration? This will insert/update data in PostgreSQL.', false)) {
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
        $missing = [];
        foreach (self::HR_COLUMNS as $col) {
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
            $this->error('');
            $this->error('The MySQL archive may not have the legacy tables loaded.');
            $this->error('Load the dump with:');
            $this->error('  Get-Content legacy/osudlagb_remotecenter.sql | docker exec -i rcerp_mysql_archive mysql -u root -parchive_root_secret rcerp_legacy');
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
        $existingById = DB::table('employees')->pluck('id')->toArray();
        $existingByCode = DB::table('employees')->pluck('employee_code')->toArray();
        $pgBranchIds = DB::table('branches')->pluck('id')->toArray();

        $newCount = 0;
        $updateCount = 0;

        foreach ($employees as $emp) {
            $exists = in_array($emp->id, $existingById) || in_array($emp->employee_code, $existingByCode);
            if ($exists) {
                $updateCount++;
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

        $this->line("  New employees to insert:  {$newCount}");
        $this->line("  Existing employees to update HR columns:  {$updateCount}");

        $this->newLine();
        $this->info('--- User Preview ---');

        $users = $data['users'];
        $existingUserIds = DB::table('users')->pluck('id')->toArray();
        $existingUsernames = DB::table('users')->pluck('username')->toArray();

        $newUserCount = 0;
        $updateUserCount = 0;

        foreach ($users as $user) {
            $exists = in_array($user->id, $existingUserIds) || in_array($user->username, $existingUsernames);
            if ($exists) {
                $updateUserCount++;
            } else {
                $newUserCount++;
            }
        }

        $this->line("  New users to insert:  {$newUserCount}");
        $this->line("  Existing users to update:  {$updateUserCount}");
    }

    // ──────────────────────────────────────────────────────────────
    // Execute migration
    // ──────────────────────────────────────────────────────────────

    private function executeMigration(array $data): void
    {
        $this->newLine();
        $this->info('Executing migration...');

        $pgBranchIds = DB::table('branches')->pluck('id')->toArray();

        DB::transaction(function () use ($data, $pgBranchIds) {
            // ── Upsert employees ──
            $bar = $this->output->createProgressBar(count($data['employees']));
            $bar->start();

            foreach ($data['employees'] as $emp) {
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

                // Check if employee already exists
                $existingById = DB::table('employees')->where('id', $emp->id)->first();
                $existingByCode = ! $existingById
                    ? DB::table('employees')->where('employee_code', trim($emp->employee_code))->first()
                    : null;
                $existing = $existingById ?? $existingByCode;

                if ($existing) {
                    // UPDATE: only update HR columns that are currently NULL
                    $hrUpdate = [];
                    foreach (self::HR_COLUMNS as $col) {
                        $legacyValue = $this->mapLegacyColumn($emp, $col);
                        // Only update if the PG column is NULL and legacy has a value
                        if (($existing->$col === null || $existing->$col === '') && $legacyValue !== null) {
                            $hrUpdate[$col] = $legacyValue;
                        }
                    }

                    if (! empty($hrUpdate)) {
                        DB::table('employees')->where('id', $existing->id)->update($hrUpdate);
                        $this->employeeUpdated++;
                    } else {
                        $this->employeeSkipped++;
                    }
                } else {
                    // INSERT: new employee with OVERRIDING SYSTEM VALUE
                    $row = $this->mapEmployeeRow($emp, $role);

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

                    $this->employeeInserted++;
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            // ── Upsert users ──
            $pgEmployeeIds = DB::table('employees')->pluck('id')->toArray();

            $bar = $this->output->createProgressBar(count($data['users']));
            $bar->start();

            foreach ($data['users'] as $user) {
                // Skip if employee_id doesn't exist in PG
                if (! in_array($user->employee_id, $pgEmployeeIds)) {
                    $this->warn("  Skipping user #{$user->id} ({$user->username}): employee_id={$user->employee_id} not found");
                    $bar->advance();
                    continue;
                }

                // Check if user already exists
                $existingById = DB::table('users')->where('id', $user->id)->first();
                $existingByUsername = ! $existingById
                    ? DB::table('users')->where('username', trim($user->username))->first()
                    : null;
                $existing = $existingById ?? $existingByUsername;

                if ($existing) {
                    // UPDATE: update password_hash and other fields from legacy
                    $userUpdate = [];
                    if ($user->password_hash && $user->password_hash !== $existing->password_hash) {
                        $userUpdate['password_hash'] = $user->password_hash;
                    }
                    if ($user->last_login && $user->last_login !== $existing->last_login) {
                        $userUpdate['last_login'] = $this->nullableTimestamp($user->last_login);
                    }
                    if ($user->last_login_ip && $user->last_login_ip !== $existing->last_login_ip) {
                        $userUpdate['last_login_ip'] = $this->nullableString($user->last_login_ip);
                    }
                    if (isset($user->last_login_user_agent) && Schema::hasColumn('users', 'last_login_user_agent')) {
                        $userUpdate['last_login_user_agent'] = $this->nullableString($user->last_login_user_agent);
                    }
                    if (isset($user->credential_version) && (int) $user->credential_version !== $existing->credential_version) {
                        $userUpdate['credential_version'] = (int) $user->credential_version;
                    }

                    if (! empty($userUpdate)) {
                        DB::table('users')->where('id', $existing->id)->update($userUpdate);
                        $this->userUpdated++;
                    } else {
                        $this->userSkipped++;
                    }
                } else {
                    // INSERT: new user
                    $row = $this->mapUserRow($user);

                    $columns = implode(', ', array_keys($row));
                    $placeholders = implode(', ', array_fill(0, count($row), '?'));
                    $params = array_values($row);

                    DB::statement(
                        "INSERT INTO users ({$columns}) VALUES ({$placeholders})
                         ON CONFLICT (id) DO NOTHING
                         ON CONFLICT (username) DO NOTHING",
                        $params
                    );

                    $this->userInserted++;
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
        });
    }

    // ──────────────────────────────────────────────────────────────
    // Row mappers
    // ──────────────────────────────────────────────────────────────

    private function mapEmployeeRow(object $emp, string $role): array
    {
        return [
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
    }

    private function mapLegacyColumn(object $emp, string $column): ?string
    {
        return match ($column) {
            'father_name'   => $this->nullableString($emp->father_name ?? null),
            'mother_name'   => $this->nullableString($emp->mother_name ?? null),
            'date_of_birth' => $this->nullableDate($emp->date_of_birth ?? null),
            'nid'           => $this->nullableString($emp->nid ?? null),
            'designation'   => $this->nullableString($emp->designation ?? null),
            'department'    => $this->nullableString($emp->department ?? null),
            'bank_account'  => $this->nullableString($emp->bank_account ?? null),
            'blood_group'   => $this->nullableString($emp->blood_group ?? null),
            'mobile'        => $this->nullableString($emp->mobile ?? null),
            default         => null,
        };
    }

    private function mapUserRow(object $user): array
    {
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

        return $row;
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
                ['Employees inserted (new)', $this->employeeInserted],
                ['Employees updated (HR columns)', $this->employeeUpdated],
                ['Employees skipped (no HR update needed)', $this->employeeSkipped],
                ['Employees skipped (missing branch)', $this->employeeBranchMissing],
                ['Employees with role fixed', $this->employeeRoleFixed],
                ['Users inserted (new)', $this->userInserted],
                ['Users updated (password/fields)', $this->userUpdated],
                ['Users skipped (no update needed)', $this->userSkipped],
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
