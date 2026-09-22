<?php

namespace Tests\Feature\Api\V1;

use App\Models\Campaign;
use App\Models\CampaignReward;
use App\Models\Customer;
use App\Models\RewardGrant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class RewardGrantApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected User $userA;
    protected Customer $customerA;

    protected Tenant $tenantB;
    protected User $userB;
    protected Customer $customerB;

    protected function setUp(): void
    {
        parent::setUp();

        // 建立租戶A
        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'domain' => 'tenant-a.test',
        ]);

        $this->userA = User::create([
            'name' => 'User A',
            'email' => 'user-a@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenantA->id,
            'role' => 'user',
        ]);

        $this->customerA = Customer::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Customer A',
            'email' => 'customer-a@example.com',
            'qr_token' => 'token-a-abc123',
        ]);

        // 建立租戶B
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'domain' => 'tenant-b.test',
        ]);

        $this->userB = User::create([
            'name' => 'User B',
            'email' => 'user-b@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenantB->id,
            'role' => 'user',
        ]);

        $this->customerB = Customer::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Customer B',
            'email' => 'customer-b@example.com',
            'qr_token' => 'token-b-xyz789',
        ]);
    }

    protected function getTokenForUserA(): string
    {
        return JWTAuth::fromUser($this->userA);
    }

    protected function getTokenForUserB(): string
    {
        return JWTAuth::fromUser($this->userB);
    }

    /**
     * 在租戶A建立一個活躍活動及點數獎勵
     */
    protected function createActiveCampaignRewardForTenantA(array $campaignOverrides = [], array $rewardOverrides = []): CampaignReward
    {
        $campaign = Campaign::create(array_merge([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Test Campaign',
            'description' => 'Test Campaign Description',
            'status' => Campaign::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ], $campaignOverrides));

        return CampaignReward::create(array_merge([
            'campaign_id' => $campaign->id,
            'reward_type' => CampaignReward::TYPE_POINTS,
            'points' => 100,
            'enabled' => true,
        ], $rewardOverrides));
    }

    // ============ Reward Grant API Tests ============

    #[Test]
    public function can_grant_reward_to_customer_successfully(): void
    {
        $token = $this->getTokenForUserA();
        $campaignReward = $this->createActiveCampaignRewardForTenantA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'grant-reward-key-001',
        ])->postJson("/api/v1/customers/{$this->customerA->id}/rewards/grant", [
            'campaign_reward_id' => $campaignReward->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Reward granted successfully')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'status',
                    'campaign_id',
                    'campaign_reward_id',
                    'granted_at',
                    'created_at',
                ],
            ]);

        $this->assertDatabaseHas('reward_grants', [
            'tenant_id' => $this->tenantA->id,
            'customer_id' => $this->customerA->id,
            'campaign_reward_id' => $campaignReward->id,
            'status' => RewardGrant::STATUS_GRANTED,
        ]);
    }

    #[Test]
    public function cannot_grant_reward_without_authentication(): void
    {
        $campaignReward = $this->createActiveCampaignRewardForTenantA();

        $response = $this->postJson("/api/v1/customers/{$this->customerA->id}/rewards/grant", [
            'campaign_reward_id' => $campaignReward->id,
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function cannot_grant_reward_for_nonexistent_customer(): void
    {
        $token = $this->getTokenForUserA();
        $campaignReward = $this->createActiveCampaignRewardForTenantA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'grant-reward-key-002',
        ])->postJson('/api/v1/customers/99999/rewards/grant', [
            'campaign_reward_id' => $campaignReward->id,
        ]);

        $response->assertStatus(404);
    }

    #[Test]
    public function cannot_grant_reward_with_invalid_campaign_reward(): void
    {
        $token = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'grant-reward-key-003',
        ])->postJson("/api/v1/customers/{$this->customerA->id}/rewards/grant", [
            'campaign_reward_id' => 99999,
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function cannot_grant_reward_for_cross_tenant_customer(): void
    {
        // 租戶A的token，試圖對租戶B的customer發放獎勵
        $tokenA = $this->getTokenForUserA();
        $campaignReward = $this->createActiveCampaignRewardForTenantA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'grant-reward-key-004',
        ])->postJson("/api/v1/customers/{$this->customerB->id}/rewards/grant", [
            'campaign_reward_id' => $campaignReward->id,
        ]);

        // customerB 屬於 tenantB，tenantA token 應無法存取
        $response->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('reward_grants', 0);
    }

    #[Test]
    public function cannot_grant_cross_tenant_campaign_reward_to_own_customer(): void
    {
        // 建立租戶B的活動獎勵
        $campaignB = Campaign::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Tenant B Campaign',
            'status' => Campaign::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ]);
        $campaignRewardB = CampaignReward::create([
            'campaign_id' => $campaignB->id,
            'reward_type' => CampaignReward::TYPE_POINTS,
            'points' => 100,
            'enabled' => true,
        ]);

        // 租戶A的token，試圖使用租戶B的campaign_reward_id對租戶A的customer發放獎勵
        $tokenA = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'grant-reward-key-005',
        ])->postJson("/api/v1/customers/{$this->customerA->id}/rewards/grant", [
            'campaign_reward_id' => $campaignRewardB->id,
        ]);

        // 跨租戶的campaign reward應被拒絕
        $response->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('reward_grants', 0);
    }

    #[Test]
    public function cannot_grant_reward_for_inactive_campaign(): void
    {
        $token = $this->getTokenForUserA();
        $campaignReward = $this->createActiveCampaignRewardForTenantA(
            ['status' => Campaign::STATUS_INACTIVE]
        );

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'grant-reward-key-006',
        ])->postJson("/api/v1/customers/{$this->customerA->id}/rewards/grant", [
            'campaign_reward_id' => $campaignReward->id,
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('reward_grants', [
            'customer_id' => $this->customerA->id,
            'status' => RewardGrant::STATUS_FAILED,
        ]);
    }

    #[Test]
    public function cannot_grant_disabled_campaign_reward(): void
    {
        $token = $this->getTokenForUserA();
        $campaignReward = $this->createActiveCampaignRewardForTenantA(
            [],
            ['enabled' => false]
        );

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'grant-reward-key-007',
        ])->postJson("/api/v1/customers/{$this->customerA->id}/rewards/grant", [
            'campaign_reward_id' => $campaignReward->id,
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function grant_reward_requires_campaign_reward_id(): void
    {
        $token = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => 'grant-reward-key-008',
        ])->postJson("/api/v1/customers/{$this->customerA->id}/rewards/grant", []);

        $response->assertStatus(422);
    }

    // ============ Idempotency Tests ============

    #[Test]
    public function idempotency_prevents_duplicate_reward_grant_with_same_key(): void
    {
        $token = $this->getTokenForUserA();
        $campaignReward = $this->createActiveCampaignRewardForTenantA();
        $idempotencyKey = 'reward-idempotency-key-001';

        // 第一次請求
        $firstResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/rewards/grant", [
            'campaign_reward_id' => $campaignReward->id,
        ]);
        $firstResponse->assertStatus(201);
        $firstData = $firstResponse->json();

        // 第二次使用相同的 key 和 body
        $secondResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/rewards/grant", [
            'campaign_reward_id' => $campaignReward->id,
        ]);

        // 應返回相同狀態碼和相同資料
        $secondResponse->assertStatus(201);
        $secondData = $secondResponse->json();
        $this->assertEquals($firstData['data']['id'], $secondData['data']['id']);

        // 不會建立第二筆 reward_grant
        $this->assertDatabaseCount('reward_grants', 1);
    }

    #[Test]
    public function idempotency_returns_409_for_different_request_with_same_key(): void
    {
        $token = $this->getTokenForUserA();
        $campaignReward = $this->createActiveCampaignRewardForTenantA();

        // 建立第二個 campaign reward
        $anotherCampaignReward = $this->createActiveCampaignRewardForTenantA(
            ['name' => 'Another Campaign'],
            ['points' => 200]
        );

        $idempotencyKey = 'reward-idempotency-key-002';

        // 第一次請求
        $firstResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/rewards/grant", [
            'campaign_reward_id' => $campaignReward->id,
        ]);
        $firstResponse->assertStatus(201);

        // 第二次使用相同 key 但不同 campaign_reward_id
        $secondResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/rewards/grant", [
            'campaign_reward_id' => $anotherCampaignReward->id,
        ]);

        $secondResponse->assertStatus(409);
    }

    #[Test]
    public function same_idempotency_key_works_across_different_tenants(): void
    {
        $tokenA = $this->getTokenForUserA();
        $campaignRewardA = $this->createActiveCampaignRewardForTenantA();
        $idempotencyKey = 'shared-reward-key';

        // 租戶A發放
        $responseA = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/rewards/grant", [
            'campaign_reward_id' => $campaignRewardA->id,
        ]);
        $responseA->assertStatus(201);

        // 清除租戶上下文，切換到租戶B
        app(\App\Support\Tenancy\TenantContext::class)->clear();
        auth()->logout();

        // 建立租戶B的活動獎勵
        $campaignB = Campaign::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Tenant B Campaign',
            'status' => Campaign::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ]);
        $campaignRewardB = CampaignReward::create([
            'campaign_id' => $campaignB->id,
            'reward_type' => CampaignReward::TYPE_POINTS,
            'points' => 50,
            'enabled' => true,
        ]);

        $tokenB = $this->getTokenForUserB();

        // 租戶B使用相同的 key，應成功，不互相干擾
        $responseB = $this->actingAs($this->userB)->withHeaders([
            'Authorization' => "Bearer {$tokenB}",
            'X-Tenant-ID' => $this->tenantB->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerB->id}/rewards/grant", [
            'campaign_reward_id' => $campaignRewardB->id,
        ]);
        $responseB->assertStatus(201);

        $this->assertDatabaseCount('reward_grants', 2);
    }

    // ============ Reward Grant History Tests ============

    #[Test]
    public function can_get_reward_grant_history_successfully(): void
    {
        $token = $this->getTokenForUserA();
        $campaignReward = $this->createActiveCampaignRewardForTenantA();

        // 建立一筆 reward grant 記錄
        RewardGrant::create([
            'tenant_id' => $this->tenantA->id,
            'campaign_id' => $campaignReward->campaign_id,
            'campaign_reward_id' => $campaignReward->id,
            'customer_id' => $this->customerA->id,
            'status' => RewardGrant::STATUS_GRANTED,
            'granted_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/reward-grants");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Reward grants retrieved successfully')
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function reward_grant_history_returns_empty_for_customer_with_no_grants(): void
    {
        $token = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/reward-grants");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function reward_grant_history_tenant_isolation(): void
    {
        // 在租戶B建立一筆 grant
        $campaignB = Campaign::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Tenant B Campaign',
            'status' => Campaign::STATUS_ACTIVE,
        ]);
        $campaignRewardB = CampaignReward::create([
            'campaign_id' => $campaignB->id,
            'reward_type' => CampaignReward::TYPE_POINTS,
            'points' => 100,
            'enabled' => true,
        ]);
        RewardGrant::create([
            'tenant_id' => $this->tenantB->id,
            'campaign_id' => $campaignB->id,
            'campaign_reward_id' => $campaignRewardB->id,
            'customer_id' => $this->customerB->id,
            'status' => RewardGrant::STATUS_GRANTED,
            'granted_at' => now(),
        ]);

        // 租戶A的token嘗試查詢租戶B的customer
        $tokenA = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerB->id}/reward-grants");

        // customerB 屬於 tenantB，tenantA token 應無法存取
        $response->assertStatus(404);
    }

    #[Test]
    public function reward_grant_history_customer_isolation(): void
    {
        $token = $this->getTokenForUserA();
        $campaignReward = $this->createActiveCampaignRewardForTenantA();

        // 在租戶A建立第二個客戶，並為其建立 grant
        $customerA2 = Customer::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Customer A2',
            'email' => 'customer-a2@example.com',
            'qr_token' => 'token-a2-xyz789',
        ]);

        RewardGrant::create([
            'tenant_id' => $this->tenantA->id,
            'campaign_id' => $campaignReward->campaign_id,
            'campaign_reward_id' => $campaignReward->id,
            'customer_id' => $customerA2->id,
            'status' => RewardGrant::STATUS_GRANTED,
            'granted_at' => now(),
        ]);

        // 查詢 customerA 的 grant history，不應看到 customerA2 的記錄
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/reward-grants");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function reward_grant_history_supports_pagination(): void
    {
        $token = $this->getTokenForUserA();
        $campaignReward = $this->createActiveCampaignRewardForTenantA();

        // 建立 25 筆 grants (一個唯一約束只能一筆，所以建立多個 campaign)
        for ($i = 0; $i < 5; $i++) {
            $campaign = Campaign::create([
                'tenant_id' => $this->tenantA->id,
                'name' => "Campaign {$i}",
                'status' => Campaign::STATUS_ACTIVE,
            ]);
            $reward = CampaignReward::create([
                'campaign_id' => $campaign->id,
                'reward_type' => CampaignReward::TYPE_POINTS,
                'points' => 10,
                'enabled' => true,
            ]);
            RewardGrant::create([
                'tenant_id' => $this->tenantA->id,
                'campaign_id' => $campaign->id,
                'campaign_reward_id' => $reward->id,
                'customer_id' => $this->customerA->id,
                'status' => RewardGrant::STATUS_GRANTED,
                'granted_at' => now(),
            ]);
        }

        // per_page=3，第一頁應有3筆
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/reward-grants?per_page=3");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');
    }
}
