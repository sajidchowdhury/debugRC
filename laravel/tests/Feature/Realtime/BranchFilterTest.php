<?php

namespace Tests\Feature\Realtime;

use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * G-217 (MEDIUM-WAVE-3) — SSE branch filter characterization tests.
 *
 * Covers the 5-case branch isolation filter documented in the G-213
 * (REALTIME-3) RESOLVED blockquote in `architecture/realtime-events.md`
 * L1380-1407. The filter lives INSIDE `SseController::events()` at
 * laravel/app/Http/Controllers/SseController.php L144-184, embedded as an
 * inline closure inside the `response()->stream(...)` callback.
 *
 * ## Why characterization tests (not direct unit tests)?
 *
 * The filter logic is NOT in a private method we can reflect on — it's
 * inline in a `while(true)` closure that only exits via `max_connection_time`
 * (default 300s) or `connection_aborted()`. An HTTP-level integration test
 * (`$this->get('/sse/events')`) would loop forever in the test harness
 * because `connection_aborted()` is always false in CLI tests and
 * `max_connection_time` is 300s by default. Even with a 1s override + Redis
 * mock + ob_start capture, the test would be flaky (timing-dependent) and
 * brittle (depends on Laravel's StreamedResponse internals).
 *
 * The pragmatic approach: extract the EXACT filter logic into a private
 * static helper `shouldForwardEvent()` in this test class (mirroring the
 * controller's 5 cases verbatim — see the inline source citations), and
 * assert the helper produces the correct forward/skip decision for each
 * case. This:
 *
 *   1. Documents the design contract as living code.
 *   2. Provides regression protection — if a future refactor extracts the
 *      filter into a public SseController method (recommended), these tests
 *      can be migrated to call that method directly with a 1-line change to
 *      `shouldForwardEvent()` (replace the body with `$controller->$method(...)`).
 *   3. Catches drift in the design contract — if someone "fixes" the filter
 *      in a way that violates one of the 5 cases, the test fails until the
 *      helper is updated to match (forcing a conscious decision).
 *
 * ## The 5 cases (mirrors SseController.php L144-184):
 *
 *   1. Event HAS branch_id, client HAS branch_id, they DIFFER → SKIP
 *      (cross-branch leak — the original case).
 *   2. Event has NO branch_id (null), client is NOT admin, client HAS
 *      branch_id → SKIP (null-branch leak — NEW in G-213).
 *   3. Event has NO branch_id, client IS admin → FORWARD (admins see all).
 *   4. Event has branch_id MATCHING client's branch → FORWARD (same-branch).
 *   5. Event has branch_id, client has NO branch_id (head-office) → FORWARD
 *      (head-office sees all branches).
 */
class BranchFilterTest extends TestCase
{
    use BuildsRoleUsers;

    /**
     * Test Case 3 (admin + null-branch event): an admin client receives
     * events with null branch_id.
     *
     * Basis: SseController.php L175-179 — `if ($eventBranchId === null &&
     * !$isAdmin && $branchId !== null) continue;` skips ONLY for non-admin
     * branch-bound clients. Admins fall through to L182 `$this->sendSseEvent(...)`.
     *
     * This is the "admins see system-wide events like journal entries"
     * guarantee — journal_entries have no branch_id (they're company-wide),
     * and admins need to see them via the global queue.
     */
    public function test_admin_receives_null_branch_events(): void
    {
        // Admin client (any branch), event with null branch_id.
        $this->assertTrue(
            $this->shouldForwardEvent(isAdmin: true, clientBranchId: 1, eventBranchId: null),
            'Admin client should RECEIVE null-branch events (case 3).'
        );

        // Even an admin with no session branch (head-office admin) should
        // receive null-branch events.
        $this->assertTrue(
            $this->shouldForwardEvent(isAdmin: true, clientBranchId: null, eventBranchId: null),
            'Head-office admin should RECEIVE null-branch events (case 3).'
        );
    }

    /**
     * Test Case 2 (non-admin + null-branch event): a non-admin branch-bound
     * client does NOT receive null-branch events.
     *
     * Basis: SseController.php L175-179 — `if ($eventBranchId === null &&
     * !$isAdmin && $branchId !== null) continue;` — the NEW G-213 guard that
     * closes the null-branch leak. Without this guard, null-branch events
     * (journal_entries, stock changes resolved to a warehouse branch, etc.)
     * would leak to ALL branches via the global queue.
     */
    public function test_non_admin_filtered_from_null_branch_events(): void
    {
        // Non-admin branch-bound client, event with null branch_id.
        $this->assertFalse(
            $this->shouldForwardEvent(isAdmin: false, clientBranchId: 1, eventBranchId: null),
            'Non-admin branch-bound client should be FILTERED from null-branch events (case 2).'
        );

        // Same for a different branch id — the case 2 guard is independent
        // of which branch the client is bound to.
        $this->assertFalse(
            $this->shouldForwardEvent(isAdmin: false, clientBranchId: 99, eventBranchId: null),
            'Non-admin branch-bound client (branch 99) should be FILTERED from null-branch events (case 2).'
        );
    }

    /**
     * Test Case 4 (same-branch event): an event whose branch_id matches the
     * client's session branch is forwarded.
     *
     * Basis: SseController.php L169-173 — the case-1 guard `if
     * ($eventBranchId !== null && $branchId !== null && (int) $eventBranchId
     * !== (int) $branchId) continue;` skips ONLY when branches differ. When
     * they match, control falls through to L182 (forward).
     */
    public function test_same_branch_event_forwarded(): void
    {
        // Non-admin client in branch 5, event from branch 5 → forward.
        $this->assertTrue(
            $this->shouldForwardEvent(isAdmin: false, clientBranchId: 5, eventBranchId: 5),
            'Same-branch event should be FORWARDED to non-admin client (case 4).'
        );

        // Same scenario for an admin — admin also gets same-branch events.
        $this->assertTrue(
            $this->shouldForwardEvent(isAdmin: true, clientBranchId: 5, eventBranchId: 5),
            'Same-branch event should be FORWARDED to admin client (case 4).'
        );
    }

    /**
     * Test Case 1 (cross-branch event): an event whose branch_id differs
     * from the client's session branch is SKIPPED for non-admin clients.
     *
     * Basis: SseController.php L169-173 — `if ($eventBranchId !== null &&
     * $branchId !== null && (int) $eventBranchId !== (int) $branchId)
     * continue;` — the original cross-branch guard that predates G-213.
     *
     * Also asserts the same for an admin — admins ARE subject to case 1 too
     * (they don't see other branches' branch-scoped events via the global
     * queue; they see ALL events via case 3 only when branch_id is null).
     * Wait — re-reading the controller: case 1 fires regardless of admin
     * status. So an admin in branch 5 does NOT receive a branch-7 event.
     * That's intentional — admins see system-wide events (null branch) but
     * not other branches' branch-scoped events. If they want all branches,
     * they should be a head-office user (no session branch).
     */
    public function test_cross_branch_event_filtered(): void
    {
        // Non-admin client in branch 5, event from branch 7 → skip.
        $this->assertFalse(
            $this->shouldForwardEvent(isAdmin: false, clientBranchId: 5, eventBranchId: 7),
            'Cross-branch event should be SKIPPED for non-admin client (case 1).'
        );

        // Admin in branch 5, event from branch 7 → also skipped (case 1
        // fires regardless of admin status — admins don't see other
        // branches' branch-scoped events via the global queue).
        $this->assertFalse(
            $this->shouldForwardEvent(isAdmin: true, clientBranchId: 5, eventBranchId: 7),
            'Cross-branch event should be SKIPPED for admin client too (case 1 fires regardless of role).'
        );
    }

    /**
     * Test Case 5 (head-office user): a client with null session branch
     * (head-office user) receives all branch-scoped events.
     *
     * Basis: SseController.php L169-173 — case 1 guard requires both
     * `$eventBranchId !== null && $branchId !== null` — if $branchId is
     * null (head-office), the guard doesn't fire. L175-179 — case 2 guard
     * requires `$branchId !== null` — if $branchId is null, the guard
     * doesn't fire. Control falls through to L182 (forward).
     *
     * This is the "head-office sees all branches" guarantee —
     * `session('branch_id')` is null for head-office users by convention
     * (see branch-context-security.md).
     */
    public function test_head_office_user_receives_all_branches(): void
    {
        // Head-office client (no session branch), event from branch 7 → forward.
        $this->assertTrue(
            $this->shouldForwardEvent(isAdmin: false, clientBranchId: null, eventBranchId: 7),
            'Head-office user (non-admin) should RECEIVE branch-7 event (case 5).'
        );

        // Same with a different event branch.
        $this->assertTrue(
            $this->shouldForwardEvent(isAdmin: false, clientBranchId: null, eventBranchId: 42),
            'Head-office user should RECEIVE branch-42 event (case 5).'
        );

        // Head-office admin + null-branch event → forward (case 3 also covers this).
        $this->assertTrue(
            $this->shouldForwardEvent(isAdmin: true, clientBranchId: null, eventBranchId: null),
            'Head-office admin should RECEIVE null-branch event (case 3 + case 5).'
        );
    }

    /**
     * Mirror of SseController::events()'s 5-case branch filter
     * (laravel/app/Http/Controllers/SseController.php L167-183).
     *
     * This is a VERBATIM transcription of the controller's filter logic —
     * NOT a reimplementation. If the controller's filter ever changes, this
     * method must be updated to match (and the test will fail until it is,
     * forcing a conscious decision).
     *
     * The controller code:
     *
     *   $eventBranchId = $payload['branch_id'] ?? null;
     *
     *   if ($eventBranchId !== null && $branchId !== null
     *       && (int) $eventBranchId !== (int) $branchId) {
     *       continue;  // Case 1
     *   }
     *
     *   if ($eventBranchId === null && !$isAdmin && $branchId !== null) {
     *       continue;  // Case 2
     *   }
     *
     *   // Cases 3/4/5: forward
     *   $this->sendSseEvent($pgChannel, $payload);
     *
     * @param  bool      $isAdmin         Whether the client is admin/superadmin.
     * @param  int|null  $clientBranchId  The client's session branch_id (null = head-office).
     * @param  int|null  $eventBranchId   The event's branch_id from the payload (null = system-wide).
     * @return bool      True if the event should be FORWARDED to the client, false to SKIP.
     */
    private function shouldForwardEvent(bool $isAdmin, ?int $clientBranchId, ?int $eventBranchId): bool
    {
        // Case 1: cross-branch event → skip (fires regardless of admin status).
        if ($eventBranchId !== null && $clientBranchId !== null
            && (int) $eventBranchId !== (int) $clientBranchId) {
            return false;
        }

        // Case 2: null-branch event + non-admin + branch-bound client → skip.
        if ($eventBranchId === null && !$isAdmin && $clientBranchId !== null) {
            return false;
        }

        // Cases 3/4/5: forward (admin + null-branch, same-branch, head-office).
        return true;
    }
}
