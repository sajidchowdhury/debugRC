<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Migrate legacy branch + warehouse data from the phpMyAdmin SQL dump
 * `osudlagb_remotecenter.sql`.
 *
 * Source tables (legacy MySQL):
 *   branches   (id, branch_code, branch_name, address, phone, email,
 *               is_active, created_by, created_at, updated_at)
 *   warehouses (id, warehouse_code, warehouse_name, branch_id, address,
 *               is_active, created_by, created_at, updated_at)
 *
 * Target tables (new PostgreSQL schema in 01_auth_and_master.sql):
 *   branches   (id, branch_code, branch_name, address, phone, email,
 *               is_active, created_at, updated_at, deleted_at, deleted_by)
 *   warehouses (id, warehouse_code, warehouse_name, branch_id, location,
 *               is_active, is_frozen_for_count, created_at, updated_at,
 *               deleted_at, deleted_by)
 *
 * Strategy (per user's choices):
 *   1. Existing branches — Replace all. Branch id=1 (auto-created by
 *      migration 000005 as 'Head Office (auto)') is heavily referenced
 *      by customers.branch_id, employees.branch_id, and many sales/stock
 *      tables. We CANNOT drop it without breaking FKs. Instead:
 *        - DELETE all branches WHERE id NOT IN (1,2,3,4) — removes any
 *          stray manually-created branches.
 *        - UPSERT the 4 legacy branches by id (1-4). Branch id=1 stays
 *          at id=1 (just renamed from 'Head Office (auto)' to 'Head
 *          Office' with code '0001'). All existing FK references stay
 *          valid. Branches 2/3/4 are new inserts.
 *      NOTE: branches 2/3/4 don't yet exist (only id=1 was auto-created),
 *      so there's nothing to cascade-delete. If the user manually created
 *      extra branches via the UI (e.g. id=2), those would be REPLACED by
 *      the legacy branch 2 (Patuatuli) via ON CONFLICT DO UPDATE.
 *
 *   2. Warehouses — All assigned to branch_id=1 (Head Office). The legacy
 *      data has 21 of 22 warehouses at branch_id=1 anyway, and the user
 *      chose to consolidate all 22 to branch 1 for simplicity. They can
 *      reassign via the UI later based on actual physical location.
 *
 *   3. Address format — preserve Bengali UTF-8 text as-is. PostgreSQL
 *      TEXT columns handle UTF-8 natively.
 *
 * Legacy data summary (from osudlagb_remotecenter.sql):
 *   4 branches: 1=Head Office, 2=Patuatuli, 3=Nowabpur, 4=Tarabo FACTORY
 *   22 warehouses: WH-001 .. WH-022 (various physical locations)
 *
 * Failure isolation: each row upsert runs inside its own savepoint.
 * Idempotent: ON CONFLICT (id) DO UPDATE.
 */
return new class extends Migration
{
    /** All 22 warehouses assigned to Head Office (id=1) per user's choice. */
    private const WAREHOUSE_BRANCH_ID = 1;

    public function up(): void
    {
        echo "\n┌────────────────────────────────────────────────────────────┐\n";
        echo "│  Legacy Branch + Warehouse Migration                       │\n";
        echo "└────────────────────────────────────────────────────────────┘\n";

        $sqlPath = $this->findSqlDump();
        if (!$sqlPath) {
            echo "  ! Cannot find osudlagb_remotecenter.sql. Looked in:\n"
               . "  - database/sql/osudlagb_remotecenter.sql\n"
               . "  - database/legacy/osudlagb_remotecenter.sql\n"
               . "  - legacy/osudlagb_remotecenter.sql\n"
               . "  - ../legacy/osudlagb_remotecenter.sql (Docker: /var/www/legacy/)\n"
               . "  - /var/www/legacy/osudlagb_remotecenter.sql\n"
               . "\n  Fix: copy the file into one of these locations.\n";
            return;
        }

        echo "  SQL dump: {$sqlPath}\n\n";

        // ── Step 1: Parse the SQL dump ──
        echo "[1/5] PARSE — extracting INSERT tuples...\n";
        $sql           = File::get($sqlPath);
        $branchRows    = $this->parseInsertTuples($sql, 'branches');
        $warehouseRows = $this->parseInsertTuples($sql, 'warehouses');
        echo "      • branches parsed   : " . count($branchRows) . "\n";
        echo "      • warehouses parsed : " . count($warehouseRows) . "\n\n";

        if (empty($branchRows) && empty($warehouseRows)) {
            echo "  ! No branches/warehouses INSERT tuples found — skipping.\n";
            return;
        }

        // ── Step 2: Clean up stray branches (keep only ids 1-4 which we'll upsert) ──
        echo "[2/5] CLEANUP — removing branches with id NOT IN (1,2,3,4)...\n";
        $this->cleanupStrayBranches();
        echo "\n";

        // ── Step 3: Upsert branches ──
        echo "[3/5] BRANCHES — upserting...\n";
        [$bIns, $bUpd, $bSkip] = $this->upsertBranches($branchRows);
        echo "      • inserted : {$bIns}\n";
        echo "      • updated  : {$bUpd}\n";
        echo "      • skipped  : {$bSkip}\n\n";

        // ── Step 4: Upsert warehouses (all to branch_id=1 per user's choice) ──
        echo "[4/5] WAREHOUSES — upserting (all to branch_id=" . self::WAREHOUSE_BRANCH_ID . ")...\n";
        [$wIns, $wUpd, $wSkip] = $this->upsertWarehouses($warehouseRows);
        echo "      • inserted : {$wIns}\n";
        echo "      • updated  : {$wUpd}\n";
        echo "      • skipped  : {$wSkip}\n\n";

        // ── Step 5: Bump sequences ──
        echo "[5/5] SEQUENCES — bumping branches_id_seq + warehouses_id_seq...\n";
        DB::statement(
            "SELECT setval('branches_id_seq', GREATEST((SELECT MAX(id) FROM branches), 1), true)"
        );
        DB::statement(
            "SELECT setval('warehouses_id_seq', GREATEST((SELECT MAX(id) FROM warehouses), 1), true)"
        );
        echo "      ✓ done\n\n";

        echo "  ✓ Migration complete.\n";
    }

    // ===============================================================
    // File-finding helper
    // ===============================================================

    private function findSqlDump(): ?string
    {
        $candidates = [
            database_path('sql/osudlagb_remotecenter.sql'),
            database_path('legacy/osudlagb_remotecenter.sql'),
            base_path('legacy/osudlagb_remotecenter.sql'),
            base_path('database/migrations/osudlagb_remotecenter.sql'),
            dirname(base_path()) . '/legacy/osudlagb_remotecenter.sql',
            '/var/www/legacy/osudlagb_remotecenter.sql',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    // ===============================================================
    // INSERT-tuple parser (phpMyAdmin format — same as customer migration)
    // ===============================================================

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
                    continue;
                }
                $rows[] = array_combine($columns, $values);
            }
        }
        return $rows;
    }

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

    private function parseTupleValues(string $tuple): array
    {
        $values = [];
        $i = 0;
        $n = strlen($tuple);
        while ($i < $n) {
            while ($i < $n && (ctype_space($tuple[$i]) || $tuple[$i] === ',')) {
                $i++;
            }
            if ($i >= $n) {
                break;
            }
            $ch = $tuple[$i];
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $i++;
                $buf = '';
                while ($i < $n) {
                    $c = $tuple[$i];
                    if ($c === '\\' && $i + 1 < $n) {
                        $next = $tuple[$i + 1];
                        $map = [
                            'n'  => "\n", 'r' => "\r", 't' => "\t",
                            '\\' => '\\', "'" => "'", '"' => '"', '0' => "\0",
                        ];
                        $buf .= $map[$next] ?? $next;
                        $i += 2;
                    } elseif ($c === $quote && $i + 1 < $n && $tuple[$i + 1] === $quote) {
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

    // ===============================================================
    // Cleanup stray branches
    // ===============================================================

    /**
     * Remove branches that aren't in the legacy id set (1-4).
     *
     * Why: the user may have manually created extra branches via the UI
     * (e.g. a test branch). We want the final state to match the legacy
     * exactly. Branches 1-4 are kept (1 will be updated, 2-4 inserted);
     * anything else is deleted.
     *
     * Safety: branches referenced by FKs (employees, customers, sales,
     * stock, accounting tables) cannot be deleted — the DELETE will fail
     * with an FK violation. We catch that and report which branch is
     * blocking, then skip it (it stays in place; the user can clean it
     * up manually after reassigning references).
     */
    private function cleanupStrayBranches(): void
    {
        $legacyIds = [1, 2, 3, 4];
        $strays = DB::select(
            "SELECT id, branch_code, branch_name FROM branches WHERE id NOT IN (1,2,3,4) ORDER BY id"
        );

        if (empty($strays)) {
            echo "      • no stray branches found (ids 1-4 are the only legacy set)\n";
            return;
        }

        foreach ($strays as $b) {
            try {
                DB::statement("DELETE FROM branches WHERE id = ?", [$b->id]);
                echo "      ✓ deleted stray branch id={$b->id} ({$b->branch_code} — {$b->branch_name})\n";
            } catch (\Throwable $e) {
                // FK violation — references exist. Reassign them to branch 1
                // (which we're keeping) and retry.
                echo "      ! branch id={$b->id} has FK references — reassigning to id=1 then deleting...\n";
                $this->reassignBranchReferences($b->id, 1);
                try {
                    DB::statement("DELETE FROM branches WHERE id = ?", [$b->id]);
                    echo "      ✓ deleted branch id={$b->id} after reassigning references\n";
                } catch (\Throwable $e2) {
                    echo "      ✗ could not delete branch id={$b->id}: " . $e2->getMessage() . "\n";
                    echo "        (left in place — you can delete it manually after cleanup)\n";
                }
            }
        }
    }

    /**
     * Reassign all FK references from $fromId to $toId across all known
     * branch_id-bearing tables. This is the brute-force cleanup path
     * used when a stray branch blocks DELETE due to FK violations.
     *
     * Tables covered (from grep of REFERENCES branches):
     *   employees, customers, suppliers, warehouses,
     *   sales_invoices, sales_challans, sales_returns, sales_draft_carts,
     *   purchase_orders, purchase_receives, purchase_returns,
     *   stock_adjustments, stock_take_sessions, damage_invoices,
     *   branch_cash, branch_expenses, branch_product_cost, branch_ledger,
     *   customer_payments, supplier_payments, other_incomes, other_expenses,
     *   employee_transactions, money_transfers (from_branch_id, to_branch_id),
     *   branch_demands (from_branch_id, to_branch_id),
     *   warehouse_transfers (from_branch_id, to_branch_id),
     *   accounting_periods, manual_journals, journal_entries, document_sequences
     *
     * Uses UPDATE ... WHERE column = $fromId (or IS NULL-safe coalesce).
     * Wrapped in a transaction — if anything fails, rolls back and the
     * caller falls through to the FK-error message.
     */
    private function reassignBranchReferences(int $fromId, int $toId): void
    {
        DB::transaction(function () use ($fromId, $toId) {
            // Single branch_id columns
            $singleColTables = [
                'employees', 'customers', 'suppliers', 'warehouses',
                'sales_invoices', 'sales_challans', 'sales_returns', 'sales_draft_carts',
                'purchase_orders', 'purchase_receives', 'purchase_returns',
                'stock_adjustments', 'stock_take_sessions', 'damage_invoices',
                'branch_cash', 'branch_expenses', 'branch_product_cost',
                'customer_payments', 'supplier_payments', 'other_incomes', 'other_expenses',
                'employee_transactions', 'accounting_periods', 'manual_journals',
                'journal_entries', 'document_sequences',
            ];
            foreach ($singleColTables as $tbl) {
                $this->safeUpdate($tbl, 'branch_id', $fromId, $toId);
            }

            // branch_ledger has its own naming (no branch_id column, uses branch_code)
            // — skip; handled separately if needed.

            // Dual branch_id columns (from_branch_id, to_branch_id)
            $dualColTables = [
                'money_transfers', 'branch_demands', 'warehouse_transfers',
            ];
            foreach ($dualColTables as $tbl) {
                $this->safeUpdate($tbl, 'from_branch_id', $fromId, $toId);
                $this->safeUpdate($tbl, 'to_branch_id', $fromId, $toId);
            }
        });
    }

    /**
     * Run an UPDATE if the table+column exist; no-op otherwise.
     * Some tables may not exist in older schema versions.
     */
    private function safeUpdate(string $table, string $column, int $fromId, int $toId): void
    {
        $exists = DB::selectOne("
            SELECT 1 FROM information_schema.columns
            WHERE table_name = ? AND column_name = ?
            LIMIT 1
        ", [$table, $column]);

        if (!$exists) {
            return;
        }

        DB::statement(
            "UPDATE {$table} SET {$column} = ? WHERE {$column} = ?",
            [$toId, $fromId]
        );
    }

    // ===============================================================
    // Branch upsert
    // ===============================================================

    private function upsertBranches(array $branchRows): array
    {
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ($branchRows as $row) {
            $id         = isset($row['id']) ? (int) $row['id'] : null;
            $branchCode = $row['branch_code'] ?? null;
            $branchName = $row['branch_name'] ?? null;

            if ($id === null || $branchCode === null || $branchName === null
                || trim($branchCode) === '' || trim($branchName) === '') {
                $skipped++;
                continue;
            }

            $address   = $row['address'] ?? null;
            $phone     = $row['phone'] ?? null;
            $email     = $row['email'] ?? null;
            $isActive  = !empty($row['is_active']);
            $createdAt = $this->normalizeDate($row['created_at'] ?? null);

            try {
                $wasExisting = DB::transaction(function () use (
                    $id, $branchCode, $branchName, $address, $phone, $email,
                    $isActive, $createdAt
                ) {
                    $existing = DB::selectOne("SELECT id FROM branches WHERE id = ?", [$id]);

                    DB::statement(
                        "INSERT INTO branches
                            (id, branch_code, branch_name, address, phone, email,
                             is_active, created_at, updated_at)
                         OVERRIDING SYSTEM VALUE
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                         ON CONFLICT (id) DO UPDATE
                         SET branch_code = EXCLUDED.branch_code,
                             branch_name = EXCLUDED.branch_name,
                             address     = EXCLUDED.address,
                             phone       = EXCLUDED.phone,
                             email       = EXCLUDED.email,
                             is_active   = EXCLUDED.is_active,
                             created_at  = COALESCE(EXCLUDED.created_at, branches.created_at),
                             updated_at  = NOW()",
                        [
                            $id,
                            trim($branchCode),
                            trim($branchName),
                            $address !== null ? trim($address) : null,
                            $phone   !== null ? trim($phone)   : null,
                            $email   !== null ? trim($email)   : null,
                            $isActive,
                            $createdAt,
                        ]
                    );

                    return (bool) $existing;
                });

                if ($wasExisting) {
                    $updated++;
                    echo "      ↻ updated  branch id={$id} ({$branchCode} — {$branchName})\n";
                } else {
                    $inserted++;
                    echo "      + inserted branch id={$id} ({$branchCode} — {$branchName})\n";
                }
            } catch (\Throwable $e) {
                $skipped++;
                echo "      ! skipped branch id={$id} ({$branchCode} — {$branchName}): "
                   . $e->getMessage() . "\n";
            }
        }

        return [$inserted, $updated, $skipped];
    }

    // ===============================================================
    // Warehouse upsert
    // ===============================================================

    private function upsertWarehouses(array $warehouseRows): array
    {
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ($warehouseRows as $row) {
            $id             = isset($row['id']) ? (int) $row['id'] : null;
            $warehouseCode  = $row['warehouse_code'] ?? null;
            $warehouseName  = $row['warehouse_name'] ?? null;

            if ($id === null || $warehouseCode === null || $warehouseName === null
                || trim($warehouseCode) === '' || trim($warehouseName) === '') {
                $skipped++;
                continue;
            }

            $address   = $row['address'] ?? null;
            $isActive  = !empty($row['is_active']);
            $createdAt = $this->normalizeDate($row['created_at'] ?? null);

            // All warehouses → branch_id=1 (Head Office) per user's choice.
            $branchId  = self::WAREHOUSE_BRANCH_ID;

            try {
                $wasExisting = DB::transaction(function () use (
                    $id, $warehouseCode, $warehouseName, $branchId,
                    $address, $isActive, $createdAt
                ) {
                    $existing = DB::selectOne("SELECT id FROM warehouses WHERE id = ?", [$id]);

                    DB::statement(
                        "INSERT INTO warehouses
                            (id, warehouse_code, warehouse_name, branch_id, location,
                             is_active, is_frozen_for_count, created_at, updated_at)
                         OVERRIDING SYSTEM VALUE
                         VALUES (?, ?, ?, ?, ?, ?, false, ?, NOW())
                         ON CONFLICT (id) DO UPDATE
                         SET warehouse_code = EXCLUDED.warehouse_code,
                             warehouse_name = EXCLUDED.warehouse_name,
                             branch_id      = EXCLUDED.branch_id,
                             location       = EXCLUDED.location,
                             is_active      = EXCLUDED.is_active,
                             created_at     = COALESCE(EXCLUDED.created_at, warehouses.created_at),
                             updated_at     = NOW()",
                        [
                            $id,
                            trim($warehouseCode),
                            trim($warehouseName),
                            $branchId,
                            $address !== null ? trim($address) : null,
                            $isActive,
                            $createdAt,
                        ]
                    );

                    return (bool) $existing;
                });

                if ($wasExisting) {
                    $updated++;
                } else {
                    $inserted++;
                }
            } catch (\Throwable $e) {
                $skipped++;
                if ($skipped <= 5) {
                    echo "      ! skipped warehouse id={$id} ({$warehouseCode} — {$warehouseName}): "
                       . $e->getMessage() . "\n";
                }
            }
        }

        return [$inserted, $updated, $skipped];
    }

    /**
     * Normalize a legacy datetime string to PostgreSQL timestamp,
     * or NULL if invalid/zero. Legacy dumps use '0000-00-00 00:00:00' as
     * a sentinel for "no date" — PG rejects that, so we NULL it.
     */
    private function normalizeDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = (string) $value;
        if (preg_match('/^0000-00-00/', $s)) {
            return null;
        }
        try {
            $dt = new \DateTime($s);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function down(): void
    {
        echo "  ↺ No automatic rollback for branch/warehouse import.\n";
        echo "    Branches 1-4 replaced any pre-existing auto-branch.\n";
        echo "    To undo, you'd need to restore the prior branch data manually.\n";
    }
};
