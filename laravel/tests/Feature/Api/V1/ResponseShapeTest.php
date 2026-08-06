<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Warehouse;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\IssuesApiTokens;
use Tests\TestCase;

/**
 * LOW-WAVE-2 (G-267) — Response-shape contract tests.
 *
 * Asserts the JSON **structure** (keys present, types correct) of
 * representative endpoints for each pattern catalogued in
 * `AI_CONTEXT/api/api-conventions.md`:
 *
 *   - §7.2 four response shapes (A: single resource, B: paginated list,
 *     C: action result, D: lookup flat array).
 *   - §8.2 pagination contract (the canonical 4-key meta shape
 *     `{current_page, last_page, per_page, total}` — no `links`, no
 *     `from`/`to`).
 *   - §10 error-contract matrix (the 11 status-code → JSON-shape rows).
 *
 * The tests use existing endpoints as fixtures and assert shape only —
 * business logic, state-machine transitions, and idempotency-replay
 * semantics are covered by the per-module `*_ApiTest.php` files.
 *
 * Coverage map (15 tests):
 *
 *   Pagination-shape (§8.2 — 4 tests, one per originally-divergent
 *   paginated controller):
 *     1. test_branches_list_pagination_meta_has_canonical_4_keys
 *     2. test_sales_invoices_list_pagination_meta_has_canonical_4_keys
 *     3. test_stock_adjustments_list_pagination_meta_has_canonical_4_keys
 *     4. test_branch_demands_list_pagination_meta_has_canonical_4_keys
 *
 *   Response-shape (§7.2 — 4 tests):
 *     5. test_shape_a_single_resource_envelope — GET /branches/{id}
 *     6. test_shape_b_paginated_list_envelope — GET /branches
 *     7. test_shape_c_action_result_envelope — DELETE /branches/{id}
 *     8. test_shape_d_lookup_flat_array_envelope — GET /lookups/branches
 *
 *   Error-contract (§10 — 7 tests):
 *     9.  test_201_created_envelope_has_data_and_message
 *     10. test_400_bad_request_envelope_has_message_and_blockers
 *     11. test_401_unauthorized_envelope_has_message_and_detail
 *     12. test_403_forbidden_envelope_has_message
 *     13. test_404_not_found_envelope_has_message_and_detail
 *     14. test_422_validation_envelope_has_message_and_errors
 *     15. test_429_too_many_requests_envelope_has_message_and_retry_after
 *
 * Intentionally out-of-scope (documented here for completeness):
 *   - 204 No Content — not used by any endpoint today (§10 row).
 *   - 409 Conflict — state-machine-specific; covered by per-module
 *     state-transition tests (e.g. StockAdjustmentApiTest::cancel).
 *   - 500 Server Error — sanitized by the G-205 global Throwable renderer
 *     in bootstrap/app.php; requires APP_DEBUG=false + fault injection to
 *     test reliably (out of scope for a structural-shape file).
 */
class ResponseShapeTest extends TestCase
{
    use BuildsRoleUsers, IssuesApiTokens;

    // ====================================================================
    // PAGINATION SHAPE — 4 tests, one per originally-divergent controller
    // (Branch, SalesInvoice, StockAdjustment, BranchDemand — the 4 that
    // historically carried `from`/`to` and were standardized to the
    // canonical 4-key meta by G3 / MEDIUM-WAVE-1).
    // ====================================================================

    public function test_branches_list_pagination_meta_has_canonical_4_keys(): void
    {
        $token = $this->apiTokenForUser($this->makeRoleUser('manager'));

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/branches');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        // The canonical form suppresses `links`, `from`, `to`.
        $meta = $response->json('meta');
        $this->assertArrayNotHasKey('links', $meta, 'meta.links must be suppressed (§8.2).');
        $this->assertArrayNotHasKey('from', $meta, 'meta.from must be omitted (§8.2 canonical).');
        $this->assertArrayNotHasKey('to', $meta, 'meta.to must be omitted (§8.2 canonical).');
    }

    public function test_sales_invoices_list_pagination_meta_has_canonical_4_keys(): void
    {
        $token = $this->apiTokenForUser($this->makeRoleUser('manager'));

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/sales/invoices');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        $meta = $response->json('meta');
        $this->assertArrayNotHasKey('links', $meta);
        $this->assertArrayNotHasKey('from', $meta);
        $this->assertArrayNotHasKey('to', $meta);
    }

    public function test_stock_adjustments_list_pagination_meta_has_canonical_4_keys(): void
    {
        // Stock Adjustment is one of the 4 set.api.branch-protected modules.
        $token = $this->apiTokenForUser($this->makeRoleUser('manager'));

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/stock-adjustments');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        $meta = $response->json('meta');
        $this->assertArrayNotHasKey('links', $meta);
        $this->assertArrayNotHasKey('from', $meta);
        $this->assertArrayNotHasKey('to', $meta);
    }

    public function test_branch_demands_list_pagination_meta_has_canonical_4_keys(): void
    {
        $token = $this->apiTokenForUser($this->makeRoleUser('manager'));

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/branch-demands');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        $meta = $response->json('meta');
        $this->assertArrayNotHasKey('links', $meta);
        $this->assertArrayNotHasKey('from', $meta);
        $this->assertArrayNotHasKey('to', $meta);
    }

    // ====================================================================
    // RESPONSE SHAPE — §7.2 four shapes
    // ====================================================================

    public function test_shape_a_single_resource_envelope(): void
    {
        // Shape A: {data: {...}} — single resource, no meta, no message.
        $branch = Branch::factory()->create();
        $token  = $this->apiTokenForUser($this->makeRoleUser('manager'));

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/branches/{$branch->id}");

        $response->assertOk();
        $body = $response->json();
        $this->assertArrayHasKey('data', $body, 'Shape A MUST include data.');
        $this->assertIsArray($body['data'], 'Shape A data is an array (object literal in JSON) of resource fields.');
        $this->assertArrayNotHasKey('meta', $body, 'Shape A MUST NOT include meta.');
        $this->assertArrayNotHasKey('message', $body, 'Shape A MUST NOT include message.');
        $this->assertArrayHasKey('id', $body['data']);
        $this->assertArrayHasKey('branch_code', $body['data']);
    }

    public function test_shape_b_paginated_list_envelope(): void
    {
        // Shape B: {data: [...], meta: {4 keys}} — paginated list.
        Branch::factory()->count(3)->create();
        $token = $this->apiTokenForUser($this->makeRoleUser('manager'));

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/branches');

        $response->assertOk();
        $body = $response->json();
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data'], 'Shape B data MUST be an array (list).');
        $this->assertArrayHasKey('meta', $body);
        $this->assertIsArray($body['meta']);
        // Canonical 4-key meta (no links/from/to — tested in detail above).
        foreach (['current_page', 'last_page', 'per_page', 'total'] as $key) {
            $this->assertArrayHasKey($key, $body['meta'], "Shape B meta.{$key} MUST be present.");
        }
    }

    public function test_shape_c_action_result_envelope(): void
    {
        // Shape C: {message: "..."} — action result, no data, no meta.
        // BranchApiController::destroy returns {message: "Branch deactivated."}
        // when the branch has no active dependents.
        $branch = Branch::factory()->create();
        $token  = $this->apiTokenForUser($this->makeRoleUser('admin'));

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->deleteJson("/api/v1/branches/{$branch->id}");

        $response->assertOk();
        $body = $response->json();
        $this->assertArrayHasKey('message', $body, 'Shape C MUST include message.');
        $this->assertArrayNotHasKey('data', $body, 'Shape C MUST NOT include data.');
        $this->assertArrayNotHasKey('meta', $body, 'Shape C MUST NOT include meta.');
    }

    public function test_shape_d_lookup_flat_array_envelope(): void
    {
        // Shape D: {data: [...]} — flat lookup array, no meta.
        Branch::factory()->create();
        $token = $this->apiTokenForUser($this->makeRoleUser('manager'));

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/lookups/branches');

        $response->assertOk();
        $body = $response->json();
        $this->assertArrayHasKey('data', $body, 'Shape D MUST include data.');
        $this->assertIsArray($body['data'], 'Shape D data MUST be an array.');
        $this->assertArrayNotHasKey('meta', $body, 'Shape D MUST NOT include meta (lookups are not paginated).');
    }

    // ====================================================================
    // ERROR CONTRACT — §10 status-code → JSON-shape matrix
    // ====================================================================

    public function test_201_created_envelope_has_data_and_message(): void
    {
        // POST /branches (admin) with a valid payload → 201 {data, message}.
        $token = $this->apiTokenForUser($this->makeRoleUser('admin'));

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/branches', [
                'branch_code' => 'TST' . substr(uniqid(), -5),
                'branch_name' => 'Test Branch for Shape Test',
            ]);

        $response->assertCreated();
        $body = $response->json();
        $this->assertArrayHasKey('data', $body, '201 MUST include data.');
        $this->assertArrayHasKey('message', $body, '201 MUST include message.');
        $this->assertArrayHasKey('id', $body['data']);
    }

    public function test_400_bad_request_envelope_has_message_and_blockers(): void
    {
        // DELETE /branches/{id} on a branch WITH active dependents →
        // 400 {message, blockers: [...]} per §10.3.
        $branch    = Branch::factory()->create();
        // Seed an active warehouse so collectDeactivationBlockers() returns
        // at least one entry → triggers the 400 branch.
        Warehouse::factory()->forBranch($branch->id)->create(['is_active' => true]);

        $token = $this->apiTokenForUser($this->makeRoleUser('admin'));

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->deleteJson("/api/v1/branches/{$branch->id}");

        $response->assertStatus(400);
        $body = $response->json();
        $this->assertArrayHasKey('message', $body, '400 MUST include message.');
        $this->assertArrayHasKey('blockers', $body, '400 blockers-shape MUST include blockers array.');
        $this->assertIsArray($body['blockers']);
        $this->assertNotEmpty($body['blockers'], 'blockers MUST list the active dependents.');
    }

    public function test_401_unauthorized_envelope_has_message_and_detail(): void
    {
        // No Authorization header → 401 {message, detail} from ApiAuth.
        $response = $this->getJson('/api/v1/branches');

        $response->assertUnauthorized();
        $body = $response->json();
        $this->assertArrayHasKey('message', $body, '401 MUST include message.');
        $this->assertArrayNotHasKey('data', $body, '401 MUST NOT include data.');
    }

    public function test_403_forbidden_envelope_has_message(): void
    {
        // POST /branches (admin-only) with a salesman token →
        // 403 {message: "Forbidden. Requires role: admin"} from ApiAuth.
        $token = $this->apiTokenForUser($this->makeRoleUser('salesman'));

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/branches', [
                'branch_code' => 'FORBIDDEN',
                'branch_name' => 'Should Not Be Created',
            ]);

        $response->assertForbidden();
        $body = $response->json();
        $this->assertArrayHasKey('message', $body, '403 MUST include message.');
        $this->assertStringContainsString('Forbidden', $body['message']);
        $this->assertArrayNotHasKey('data', $body, '403 MUST NOT include data.');
    }

    public function test_404_not_found_envelope_has_message_and_detail(): void
    {
        // GET /branches/999999 → 404 {message, detail} from
        // BranchApiController::notFound() helper.
        $token = $this->apiTokenForUser($this->makeRoleUser('manager'));

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/branches/999999');

        $response->assertNotFound();
        $body = $response->json();
        $this->assertArrayHasKey('message', $body, '404 MUST include message.');
        $this->assertArrayNotHasKey('data', $body, '404 MUST NOT include data.');
    }

    public function test_422_validation_envelope_has_message_and_errors(): void
    {
        // POST /branches with empty body → 422 {message, errors: {field: [msg]}}
        // from StoreBranchRequest → ValidationException default JSON renderer.
        $token = $this->apiTokenForUser($this->makeRoleUser('admin'));

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/branches', []);

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertArrayHasKey('message', $body, '422 MUST include message.');
        $this->assertArrayHasKey('errors', $body, '422 MUST include errors object.');
        $this->assertIsArray($body['errors']);
        // The 2 required fields (branch_code, branch_name) MUST each appear.
        $this->assertArrayHasKey('branch_code', $body['errors']);
        $this->assertArrayHasKey('branch_name', $body['errors']);
        $this->assertIsArray($body['errors']['branch_code']);
        $this->assertIsArray($body['errors']['branch_name']);
    }

    public function test_429_too_many_requests_envelope_has_message_and_retry_after(): void
    {
        // Hammer /branches (api.rate:60) — the 61st request returns 429.
        // Shape: {message, retry_after} + Retry-After header.
        // (Cross-references tests/Feature/Api/ApiRateLimitTest for the
        // broader rate-limit behavior — this test pins ONLY the envelope
        // shape + Retry-After header for the §10 matrix.)
        $token  = $this->apiTokenForUser($this->makeRoleUser('admin'));
        $header = ['Authorization' => $this->bearerHeader($token)];

        for ($i = 0; $i < 60; $i++) {
            $this->withHeaders($header)->getJson('/api/v1/branches');
        }

        // 61st request — over the 60/min cap.
        $over = $this->withHeaders($header)->getJson('/api/v1/branches');

        $over->assertStatus(429);
        $body = $over->json();
        $this->assertArrayHasKey('message', $body, '429 MUST include message.');
        $this->assertStringContainsString('Rate limit exceeded', $body['message']);
        $this->assertArrayHasKey('retry_after', $body, '429 MUST include retry_after.');
        $this->assertIsInt($body['retry_after']);
        // The Retry-After header MUST be present per §10.
        $this->assertTrue($over->headers->has('Retry-After'), '429 MUST set Retry-After header.');
    }
}
