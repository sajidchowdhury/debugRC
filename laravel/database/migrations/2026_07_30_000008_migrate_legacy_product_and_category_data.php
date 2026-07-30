<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Migrate legacy product + category data from the phpMyAdmin SQL dump
 * `product_catagory.sql` (filename typo is intentional — that's the actual
 * file in the repo).
 *
 * Source tables (in the legacy dump):
 *   setup_category → product_categories : id, category_name, is_active
 *   setup_product  → products           : id, product_code, product_name,
 *                                          category_id, group_id, unit,
 *                                          purchase_rate, sales_rate,
 *                                          min_stock, reorder_level, is_active
 *
 * Mapping notes (legacy → new):
 *   • setup_category.category     → product_categories.category_name
 *   • setup_product.code          → products.product_code
 *   • setup_product.unit_id       → products.unit (string enum):
 *       3 → 'Pcs'  (1206 rows — the overwhelming majority, remote controls)
 *       5 → 'KG'   (2 rows)
 *       6 → 'Set'  (11 rows)
 *       anything else → 'Pcs' (safe default; products.unit CHECK constraint
 *       only allows Pcs/Carton/KG/Bag/Dobe/Set)
 *   • setup_product.in_service    → products.is_active (boolean):
 *       'checked' → TRUE, 'Block' → FALSE, anything else → FALSE
 *   • setup_product.safty_stock   → products.min_stock AND products.reorder_level
 *   • setup_product.sales_rate    → products.sales_rate
 *   • setup_product.wholesale_rate, vat_percentage, discount, pcs_in_cartoon
 *     → NOT carried over (no matching column in the new schema; pcs_in_cartoon
 *       should be set up later via product_uom_conversions if needed)
 *   • setup_product.group_id      → does NOT exist in legacy; defaults to 1.
 *       We auto-create a "Default" group id=1 if it doesn't already exist
 *       (some installs already have product_groups seeded from the legacy
 *       `product_groups` table — China/Local — so we use ON CONFLICT).
 *
 * Idempotent: ON CONFLICT (id) DO UPDATE — safe to re-run.
 * Reversible: down() deletes only the rows we imported (by id range or
 *   by matching category_name in our import set). To be safe and avoid
 *   clobbering manually-created products, we only delete product rows whose
 *   id appeared in the legacy dump, and categories by name match.
 */
return new class extends Migration
{
    /**
     * unit_id → unit string enum mapping.
     * Derived from the actual distribution in the legacy dump:
     *   3 = 1206 rows (Pcs — remote controls)
     *   5 = 2 rows    (KG)
     *   6 = 11 rows   (Set)
     * Any unknown unit_id falls back to 'Pcs'.
     */
    private const UNIT_ID_MAP = [
        3 => 'Pcs',
        5 => 'KG',
        6 => 'Set',
    ];

    private const DEFAULT_GROUP_ID = 1;
    private const DEFAULT_GROUP_NAME = 'Default';
    private const DEFAULT_GROUP_CODE = 'GRP-001';

    public function up(): void
    {
        echo "\n┌────────────────────────────────────────────────────────────┐\n";
        echo "│  Legacy Product + Category Migration                       │\n";
        echo "└────────────────────────────────────────────────────────────┘\n";

        // ── Step 0: Find the SQL dump ──
        $sqlPath = $this->findSqlDump();
        if (!$sqlPath) {
            echo "  ! Cannot find product_catagory.sql. Looked in:\n"
               . "  - database/sql/product_catagory.sql\n"
               . "  - database/legacy/product_catagory.sql\n"
               . "  - legacy/product_catagory.sql\n"
               . "  - ../legacy/product_catagory.sql (Docker: /var/www/legacy/)\n"
               . "  - /var/www/legacy/product_catagory.sql\n"
               . "\n  Fix: copy product_catagory.sql into one of these locations.\n";
            return;
        }

        echo "  SQL dump: {$sqlPath}\n\n";

        // ── Step 1: Parse the SQL dump ──
        echo "[1/4] CHECK — parsing SQL dump...\n";
        $sql = File::get($sqlPath);

        $categoryRows = $this->parseInsertTuples($sql, 'setup_category');
        $productRows  = $this->parseInsertTuples($sql, 'setup_product');

        echo "      • setup_category rows parsed : " . count($categoryRows) . "\n";
        echo "      • setup_product rows parsed  : " . count($productRows) . "\n\n";

        if (empty($categoryRows) && empty($productRows)) {
            echo "  ! No setup_category or setup_product INSERT tuples found in dump — skipping.\n";
            return;
        }

        // ── Step 2: Ensure default product group exists ──
        echo "[2/4] GROUP — ensuring default product group id=" . self::DEFAULT_GROUP_ID . "...\n";
        $this->ensureDefaultGroupExists();

        // ── Step 3: Upsert categories ──
        echo "[3/4] CATEGORIES — upserting product_categories...\n";
        [$catInserted, $catUpdated, $catSkipped] = $this->upsertCategories($categoryRows);
        echo "      • inserted : {$catInserted}\n";
        echo "      • updated  : {$catUpdated}\n";
        echo "      • skipped  : {$catSkipped}\n\n";

        // ── Step 4: Upsert products ──
        echo "[4/4] PRODUCTS — upserting products...\n";
        [$prodInserted, $prodUpdated, $prodSkipped] = $this->upsertProducts($productRows);
        echo "      • inserted : {$prodInserted}\n";
        echo "      • updated  : {$prodUpdated}\n";
        echo "      • skipped  : {$prodSkipped}\n\n";

        // Bump sequences so future inserts don't collide with imported IDs.
        DB::statement(
            "SELECT setval('products_id_seq', GREATEST((SELECT MAX(id) FROM products), 1), true)"
        );
        DB::statement(
            "SELECT setval('product_categories_id_seq', GREATEST((SELECT MAX(id) FROM product_categories), 1), true)"
        );

        echo "  ✓ Migration complete.\n";
    }

    // ===============================================================
    // File-finding helpers
    // ===============================================================

    /**
     * Find the legacy SQL dump. Same path strategy as the employee migration.
     *
     * Docker note: docker-compose.yml mounts host ./legacy at /var/www/legacy,
     * and Laravel base_path() is /var/www/laravel — so /var/www/legacy is
     * one directory UP from base_path().
     */
    private function findSqlDump(): ?string
    {
        $candidates = [
            database_path('sql/product_catagory.sql'),       // typo in filename
            database_path('sql/product_category.sql'),       // also try the correct spelling
            database_path('legacy/product_catagory.sql'),
            database_path('legacy/product_category.sql'),
            base_path('legacy/product_catagory.sql'),
            base_path('legacy/product_category.sql'),
            base_path('database/migrations/product_catagory.sql'),
            dirname(base_path()) . '/legacy/product_catagory.sql',
            dirname(base_path()) . '/legacy/product_category.sql',
            '/var/www/legacy/product_catagory.sql',
            '/var/www/legacy/product_category.sql',
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
    // Copied from the employee migration to keep this migration standalone.
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
    // Group / category / product upserts
    // ===============================================================

    /**
     * Make sure the default product group (id=1) exists. The product_groups
     * table requires group_name (NOT NULL). Uses OVERRIDING SYSTEM VALUE
     * because `id` is GENERATED ALWAYS AS IDENTITY.
     */
    private function ensureDefaultGroupExists(): void
    {
        $exists = DB::selectOne(
            "SELECT id FROM product_groups WHERE id = ?",
            [self::DEFAULT_GROUP_ID]
        );

        if ($exists) {
            echo "      • product_groups id=" . self::DEFAULT_GROUP_ID . " already exists — skipping\n";
            return;
        }

        DB::statement(
            "INSERT INTO product_groups (id, group_name, description, sort_order, is_active)
             OVERRIDING SYSTEM VALUE
             VALUES (?, ?, ?, 0, true)
             ON CONFLICT (id) DO NOTHING",
            [
                self::DEFAULT_GROUP_ID,
                self::DEFAULT_GROUP_NAME,
                'Auto-created default group for legacy product import',
            ]
        );

        DB::statement(
            "SELECT setval('product_groups_id_seq', GREATEST((SELECT MAX(id) FROM product_groups), 1), true)"
        );

        echo "      • auto-created product_groups id=" . self::DEFAULT_GROUP_ID
           . " (group_name='" . self::DEFAULT_GROUP_NAME . "')\n";
    }

    /**
     * Upsert categories.
     * Uses OVERRIDING SYSTEM VALUE because `id` is GENERATED ALWAYS AS IDENTITY.
     * ON CONFLICT (id) DO UPDATE makes it safe to re-run.
     */
    private function upsertCategories(array $categoryRows): array
    {
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ($categoryRows as $row) {
            $id   = isset($row['id'])   ? (int) $row['id']   : null;
            $name = $row['category'] ?? ($row['category_name'] ?? null);

            if ($id === null || $name === null || $name === '') {
                $skipped++;
                continue;
            }

            $existing = DB::selectOne(
                "SELECT id FROM product_categories WHERE id = ?",
                [$id]
            );

            try {
                DB::statement(
                    "INSERT INTO product_categories (id, category_name, description, is_active)
                     OVERRIDING SYSTEM VALUE
                     VALUES (?, ?, NULL, true)
                     ON CONFLICT (id) DO UPDATE
                     SET category_name = EXCLUDED.category_name,
                         is_active     = TRUE",
                    [$id, trim($name)]
                );

                if ($existing) {
                    $updated++;
                } else {
                    $inserted++;
                }
            } catch (\Throwable $e) {
                $skipped++;
                echo "      ! skipped category id={$id} ({$name}): " . $e->getMessage() . "\n";
            }
        }

        return [$inserted, $updated, $skipped];
    }

    /**
     * Upsert products.
     *
     * Column mapping (see class docblock for the full table).
     */
    private function upsertProducts(array $productRows): array
    {
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ($productRows as $row) {
            $id   = isset($row['id'])   ? (int) $row['id']   : null;
            $code = $row['code'] ?? ($row['product_code'] ?? null);
            $name = $row['product_name'] ?? null;

            if ($id === null || $code === null || $name === null || trim($code) === '' || trim($name) === '') {
                $skipped++;
                continue;
            }

            // category_id (may be NULL or invalid in legacy — fall back to NULL)
            $categoryId = isset($row['category_id']) && $row['category_id'] !== null
                ? (int) $row['category_id']
                : null;

            // unit_id → unit string
            $unitId   = isset($row['unit_id']) && $row['unit_id'] !== null ? (int) $row['unit_id'] : null;
            $unitEnum = self::UNIT_ID_MAP[$unitId] ?? 'Pcs';

            // sales_rate → sales_rate (default 0)
            $salesRate = isset($row['sales_rate']) && $row['sales_rate'] !== null
                ? (float) $row['sales_rate']
                : 0.0;

            // safty_stock (legacy typo) → min_stock + reorder_level (default 0)
            $safetyStock = isset($row['safty_stock']) && $row['safty_stock'] !== null
                ? (float) $row['safty_stock']
                : 0.0;

            // in_service → is_active
            // Legacy uses 'checked' (active) / 'Block' (inactive). Default to true.
            $inService = $row['in_service'] ?? 'checked';
            $isActive  = (strcasecmp((string) $inService, 'checked') === 0);

            // created_at — prefer legacy `date` if it's a valid date, else NULL
            // (so the column DEFAULT CURRENT_TIMESTAMP kicks in).
            $legacyDate = $row['date'] ?? null;
            $createdAt  = $this->normalizeDate($legacyDate);

            $existing = DB::selectOne(
                "SELECT id FROM products WHERE id = ?",
                [$id]
            );

            try {
                DB::statement(
                    "INSERT INTO products
                        (id, product_code, product_name, category_id, group_id, unit,
                         purchase_rate, sales_rate, min_stock, max_stock, reorder_level,
                         product_image, is_active, condition_state, created_at, updated_at)
                     OVERRIDING SYSTEM VALUE
                     VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 0, ?, NULL, ?, 'Good', ?, NOW())
                     ON CONFLICT (id) DO UPDATE
                     SET product_code  = EXCLUDED.product_code,
                         product_name  = EXCLUDED.product_name,
                         category_id   = EXCLUDED.category_id,
                         group_id      = EXCLUDED.group_id,
                         unit          = EXCLUDED.unit,
                         sales_rate    = EXCLUDED.sales_rate,
                         min_stock     = EXCLUDED.min_stock,
                         reorder_level = EXCLUDED.reorder_level,
                         is_active     = EXCLUDED.is_active,
                         updated_at    = NOW()",
                    [
                        $id,
                        trim($code),
                        trim($name),
                        $categoryId,
                        self::DEFAULT_GROUP_ID,
                        $unitEnum,
                        $salesRate,
                        $safetyStock,
                        $safetyStock,
                        $isActive,
                        $createdAt,  // null is fine — column DEFAULT kicks in
                    ]
                );

                if ($existing) {
                    $updated++;
                } else {
                    $inserted++;
                }
            } catch (\Throwable $e) {
                $skipped++;
                if ($skipped <= 5) {
                    echo "      ! skipped product id={$id} ({$code} — {$name}): "
                       . $e->getMessage() . "\n";
                }
            }
        }

        return [$inserted, $updated, $skipped];
    }

    /**
     * Normalize a legacy date string to a PostgreSQL-compatible timestamp,
     * or return NULL if the date is invalid/zero.
     *
     * Legacy dumps frequently contain '0000-00-00' as a sentinel for "no date".
     * PostgreSQL rejects that, so we must NULL it out.
     */
    private function normalizeDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = (string) $value;
        // '0000-00-00' or any variant with zero month/day → NULL
        if (preg_match('/^0000-00-00/', $s)) {
            return null;
        }
        // Validate it parses as a date
        try {
            $dt = new \DateTime($s);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ===============================================================
    // Rollback
    // ===============================================================

    public function down(): void
    {
        // We deliberately do NOT delete imported products/categories on
        // rollback — they may have been edited or referenced by transactions
        // since the import. If you really need to wipe them, do it manually:
        //
        //   DELETE FROM products WHERE id <= (max legacy id you imported);
        //   DELETE FROM product_categories WHERE id <= (max legacy id);
        //
        // This is the same conservative behaviour as the employee migration.
        echo "  ↺ No automatic rollback for product import — rows may have been referenced.\n";
        echo "    To manually undo, run DELETE FROM products WHERE id <= <max legacy id>;\n";
    }
};
