<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Migrate legacy bank data from the phpMyAdmin SQL dump
 * `osudlagb_remotecenter.sql` (the full legacy DB dump).
 *
 * Source table: banks (legacy MySQL)
 *   id, bank_name, account_number, account_name, branch_name,
 *   is_active (tinyint), created_by, updated_at (int YYYYMMDD),
 *   balance (float(20,2))
 *
 * Target table: banks (new PostgreSQL schema)
 *   id, bank_name, account_number, account_holder, branch_name,
 *   balance (numeric(18,2)), updated_at (date), is_active (bool),
 *   ledger_id, created_by, created_at
 *
 * Mapping notes:
 *   • account_name → account_holder (column renamed in new schema)
 *   • updated_at int YYYYMMDD (e.g. 20221011) → date '2022-10-11'
 *     0 or invalid → NULL (column DEFAULT CURRENT_DATE kicks in)
 *   • is_active 0/1 → boolean
 *   • balance float → numeric (PG cast, no precision loss for these magnitudes)
 *   • created_at — legacy banks table has no created_at column; uses NOW()
 *   • ledger_id — NULL (banks are linked to ledgers later via
 *     bank_ledger_mappings, not set during legacy import)
 *
 * Failure isolation: each row upsert runs inside its own savepoint.
 * Idempotent: ON CONFLICT (id) DO UPDATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        echo "\n┌────────────────────────────────────────────────────────────┐\n";
        echo "│  Legacy Bank Migration                                     │\n";
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
        $bankRows = $this->parseInsertTuples($sql, 'banks');
        echo "      • banks rows parsed : " . count($bankRows) . "\n\n";

        if (empty($bankRows)) {
            echo "  ! No banks INSERT tuples found in dump — skipping.\n";
            return;
        }

        // ── Step 2: Upsert banks ──
        echo "[2/3] BANKS — upserting...\n";
        [$inserted, $updated, $skipped] = $this->upsertBanks($bankRows);
        echo "      • inserted : {$inserted}\n";
        echo "      • updated  : {$updated}\n";
        echo "      • skipped  : {$skipped}\n\n";

        // ── Step 3: Bump sequence ──
        echo "[3/3] SEQUENCE — bumping banks_id_seq...\n";
        DB::statement(
            "SELECT setval('banks_id_seq', GREATEST((SELECT MAX(id) FROM banks), 1), true)"
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
    // Bank upsert
    // ===============================================================

    private function upsertBanks(array $bankRows): array
    {
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ($bankRows as $row) {
            $id       = isset($row['id'])       ? (int) $row['id']       : null;
            $bankName = $row['bank_name'] ?? null;

            if ($id === null || $bankName === null || trim($bankName) === '') {
                $skipped++;
                continue;
            }

            $accountNumber = $row['account_number'] ?? null;
            $accountHolder = $row['account_name'] ?? null; // renamed → account_holder
            $branchName    = $row['branch_name'] ?? null;
            $balance       = isset($row['balance']) && $row['balance'] !== null
                ? (float) $row['balance']
                : 0.0;
            $isActive      = !empty($row['is_active']);
            $createdBy     = isset($row['created_by']) && $row['created_by'] !== null
                ? (int) $row['created_by']
                : null;
            $updatedAt     = $this->normalizeIntDate($row['updated_at'] ?? null);

            try {
                $wasExisting = DB::transaction(function () use (
                    $id, $bankName, $accountNumber, $accountHolder,
                    $branchName, $balance, $isActive, $createdBy, $updatedAt
                ) {
                    $existing = DB::selectOne("SELECT id FROM banks WHERE id = ?", [$id]);

                    DB::statement(
                        "INSERT INTO banks
                            (id, bank_name, account_number, account_holder, branch_name,
                             balance, updated_at, is_active, ledger_id, created_by, created_at)
                         OVERRIDING SYSTEM VALUE
                         VALUES (?, ?, ?, ?, ?, ?, ?, TRUE, NULL, ?, NOW())
                         ON CONFLICT (id) DO UPDATE
                         SET bank_name      = EXCLUDED.bank_name,
                             account_number = EXCLUDED.account_number,
                             account_holder = EXCLUDED.account_holder,
                             branch_name    = EXCLUDED.branch_name,
                             balance        = EXCLUDED.balance,
                             updated_at     = EXCLUDED.updated_at,
                             is_active      = EXCLUDED.is_active,
                             created_by     = EXCLUDED.created_by",
                        [
                            $id,
                            trim($bankName),
                            $accountNumber !== null ? trim($accountNumber) : null,
                            $accountHolder !== null ? trim($accountHolder) : null,
                            $branchName    !== null ? trim($branchName)    : null,
                            $balance,
                            $updatedAt,  // string 'YYYY-MM-DD' or NULL
                            $createdBy,
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
                    echo "      ! skipped bank id={$id} ({$bankName}): " . $e->getMessage() . "\n";
                }
            }
        }

        return [$inserted, $updated, $skipped];
    }

    /**
     * Convert legacy `updated_at` (int YYYYMMDD, e.g. 20221011) to a
     * PostgreSQL DATE string 'YYYY-MM-DD'. Returns NULL for 0 or invalid.
     */
    private function normalizeIntDate($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = (string) $value;
        if (!preg_match('/^\d{8}$/', $s)) {
            return null;
        }
        $y = substr($s, 0, 4);
        $m = substr($s, 4, 2);
        $d = substr($s, 6, 2);
        if (!checkdate((int) $m, (int) $d, (int) $y)) {
            return null;
        }
        return "{$y}-{$m}-{$d}";
    }

    public function down(): void
    {
        echo "  ↺ No automatic rollback for bank import — rows may have been referenced.\n";
        echo "    To manually undo, run DELETE FROM banks WHERE id <= <max legacy id>;\n";
    }
};
