<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\IssuesApiTokens;
use Tests\TestCase;

/**
 * Phase 13 — RESTful Branch API tests.
 *
 * Covers all CRUD endpoints + auth:
 *   - GET    /api/v1/branches        (paginated list + search)
 *   - GET    /api/v1/branches/{id}   (single record, 404 if missing)
 *   - POST   /api/v1/branches        (admin only, 403 for non-admin)
 *   - PUT    /api/v1/branches/{id}   (admin only)
 *   - DELETE /api/v1/branches/{id}   (admin only, with deactivation blockers)
 *
 * Auth coverage:
 *   - missing Authorization header → 401
 *   - invalid token                → 401
 *   - disabled user token          → 401
 *   - valid token                  → 200
 *   - non-admin token on writes    → 403
 */
class BranchApiTest extends TestCase
{
    use BuildsRoleUsers, IssuesApiTokens;

    // ====================================================================
    // AUTH
    // ====================================================================

    public function test_index_returns_401_when_no_token(): void
    {
        $this->getJson('/api/v1/branches')->assertUnauthorized();
    }

    public function test_index_returns_401_when_token_is_invalid(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
            ->getJson('/api/v1/branches')
            ->assertUnauthorized();
    }

    public function test_index_returns_401_when_token_is_for_disabled_user(): void
    {
        $user = $this->makeRoleUser('salesman');
        $user->is_active = false;
        $user->save();
        $token = $this->apiTokenForUser($user);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/branches')
            ->assertUnauthorized();
    }

    public function test_index_returns_401_when_header_is_not_bearer(): void
    {
        $user = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        // Wrong scheme — should be rejected by the bearer-token parser.
        $this->withHeaders(['Authorization' => 'Basic ' . $token])
            ->getJson('/api/v1/branches')
            ->assertUnauthorized();
    }

    // ====================================================================
    // INDEX (LIST)
    // ====================================================================

    public function test_index_returns_paginated_branch_list(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        Branch::factory()->count(3)->create();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/branches');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $this->assertGreaterThanOrEqual(3, count($response->json('data')));
    }

    public function test_index_supports_search_query(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        Branch::factory()->create(['branch_code' => 'API-XYZ', 'branch_name' => 'Unique Searchable Branch']);
        Branch::factory()->create(['branch_name' => 'Other Branch']);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/branches?q=Unique%20Searchable');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        // Each returned row must contain the searchable name.
        collect($data)->each(fn ($row) => $this->assertSame('Unique Searchable Branch', $row['branch_name']));
    }

    public function test_index_respects_per_page_param(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        Branch::factory()->count(5)->create();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/branches?per_page=2');

        $response->assertOk();
        $this->assertSame(2, $response->json('meta.per_page'));
        $this->assertLessThanOrEqual(2, count($response->json('data')));
    }

    public function test_index_excludes_soft_deleted_branches(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        $live = Branch::factory()->create(['branch_name' => 'Live Branch API']);
        $dead = Branch::factory()->create(['branch_name' => 'Dead Branch API']);
        $dead->delete();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/branches?q=Branch%20API');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('branch_name')->all();
        $this->assertContains('Live Branch API', $names);
        $this->assertNotContains('Dead Branch API', $names);
    }

    // ====================================================================
    // SHOW
    // ====================================================================

    public function test_show_returns_branch_by_id(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        $branch = Branch::factory()->create();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/branches/{$branch->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $branch->id);
        $response->assertJsonPath('data.branch_code', $branch->branch_code);
    }

    public function test_show_returns_404_for_unknown_id(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/branches/999999')
            ->assertNotFound();
    }

    // ====================================================================
    // STORE (admin only)
    // ====================================================================

    public function test_store_creates_branch_with_admin_token(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/branches', [
                'branch_code' => 'api-st-001',
                'branch_name' => 'API Created Branch',
                'address'     => '123 API Street',
                'phone'       => '01712345678',
                'email'       => 'api@example.com',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.branch_code', 'API-ST-001'); // uppercased
        $response->assertJsonPath('data.branch_name', 'API Created Branch');

        $this->assertDatabaseHas('branches', [
            'branch_code' => 'API-ST-001',
            'branch_name' => 'API Created Branch',
        ]);
    }

    public function test_store_returns_403_for_non_admin(): void
    {
        $user  = $this->makeRoleUser('salesman');
        $token = $this->apiTokenForUser($user);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/branches', [
                'branch_code' => 'api-noauth-01',
                'branch_name' => 'Should Not Create',
            ])
            ->assertForbidden();
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/v1/branches', [
            'branch_code' => 'api-noauth-02',
            'branch_name' => 'Should Not Create',
        ])->assertUnauthorized();
    }

    public function test_store_returns_422_when_required_field_missing(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/branches', [
                'branch_name' => 'Missing Code Branch',
            ])
            ->assertStatus(422);
    }

    public function test_store_returns_422_on_duplicate_branch_code(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        Branch::factory()->create(['branch_code' => 'API-DUP-01']);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/branches', [
                'branch_code' => 'API-DUP-01',
                'branch_name' => 'Dup Code Test',
            ])
            ->assertStatus(422);
    }

    // ====================================================================
    // UPDATE (admin only)
    // ====================================================================

    public function test_update_modifies_branch_with_admin_token(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        $branch = Branch::factory()->create(['branch_name' => 'Before Update']);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->putJson("/api/v1/branches/{$branch->id}", [
                'branch_name' => 'After Update',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.branch_name', 'After Update');
        $this->assertDatabaseHas('branches', [
            'id'          => $branch->id,
            'branch_name' => 'After Update',
        ]);
    }

    public function test_update_returns_403_for_non_admin(): void
    {
        $user  = $this->makeRoleUser('manager');
        $token = $this->apiTokenForUser($user);

        $branch = Branch::factory()->create();

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->putJson("/api/v1/branches/{$branch->id}", [
                'branch_name' => 'Hijacked Update',
            ])
            ->assertForbidden();
    }

    public function test_update_returns_404_for_unknown_branch(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->putJson('/api/v1/branches/999999', [
                'branch_name' => 'Ghost Update',
            ])
            ->assertNotFound();
    }

    // ====================================================================
    // DESTROY (admin only, with deactivation blockers)
    // ====================================================================

    public function test_destroy_deactivates_branch_with_no_blockers(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        $branch = Branch::factory()->create();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->deleteJson("/api/v1/branches/{$branch->id}");

        $response->assertOk();
        $response->assertJsonPath('message', 'Branch deactivated.');

        $branch->refresh();
        $this->assertNotNull($branch->deleted_at);
        $this->assertFalse($branch->is_active);
    }

    public function test_destroy_returns_400_when_branch_has_active_warehouses(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        $branch = Branch::factory()->create();
        \App\Models\Warehouse::factory()->create([
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->deleteJson("/api/v1/branches/{$branch->id}")
            ->assertStatus(400);

        // Branch should still be present (deactivation was blocked).
        $this->assertDatabaseHas('branches', [
            'id'         => $branch->id,
            'deleted_at' => null,
        ]);
    }

    public function test_destroy_returns_403_for_non_admin(): void
    {
        $user  = $this->makeRoleUser('manager');
        $token = $this->apiTokenForUser($user);

        $branch = Branch::factory()->create();

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->deleteJson("/api/v1/branches/{$branch->id}")
            ->assertForbidden();
    }

    public function test_destroy_returns_404_for_unknown_branch(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->deleteJson('/api/v1/branches/999999')
            ->assertNotFound();
    }
}
