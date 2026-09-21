<?php

namespace Tests\Feature\Api\V1;

use App\Models\CouponTemplate;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\IdempotencyKey;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserCoupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CouponApiTest extends TestCase
{
    use RefreshDatabase;

    protected $tenantA;
    protected $userA;
    protected $customerA;

    protected $tenantB;
    protected $userB;
    protected $customerB;

    /**
     * 設定測試環境，建立兩個租戶、使用者、客戶
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 建立租戶A
        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'domain' => 'tenant-a.test',
        ]);

        // 建立租戶A的使用者
        $this->userA = User::create([
            'name' => 'User A',
            'email' => 'user-a@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenantA->id,
            'role' => 'user',
        ]);

        // 建立租戶A的客戶
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

        // 建立租戶B的使用者
        $this->userB = User::create([
            'name' => 'User B',
            'email' => 'user-b@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenantB->id,
            'role' => 'user',
        ]);

        // 建立租戶B的客戶
        $this->customerB = Customer::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Customer B',
            'email' => 'customer-b@example.com',
            'qr_token' => 'token-b-xyz789',
        ]);
    }

    /**
     * 取得租戶A使用者的JWT token
     */
    protected function getTokenForUserA()
    {
        return JWTAuth::fromUser($this->userA);
    }

    /**
     * 取得租戶B使用者的JWT token
     */
    protected function getTokenForUserB()
    {
        return JWTAuth::fromUser($this->userB);
    }

    /**
     * 為租戶A建立一個有效的優惠券模板
     */
    protected function createValidCouponTemplateForTenantA(array $overrides = []): CouponTemplate
    {
        return CouponTemplate::create(array_merge([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Summer Sale 2024',
            'code' => 'SUMMER2024',
            'type' => CouponTemplate::TYPE_FIXED_AMOUNT,
            'discount_amount' => 100,
            'minimum_order_amount' => 500,
            'starts_at' => now()->subDays(1),
            'expires_at' => now()->addDays(30),
            'total_quantity' => 100,
            'issued_quantity' => 0,
            'per_customer_limit' => 1,
            'status' => CouponTemplate::STATUS_ACTIVE,
        ], $overrides));
    }

    // ============ 1. Coupon 領取成功測試 ============
    #[Test]
    public function customer_can_claim_coupon_successfully(): void
    {
        $token = $this->getTokenForUserA();
        $template = $this->createValidCouponTemplateForTenantA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/claim", [
            'code' => 'SUMMER2024'
        ]);

        // 驗證API回應
        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Coupon claimed successfully')
            ->assertJsonPath('data.customer_id', $this->customerA->id)
            ->assertJsonPath('data.status', UserCoupon::STATUS_AVAILABLE);

        // 驗證資料庫狀態
        $this->assertDatabaseHas('user_coupons', [
            'tenant_id' => $this->tenantA->id,
            'customer_id' => $this->customerA->id,
            'coupon_template_id' => $template->id,
            'status' => UserCoupon::STATUS_AVAILABLE,
        ]);

        // 驗證已發行數量正確扣減
        $template->refresh();
        $this->assertEquals(1, $template->issued_quantity);
    }

    // ============ 2. Coupon 超發測試 - 達到發放上限 ============
    #[Test]
    public function cannot_claim_coupon_when_max_quantity_reached(): void
    {
        $token = $this->getTokenForUserA();
        // 建立一個只能發行1張的優惠券
        $template = $this->createValidCouponTemplateForTenantA([
            'total_quantity' => 1,
            'issued_quantity' => 1, // 已經發行完畢
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/claim", [
            'code' => 'SUMMER2024'
        ]);

        // 驗證領取失敗
        $response->assertStatus(400)
            ->assertJsonPath('success', false);

        // 驗證不會建立多餘的UserCoupon
        $this->assertDatabaseCount('user_coupons', 0);

        // 驗證發行數量不會超過上限
        $template->refresh();
        $this->assertEquals(1, $template->issued_quantity);
    }

    // ============ 3. 同一使用者重複領取測試 ============
    #[Test]
    public function cannot_claim_same_coupon_template_twice(): void
    {
        $token = $this->getTokenForUserA();
        $template = $this->createValidCouponTemplateForTenantA([
            'per_customer_limit' => 1, // 每人只能領1張
        ]);

        // 第一次領取成功
        $firstResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/claim", [
            'code' => 'SUMMER2024'
        ]);
        $firstResponse->assertStatus(201);

        // 第二次領取應該失敗
        $secondResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/claim", [
            'code' => 'SUMMER2024'
        ]);

        $secondResponse->assertStatus(400)
            ->assertJsonPath('success', false);

        // 驗證只建立了一張UserCoupon
        $this->assertDatabaseCount('user_coupons', 1);
    }

    // ============ 4. Coupon 核銷成功測試 ============
    #[Test]
    public function can_redeem_valid_coupon_successfully(): void
    {
        $token = $this->getTokenForUserA();
        $template = $this->createValidCouponTemplateForTenantA();

        // 先領取優惠券
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/claim", [
            'code' => 'SUMMER2024'
        ]);

        $userCoupon = UserCoupon::first();

        // 核銷優惠券
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/{$userCoupon->id}/redeem", [
            'reference' => 'REDEMPTION-001',
            'order_reference' => 'ORDER-12345',
            'order_amount' => 1000,
        ]);

        // 驗證API回應
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Coupon redeemed successfully')
            ->assertJsonPath('data.reference', 'REDEMPTION-001')
            ->assertJsonPath('data.discount_amount', 100);

        // 驗證UserCoupon狀態更新正確
        $userCoupon->refresh();
        $this->assertEquals(UserCoupon::STATUS_USED, $userCoupon->status);
        $this->assertNotNull($userCoupon->used_at);

        // 驗證CouponRedemption正確建立
        $this->assertDatabaseHas('coupon_redemptions', [
            'tenant_id' => $this->tenantA->id,
            'user_coupon_id' => $userCoupon->id,
            'customer_id' => $this->customerA->id,
            'reference' => 'REDEMPTION-001',
            'order_reference' => 'ORDER-12345',
            'discount_amount' => 100,
        ]);
    }

    // ============ 5. 重複核銷測試 ============
    #[Test]
    public function cannot_redeem_already_redeemed_coupon(): void
    {
        $token = $this->getTokenForUserA();
        $template = $this->createValidCouponTemplateForTenantA();

        // 領取優惠券
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/claim", [
            'code' => 'SUMMER2024'
        ]);

        $userCoupon = UserCoupon::first();

        // 第一次核銷成功
        $firstResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/{$userCoupon->id}/redeem", [
            'reference' => 'REDEMPTION-001',
            'order_reference' => 'ORDER-12345',
            'order_amount' => 1000,
        ]);
        $firstResponse->assertStatus(200);

        // 第二次核銷應該失敗
        $secondResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/{$userCoupon->id}/redeem", [
            'reference' => 'REDEMPTION-002',
            'order_reference' => 'ORDER-67890',
            'order_amount' => 1000,
        ]);

        $secondResponse->assertStatus(400)
            ->assertJsonPath('success', false);

        // 驗證只建立了一筆redemption記錄
        $this->assertDatabaseCount('coupon_redemptions', 1);

        // 驗證狀態維持正確
        $userCoupon->refresh();
        $this->assertEquals(UserCoupon::STATUS_USED, $userCoupon->status);
    }

    // ============ 6. 已過期 Coupon 測試 ============
    #[Test]
    public function cannot_claim_expired_coupon(): void
    {
        $token = $this->getTokenForUserA();
        // 建立一個已經過期的優惠券
        $template = $this->createValidCouponTemplateForTenantA([
            'expires_at' => now()->subDays(1), // 已過期
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/claim", [
            'code' => 'SUMMER2024'
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('user_coupons', 0);
    }

    #[Test]
    public function cannot_redeem_expired_coupon(): void
    {
        $token = $this->getTokenForUserA();

        // 手動建立一個已過期的UserCoupon
        $template = $this->createValidCouponTemplateForTenantA([
            'expires_at' => now()->subDays(1),
        ]);

        $userCoupon = UserCoupon::create([
            'tenant_id' => $this->tenantA->id,
            'customer_id' => $this->customerA->id,
            'coupon_template_id' => $template->id,
            'status' => UserCoupon::STATUS_AVAILABLE,
            'issued_at' => now()->subDays(5),
            'expired_at' => now()->subDays(1),
        ]);

        // 嘗試核銷
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/{$userCoupon->id}/redeem", [
            'reference' => 'REDEMPTION-001',
            'order_amount' => 1000,
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false);

        // 驗證不會建立redemption
        $this->assertDatabaseCount('coupon_redemptions', 0);

        // 狀態維持不變
        $userCoupon->refresh();
        $this->assertEquals(UserCoupon::STATUS_AVAILABLE, $userCoupon->status);
    }

    // ============ 7. 尚未開始的 Coupon 測試 ============
    #[Test]
    public function cannot_claim_coupon_that_has_not_started(): void
    {
        $token = $this->getTokenForUserA();
        // 建立一個尚未開始的優惠券
        $template = $this->createValidCouponTemplateForTenantA([
            'starts_at' => now()->addDays(7), // 一週後才開始
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/claim", [
            'code' => 'SUMMER2024'
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('user_coupons', 0);
    }

    // ============ 8. Tenant Isolation 租戶隔離測試 ============
    #[Test]
    public function tenant_b_cannot_claim_tenant_a_coupon(): void
    {
        $tokenB = $this->getTokenForUserB();
        // 在租戶A建立一個優惠券
        $this->createValidCouponTemplateForTenantA();

        // 租戶B的使用者嘗試領取租戶A的優惠券（使用租戶B的X-Tenant-ID）
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$tokenB}",
            'X-Tenant-ID' => $this->tenantB->id,
        ])->postJson("/api/v1/customers/{$this->customerB->id}/coupons/claim", [
            'code' => 'SUMMER2024' // 代碼相同但屬於不同租戶
        ]);

        // 應該找不到優惠券，領取失敗
        $response->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('user_coupons', 0);
    }

    #[Test]
    public function tenant_b_cannot_redeem_tenant_a_usercoupon(): void
    {
        $tokenA = $this->getTokenForUserA();
        $tokenB = $this->getTokenForUserB();

        // 租戶A建立優惠券並領取
        $template = $this->createValidCouponTemplateForTenantA();
        $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/claim", [
            'code' => 'SUMMER2024',
        ]);

        $userCoupon = UserCoupon::first();

        // 租戶B嘗試核銷租戶A的UserCoupon
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$tokenB}",
            'X-Tenant-ID' => $this->tenantB->id,
        ])->postJson("/api/v1/customers/{$this->customerB->id}/coupons/{$userCoupon->id}/redeem", [
            'reference' => 'REDEMPTION-001',
            'order_amount' => 1000,
        ]);

        // 應該回傳404找不到
        $response->assertStatus(404);

        // 驗證不會建立redemption
        $this->assertDatabaseCount('coupon_redemptions', 0);
    }

    // ============ 9. Idempotency 冪等性測試 ============
    #[Test]
    public function idempotency_prevents_duplicate_coupon_claims(): void
    {
        $token = $this->getTokenForUserA();
        $template = $this->createValidCouponTemplateForTenantA();
        $idempotencyKey = 'test-idempotency-key-123';

        // 第一次請求成功
        $firstResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/claim", [
            'code' => 'SUMMER2024'
        ]);
        $firstResponse->assertStatus(201);

        // 使用相同的冪等性鍵發送相同的請求，應該返回相同結果且不會重複建立
        $secondResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/claim", [
            'code' => 'SUMMER2024'
        ]);

        // 驗證只建立了一張UserCoupon
        $this->assertDatabaseCount('user_coupons', 1);
        $template->refresh();
        $this->assertEquals(1, $template->issued_quantity);
    }

    #[Test]
    public function idempotency_returns_409_for_different_request_with_same_key(): void
    {
        $token = $this->getTokenForUserA();
        $this->createValidCouponTemplateForTenantA();
        $idempotencyKey = 'test-idempotency-key-456';

        // 第一次請求
        $firstResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/claim", [
            'code' => 'SUMMER2024'
        ]);
        $firstResponse->assertStatus(201);

        // 使用相同的冪等性鍵但不同的請求內容
        $secondResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/claim", [
            'code' => 'DIFFERENT2024' // 不同的code
        ]);

        // 應該返回409衝突
        $secondResponse->assertStatus(409);
    }

    #[Test]
    public function same_idempotency_key_works_across_different_tenants(): void
    {
        $tokenA = $this->getTokenForUserA();
        $tokenB = $this->getTokenForUserB();
        $idempotencyKey = 'shared-idempotency-key';

        // 在租戶A建立並領取優惠券
        $this->createValidCouponTemplateForTenantA();
        $responseA = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/coupons/claim", [
            'code' => 'SUMMER2024'
        ]);
        $responseA->assertStatus(201);

        // 在租戶B建立相同代碼的優惠券 - 使用withoutGlobalScope避免租戶上下文干扰
        \App\Models\CouponTemplate::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Winter Sale',
            'code' => 'WINTER2024',
            'type' => CouponTemplate::TYPE_FIXED_AMOUNT,
            'discount_amount' => 100,
            'minimum_order_amount' => 500,
            'starts_at' => now()->subDays(1),
            'expires_at' => now()->addDays(30),
            'total_quantity' => 100,
            'issued_quantity' => 0,
            'per_customer_limit' => 1,
            'status' => CouponTemplate::STATUS_ACTIVE,
        ]);

        // 清除租戶上下文，並切換認證使用者為userB，確保第二個請求能正確解析新的租戶
        app(\App\Support\Tenancy\TenantContext::class)->clear();
        auth()->logout();

        // 租戶B使用相同的冪等性鍵應該可以成功，不會互相干擾
        $responseB = $this->actingAs($this->userB)->withHeaders([
            'Authorization' => "Bearer {$tokenB}",
            'X-Tenant-ID' => $this->tenantB->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerB->id}/coupons/claim", [
            'code' => 'WINTER2024'
        ]);
        $responseB->assertStatus(201);

        // 兩個租戶都成功建立了自己的UserCoupon
        $this->assertDatabaseCount('user_coupons', 2);
    }

    // ============ 併發安全性補充測試：確保不會超發 ============
    #[Test]
    public function concurrent_claims_do_not_exceed_total_quantity(): void
    {
        $token = $this->getTokenForUserA();
        // 建立只能發行5張的優惠券
        $template = $this->createValidCouponTemplateForTenantA([
            'total_quantity' => 5,
            'per_customer_limit' => 1, // 每個客戶只能領取一次，符合資料庫唯一約束
        ]);

        // 建立10個不同的客戶來模擬多使用者並發請求
        $customers = collect();
        for ($c = 0; $c < 10; $c++) {
            $customers->push(\App\Models\Customer::create([
                'tenant_id' => $this->tenantA->id,
                'name' => "Test Customer {$c}",
                'email' => "customer{$c}@example.com",
                'phone' => "1380000" . str_pad((string)$c, 4, '0'),
            ]));
        }

        // 模擬10次並發請求，但只能成功5次
        $successCount = 0;
        $failCount = 0;

        for ($i = 0; $i < 10; $i++) {
            // 清除租戶上下文，確保每次請求都能正確解析
            app(\App\Support\Tenancy\TenantContext::class)->clear();
            auth()->logout();

            $customer = $customers[$i];
            $response = $this->actingAs($this->userA)->withHeaders([
                'Authorization' => "Bearer {$token}",
                'X-Tenant-ID' => $this->tenantA->id,
                'Idempotency-Key' => "concurrent-key-{$i}",
            ])->postJson("/api/v1/customers/{$customer->id}/coupons/claim", [
                'code' => 'SUMMER2024'
            ]);

            // 手動釋放緩存鎖，確保下一次請求能獲取鎖（測試環境array cache驅動不會自動釋放）
            $templateLockKey = sprintf('coupon_template:%d:tenant:%d', $template->id, $this->tenantA->id);
            $customerTemplateLockKey = sprintf('coupon_customer:%d:template:%d:tenant:%d', $customer->id, $template->id, $this->tenantA->id);
            try {
                \Illuminate\Support\Facades\Cache::lock($templateLockKey)->release();
                \Illuminate\Support\Facades\Cache::lock($customerTemplateLockKey)->release();
            } catch (\Exception $e) {
                // 忽略釋放鎖時的錯誤
            }

            if ($response->getStatusCode() === 201) {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        // 最多只能成功5次
        $this->assertEquals(5, $successCount);
        $this->assertEquals(5, $failCount);

        // 資料庫中只會有5張UserCoupon
        $this->assertDatabaseCount('user_coupons', 5);

        // issued_quantity 等於5，不會超過total_quantity
        $template->refresh();
        $this->assertEquals(5, $template->issued_quantity);
    }
}
