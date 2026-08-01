<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Migrate legacy admin + setup_employee data from the phpMyAdmin SQL dump
 * into the Laravel PostgreSQL `employees` + `users` tables.
 *
 * SCOPE (simplified per business request):
 *   setup_employee → employees : id, name (only)
 *   admin          → users     : employee_id, username, password (only)
 *   Everything else is auto-generated (employee_code, role, branch_id, etc.)
 *
 * SCHEMA NOTES (must match /database/sql/01_auth_and_master.sql):
 *   branches   : id, branch_code (NOT NULL UNIQUE), branch_name (NOT NULL), is_active
 *   employees  : id, employee_code (NOT NULL UNIQUE), name (NOT NULL),
 *                role (NOT NULL — 'user' allowed per 2025_01_12_000001),
 *                branch_id (NOT NULL FK→branches)
 *   users      : id, employee_id (NOT NULL UNIQUE FK→employees),
 *                username (NOT NULL UNIQUE), password_hash (NOT NULL), is_active
 *
 * NOTE: the PG `users` table has NO `hr_status` column — it only exists in
 * the legacy MySQL `admin` table. We deliberately skip hr_status.
 *
 * NOTE: the PG `users` table column is `password_hash`, NOT `password`.
 *
 * TRANSACTIONAL = false:
 *   Laravel wraps each migration in a single DB transaction by default.
 *   For pgsql, once ANY statement fails inside that transaction, every
 *   subsequent statement raises SQLSTATE[25P02] "current transaction is
 *   aborted, commands ignored until end of transaction block". That makes
 *   error recovery impossible mid-migration. Since every statement below
 *   is idempotent (IF NOT EXISTS / ON CONFLICT / OVERRIDING SYSTEM VALUE),
 *   we run without a wrapping transaction so one bad row doesn't poison
 *   the rest of the import.
 */
return new class extends Migration
{
    public $transactional = false;

    private const DEFAULT_BRANCH_ID = 1;
    private const DEFAULT_BRANCH_CODE = 'BR-001';
    private const DEFAULT_BRANCH_NAME = 'Head Office (auto)';

    public function up(): void
    {
        $sqlPath = $this->findSqlDump();
        if ($sqlPath === null) {
            throw new \RuntimeException(
                "Cannot find admin_employee.sql. Looked in:\n"
                . "  database/sql/admin_employee.sql\n"
                . "  database/legacy/admin_employee.sql\n"
                . "  legacy/admin_employee.sql (relative to Laravel base)\n"
                . "  ../legacy/admin_employee.sql (Docker: /var/www/legacy/)\n"
                . "  /var/www/legacy/admin_employee.sql (Docker absolute)\n"
                . "\nFix: copy admin_employee.sql into one of these locations,\n"
                . "or mount the legacy/ directory into the container."
            );
        }

        echo "\n┌────────────────────────────────────────────────────────────┐\n";
        echo "│  Legacy Admin + Employee Migration                         │\n";
        echo "└────────────────────────────────────────────────────────────┘\n";
        echo "  SQL dump: {$sqlPath}\n\n";

        // ── Step 1/4: Parse the SQL dump ──
        echo "[1/4] CHECK — parsing SQL dump...\n";
        $sql = File::get($sqlPath);

        $setupEmployeeRows = $this->parseInsertTuples($sql, 'setup_employee');
        $adminRows         = $this->parseInsertTuples($sql, 'admin');

        // Pull only id + name from setup_employee
        $employees = [];
        foreach ($setupEmployeeRows as $row) {
            $id   = $row['id']   ?? null;
            $name = $row['name'] ?? null;
            if ($id !== null && $name !== null && $name !== '') {
                $employees[(int) $id] = [
                    'id'   => (int) $id,
                    'name' => trim($name),
                ];
            }
        }

        // Pull only employee_id + username + password from admin.
        // Deduplicate by employee_id — keep the row with the HIGHEST admin.id
        // (most recent login record) so we don't trip users_employee_id_unique.
        $usersByEmployee = [];
        $nullEmpCount = 0;
        foreach ($adminRows as $row) {
            $empId    = $row['employee_id'] ?? null;
            $username = $row['username']    ?? null;
            $password = $row['password']    ?? null;
            $adminId  = $row['id']          ?? null;
            if ($empId === null) {
                $nullEmpCount++;
                continue;
            }
            if ($username === null || $password === null) {
                continue;
            }
            $empId = (int) $empId;
            if (!isset($usersByEmployee[$empId])
                || (int) $adminId > (int) $usersByEmployee[$empId]['admin_id']
            ) {
                $usersByEmployee[$empId] = [
                    'admin_id'    => (int) $adminId,
                    'employee_id' => $empId,
                    'username'    => trim($username),
                    'password'    => $password,
                ];
            }
        }

        echo "      • setup_employee rows parsed : " . count($employees) . "\n";
        echo "      • admin rows parsed          : " . count($adminRows) . "\n";
        echo "      • admin rows (deduped)       : " . count($usersByEmployee) . "\n";
        if ($nullEmpCount > 0) {
            echo "      • admin rows w/o employee_id : {$nullEmpCount} (skipped)\n";
        }
        echo "\n";

        // ── Step 2/4: Ensure default branch exists ──
        echo "[2/4] BRANCH — ensuring default branch id=" . self::DEFAULT_BRANCH_ID . "...\n";
        $this->ensureDefaultBranchExists();
        echo "\n";

        // ── Step 3/4: Upsert employees ──
        echo "[3/4] INSERT — upserting employees...\n";
        $this->upsertEmployees($employees);
        echo "\n";

        // ── Step 4/4: Upsert users ──
        echo "[4/4] INSERT — upserting users...\n";
        $this->upsertUsers($usersByEmployee);
        echo "\n  ✓ Migration complete.\n\n";
    }

    /**
     * Find the legacy SQL dump. Looks in several candidate paths relative
     * to the Laravel base directory so the migration works regardless of
     * whether the user put the file under database/sql/ or legacy/.
     *
     * Docker note: the docker-compose.yml mounts the host ./legacy directory
     * at /var/www/legacy (NOT /var/www/laravel/legacy), and the Laravel
     * base_path() is /var/www/laravel — so we must also look one level
     * UP from base_path() to find /var/www/legacy/admin_employee.sql.
     */
    private function findSqlDump(): ?string
    {
        $candidates = [
            database_path('sql/admin_employee.sql'),
            database_path('legacy/admin_employee.sql'),
            base_path('legacy/admin_employee.sql'),
            base_path('database/migrations/admin_employee.sql'),
            // Docker: /var/www/legacy/admin_employee.sql
            dirname(base_path()) . '/legacy/admin_employee.sql',
            // Docker fallback (absolute, in case base_path resolves oddly)
            '/var/www/legacy/admin_employee.sql',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Parse all INSERT INTO `<table>` ... VALUES (...),(...),...; tuples
     * from a phpMyAdmin-style SQL dump. Returns an array of associative
     * arrays keyed by the column list from the INSERT statement.
     *
     * phpMyAdmin format:
     *   INSERT INTO `setup_employee` (`id`, `code`, `name`, ...) VALUES
     *   (1, '0001', 'Mahbubur Rahman Linkon', ...),
     *   (2, '0002', 'Sohidul Islam Sumon', ...);
     */
    private function parseInsertTuples(string $sql, string $table): array
    {
        $rows = [];

        $pattern = '/'
            . 'INSERT\s+INTO\s+`' . preg_quote($table, '/') . '`\s*'
            . '\(([^)]+)\)\s*'
            . 'VALUES\s*'
            . '(.*?);'
            . '/is';

        if (!preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $m) {
            $columnList = $m[1];
            $columns = [];
            foreach (explode(',', $columnList) as $col) {
                $col = trim($col);
                if (preg_match('/^`([^`]+)`$/', $col, $cm)) {
                    $columns[] = $cm[1];
                } else {
                    $columns[] = trim($col, "'\"");
                }
            }

            $tuplesStr = $m[2];
            $tuples    = $this->splitTuples($tuplesStr);

            foreach ($tuples as $tuple) {
                $values = $this->parseTupleValues($tuple);
                if (count($values) !== count($columns)) {
                    // Skip malformed rows (column/value count mismatch)
                    continue;
                }
                $rows[] = array_combine($columns, $values);
            }
        }

        return $rows;
    }

    /**
     * Split a "VALUES (...),(...),(...)" string into individual tuple
     * bodies (each without the surrounding parens). Handles parentheses
     * and quoted strings inside the tuples.
     */
    private function splitTuples(string $tuplesStr): array
    {
        $tuples = [];
        $depth = 0;
        $inString = false;
        $stringChar = null;
        $escaped = false;
        $buf = '';

        for ($i = 0, $n = strlen($tuplesStr); $i < $n; $i++) {
            $ch = $tuplesStr[$i];

            if ($inString) {
                $buf .= $ch;
                if ($escaped) {
                    $escaped = false;
                } elseif ($ch === '\\') {
                    $escaped = true;
                } elseif ($ch === $stringChar) {
                    $inString = false;
                    $stringChar = null;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString = true;
                $stringChar = $ch;
                $buf .= $ch;
            } elseif ($ch === '(') {
                $depth++;
                $buf = $depth === 1 ? '' : $buf . $ch;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $tuples[] = $buf;
                    $buf = '';
                } else {
                    $buf .= $ch;
                }
            } elseif ($depth > 0) {
                $buf .= $ch;
            }
        }

        return $tuples;
    }

    /**
     * Parse a single tuple body (without surrounding parens) into an
     * array of PHP values. NULL → null, numeric → int/float, quoted → string.
     */
    private function parseTupleValues(string $tuple): array
    {
        $values = [];
        $i = 0;
        $n = strlen($tuple);

        while ($i < $n) {
            // Skip whitespace + commas
            while ($i < $n && (ctype_space($tuple[$i]) || $tuple[$i] === ',')) {
                $i++;
            }
            if ($i >= $n) {
                break;
            }

            $ch = $tuple[$i];

            if ($ch === "'" || $ch === '"') {
                // Quoted string — handle backslash + doubled-quote escapes
                $quote = $ch;
                $i++;
                $buf = '';
                while ($i < $n) {
                    $c = $tuple[$i];
                    if ($c === '\\' && $i + 1 < $n) {
                        $next = $tuple[$i + 1];
                        $map = [
                            'n'  => "\n",
                            'r'  => "\r",
                            't'  => "\t",
                            '\\' => '\\',
                            "'"  => "'",
                            '"'  => '"',
                            '0'  => "\0",
                        ];
                        $buf .= $map[$next] ?? $next;
                        $i += 2;
                    } elseif ($c === $quote && $i + 1 < $n && $tuple[$i + 1] === $quote) {
                        // Doubled-quote escape: '' → '
                        $buf .= $quote;
                        $i += 2;
                    } elseif ($c === $quote) {
                        $i++;
                        break;
                    } else {
                        $buf .= $c;
                        $i++;
                    }
                }
                $values[] = $buf;
            } else {
                // Unquoted token: read until comma or end
                $start = $i;
                while ($i < $n && $tuple[$i] !== ',') {
                    $i++;
                }
                $token = trim(substr($tuple, $start, $i - $start));
                if (strcasecmp($token, 'NULL') === 0) {
                    $values[] = null;
                } elseif (is_numeric($token)) {
                    $values[] = strpos($token, '.') !== false
                        ? (float) $token
                        : (int) $token;
                } else {
                    $values[] = $token;
                }
            }
        }

        return $values;
    }

    /**
     * Make sure the default branch (id=1) exists. The branches table
     * requires branch_code (NOT NULL UNIQUE) + branch_name (NOT NULL).
     *
     * Uses OVERRIDING SYSTEM VALUE because `id` is GENERATED ALWAYS AS IDENTITY.
     */
    private function ensureDefaultBranchExists(): void
    {
        $exists = DB::selectOne(
            "SELECT id FROM branches WHERE id = ?",
            [self::DEFAULT_BRANCH_ID]
        );

        if ($exists) {
            echo "      • branch id=" . self::DEFAULT_BRANCH_ID . " already exists — skipping\n";
            return;
        }

        // Use a raw statement so we can pass OVERRIDING SYSTEM VALUE.
        DB::statement(
            "INSERT INTO branches (id, branch_code, branch_name, is_active)
             OVERRIDING SYSTEM VALUE
             VALUES (?, ?, ?, true)
             ON CONFLICT (id) DO NOTHING",
            [
                self::DEFAULT_BRANCH_ID,
                self::DEFAULT_BRANCH_CODE,
                self::DEFAULT_BRANCH_NAME,
            ]
        );

        // Bump the sequence so the next natural insert doesn't collide.
        DB::statement(
            "SELECT setval('branches_id_seq', GREATEST((SELECT MAX(id) FROM branches), 1), true)"
        );

        echo "      • auto-created branch id=" . self::DEFAULT_BRANCH_ID
           . " (branch_code='" . self::DEFAULT_BRANCH_CODE
           . "', branch_name='" . self::DEFAULT_BRANCH_NAME . "')\n";
    }

    /**
     * Upsert employees. Uses OVERRIDING SYSTEM VALUE because `id` is
     * GENERATED ALWAYS AS IDENTITY. ON CONFLICT (id) DO UPDATE makes it
     * safe to re-run.
     */
    private function upsertEmployees(array $employees): void
    {
        if (empty($employees)) {
            echo "      • no employee rows to insert\n";
            return;
        }

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ($employees as $emp) {
            $employeeCode = 'EMP-' . str_pad((string) $emp['id'], 4, '0', STR_PAD_LEFT);

            // Check existing row to differentiate INSERT vs UPDATE for reporting.
            $existing = DB::selectOne(
                "SELECT id FROM employees WHERE id = ?",
                [$emp['id']]
            );

            try {
                DB::statement(
                    "INSERT INTO employees (id, employee_code, name, role, branch_id, is_active)
                     OVERRIDING SYSTEM VALUE
                     VALUES (?, ?, ?, 'user', ?, true)
                     ON CONFLICT (id) DO UPDATE
                     SET name = EXCLUDED.name,
                         employee_code = EXCLUDED.employee_code,
                         branch_id = EXCLUDED.branch_id",
                    [
                        $emp['id'],
                        $employeeCode,
                        $emp['name'],
                        self::DEFAULT_BRANCH_ID,
                    ]
                );

                if ($existing) {
                    $updated++;
                } else {
                    $inserted++;
                }
            } catch (\Throwable $e) {
                $skipped++;
                echo "      ! skipped employee id={$emp['id']} ({$emp['name']}): "
                   . $e->getMessage() . "\n";
            }
        }

        // Bump the sequence so future inserts don't collide.
        DB::statement(
            "SELECT setval('employees_id_seq', GREATEST((SELECT MAX(id) FROM employees), 1), true)"
        );

        echo "      • inserted : {$inserted}\n";
        echo "      • updated  : {$updated}\n";
        if ($skipped > 0) {
            echo "      • skipped  : {$skipped}\n";
        }
    }

    /**
     * Upsert users. The PG column is `password_hash` (NOT `password`).
     * Uses ON CONFLICT (employee_id) because legacy has duplicate usernames
     * across different employee_ids (the unique constraint on employee_id
     * is the more reliable key for upsert).
     */
    private function upsertUsers(array $usersByEmployee): void
    {
        if (empty($usersByEmployee)) {
            echo "      • no user rows to insert\n";
            return;
        }

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ($usersByEmployee as $u) {
            // Verify the employee exists — users.employee_id has a FK constraint.
            $empExists = DB::selectOne(
                "SELECT id FROM employees WHERE id = ?",
                [$u['employee_id']]
            );
            if (!$empExists) {
                $skipped++;
                echo "      ! skipped user '{$u['username']}' — employee_id={$u['employee_id']} not in employees table\n";
                continue;
            }

            $existing = DB::selectOne(
                "SELECT id FROM users WHERE employee_id = ?",
                [$u['employee_id']]
            );

            try {
                DB::statement(
                    "INSERT INTO users (employee_id, username, password_hash, is_active)
                     VALUES (?, ?, ?, true)
                     ON CONFLICT (employee_id) DO UPDATE
                     SET username = EXCLUDED.username,
                         password_hash = EXCLUDED.password_hash",
                    [
                        $u['employee_id'],
                        $u['username'],
                        $u['password'],
                    ]
                );

                if ($existing) {
                    $updated++;
                } else {
                    $inserted++;
                }
            } catch (\Throwable $e) {
                $skipped++;
                echo "      ! skipped user '{$u['username']}': " . $e->getMessage() . "\n";
            }
        }

        // Bump the sequence.
        DB::statement(
            "SELECT setval('users_id_seq', GREATEST((SELECT COALESCE(MAX(id), 1) FROM users), 1), true)"
        );

        echo "      • inserted : {$inserted}\n";
        echo "      • updated  : {$updated}\n";
        if ($skipped > 0) {
            echo "      • skipped  : {$skipped}\n";
        }
    }

    public function down(): void
    {
        // Don't auto-delete legacy-imported data on rollback — the user
        // should decide what to keep. Print guidance instead.
        echo "  [skip] Legacy data migration has no automatic down().\n";
        echo "  To remove imported employees/users, run:\n";
        echo "    DELETE FROM users WHERE employee_id IN "
           . "(SELECT id FROM employees WHERE employee_code LIKE 'EMP-%');\n";
        echo "    DELETE FROM employees WHERE employee_code LIKE 'EMP-%';\n";
    }
};
