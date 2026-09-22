<?php

namespace Tests\Feature\Api\V1;

use App\Models\CouponRedemption;
use App\Models\CouponTemplate;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserCoupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CouponRedemptionHistoryApiTest extends TestCase
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
     * 建立一個 UserCoupon 和對應的 CouponRedemption
     */
    protected function createRedemptionForCustomer(Customer $customer, array $overrides = []): CouponRedemption
    {
        $template = CouponTemplate::withoutGlobalScope('tenant')->create([
            'tenant_id' => $customer->tenant_id,
            'name' => 'Test Coupon',
            'code' => 'TEST-' . uniqid(),
            'type' => CouponTemplate::TYPE_FIXED_AMOUNT,
            'discount_amount' => 100,
            'minimum_order_amount' => 500,
            'starts_at' => now()->subDays(1),
            'expires_at' => now()->addDays(30),
            'total_quantity' => 100,
            'issued_quantity' => 1,
            'per_customer_limit' => 1,
            'status' => CouponTemplate::STATUS_ACTIVE,
        ]);

        $userCoupon = UserCoupon::withoutGlobalScope('tenant')->create([
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
            'coupon_template_id' => $template->id,
            'status' => UserCoupon::STATUS_USED,
            'issued_at' => now()->subHours(2),
            'used_at' => now()->subHour(),
        ]);

        return CouponRedemption::withoutGlobalScope('tenant')->create(array_merge([
            'tenant_id' => $customer->tenant_id,
            'user_coupon_id' => $userCoupon->id,
            'customer_id' => $customer->id,
            'reference' => 'REF-' . uniqid(),
            'discount_amount' => 100,
            'redeemed_at' => now()->subHour(),
        ], $overrides));
    }

    // ============ Coupon Redemption History Tests ============

    #[Test]
    public function can_get_coupon_redemption_history_successfully(): void
    {
        $token = $this->getTokenForUserA();

        // 建立一筆 redemption
        $redemption = $this->createRedemptionForCustomer($this->customerA);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/coupon-redemptions");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Coupon redemptions retrieved successfully')
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'reference',
                        'order_reference',
                        'discount_amount',
                        'redeemed_at',
                        'created_at',
                    ],
                ],
            ]);

        $this->assertEquals($redemption->id, $response->json('data.0.id'));
    }

    #[Test]
    public function coupon_redemption_history_returns_empty_for_customer_with_no_redemptions(): void
    {
        $token = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/coupon-redemptions");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function coupon_redemption_history_tenant_isolation(): void
    {
        // 在租戶B建立一筆 redemption
        $this->createRedemptionForCustomer($this->customerB);

        // 租戶A的token嘗試查詢租戶B的customer
        $tokenA = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerB->id}/coupon-redemptions");

        // customerB 屬於 tenantB，tenantA token 應無法存取
        $response->assertStatus(404);
    }

    #[Test]
    public function coupon_redemption_history_customer_isolation(): void
    {
        $token = $this->getTokenForUserA();

        // 在租戶A建立第二個客戶並為其建立 redemption
        $customerA2 = Customer::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Customer A2',
            'email' => 'customer-a2@example.com',
            'qr_token' => 'token-a2-xyz789',
        ]);

        // 只為 customerA2 建立 redemption
        $this->createRedemptionForCustomer($customerA2);

        // 查詢 customerA 的 redemption history，不應看到 customerA2 的記錄
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/coupon-redemptions");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function coupon_redemption_history_supports_pagination(): void
    {
        $token = $this->getTokenForUserA();

        // 建立 5 筆 redemptions
        for ($i = 0; $i < 5; $i++) {
            $this->createRedemptionForCustomer($this->customerA);
        }

        // per_page=3，第一頁應有3筆
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/coupon-redemptions?per_page=3");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function coupon_redemption_history_requires_authentication(): void
    {
        $response = $this->getJson("/api/v1/customers/{$this->customerA->id}/coupon-redemptions");

        $response->assertStatus(401);
    }

    #[Test]
    public function coupon_redemption_history_can_filter_by_start_date(): void
    {
        $token = $this->getTokenForUserA();

        // 舊的 redemption（3 天前）
        $this->createRedemptionForCustomer($this->customerA, [
            'redeemed_at' => now()->subDays(3),
        ]);

        // 新的 redemption（今天）
        $recentRedemption = $this->createRedemptionForCustomer($this->customerA, [
            'redeemed_at' => now(),
        ]);

        // 查詢從昨天開始的 redemptions
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/coupon-redemptions?start_date=" . now()->subDay()->format('Y-m-d'));

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->assertEquals($recentRedemption->id, $response->json('data.0.id'));
    }
}
