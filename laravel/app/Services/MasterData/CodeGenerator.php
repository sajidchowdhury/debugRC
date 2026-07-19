<?php

namespace App\Services\MasterData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto-Generate Code Service — Phase 16.
 *
 * Generates sequential, human-readable codes for master-data entities
 * following the legacy RC_ERP conventions:
 *
 *   Branch    → HO, PT, NW, TR (manual — short alphabetic)
 *   Warehouse → WH-NNNN
 *   Product   → P-NNNN
 *   Customer  → C-NNNN
 *   Supplier  → S-NNNN
 *   Employee  → EMP-NNNN
 *   Ledger    → L-NNNN
 *   Bank      → B-NNNN (manual — bank-specific)
 *
 * The code is zero-padded to 4 digits and uses the next available sequence
 * number based on the MAX(existing code) + 1 pattern. This mirrors the
 * legacy app/helpers/MasterDataCodeHelper.php behavior.
 *
 * Usage:
 *   $code = CodeGenerator::generate('products', 'product_code', 'P-');
 *   // Returns: P-0001, P-0002, etc.
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
     * Generate a customer code (C-NNNN format).
     */
    public static function customerCode(): string
    {
        return self::generate('customers', 'customer_code', 'C-');
    }

    /**
     * Generate a supplier code (S-NNNN format).
     */
    public static function supplierCode(): string
    {
        return self::generate('suppliers', 'supplier_code', 'S-');
    }

    /**
     * Generate an employee code (EMP-NNNN format).
     */
    public static function employeeCode(): string
    {
        return self::generate('employees', 'employee_code', 'EMP-');
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
