<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Document Sequence Service — Atomic Code Generation with Advisory Locks (Task 20).
 *
 * Replaces the previous `document_sequences SELECT FOR UPDATE` pattern used by 12+ services
 * with PostgreSQL session-level advisory locks (`pg_advisory_xact_lock`). This provides:
 *
 *   1. **No disk I/O for locking** — advisory locks live in shared_memory, unlike row-level
 *      locks that must write to the page's infomask and potentially flush WAL.
 *   2. **Transaction-scoped auto-release** — `pg_advisory_xact_lock` is released automatically
 *      on COMMIT or ROLLBACK, so there is no risk of orphaned locks from forgotten unlock calls.
 *   3. **Non-blocking reads** — other transactions can still SELECT from document_sequences
 *      without being blocked; only concurrent code-generation calls for the same doc_type
 *      are serialized.
 *   4. **No RLS conflict** — advisory locks operate at the session level and are completely
 *      independent of Row-Level Security policies. The old `lockForUpdate()` on
 *      document_sequences could conflict with RLS because branch_id=0 rows might be
 *      filtered out by the USING clause, causing the SELECT FOR UPDATE to return 0 rows.
 *
 * Advisory lock key construction:
 *   - int4 key = hash32(doc_type || branch_id || period_key)
 *   - We use the single-int4 form `pg_advisory_xact_lock(int)` for simplicity.
 *   - The hash is a deterministic CRC-32 computed in PHP, mapped to a signed 32-bit integer
 *     (PostgreSQL expects int4 for the single-argument form).
 *
 * Usage:
 *   $code = DocumentSequenceService::nextCode(
 *       doc_type: 'sales_invoice',
 *       prefix:   'INV',
 *       datePart: now()->format('Ymd'),
 *       padLength: 4,
 *   );
 *   // Returns: "INV-20250120-0001"
 *
 * For journal entries (different period granularity):
 *   $code = DocumentSequenceService::nextCode(
 *       doc_type:  'journal_entry',
 *       prefix:    'JE',
 *       datePart:  '2025',
 *       periodKey: '2025',
 *       padLength: 6,
 *       separator: '-',
 *   );
 *   // Returns: "JE-2025-000001"
 */
class DocumentSequenceService
{
    /**
     * Generate the next sequential document code atomically using advisory locks.
     *
     * @param  string $docType   The document type key (e.g., 'sales_invoice', 'purchase_order')
     * @param  string $prefix    The code prefix (e.g., 'INV', 'PO', 'JE')
     * @param  string $datePart  The date portion of the code (e.g., '20250120', '2025')
     * @param  int    $padLength Zero-pad length for the sequence number (default 4)
     * @param  string $periodKey The period key for sequence isolation (default: Y-m from now)
     * @param  int    $branchId  The branch scope (default: 0 = global)
     * @param  string $separator Separator between prefix-date-number (default: '-')
     * @return string The generated document code (e.g., "INV-20250120-0001")
     * @throws \RuntimeException If the advisory lock cannot be acquired
     */
    public static function nextCode(
        string $docType,
        string $prefix,
        string $datePart,
        int    $padLength = 4,
        string $periodKey = '',
        int    $branchId = 0,
        string $separator = '-',
    ): string {
        // Default period key to current year-month if not specified.
        if ($periodKey === '') {
            $periodKey = now()->format('Y-m');
        }

        // Compute a deterministic signed int4 hash for the advisory lock key.
        // CRC32 produces a 32-bit unsigned integer; we convert to signed for PostgreSQL int4.
        $lockKey = self::computeLockKey($docType, $branchId, $periodKey);

        return DB::transaction(function () use ($docType, $prefix, $datePart, $padLength, $periodKey, $branchId, $separator, $lockKey) {
            // Acquire transaction-scoped advisory lock.
            // This blocks until the lock is available, then auto-releases on COMMIT/ROLLBACK.
            DB::select("SELECT pg_advisory_xact_lock(?)", [$lockKey]);

            // Now safely read the current sequence value — no FOR UPDATE needed.
            // The advisory lock guarantees no other transaction can increment the same
            // doc_type/branch_id/period_key combination concurrently.
            $seqRow = DB::table('document_sequences')
                ->where('doc_type', $docType)
                ->where('branch_id', $branchId)
                ->where('period_key', $periodKey)
                ->first();

            $nextNumber = $seqRow ? ((int) $seqRow->last_number + 1) : 1;

            if ($seqRow) {
                DB::table('document_sequences')
                    ->where('id', $seqRow->id)
                    ->update(['last_number' => $nextNumber, 'updated_at' => now()]);
            } else {
                DB::table('document_sequences')->insert([
                    'doc_type'   => $docType,
                    'branch_id'  => $branchId,
                    'period_key' => $periodKey,
                    'last_number' => $nextNumber,
                    'updated_at' => now(),
                ]);
            }

            return $prefix . $separator . $datePart . $separator
                . str_pad((string) $nextNumber, $padLength, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Compute a deterministic signed int4 hash for the advisory lock key.
     *
     * Uses CRC32 to produce a 32-bit value, then converts to signed integer
     * because PostgreSQL's `pg_advisory_xact_lock(int)` expects int4 (signed).
     *
     * Collision probability: with ~20 active doc_types, the chance of two different
     * doc_type/branch_id/period_key combinations mapping to the same int4 is
     * approximately 20²/(2³²) ≈ 0.00009% — negligible for this workload.
     * Even if a collision occurred, the worst case is serialization of two
     * unrelated sequences, not data corruption.
     *
     * @param  string $docType
     * @param  int    $branchId
     * @param  string $periodKey
     * @return int Signed 32-bit integer suitable for pg_advisory_xact_lock
     */
    public static function computeLockKey(string $docType, int $branchId, string $periodKey): int
    {
        $key = "{$docType}:{$branchId}:{$periodKey}";
        $unsigned = crc32($key);

        // Convert unsigned 32-bit to signed 32-bit for PostgreSQL int4.
        return $unsigned >= 2147483648 ? (int) ($unsigned - 4294967296) : (int) $unsigned;
    }

    /**
     * Try to acquire the advisory lock without blocking (non-blocking variant).
     *
     * Returns true if the lock was acquired, false if it would block.
     * Useful for diagnostic/monitoring purposes — not used in the main code path.
     *
     * @param  string $docType
     * @param  int    $branchId
     * @param  string $periodKey
     * @return bool
     */
    public static function tryLock(string $docType, int $branchId, string $periodKey): bool
    {
        $lockKey = self::computeLockKey($docType, $branchId, $periodKey);
        $result = DB::selectOne("SELECT pg_try_advisory_xact_lock(?) AS acquired", [$lockKey]);

        return (bool) ($result->acquired ?? false);
    }
}
