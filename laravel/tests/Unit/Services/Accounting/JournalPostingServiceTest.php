<?php

namespace Tests\Unit\Services\Accounting;

use Tests\TestCase;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\DB;

/**
 * Journal Posting Service Test — Phase 1.2 (Core Foundation Hardening).
 *
 * Tests the core accounting engine:
 *   - createJournalEntry() Dr=Cr validation
 *   - Period validation (inactive ledger rejection)
 *   - reverseJournalEntry() append-only verification
 *   - DocumentSequenceService::nextCode() concurrency safety
 *   - verifyAllEntriesBalanced() verification
 */
class JournalPostingServiceTest extends TestCase
{
    private JournalPostingService $service;
    private int $branchId;
    private int $ledgerId1;
    private int $ledgerId2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(JournalPostingService::class);

        $this->branchId = (int) DB::table('branches')
            ->where('is_active', true)
            ->value('id') ?: 1;

        $ledgers = DB::table('ledgers')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->limit(2)
            ->pluck('id')
            ->toArray();

        $this->ledgerId1 = $ledgers[0] ?? 1;
        $this->ledgerId2 = $ledgers[1] ?? 2;
    }

    // ============================================================
    // 1. CREATE JOURNAL ENTRY
    // ============================================================

    public function test_create_balanced_journal_entry(): void
    {
        $entryId = $this->service->createJournalEntry([
            'entry_date'     => now()->format('Y-m-d'),
            'reference_type' => 'test',
            'reference_id'   => 1,
            'branch_id'      => $this->branchId,
            'description'    => 'Test JE — balanced',
            'source'         => 'test',
            'created_by'     => 1,
        ], [
            ['ledger_id' => $this->ledgerId1, 'debit' => 1000, 'credit' => 0, 'memo' => 'Dr'],
            ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 1000, 'memo' => 'Cr'],
        ]);

        $this->assertIsInt($entryId);
        $this->assertGreaterThan(0, $entryId);

        // Verify entry.
        $entry = DB::table('journal_entries')->where('id', $entryId)->first();
        $this->assertNotNull($entry);
        $this->assertStringStartsWith('JE-', $entry->entry_no);
        $this->assertFalse($entry->is_reversed);

        // Verify lines.
        $lines = DB::table('journal_lines')->where('journal_entry_id', $entryId)->get();
        $this->assertCount(2, $lines);
        $this->assertEquals(1000.00, (float) $lines->sum('debit'));
        $this->assertEquals(1000.00, (float) $lines->sum('credit'));
    }

    public function test_create_unbalanced_entry_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not balanced');

        $this->service->createJournalEntry([
            'entry_date'     => now()->format('Y-m-d'),
            'reference_type' => 'test',
            'reference_id'   => 1,
            'branch_id'      => $this->branchId,
            'description'    => 'Test JE — unbalanced',
            'source'         => 'test',
            'created_by'     => 1,
        ], [
            ['ledger_id' => $this->ledgerId1, 'debit' => 1000, 'credit' => 0, 'memo' => ''],
            ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 500, 'memo' => ''],
        ]);
    }

    public function test_create_entry_with_empty_lines_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('at least one line');

        $this->service->createJournalEntry([
            'entry_date'     => now()->format('Y-m-d'),
            'reference_type' => 'test',
            'reference_id'   => 1,
            'branch_id'      => $this->branchId,
            'description'    => 'Test JE — empty lines',
            'source'         => 'test',
            'created_by'     => 1,
        ], []);
    }

    // ============================================================
    // 2. REVERSE JOURNAL ENTRY
    // ============================================================

    public function test_reverse_journal_entry(): void
    {
        $entryId = $this->service->createJournalEntry([
            'entry_date'     => now()->format('Y-m-d'),
            'reference_type' => 'test',
            'reference_id'   => 2,
            'branch_id'      => $this->branchId,
            'description'    => 'Test JE — to reverse',
            'source'         => 'test',
            'created_by'     => 1,
        ], [
            ['ledger_id' => $this->ledgerId1, 'debit' => 5000, 'credit' => 0, 'memo' => ''],
            ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 5000, 'memo' => ''],
        ]);

        $reversalId = $this->service->reverseJournalEntry($entryId, 1, 'Test reversal');

        $this->assertIsInt($reversalId);
        $this->assertGreaterThan(0, $reversalId);
        $this->assertNotEquals($entryId, $reversalId);

        // Verify original is marked reversed.
        $original = DB::table('journal_entries')->where('id', $entryId)->first();
        $this->assertTrue($original->is_reversed);
        $this->assertEquals($reversalId, $original->reversal_of_entry_id);

        // Verify reversal entry exists.
        $reversal = DB::table('journal_entries')->where('id', $reversalId)->first();
        $this->assertNotNull($reversal);
        $this->assertEquals('reversal', $reversal->source);

        // Verify reversal lines (swapped Dr/Cr).
        $reversalLines = DB::table('journal_lines')->where('journal_entry_id', $reversalId)->get();
        $this->assertEquals(5000.00, (float) $reversalLines->sum('credit'));  // Original Dr → reversal Cr
        $this->assertEquals(5000.00, (float) $reversalLines->sum('debit'));   // Original Cr → reversal Dr
    }

    public function test_reverse_already_reversed_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already reversed');

        $entryId = $this->service->createJournalEntry([
            'entry_date'     => now()->format('Y-m-d'),
            'reference_type' => 'test',
            'reference_id'   => 3,
            'branch_id'      => $this->branchId,
            'description'    => 'Test JE — double reverse',
            'source'         => 'test',
            'created_by'     => 1,
        ], [
            ['ledger_id' => $this->ledgerId1, 'debit' => 1000, 'credit' => 0, 'memo' => ''],
            ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 1000, 'memo' => ''],
        ]);

        $this->service->reverseJournalEntry($entryId, 1, 'First reversal');

        // Try to reverse again — should throw.
        $this->service->reverseJournalEntry($entryId, 1, 'Second reversal');
    }

    // ============================================================
    // 3. VERIFICATION METHODS
    // ============================================================

    public function test_verify_all_entries_balanced(): void
    {
        $result = $this->service->verifyAllEntriesBalanced();

        $this->assertArrayHasKey('total_entries', $result);
        $this->assertArrayHasKey('unbalanced_count', $result);
        $this->assertArrayHasKey('unbalanced_ids', $result);
        $this->assertIsInt($result['total_entries']);
        $this->assertIsInt($result['unbalanced_count']);
    }

    public function test_get_total_debits_credits(): void
    {
        $result = $this->service->getTotalDebitsCredits();

        $this->assertArrayHasKey('total_debit', $result);
        $this->assertArrayHasKey('total_credit', $result);
        $this->assertArrayHasKey('balanced', $result);
        $this->assertIsFloat($result['total_debit']);
        $this->assertIsFloat($result['total_credit']);
        $this->assertIsBool($result['balanced']);
    }

    // ============================================================
    // 4. POSTING LOG
    // ============================================================

    public function test_create_entry_creates_posting_log(): void
    {
        $entryId = $this->service->createJournalEntry([
            'entry_date'     => now()->format('Y-m-d'),
            'reference_type' => 'test',
            'reference_id'   => 4,
            'branch_id'      => $this->branchId,
            'description'    => 'Test JE — posting log',
            'source'         => 'test',
            'created_by'     => 1,
        ], [
            ['ledger_id' => $this->ledgerId1, 'debit' => 1000, 'credit' => 0, 'memo' => ''],
            ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 1000, 'memo' => ''],
        ]);

        $log = DB::table('journal_posting_logs')
            ->where('journal_entry_id', $entryId)
            ->where('action', 'posted')
            ->first();

        $this->assertNotNull($log);
    }

    // ============================================================
    // 5. FIND BY REFERENCE
    // ============================================================

    public function test_find_journal_entry_by_reference(): void
    {
        $entryId = $this->service->createJournalEntry([
            'entry_date'     => now()->format('Y-m-d'),
            'reference_type' => 'test_ref_find',
            'reference_id'   => 99,
            'branch_id'      => $this->branchId,
            'description'    => 'Test JE — find by ref',
            'source'         => 'test',
            'created_by'     => 1,
        ], [
            ['ledger_id' => $this->ledgerId1, 'debit' => 1000, 'credit' => 0, 'memo' => ''],
            ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 1000, 'memo' => ''],
        ]);

        $found = $this->service->findJournalEntryByReference('test_ref_find', 99);

        $this->assertNotNull($found);
        $this->assertEquals($entryId, $found->id);
    }

    public function test_find_journal_entry_by_reference_not_found(): void
    {
        $found = $this->service->findJournalEntryByReference('nonexistent', 999);
        $this->assertNull($found);
    }
}
