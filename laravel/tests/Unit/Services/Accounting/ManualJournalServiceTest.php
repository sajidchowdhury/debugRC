<?php

namespace Tests\Unit\Services\Accounting;

use Tests\TestCase;
use App\Services\Accounting\ManualJournalService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\AccountingPeriodService;
use App\Services\Accounting\DocumentSequenceService;
use App\Models\ManualJournal;
use App\Models\ManualJournalLine;
use Illuminate\Support\Facades\DB;

/**
 * Manual Journal Service Test — Phase 1.2 (Core Foundation Hardening).
 *
 * Tests the full lifecycle of manual journal entries:
 *   - createJournal() with draft and post variants
 *   - postJournal() draft-to-post conversion
 *   - reverseJournal() with GL + sub-ledger cascade
 *   - Dr=Cr validation
 *   - Period validation
 *   - Line persistence for drafts
 *   - Draft-to-post workflow
 */
class ManualJournalServiceTest extends TestCase
{
    private ManualJournalService $service;
    private int $branchId;
    private int $ledgerId1;
    private int $ledgerId2;
    private int $ledgerId3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ManualJournalService::class);

        // Get a valid branch ID from the database.
        $this->branchId = (int) DB::table('branches')
            ->where('is_active', true)
            ->value('id') ?: 1;

        // Get active ledgers for testing.
        $ledgers = DB::table('ledgers')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->limit(3)
            ->pluck('id')
            ->toArray();

        $this->ledgerId1 = $ledgers[0] ?? 1;
        $this->ledgerId2 = $ledgers[1] ?? 2;
        $this->ledgerId3 = $ledgers[2] ?? 3;
    }

    // ============================================================
    // 1. CREATE JOURNAL (posted)
    // ============================================================

    public function test_create_journal_posted_immediately(): void
    {
        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — posted immediately',
            'post'         => true,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 1000, 'credit' => 0, 'description' => 'Debit line'],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 1000, 'description' => 'Credit line'],
            ],
            'created_by'   => 1,
        ]);

        $this->assertInstanceOf(ManualJournal::class, $journal);
        $this->assertEquals('posted', $journal->status);
        $this->assertEquals(1000.00, (float) $journal->total_debit);
        $this->assertEquals(1000.00, (float) $journal->total_credit);
        $this->assertNotNull($journal->journal_entry_id);
        $this->assertStringStartsWith('MJ-', $journal->journal_code);
    }

    public function test_create_journal_posted_creates_gl_entry(): void
    {
        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — GL entry check',
            'post'         => true,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 5000, 'credit' => 0, 'description' => ''],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 5000, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);

        // Verify GL journal entry exists.
        $je = DB::table('journal_entries')->where('id', $journal->journal_entry_id)->first();
        $this->assertNotNull($je);
        $this->assertEquals('manual_journal', $je->reference_type);
        $this->assertEquals($journal->id, $je->reference_id);
        $this->assertFalse($je->is_reversed);

        // Verify GL journal lines.
        $lines = DB::table('journal_lines')->where('journal_entry_id', $je->id)->get();
        $this->assertCount(2, $lines);
        $this->assertEquals(5000.00, (float) $lines->sum('debit'));
        $this->assertEquals(5000.00, (float) $lines->sum('credit'));
    }

    public function test_create_journal_posted_persists_manual_journal_lines(): void
    {
        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — line persistence',
            'post'         => true,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 3000, 'credit' => 0, 'description' => 'Dr line'],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 3000, 'description' => 'Cr line'],
            ],
            'created_by'   => 1,
        ]);

        // Verify manual_journal_lines were persisted.
        $mjLines = DB::table('manual_journal_lines')
            ->where('manual_journal_id', $journal->id)
            ->get();

        $this->assertCount(2, $mjLines);
        $this->assertEquals('posted', $mjLines[0]->status);
        $this->assertEquals('posted', $mjLines[1]->status);
        $this->assertNotNull($mjLines[0]->journal_line_id);
        $this->assertNotNull($mjLines[1]->journal_line_id);
    }

    // ============================================================
    // 2. CREATE JOURNAL (draft)
    // ============================================================

    public function test_create_journal_draft(): void
    {
        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — draft',
            'post'         => false,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 2000, 'credit' => 0, 'description' => ''],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 2000, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);

        $this->assertInstanceOf(ManualJournal::class, $journal);
        $this->assertEquals('draft', $journal->status);
        $this->assertNull($journal->journal_entry_id);
        $this->assertEquals(2000.00, (float) $journal->total_debit);
        $this->assertEquals(2000.00, (float) $journal->total_credit);
    }

    public function test_create_journal_draft_persists_lines(): void
    {
        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — draft lines',
            'post'         => false,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 4000, 'credit' => 0, 'description' => 'Dr'],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 4000, 'description' => 'Cr'],
            ],
            'created_by'   => 1,
        ]);

        // Verify draft lines are persisted in manual_journal_lines.
        $mjLines = DB::table('manual_journal_lines')
            ->where('manual_journal_id', $journal->id)
            ->get();

        $this->assertCount(2, $mjLines);
        $this->assertEquals('draft', $mjLines[0]->status);
        $this->assertEquals('draft', $mjLines[1]->status);
        $this->assertNull($mjLines[0]->journal_line_id);
        $this->assertNull($mjLines[1]->journal_line_id);
    }

    public function test_create_journal_draft_does_not_create_gl_entry(): void
    {
        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — no GL for draft',
            'post'         => false,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 1500, 'credit' => 0, 'description' => ''],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 1500, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);

        // No GL journal entry should exist for a draft.
        $this->assertNull($journal->journal_entry_id);
        $jeCount = DB::table('journal_entries')
            ->where('reference_type', 'manual_journal')
            ->where('reference_id', $journal->id)
            ->count();
        $this->assertEquals(0, $jeCount);
    }

    // ============================================================
    // 3. POST DRAFT (draft → posted)
    // ============================================================

    public function test_post_draft_journal(): void
    {
        // Create a draft.
        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — post draft',
            'post'         => false,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 7500, 'credit' => 0, 'description' => ''],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 7500, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);

        $this->assertEquals('draft', $journal->status);

        // Post the draft.
        $posted = $this->service->postJournal($journal->id, 1);

        $this->assertEquals('posted', $posted->status);
        $this->assertNotNull($posted->journal_entry_id);
        $this->assertEquals(7500.00, (float) $posted->total_debit);
        $this->assertEquals(7500.00, (float) $posted->total_credit);
    }

    public function test_post_draft_journal_creates_gl_entry(): void
    {
        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — post draft GL',
            'post'         => false,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 6000, 'credit' => 0, 'description' => ''],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 6000, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);

        $posted = $this->service->postJournal($journal->id, 1);

        // Verify GL entry.
        $je = DB::table('journal_entries')->where('id', $posted->journal_entry_id)->first();
        $this->assertNotNull($je);
        $this->assertEquals('manual_journal', $je->reference_type);

        // Verify GL lines.
        $glLines = DB::table('journal_lines')->where('journal_entry_id', $je->id)->get();
        $this->assertCount(2, $glLines);
    }

    public function test_post_draft_journal_updates_line_status(): void
    {
        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — line status update',
            'post'         => false,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 2500, 'credit' => 0, 'description' => ''],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 2500, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);

        $this->service->postJournal($journal->id, 1);

        // Verify all lines are now 'posted' and linked to GL.
        $mjLines = DB::table('manual_journal_lines')
            ->where('manual_journal_id', $journal->id)
            ->get();

        foreach ($mjLines as $line) {
            $this->assertEquals('posted', $line->status);
            $this->assertNotNull($line->journal_line_id);
        }
    }

    public function test_post_non_draft_journal_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only draft journals can be posted');

        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — already posted',
            'post'         => true,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 1000, 'credit' => 0, 'description' => ''],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 1000, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);

        // Try to post an already-posted journal — should throw.
        $this->service->postJournal($journal->id, 1);
    }

    // ============================================================
    // 4. REVERSE JOURNAL (posted → reversed)
    // ============================================================

    public function test_reverse_posted_journal(): void
    {
        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — reverse',
            'post'         => true,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 8000, 'credit' => 0, 'description' => ''],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 8000, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);

        $reversed = $this->service->reverseJournal($journal->id, 1, 'Test reversal reason');

        $this->assertEquals('reversed', $reversed->status);
        $this->assertNotNull($reversed->reversed_at);
        $this->assertEquals('Test reversal reason', $reversed->reverse_reason);
    }

    public function test_reverse_creates_reversal_gl_entry(): void
    {
        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — reversal GL',
            'post'         => true,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 9000, 'credit' => 0, 'description' => ''],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 9000, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);

        $this->service->reverseJournal($journal->id, 1, 'Reversal GL check');

        // Verify original is marked reversed.
        $original = DB::table('journal_entries')->where('id', $journal->journal_entry_id)->first();
        $this->assertTrue($original->is_reversed);

        // Verify reversal entry exists.
        $reversal = DB::table('journal_entries')
            ->where('reversal_of_entry_id', $journal->journal_entry_id)
            ->first();
        $this->assertNotNull($reversal);
        $this->assertEquals('reversal', $reversal->source);
    }

    public function test_reverse_non_posted_journal_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only posted journals can be reversed');

        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — cannot reverse draft',
            'post'         => false,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 1000, 'credit' => 0, 'description' => ''],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 1000, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);

        $this->service->reverseJournal($journal->id, 1, 'Should fail');
    }

    public function test_reverse_requires_reason(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reversal reason is required');

        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — reason required',
            'post'         => true,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 1000, 'credit' => 0, 'description' => ''],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 1000, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);

        $this->service->reverseJournal($journal->id, 1, 'AB');  // Too short (< 3 chars)
    }

    // ============================================================
    // 5. BALANCE VALIDATION
    // ============================================================

    public function test_create_unbalanced_journal_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not balanced');

        $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — unbalanced',
            'post'         => true,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 1000, 'credit' => 0, 'description' => ''],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 500, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);
    }

    public function test_create_unbalanced_draft_is_allowed(): void
    {
        // Drafts can be unbalanced — the accountant is still working.
        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — unbalanced draft',
            'post'         => false,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 1000, 'credit' => 0, 'description' => ''],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 500, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);

        $this->assertEquals('draft', $journal->status);
        $this->assertEquals(1000.00, (float) $journal->total_debit);
        $this->assertEquals(500.00, (float) $journal->total_credit);
    }

    public function test_post_unbalanced_draft_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not balanced');

        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — post unbalanced draft',
            'post'         => false,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 1000, 'credit' => 0, 'description' => ''],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 500, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);

        $this->service->postJournal($journal->id, 1);
    }

    // ============================================================
    // 6. LINE VALIDATION
    // ============================================================

    public function test_create_journal_with_both_debit_and_credit_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot have both debit and credit');

        $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — both Dr/Cr',
            'post'         => true,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 1000, 'credit' => 500, 'description' => ''],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 500, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);
    }

    public function test_create_journal_with_less_than_2_lines_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('At least 2 journal lines are required');

        $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — single line',
            'post'         => true,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 1000, 'credit' => 0, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);
    }

    // ============================================================
    // 7. MULTI-LINE JOURNALS
    // ============================================================

    public function test_create_multi_line_journal(): void
    {
        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — multi-line',
            'post'         => true,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 5000, 'credit' => 0, 'description' => 'Dr 1'],
                ['ledger_id' => $this->ledgerId2, 'debit' => 3000, 'credit' => 0, 'description' => 'Dr 2'],
                ['ledger_id' => $this->ledgerId3, 'debit' => 0, 'credit' => 8000, 'description' => 'Cr 1'],
            ],
            'created_by'   => 1,
        ]);

        $this->assertEquals('posted', $journal->status);
        $this->assertEquals(8000.00, (float) $journal->total_debit);
        $this->assertEquals(8000.00, (float) $journal->total_credit);

        // Verify 3 GL lines.
        $glLines = DB::table('journal_lines')
            ->where('journal_entry_id', $journal->journal_entry_id)
            ->count();
        $this->assertEquals(3, $glLines);

        // Verify 3 manual_journal_lines.
        $mjLines = DB::table('manual_journal_lines')
            ->where('manual_journal_id', $journal->id)
            ->count();
        $this->assertEquals(3, $mjLines);
    }

    // ============================================================
    // 8. FULL LIFECYCLE: draft → post → reverse
    // ============================================================

    public function test_full_lifecycle_draft_post_reverse(): void
    {
        // Step 1: Create draft.
        $journal = $this->service->createJournal([
            'journal_date' => now()->format('Y-m-d'),
            'branch_id'    => $this->branchId,
            'description'  => 'Test journal — full lifecycle',
            'post'         => false,
            'lines'        => [
                ['ledger_id' => $this->ledgerId1, 'debit' => 12000, 'credit' => 0, 'description' => ''],
                ['ledger_id' => $this->ledgerId2, 'debit' => 0, 'credit' => 12000, 'description' => ''],
            ],
            'created_by'   => 1,
        ]);

        $this->assertEquals('draft', $journal->status);
        $this->assertNull($journal->journal_entry_id);

        // Verify draft lines exist.
        $draftLines = DB::table('manual_journal_lines')
            ->where('manual_journal_id', $journal->id)
            ->where('status', 'draft')
            ->count();
        $this->assertEquals(2, $draftLines);

        // Step 2: Post the draft.
        $posted = $this->service->postJournal($journal->id, 1);
        $this->assertEquals('posted', $posted->status);
        $this->assertNotNull($posted->journal_entry_id);

        // Verify GL entry exists.
        $je = DB::table('journal_entries')->where('id', $posted->journal_entry_id)->first();
        $this->assertNotNull($je);
        $this->assertFalse($je->is_reversed);

        // Verify lines are now 'posted'.
        $postedLines = DB::table('manual_journal_lines')
            ->where('manual_journal_id', $journal->id)
            ->where('status', 'posted')
            ->count();
        $this->assertEquals(2, $postedLines);

        // Step 3: Reverse the posted journal.
        $reversed = $this->service->reverseJournal($journal->id, 1, 'Full lifecycle reversal test');
        $this->assertEquals('reversed', $reversed->status);

        // Verify original GL entry is reversed.
        $originalJe = DB::table('journal_entries')->where('id', $posted->journal_entry_id)->first();
        $this->assertTrue($originalJe->is_reversed);

        // Verify reversal GL entry exists.
        $reversalJe = DB::table('journal_entries')
            ->where('reversal_of_entry_id', $posted->journal_entry_id)
            ->first();
        $this->assertNotNull($reversalJe);
    }
}
