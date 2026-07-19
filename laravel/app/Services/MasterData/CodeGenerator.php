<?php

namespace App\Services\MasterData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto-Generate Code Service — Phase 16 + Task 17-REFACTOR-AUTOGEN-API.
 *
 * Generates sequential, human-readable codes for master-data entities
 * following the legacy RC_ERP conventions:
 *
 *   Branch    → HO, PT, NW, TR (manual — short alphabetic)
 *   Warehouse → WH-NNNN
 *   Product   → P-NNNN
 *   Customer  → CUS-YYYY-NNNNNN (year-scoped sequence)
 *   Supplier  → SUP-NNNNNN
 *   Employee  → EMP-NNNNNN
 *   Ledger    → L-NNNN
 *   Bank      → B-NNNN (manual — bank-specific)
 *
 * Phase 17 refactor: the Customer/Supplier/Employee controllers used to
 * have their own private generateXxxCode() methods. Those have been
 * removed and the centralized CodeGenerator now produces the SAME formats
 * the per-controller methods did, so existing data stays backward-compatible:
 *
 *   - Customer   → CUS-YYYY-NNNNNN  (6-digit zero-pad, scoped by year)
 *   - Supplier    → SUP-NNNNNN       (6-digit zero-pad)
 *   - Employee    → EMP-NNNNNN       (6-digit zero-pad)
 *
 * The code is zero-padded and uses the next available sequence number based
 * on the MAX(existing code) + 1 pattern. This mirrors the legacy
 * app/helpers/MasterDataCodeHelper.php behavior.
 *
 * Usage:
 *   $code = CodeGenerator::generate('products', 'product_code', 'P-');
 *   // Returns: P-0001, P-0002, etc.
 *   $code = CodeGenerator::customerCode();
 *   // Returns: CUS-2025-000001
 */
class CodeGenerator
{
    /**
     * Generate the next sequential code for a table column.
     *
     * @param  string $table   The database table name (e.g., 'products')
     * @param  string $column  The code column name (e.g., 'product_code')
     * @param  string $prefix  The code prefix (e.g., 'P-')
     * @param  int    $padLength  Zero-pad length (default 4)
     * @return string The generated code (e.g., 'P-0001')
     */
    public static function generate(
        string $table,
        string $column,
        string $prefix,
        int $padLength = 4,
    ): string {
        try {
            // Fetch all codes with this prefix, then find the max numeric suffix in PHP.
            // This avoids PostgreSQL REGEXP_REPLACE quoting issues.
            $codes = DB::table($table)
                ->where($column, 'LIKE', $prefix . '%')
                ->pluck($column);

            $maxSuffix = 0;
            $prefixLen = strlen($prefix);
            foreach ($codes as $code) {
                $suffix = substr($code, $prefixLen);
                // Extract leading digits from the suffix
                if (preg_match('/^(\d+)/', $suffix, $m)) {
                    $num = (int) $m[1];
                    if ($num > $maxSuffix) {
                        $maxSuffix = $num;
                    }
                }
            }

            $nextSuffix = $maxSuffix + 1;

            return $prefix . str_pad((string) $nextSuffix, $padLength, '0', STR_PAD_LEFT);
        } catch (\Throwable $e) {
            Log::warning('CodeGenerator::generate failed, using fallback', [
                'table' => $table,
                'column' => $column,
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);

            // Fallback: use timestamp-based unique code
            return $prefix . strtoupper(substr(uniqid(), -$padLength));
        }
    }

    /**
     * Generate a product code (P-NNNN format).
     */
    public static function productCode(): string
    {
        return self::generate('products', 'product_code', 'P-');
    }

    /**
     * Generate a customer code in the legacy CUS-YYYY-NNNNNN format.
     *
     * The sequence is scoped per-year (so the year-prefix changes every
     * calendar year). For backward compatibility with existing data the
     * sequence number is zero-padded to 6 digits.
     *
     * Examples: CUS-2025-000001, CUS-2025-000002, CUS-2026-000001
     */
    public static function customerCode(): string
    {
        $year   = now()->format('Y');
        $prefix = "CUS-{$year}-";

        return self::generate('customers', 'customer_code', $prefix, 6);
    }

    /**
     * Generate a supplier code in the SUP-NNNNNN format.
     *
     * Zero-padded to 6 digits to match the legacy SupplierController
     * format (was previously `S-NNNN` in Phase 16 — changed in Task 17
     * to match existing data).
     */
    public static function supplierCode(): string
    {
        return self::generate('suppliers', 'supplier_code', 'SUP-', 6);
    }

    /**
     * Generate an employee code in the EMP-NNNNNN format.
     *
     * Zero-padded to 6 digits to match the legacy EmployeeController
     * format (was `EMP-NNNN` in Phase 16 — changed in Task 17 to
     * match existing data).
     */
    public static function employeeCode(): string
    {
        return self::generate('employees', 'employee_code', 'EMP-', 6);
    }

    /**
     * Generate a ledger code (L-NNNN format).
     */
    public static function ledgerCode(): string
    {
        return self::generate('ledgers', 'ledger_code', 'L-');
    }

    /**
     * Generate a warehouse code (WH-NNNN format).
     */
    public static function warehouseCode(): string
    {
        return self::generate('warehouses', 'warehouse_code', 'WH-');
    }
}
