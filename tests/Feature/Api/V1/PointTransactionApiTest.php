<?php

namespace Tests\Feature\Api\V1;

use App\Models\Customer;
use App\Models\PointAccount;
use App\Models\PointLot;
use App\Models\PointTransaction;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class PointTransactionApiTest extends TestCase
{
    use RefreshDatabase;

    protected $tenantA;
    protected $userA;
    protected $customerA;
    protected $pointAccountA;

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

        // 建立客戶A的點數帳戶
        $this->pointAccountA = PointAccount::create([
            'tenant_id' => $this->tenantA->id,
            'customer_id' => $this->customerA->id,
            'balance' => 1000,
            'total_earned' => 1000,
            'total_redeemed' => 0,
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
     * 為客戶A建立點數批次
     */
    protected function createPointLotForCustomerA(array $overrides = []): PointLot
    {
        return PointLot::create(array_merge([
            'tenant_id' => $this->tenantA->id,
            'customer_id' => $this->customerA->id,
            'point_account_id' => $this->pointAccountA->id,
            'original_points' => 500,
            'remaining_points' => 500,
            'earned_at' => now()->subDays(30),
            'expired_at' => now()->addDays(15), // 預設15天後過期
            'origin_transaction_id' => null,
        ], $overrides));
    }

    // ============ 1. 點數即將過期查詢API測試 ============
    #[Test]
    public function can_retrieve_expiring_point_lots_successfully(): void
    {
        $token = $this->getTokenForUserA();

        // 建立一個10天後過期的點數批次（會被包含在30天內）
        $lot1 = $this->createPointLotForCustomerA([
            'expired_at' => now()->addDays(10),
            'remaining_points' => 300,
        ]);

        // 建立一個40天後過期的點數批次（不會被包含在30天內）
        $lot2 = $this->createPointLotForCustomerA([
            'expired_at' => now()->addDays(40),
            'remaining_points' => 200,
        ]);

        // 建立一個已過期的點數批次（不會被包含）
        $lot3 = $this->createPointLotForCustomerA([
            'expired_at' => now()->subDays(5),
            'remaining_points' => 100,
        ]);

        // 建立一個餘額為0的點數批次（不會被包含）
        $lot4 = $this->createPointLotForCustomerA([
            'expired_at' => now()->addDays(10),
            'remaining_points' => 0,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/point-transactions/expiring?days=30");

        // 驗證API回應
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', '即將過期的點數批次查詢成功');

        $responseData = $response->json();

        // 驗證只返回符合條件的點數批次（只有lot1）
        $this->assertCount(1, $responseData['data']);
        $this->assertEquals($lot1->id, $responseData['data'][0]['id']);
        // days_until_expiry可能因為執行時間差而返回浮點數，使用assertGreaterThan或範圍驗證
        $this->assertGreaterThan(9, $responseData['data'][0]['days_until_expiry']);
        $this->assertLessThan(11, $responseData['data'][0]['days_until_expiry']);
    }

    #[Test]
    public function expiring_api_respects_custom_days_parameter(): void
    {
        $token = $this->getTokenForUserA();

        // 建立25天後過期的點數批次
        $lot1 = $this->createPointLotForCustomerA([
            'expired_at' => now()->addDays(25),
            'remaining_points' => 300,
        ]);

        // 建立35天後過期的點數批次
        $lot2 = $this->createPointLotForCustomerA([
            'expired_at' => now()->addDays(35),
            'remaining_points' => 200,
        ]);

        // 使用days=30查詢，只應該返回lot1
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/point-transactions/expiring?days=30");

        $response->assertStatus(200);
        $responseData = $response->json();
        $this->assertCount(1, $responseData['data']);

        // 使用days=40查詢，應該返回兩個批次
        $response2 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/point-transactions/expiring?days=40");
        $response2->assertStatus(200);
        $responseData2 = $response2->json();
        $this->assertCount(2, $responseData2['data']);
    }

    #[Test]
    public function cannot_access_expiring_lots_of_another_tenant_customer(): void
    {
        $token = $this->getTokenForUserB(); // 使用租戶B的token

        // 在租戶A的客戶下建立一個即將過期的點數批次
        $this->createPointLotForCustomerA([
            'expired_at' => now()->addDays(10),
            'remaining_points' => 300,
        ]);

        // 嘗試用租戶B的token存取租戶A客戶的點數批次
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantB->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/point-transactions/expiring");

        // 應該返回404
        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Customer not found');
    }

    #[Test]
    public function expiring_api_returns_expired_at_in_correct_order(): void
    {
        $token = $this->getTokenForUserA();

        // 建立不同過期時間的點數批次
        $lot1 = $this->createPointLotForCustomerA(['expired_at' => now()->addDays(5), 'remaining_points' => 100]);
        $lot2 = $this->createPointLotForCustomerA(['expired_at' => now()->addDays(20), 'remaining_points' => 100]);
        $lot3 = $this->createPointLotForCustomerA(['expired_at' => now()->addDays(10), 'remaining_points' => 100]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/point-transactions/expiring?days=30");

        $response->assertStatus(200);
        $responseData = $response->json();

        // 驗證返回的順序是按照過期時間從近到遠排序
        $this->assertCount(3, $responseData['data']);
        $this->assertEquals($lot1->id, $responseData['data'][0]['id']); // 5天後最先
        $this->assertEquals($lot3->id, $responseData['data'][1]['id']); // 然後是10天後
        $this->assertEquals($lot2->id, $responseData['data'][2]['id']); // 最後是20天後
    }

    // ============ 2. 點數流水完整查詢API測試 ============
    #[Test]
    public function can_retrieve_point_transactions_with_pagination(): void
    {
        $token = $this->getTokenForUserA();

        // 建立15筆點數交易記錄
        for ($i = 0; $i < 15; $i++) {
            PointTransaction::create([
                'tenant_id' => $this->tenantA->id,
                'customer_id' => $this->customerA->id,
                'point_account_id' => $this->pointAccountA->id,
                'type' => $i % 2 === 0 ? PointTransaction::TYPE_EARN : PointTransaction::TYPE_REDEEM,
                'amount' => 100,
                'balance_before' => 1000 - ($i * 100),
                'balance_after' => 1000 - (($i + 1) * 100),
                'description' => "Test transaction {$i}",
                'created_by' => $this->userA->id,
            ]);
        }

        // 測試第一頁，每頁10筆
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/point-transactions?per_page=10");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $responseData = $response->json();

        // 從調試輸出看到，$responseData['data']直接是數據數組
        $this->assertCount(10, $responseData['data']);
    }

    #[Test]
    public function can_filter_point_transactions_by_type(): void
    {
        $token = $this->getTokenForUserA();

        // 建立5筆獲得點數的交易
        for ($i = 0; $i < 5; $i++) {
            PointTransaction::create([
                'tenant_id' => $this->tenantA->id,
                'customer_id' => $this->customerA->id,
                'point_account_id' => $this->pointAccountA->id,
                'type' => PointTransaction::TYPE_EARN,
                'amount' => 100,
                'balance_before' => $i * 100,
                'balance_after' => ($i + 1) * 100,
                'description' => "Earn transaction {$i}",
                'created_by' => $this->userA->id,
            ]);
        }

        // 建立3筆兌換點數的交易
        for ($i = 0; $i < 3; $i++) {
            PointTransaction::create([
                'tenant_id' => $this->tenantA->id,
                'customer_id' => $this->customerA->id,
                'point_account_id' => $this->pointAccountA->id,
                'type' => PointTransaction::TYPE_REDEEM,
                'amount' => 100,
                'balance_before' => 500 - ($i * 100),
                'balance_after' => 500 - (($i + 1) * 100),
                'description' => "Redeem transaction {$i}",
                'created_by' => $this->userA->id,
            ]);
        }

        // 過濾只查詢兌換類型的交易
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantA->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/point-transactions?type=redeem");

        $response->assertStatus(200);
        $responseData = $response->json();

        // 從調試輸出看到，$responseData['data']直接是數據數組
        $this->assertCount(3, $responseData['data']);

        // 驗證所有返回的交易都是redeem類型
        foreach ($responseData['data'] as $transaction) {
            $this->assertEquals(PointTransaction::TYPE_REDEEM, $transaction['type']);
        }
    }

    #[Test]
    public function cannot_access_point_transactions_of_another_tenant_customer(): void
    {
        $token = $this->getTokenForUserB();

        // 在租戶A的客戶下建立一筆點數交易
        PointTransaction::create([
            'tenant_id' => $this->tenantA->id,
            'customer_id' => $this->customerA->id,
            'point_account_id' => $this->pointAccountA->id,
            'type' => PointTransaction::TYPE_EARN,
            'amount' => 100,
            'balance_before' => 0,
            'balance_after' => 100,
            'description' => 'Test transaction',
            'created_by' => $this->userA->id,
        ]);

        // 嘗試用租戶B的token存取租戶A客戶的點數流水
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => $this->tenantB->id,
        ])->getJson("/api/v1/customers/{$this->customerA->id}/point-transactions");

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }
}
