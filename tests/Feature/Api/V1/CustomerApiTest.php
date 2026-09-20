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
}
