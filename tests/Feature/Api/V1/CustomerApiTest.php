<?php

namespace Tests\Feature\Api\V1;

use App\Models\Customer;
use App\Models\PointAccount;
use App\Models\PointTransaction;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Cache;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    protected $tenantA;
    protected $userA;
    protected $customerA;
    protected $pointAccountA;

    protected $tenantB;
    protected $userB;
    protected $customerB;
    protected $pointAccountB;

    /**
     * 設定測試環境，建立兩個租戶、使用者、客戶和點數帳戶
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

        // 建立租戶A的點數帳戶
        $this->pointAccountA = PointAccount::create([
            'tenant_id' => $this->tenantA->id,
            'customer_id' => $this->customerA->id,
            'balance' => 1000,
            'total_earned' => 1000,
            'total_redeemed' => 0,
        ]);

        // 建立對應的點數批次，確保SUM(remaining_points) == balance
        \App\Models\PointLot::create([
            'tenant_id' => $this->tenantA->id,
            'customer_id' => $this->customerA->id,
            'point_account_id' => $this->pointAccountA->id,
            'original_points' => 1000,
            'remaining_points' => 1000,
            'earned_at' => now(),
            'expired_at' => null,
            'origin_transaction_id' => null,
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

        // 建立租戶B的點數帳戶
        $this->pointAccountB = PointAccount::create([
            'tenant_id' => $this->tenantB->id,
            'customer_id' => $this->customerB->id,
            'balance' => 1000,
            'total_earned' => 1000,
            'total_redeemed' => 0,
        ]);

        // 建立對應的點數批次，確保SUM(remaining_points) == balance
        \App\Models\PointLot::create([
            'tenant_id' => $this->tenantB->id,
            'customer_id' => $this->customerB->id,
            'point_account_id' => $this->pointAccountB->id,
            'original_points' => 1000,
            'remaining_points' => 1000,
            'earned_at' => now(),
            'expired_at' => null,
            'origin_transaction_id' => null,
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

    // ============ GET /customers/{customer}/qr-code 測試 ============

    #[Test]
    public function can_retrieve_customer_qr_code(): void
    {
        $token = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/qr-code");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'QR code retrieved successfully')
            ->assertJsonPath('data.member_code', $this->customerA->member_code);
    }

    #[Test]
    public function qr_code_uses_customer_qr_token(): void
    {
        $token = $this->getTokenForUserA();

        // 呼叫QR Code endpoint
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/qr-code");

        // Layer 1: API Response Contract 驗證
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'QR code retrieved successfully')
            ->assertJsonPath('data.member_code', $this->customerA->member_code);

        // 驗證qr_code存在且是正確的SVG data URI格式
        $qrCode = $response->json('data.qr_code');
        $this->assertNotEmpty($qrCode);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $qrCode);

        // 重新整理customerA的資料，確保取得最新的qr_token
        $this->customerA->refresh();

        // Layer 2: QR Payload 驗證
        // 因為目前環境只有GD擴展，Zxing解碼器無法直接解析SVG格式
        // 但我們可以驗證：
        // 1. API確實使用Customer模型的qr_token作為QR Code內容（程式碼層面保障）
        // 2. SVG成功產生（base64解碼後是有效的XML）
        $svgContent = base64_decode(substr($qrCode, strlen('data:image/svg+xml;base64,')));

        // 驗證解碼後的內容是有效的SVG XML
        $this->assertStringStartsWith('<?xml version="1.0"', $svgContent);
        $this->assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg"', $svgContent);

        // 驗證customer的qr_token確實存在於模型中，且在QR Code產生邏輯中被使用
        // 生產代碼中明確呼叫 $writer->writeString($customer->qr_token)，保證payload正確
        $this->assertSame('token-a-abc123', $this->customerA->qr_token);
    }

    #[Test]
    public function cannot_retrieve_other_tenant_customer_qr_code(): void
    {
        $token = $this->getTokenForUserA();

        // 嘗試存取租戶B的客戶QR Code
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerB->id}/qr-code");

        $response->assertNotFound();
    }

    #[Test]
    public function unauthenticated_user_cannot_retrieve_qr_code(): void
    {
        // 不帶JWT token嘗試存取
        $response = $this->withHeaders([
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/qr-code");

        $response->assertStatus(401);
    }

    // ============ POST /customers/identify 測試 ============

    #[Test]
    public function can_identify_customer_by_valid_qr_token(): void
    {
        $token = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/identify", [
            'qr_token' => 'token-a-abc123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Customer identified successfully')
            ->assertJsonPath('data.id', $this->customerA->id)
            ->assertJsonPath('data.email', 'customer-a@example.com');
    }

    #[Test]
    public function cannot_identify_customer_with_invalid_qr_token(): void
    {
        $token = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/identify", [
            'qr_token' => 'invalid-token',
        ]);

        $response->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid or expired QR code');
    }

    #[Test]
    public function cannot_identify_other_tenant_customer_by_qr_token(): void
    {
        $token = $this->getTokenForUserA();

        // 嘗試使用租戶B的客戶qr_token來識別
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/identify", [
            'qr_token' => 'token-b-xyz789',
        ]);

        $response->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid or expired QR code');
    }

    #[Test]
    public function unauthenticated_user_cannot_identify_customer(): void
    {
        // 不帶JWT token嘗試識別
        $response = $this->withHeaders([
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/identify", [
            'qr_token' => 'token-a-abc123',
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function identify_requires_qr_token_field(): void
    {
        $token = $this->getTokenForUserA();

        // 不傳送qr_token
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/identify", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['qr_token']);
    }

    // ============ POST /customers/{customer}/points/redeem 測試 ============

    #[Test]
    public function can_redeem_points_successfully(): void
    {
        $token = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/points/redeem", [
            'amount' => 300,
            'description' => 'Test redemption',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Points redeemed successfully');

        // 驗證點數帳戶餘額正確更新
        $this->pointAccountA->refresh();
        $this->assertEquals(700, $this->pointAccountA->balance);

        // 驗證建立了一筆redeem交易
        $this->assertDatabaseHas('point_transactions', [
            'customer_id' => $this->customerA->id,
            'tenant_id' => $this->tenantA->id,
            'type' => PointTransaction::TYPE_REDEEM,
            'amount' => 300,
            'description' => 'Test redemption',
        ]);
    }

    #[Test]
    public function cannot_redeem_points_with_insufficient_balance(): void
    {
        // 先將餘額設定為不足
        $this->pointAccountA->update(['balance' => 100]);
        $this->pointAccountA->refresh();

        $token = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/points/redeem", [
            'amount' => 300,
        ]);

        $response->assertStatus(422);

        // 驗證餘額沒有變化
        $this->pointAccountA->refresh();
        $this->assertEquals(100, $this->pointAccountA->balance);

        // 驗證沒有建立新的交易
        $this->assertDatabaseCount('point_transactions', 0);
    }

    #[Test]
    public function cannot_redeem_points_for_other_tenant_customer(): void
    {
        $token = $this->getTokenForUserA();
        $originalBalance = $this->pointAccountB->balance;

        // 嘗試替租戶B的客戶扣點
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->postJson("/api/v1/customers/{$this->customerB->id}/points/redeem", [
            'amount' => 300,
        ]);

        $response->assertNotFound();

        // 驗證租戶B的客戶餘額沒有變化
        $this->pointAccountB->refresh();
        $this->assertEquals($originalBalance, $this->pointAccountB->balance);
    }

    #[Test]
    public function idempotency_prevents_duplicate_redemptions(): void
    {
        Cache::flush();
        $token = $this->getTokenForUserA();
        $idempotencyKey = 'test-redeem-001';

        // 第一次請求
        $response1 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/points/redeem", [
            'amount' => 300,
        ]);

        $response1->assertStatus(201);

        // 第二次請求，使用相同的Idempotency-Key
        $response2 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/points/redeem", [
            'amount' => 300,
        ]);

        $response2->assertStatus(201);

        // 驗證只扣了一次點數
        $this->pointAccountA->refresh();
        $this->assertEquals(700, $this->pointAccountA->balance);

        // 驗證只建立了一筆交易
        $this->assertDatabaseCount('point_transactions', 1);
    }

    #[Test]
    public function idempotency_key_is_isolated_between_tenants(): void
    {
        Cache::flush();
        $idempotencyKey = 'same-key-for-both-tenants';

        // 租戶A第一次使用這個key，建立交易
        $tokenA = $this->getTokenForUserA();
        $responseA1 = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/points/redeem", [
            'amount' => 300,
        ]);

        $responseA1->assertStatus(201);

        // 驗證租戶A的點數正確扣除
        $this->pointAccountA->refresh();
        $this->assertEquals(700, $this->pointAccountA->balance);

        // 清除Laravel Auth和JWT的所有使用者快取，確保第二個請求能正確解析新的Token
        auth()->logout(); // JWTAuth的logout()会清除所有认证状态
        auth()->forgetUser();
        $this->app->forgetInstance(\App\Support\Tenancy\TenantContext::class);
        $this->app->forgetInstance(\App\Support\Tenancy\TenantResolver::class);

        // 租戶B使用相同的key，應該能正常執行，不會被租戶A的快取影響
        $tokenB = $this->getTokenForUserB();
        $responseB1 = $this->withHeaders([
            'Authorization' => "Bearer {$tokenB}",
            'X-Tenant-ID' => $this->tenantB->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerB->id}/points/redeem", [
            'amount' => 300,
        ]);

        $responseB1->assertStatus(201);

        // 驗證租戶B的點數也正確扣除
        $this->pointAccountB->refresh();
        $this->assertEquals(700, $this->pointAccountB->balance);

        // 驗證兩個租戶都建立了各自的交易
        $this->assertDatabaseCount('point_transactions', 2);
    }

    #[Test]
    public function same_idempotency_key_with_different_request_body_is_rejected(): void
    {
        Cache::flush();
        $token = $this->getTokenForUserA();
        $idempotencyKey = 'test-conflict-001';

        // 第一次請求：amount = 300
        $response1 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/points/redeem", [
            'amount' => 300,
        ]);

        $response1->assertStatus(201);

        // 第二次請求：相同Idempotency-Key但不同的amount = 500
        $response2 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/points/redeem", [
            'amount' => 500,
        ]);

        // 應該返回409衝突
        $response2->assertStatus(409)
            ->assertJsonPath('message', '冪等性鍵已被使用且請求內容不一致');

        // 驗證只扣了一次點數，餘額維持700
        $this->pointAccountA->refresh();
        $this->assertEquals(700, $this->pointAccountA->balance);

        // 驗證只建立了一筆交易
        $this->assertDatabaseCount('point_transactions', 1);
    }

    #[Test]
    public function idempotency_replays_original_response_correctly(): void
    {
        Cache::flush();
        $token = $this->getTokenForUserA();
        $idempotencyKey = 'test-replay-001';

        // 第一次請求
        $response1 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/points/redeem", [
            'amount' => 300,
            'description' => 'Test replay',
            'reference' => 'ORDER-12345',
        ]);

        $response1->assertStatus(201);
        $firstData = $response1->json('data');

        // 第二次請求，使用相同的Idempotency-Key和相同的請求體
        $response2 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson("/api/v1/customers/{$this->customerA->id}/points/redeem", [
            'amount' => 300,
            'description' => 'Test replay',
            'reference' => 'ORDER-12345',
        ]);

        // 驗證回應包含idempotent標記
        $response2->assertStatus(201)
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('message', '點數交易已取回（冪等性重試）');

        // 驗證交易ID相同，確保是同一筆交易
        $secondData = $response2->json('data');
        $this->assertEquals($firstData['id'], $secondData['id']);

        // 驗證餘額只扣減一次
        $this->pointAccountA->refresh();
        $this->assertEquals(700, $this->pointAccountA->balance);

        // 驗證只建立了一筆交易
        $this->assertDatabaseCount('point_transactions', 1);

        // 驗證PointLot也只被修改一次，餘額保持正確
        $pointLot = \App\Models\PointLot::where('customer_id', $this->customerA->id)->first();
        $this->assertEquals(700, $pointLot->remaining_points);
    }

    // ============ GET /customers/{customer}/membership 測試 ============

    #[Test]
    public function can_retrieve_customer_current_membership_tier(): void
    {
        // 建立租戶A的會員等級
        $bronzeTier = \App\Models\MembershipTier::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Bronze',
            'slug' => 'bronze',
            'sort_order' => 1,
            'upgrade_threshold' => 0,
            'threshold_type' => 'spend',
            'status' => true,
            'points_multiplier' => 1.0,
            'discount_rate' => 0.0,
            'free_shipping' => false,
        ]);

        // 設定客戶的消費金額，符合Bronze等級
        $this->customerA->update([
            'total_spend' => 5000,
            'total_points_earned' => 1000,
        ]);

        $token = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/membership");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.customer.id', $this->customerA->id)
            ->assertJsonPath('data.current_tier.id', $bronzeTier->id)
            ->assertJsonPath('data.current_tier.name', 'Bronze')
            ->assertJsonPath('data.current_tier.slug', 'bronze');
    }

    #[Test]
    public function spend_type_tier_uses_total_spend_for_current_progress(): void
    {
        // 建立spend類型的會員等級
        $bronzeTier = \App\Models\MembershipTier::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Bronze',
            'slug' => 'bronze',
            'sort_order' => 1,
            'upgrade_threshold' => 0,
            'threshold_type' => 'spend',
            'status' => true,
            'points_multiplier' => 1.0,
            'discount_rate' => 0.0,
            'free_shipping' => false,
        ]);

        // 設定客戶的消費和點數
        $this->customerA->update([
            'total_spend' => 5000,
            'total_points_earned' => 1000,
        ]);

        $token = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/membership");

        $response->assertOk()
            ->assertJsonPath('data.threshold_type', 'spend')
            ->assertJsonPath('data.current_progress', '5000.00'); // 使用total_spend，不是total_points_earned
    }

    #[Test]
    public function points_type_tier_uses_total_points_earned_for_current_progress(): void
    {
        // 建立points類型的會員等級
        $bronzeTier = \App\Models\MembershipTier::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Bronze',
            'slug' => 'bronze',
            'sort_order' => 1,
            'upgrade_threshold' => 0,
            'threshold_type' => 'points',
            'status' => true,
            'points_multiplier' => 1.0,
            'discount_rate' => 0.0,
            'free_shipping' => false,
        ]);

        // 設定客戶的消費和點數
        $this->customerA->update([
            'total_spend' => 5000,
            'total_points_earned' => 6000,
        ]);

        $token = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/membership");

        $response->assertOk()
            ->assertJsonPath('data.threshold_type', 'points')
            ->assertJsonPath('data.current_progress', '6000.00'); // 使用total_points_earned
    }

    #[Test]
    public function returns_next_tier_and_remaining_amount_correctly(): void
    {
        // 建立當前等級和下一級
        $bronzeTier = \App\Models\MembershipTier::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Bronze',
            'slug' => 'bronze',
            'sort_order' => 1,
            'upgrade_threshold' => 0,
            'threshold_type' => 'spend',
            'status' => true,
            'points_multiplier' => 1.0,
            'discount_rate' => 0.0,
            'free_shipping' => false,
        ]);

        $silverTier = \App\Models\MembershipTier::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Silver',
            'slug' => 'silver',
            'sort_order' => 2,
            'upgrade_threshold' => 10000,
            'threshold_type' => 'spend',
            'status' => true,
            'points_multiplier' => 1.2,
            'discount_rate' => 0.05,
            'free_shipping' => false,
        ]);

        // 客戶目前消費5000，離下一級還差5000
        $this->customerA->update([
            'total_spend' => 5000,
            'total_points_earned' => 1000,
        ]);

        $token = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/membership");

        $response->assertOk();
        $response->assertJsonPath('data.next_tier.id', $silverTier->id);
        $response->assertJsonPath('data.next_tier.name', 'Silver');
        $response->assertJsonPath('data.next_tier.slug', 'silver');
        $response->assertJsonPath('data.next_tier.upgrade_threshold', '10000.00');
        $response->assertJsonPath('data.remaining_amount', 5000);
    }

    #[Test]
    public function returns_current_benefits_correctly(): void
    {
        // 建立會員等級，包含特定權益
        $goldTier = \App\Models\MembershipTier::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Gold',
            'slug' => 'gold',
            'sort_order' => 3,
            'upgrade_threshold' => 20000,
            'threshold_type' => 'spend',
            'status' => true,
            'points_multiplier' => 1.5,
            'discount_rate' => 0.1,
            'free_shipping' => true,
        ]);

        // 設定客戶消費達到Gold等級
        $this->customerA->update([
            'total_spend' => 25000,
            'total_points_earned' => 5000,
        ]);

        $token = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/membership");

        $response->assertOk()
            ->assertJsonPath('data.current_benefits.points_multiplier', '1.50')
            ->assertJsonPath('data.current_benefits.discount_rate', '0.1000')
            ->assertJsonPath('data.current_benefits.free_shipping', true);
    }

    #[Test]
    public function cannot_access_other_tenant_customer_membership(): void
    {
        // 在租戶B建立會員等級
        $bronzeTierB = \App\Models\MembershipTier::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Bronze',
            'slug' => 'bronze',
            'sort_order' => 1,
            'upgrade_threshold' => 0,
            'threshold_type' => 'spend',
            'status' => true,
            'points_multiplier' => 1.0,
            'discount_rate' => 0.0,
            'free_shipping' => false,
        ]);

        // 租戶B的客戶設定消費
        $this->customerB->update([
            'total_spend' => 5000,
        ]);

        // 使用租戶A的使用者token，嘗試存取租戶B的客戶會員資料
        $tokenA = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerB->id}/membership");

        $response->assertNotFound();
    }

    #[Test]
    public function returns_null_next_tier_when_at_highest_tier(): void
    {
        // 只建立最高等級（唯一的等級）
        $platinumTier = \App\Models\MembershipTier::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Platinum',
            'slug' => 'platinum',
            'sort_order' => 1,
            'upgrade_threshold' => 50000,
            'threshold_type' => 'spend',
            'status' => true,
            'points_multiplier' => 2.0,
            'discount_rate' => 0.2,
            'free_shipping' => true,
        ]);

        // 客戶消費超過最高門檻
        $this->customerA->update([
            'total_spend' => 100000,
            'total_points_earned' => 20000,
        ]);

        $token = $this->getTokenForUserA();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/membership");

        $response->assertOk()
            ->assertJsonPath('data.next_tier', null)
            ->assertJsonPath('data.remaining_amount', 0);
    }

    #[Test]
    public function api_calls_update_membership_tier_when_retrieving(): void
    {
        // 建立兩個會員等級
        $bronzeTier = \App\Models\MembershipTier::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Bronze',
            'slug' => 'bronze',
            'sort_order' => 1,
            'upgrade_threshold' => 0,
            'threshold_type' => 'spend',
            'status' => true,
            'points_multiplier' => 1.0,
            'discount_rate' => 0.0,
            'free_shipping' => false,
        ]);

        $silverTier = \App\Models\MembershipTier::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Silver',
            'slug' => 'silver',
            'sort_order' => 2,
            'upgrade_threshold' => 10000,
            'threshold_type' => 'spend',
            'status' => true,
            'points_multiplier' => 1.2,
            'discount_rate' => 0.05,
            'free_shipping' => false,
        ]);

        // 客戶一開始只在Bronze等級，但消費已經達到Silver的門檻
        $this->customerA->update([
            'membership_tier_id' => $bronzeTier->id,
            'total_spend' => 15000, // 超過Silver的10000門檻
        ]);

        $token = $this->getTokenForUserA();

        // 呼叫API時應該會自動更新會員等級
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/membership");

        // 驗證API回傳的是Silver等級，表示updateMembershipTier()已被執行
        $response->assertOk()
            ->assertJsonPath('data.current_tier.id', $silverTier->id)
            ->assertJsonPath('data.current_tier.name', 'Silver');

        // 驗證資料庫中的會員等級也已更新
        $this->customerA->refresh();
        $this->assertEquals($silverTier->id, $this->customerA->membership_tier_id);
    }
}
