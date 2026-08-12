<?php

namespace Tests\Feature\Api\V1\Sales;

use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\Employee;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\IssuesApiTokens;
use Tests\TestCase;

/**
 * Commission API Feature Tests — Task 37 / API-4.
 *
 * Tests the CommissionApiController's REST API endpoints:
 *   GET  /api/v1/sales/commission/rules           List rules
 *   GET  /api/v1/sales/commission/rules/{id}       Show rule
 *   POST /api/v1/sales/commission/rules            Create rule (admin)
 *   POST /api/v1/sales/commission/rules/{id}/deactivate  Deactivate (admin)
 *   GET  /api/v1/sales/commission/entries          List entries
 *   GET  /api/v1/sales/commission/salesman-summary Salesman summary
 *   GET  /api/v1/sales/commission/branch-summary   Branch summary
 *   POST /api/v1/sales/commission/confirm-period   Confirm period (admin)
 *
 * Auth coverage:
 *   - missing Authorization header → 401
 *   - invalid token → 401
 *   - non-admin token on writes (store/deactivate/confirm) → 403
 *
 * Uses BuildsRoleUsers + IssuesApiTokens (G5 consistency).
 */
class CommissionApiTest extends TestCase
{
    use BuildsRoleUsers, IssuesApiTokens;

    private $admin;
    private $salesman;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeRoleUser('admin');
        // Create a salesman Employee for commission rule linkage.
        $this->salesman = Employee::factory()
            ->forBranch($this->admin->getBranchId())
            ->withRole('salesman')
            ->create();
    }

    // ====================================================================
    // AUTH
    // ====================================================================

    public function test_list_rules_returns_401_when_no_token(): void
    {
        $this->getJson('/api/v1/sales/commission/rules')->assertUnauthorized();
    }

    public function test_list_rules_returns_401_when_token_is_invalid(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
            ->getJson('/api/v1/sales/commission/rules')
            ->assertUnauthorized();
    }

    public function test_store_rule_returns_403_for_non_admin(): void
    {
        $salesmanUser = $this->makeRoleUser('salesman');
        $token = $this->apiTokenForUser($salesmanUser);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/commission/rules', [
                'salesman_id' => $this->salesman->id,
                'rule_type'   => 'flat',
                'rate'        => 1.5,
            ])
            ->assertForbidden();
    }

    // ====================================================================
    // LIST RULES
    // ====================================================================

    public function test_list_rules_returns_paginated_json(): void
    {
        $token = $this->apiTokenForUser($this->admin);

        CommissionRule::factory()
            ->forSalesman($this->salesman->id)
            ->count(3)
            ->create();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/sales/commission/rules');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $this->assertGreaterThanOrEqual(3, count($response->json('data')));
    }

    public function test_list_rules_supports_salesman_filter(): void
    {
        $token = $this->apiTokenForUser($this->admin);

        CommissionRule::factory()->forSalesman($this->salesman->id)->create();
        $otherSalesman = Employee::factory()
            ->forBranch($this->admin->getBranchId())
            ->withRole('salesman')
            ->create();
        CommissionRule::factory()->forSalesman($otherSalesman->id)->create();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/sales/commission/rules?salesman_id={$this->salesman->id}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        collect($data)->each(fn ($row) => $this->assertSame($this->salesman->id, $row['salesman']['id']));
    }

    public function test_list_rules_clamps_per_page_to_100(): void
    {
        $token = $this->apiTokenForUser($this->admin);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/sales/commission/rules?per_page=999');

        $response->assertOk();
        $this->assertLessThanOrEqual(100, $response->json('meta.per_page'));
    }

    // ====================================================================
    // SHOW RULE
    // ====================================================================

    public function test_show_rule_returns_rule_by_id(): void
    {
        $token = $this->apiTokenForUser($this->admin);

        $rule = CommissionRule::factory()->forSalesman($this->salesman->id)->create();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/sales/commission/rules/{$rule->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $rule->id);
        $response->assertJsonPath('data.rule_type', $rule->rule_type);
    }

    public function test_show_rule_returns_404_for_unknown_id(): void
    {
        $token = $this->apiTokenForUser($this->admin);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/sales/commission/rules/999999')
            ->assertNotFound();
    }

    // ====================================================================
    // STORE RULE (admin only)
    // ====================================================================

    public function test_store_rule_creates_flat_rule_with_admin_token(): void
    {
        $token = $this->apiTokenForUser($this->admin);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/commission/rules', [
                'salesman_id' => $this->salesman->id,
                'rule_type'   => 'flat',
                'rate'        => 1.5,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.rule_type', 'flat');
        $response->assertJsonPath('data.rate', 1.5);
        $response->assertJsonPath('data.salesman.id', $this->salesman->id);

        $this->assertDatabaseHas('commission_rules', [
            'salesman_id' => $this->salesman->id,
            'rule_type'   => 'flat',
            'rate'        => '1.5000',
        ]);
    }

    public function test_store_rule_returns_422_when_required_field_missing(): void
    {
        $token = $this->apiTokenForUser($this->admin);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/commission/rules', [
                'rule_type' => 'flat',
                'rate'      => 1.5,
                // salesman_id missing
            ])
            ->assertStatus(422);
    }

    public function test_store_rule_returns_422_for_invalid_rule_type(): void
    {
        $token = $this->apiTokenForUser($this->admin);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/commission/rules', [
                'salesman_id' => $this->salesman->id,
                'rule_type'   => 'invalid_type',
                'rate'        => 1.5,
            ])
            ->assertStatus(422);
    }

    // ====================================================================
    // DEACTIVATE RULE (admin only)
    // ====================================================================

    public function test_deactivate_rule_succeeds_with_admin_token(): void
    {
        $token = $this->apiTokenForUser($this->admin);

        $rule = CommissionRule::factory()
            ->forSalesman($this->salesman->id)
            ->active()
            ->create();

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/sales/commission/rules/{$rule->id}/deactivate")
            ->assertOk();

        $rule->refresh();
        $this->assertFalse($rule->is_active);
        $this->assertNotNull($rule->effective_to);
    }

    public function test_deactivate_rule_returns_403_for_non_admin(): void
    {
        $salesmanUser = $this->makeRoleUser('salesman');
        $token = $this->apiTokenForUser($salesmanUser);

        $rule = CommissionRule::factory()->forSalesman($this->salesman->id)->create();

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/sales/commission/rules/{$rule->id}/deactivate")
            ->assertForbidden();
    }

    // ====================================================================
    // LIST ENTRIES
    // ====================================================================

    public function test_list_entries_returns_paginated_json(): void
    {
        $token = $this->apiTokenForUser($this->admin);

        $rule = CommissionRule::factory()->forSalesman($this->salesman->id)->create();
        CommissionEntry::factory()
            ->forSalesman($this->salesman->id)
            ->forBranch($this->admin->getBranchId())
            ->forRule($rule->id)
            ->count(3)
            ->create();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/sales/commission/entries');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
    }

    public function test_list_entries_supports_period_filter(): void
    {
        $token = $this->apiTokenForUser($this->admin);

        $rule = CommissionRule::factory()->forSalesman($this->salesman->id)->create();
        CommissionEntry::factory()
            ->forSalesman($this->salesman->id)
            ->forBranch($this->admin->getBranchId())
            ->forRule($rule->id)
            ->forPeriod('2025-01')
            ->create();
        CommissionEntry::factory()
            ->forSalesman($this->salesman->id)
            ->forBranch($this->admin->getBranchId())
            ->forRule($rule->id)
            ->forPeriod('2025-02')
            ->create();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/sales/commission/entries?period=2025-01');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        collect($data)->each(fn ($row) => $this->assertSame('2025-01', $row['commission_period']));
    }

    // ====================================================================
    // SUMMARIES
    // ====================================================================

    public function test_salesman_summary_returns_422_without_period(): void
    {
        $token = $this->apiTokenForUser($this->admin);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/sales/commission/salesman-summary?salesman_id={$this->salesman->id}")
            ->assertStatus(422);
    }

    public function test_branch_summary_returns_422_with_invalid_period_format(): void
    {
        $token = $this->apiTokenForUser($this->admin);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/sales/commission/branch-summary?period=invalid')
            ->assertStatus(422);
    }

    public function test_branch_summary_returns_200_for_valid_period(): void
    {
        $token = $this->apiTokenForUser($this->admin);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/sales/commission/branch-summary?period=2025-01');

        $response->assertOk();
        $response->assertJsonStructure(['data']);
    }

    // ====================================================================
    // CONFIRM PERIOD (admin only)
    // ====================================================================

    public function test_confirm_period_returns_403_for_non_admin(): void
    {
        $salesmanUser = $this->makeRoleUser('salesman');
        $token = $this->apiTokenForUser($salesmanUser);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/commission/confirm-period', [
                'period' => '2025-01',
            ])
            ->assertForbidden();
    }

    public function test_confirm_period_returns_422_without_period(): void
    {
        $token = $this->apiTokenForUser($this->admin);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/commission/confirm-period', [])
            ->assertStatus(422);
    }

    public function test_confirm_period_returns_200_with_no_pending_entries(): void
    {
        $token = $this->apiTokenForUser($this->admin);

        // No calculated entries exist for this period → message says "No pending".
        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/commission/confirm-period', [
                'period' => '2099-12',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.confirmed_count', 0);
    }
}
