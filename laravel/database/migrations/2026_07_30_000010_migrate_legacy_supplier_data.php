<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Migrate legacy supplier data from the phpMyAdmin SQL dump
 * `osudlagb_remotecenter.sql` (the full legacy DB dump).
 *
 * Source table: suppliers (legacy MySQL)
 *   id, supplier_code, supplier_name, mobile, address,
 *   is_active (tinyint), created_at, created_by, updated_at
 *
 * Target table: suppliers (new PostgreSQL schema)
 *   id, supplier_code, supplier_name, phone, mobile, email, address,
 *   branch_id (FK→branches), contact_person, opening_balance,
 *   balance_type, is_active, created_at, updated_at, deleted_at, deleted_by
 *
 * Mapping notes:
 *   • supplier_code, supplier_name, mobile, address, is_active → direct
 *   • branch_id — not in legacy; defaults to 1 (the auto-created default branch
 *     from the employee migration). suppliers.branch_id is nullable, but we
 *     set it explicitly so reports group correctly.
 *   • created_at — sanitized ('0000-00-00 00:00:00' → NULL → column DEFAULT)
 *   • opening_balance — 0 (not in legacy dump; would come from supplier_ledger
 *     if we imported opening balances, which we deliberately skip)
 *   • balance_type — 'credit' (default for suppliers — they owe us goods,
 *     we owe them money)
 *   • contact_person, email, phone — NULL (not in legacy dump)
 *
 * Failure isolation: each row upsert runs inside its own savepoint.
 * Idempotent: ON CONFLICT (id) DO UPDATE.
 */
return new class extends Migration
{
    private const DEFAULT_BRANCH_ID = 1;

    public function up(): void
    {
        echo "\n┌────────────────────────────────────────────────────────────┐\n";
        echo "│  Legacy Supplier Migration                                 │\n";
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
        echo "[1/3] CHECK — parsing SQL dump...\n";
        $sql = File::get($sqlPath);
        $supplierRows = $this->parseInsertTuples($sql, 'suppliers');
        echo "      • suppliers rows parsed : " . count($supplierRows) . "\n\n";

        if (empty($supplierRows)) {
            echo "  ! No suppliers INSERT tuples found in dump — skipping.\n";
            return;
        }

        // ── Step 2: Upsert suppliers ──
        echo "[2/3] SUPPLIERS — upserting...\n";
        [$inserted, $updated, $skipped] = $this->upsertSuppliers($supplierRows);
        echo "      • inserted : {$inserted}\n";
        echo "      • updated  : {$updated}\n";
        echo "      • skipped  : {$skipped}\n\n";

        // ── Step 3: Bump sequence ──
        echo "[3/3] SEQUENCE — bumping suppliers_id_seq...\n";
        DB::statement(
            "SELECT setval('suppliers_id_seq', GREATEST((SELECT MAX(id) FROM suppliers), 1), true)"
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
    // INSERT-tuple parser (phpMyAdmin format)
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
    // Supplier upsert
    // ===============================================================

    private function upsertSuppliers(array $supplierRows): array
    {
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ($supplierRows as $row) {
            $id           = isset($row['id']) ? (int) $row['id'] : null;
            $supplierCode = $row['supplier_code'] ?? null;
            $supplierName = $row['supplier_name'] ?? null;

            if ($id === null || $supplierCode === null || $supplierName === null
                || trim($supplierCode) === '' || trim($supplierName) === '') {
                $skipped++;
                continue;
            }

            $mobile    = $row['mobile'] ?? null;
            $address   = $row['address'] ?? null;
            $isActive  = !empty($row['is_active']);
            $createdAt = $this->normalizeDate($row['created_at'] ?? null);

            try {
                $wasExisting = DB::transaction(function () use (
                    $id, $supplierCode, $supplierName, $mobile, $address,
                    $isActive, $createdAt
                ) {
                    $existing = DB::selectOne("SELECT id FROM suppliers WHERE id = ?", [$id]);

                    DB::statement(
                        "INSERT INTO suppliers
                            (id, supplier_code, supplier_name, phone, mobile, email, address,
                             branch_id, contact_person, opening_balance, balance_type,
                             is_active, created_at, updated_at)
                         OVERRIDING SYSTEM VALUE
                         VALUES (?, ?, ?, NULL, ?, NULL, ?, ?, NULL, 0, 'credit', ?, ?, NOW())
                         ON CONFLICT (id) DO UPDATE
                         SET supplier_code = EXCLUDED.supplier_code,
                             supplier_name = EXCLUDED.supplier_name,
                             mobile        = EXCLUDED.mobile,
                             address       = EXCLUDED.address,
                             branch_id     = EXCLUDED.branch_id,
                             is_active     = EXCLUDED.is_active,
                             created_at    = COALESCE(EXCLUDED.created_at, suppliers.created_at),
                             updated_at    = NOW()",
                        [
                            $id,
                            trim($supplierCode),
                            trim($supplierName),
                            $mobile  !== null ? trim($mobile)  : null,
                            $address !== null ? trim($address) : null,
                            self::DEFAULT_BRANCH_ID,
                            $isActive,
                            $createdAt,  // null is fine — column DEFAULT kicks in
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
                    echo "      ! skipped supplier id={$id} ({$supplierCode} — {$supplierName}): "
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
        echo "  ↺ No automatic rollback for supplier import — rows may have been referenced.\n";
        echo "    To manually undo, run DELETE FROM suppliers WHERE id <= <max legacy id>;\n";
    }
};
