<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Customer;
use App\Models\PointAccount;
use App\Models\PointLot;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;
use Tymon\JWTAuth\Facades\JWTAuth;

class ExpiringPointsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_expiring_points_api_returns_only_upcoming_expirations()
    {
        // 手動建立租戶
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'domain' => 'test.example.com',
            'is_active' => true,
            'settings' => [],
        ]);

        // 手動建立使用者（使用UserFactory，因為User有工廠）
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        // 手動建立客戶
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'phone' => '1234567890',
        ]);

        // 手動建立點數帳戶
        $account = PointAccount::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'balance' => 300,
        ]);

        // 手動建立原始交易
        $originTransaction = PointTransaction::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $account->id,
            'type' => 'earn',
            'amount' => 300,
            'balance_before' => 0,
            'balance_after' => 300,
        ]);

        // 7天後過期 - 應該被返回
        PointLot::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $account->id,
            'original_points' => 100,
            'remaining_points' => 100,
            'earned_at' => Carbon::now(),
            'expired_at' => Carbon::now()->addDays(5),
            'origin_transaction_id' => $originTransaction->id,
        ]);

        // 40天後過期 - 超出預設30天，不應該被返回
        PointLot::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $account->id,
            'original_points' => 100,
            'remaining_points' => 100,
            'earned_at' => Carbon::now(),
            'expired_at' => Carbon::now()->addDays(40),
            'origin_transaction_id' => $originTransaction->id,
        ]);

        // 已過期 - 不應該被返回
        PointLot::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $account->id,
            'original_points' => 100,
            'remaining_points' => 100,
            'earned_at' => Carbon::now()->subMonths(6),
            'expired_at' => Carbon::now()->subDay(),
            'origin_transaction_id' => $originTransaction->id,
        ]);

        // 剩餘點數為0 - 不應該被返回
        PointLot::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $account->id,
            'original_points' => 100,
            'remaining_points' => 0,
            'earned_at' => Carbon::now(),
            'expired_at' => Carbon::now()->addDays(5),
            'origin_transaction_id' => $originTransaction->id,
        ]);

        $token = JWTAuth::fromUser($user);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Tenant-ID' => $tenant->id])
            ->getJson("/api/v1/customers/{$customer->id}/point-transactions/expiring");

        $response->assertStatus(200);

        // 應該只返回1個符合條件的批次（5天內到期且剩餘>0）
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(100, $response->json('data.0.remaining_points'));
    }

    public function test_expiring_points_respects_customer_isolation()
    {
        // 手動建立租戶
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'domain' => 'test.example.com',
            'is_active' => true,
            'settings' => [],
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        // 建立兩個客戶
        $customer1 = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Customer 1',
            'email' => 'customer1@example.com',
            'phone' => '1234567890',
        ]);

        $customer2 = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Customer 2',
            'email' => 'customer2@example.com',
            'phone' => '0987654321',
        ]);

        // 建立兩個點數帳戶
        $account1 = PointAccount::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer1->id,
            'balance' => 100,
        ]);

        $account2 = PointAccount::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer2->id,
            'balance' => 200,
        ]);

        // 建立客戶1的原始交易
        $originTransaction1 = PointTransaction::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer1->id,
            'point_account_id' => $account1->id,
            'type' => 'earn',
            'amount' => 100,
            'balance_before' => 0,
            'balance_after' => 100,
        ]);

        // 建立客戶2的原始交易
        $originTransaction2 = PointTransaction::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer2->id,
            'point_account_id' => $account2->id,
            'type' => 'earn',
            'amount' => 200,
            'balance_before' => 0,
            'balance_after' => 200,
        ]);

        // 為客戶1建立即將過期的點數
        PointLot::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer1->id,
            'point_account_id' => $account1->id,
            'original_points' => 100,
            'remaining_points' => 100,
            'earned_at' => Carbon::now(),
            'expired_at' => Carbon::now()->addDays(5),
            'origin_transaction_id' => $originTransaction1->id,
        ]);

        // 為客戶2也建立符合條件的即將過期點數
        PointLot::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer2->id,
            'point_account_id' => $account2->id,
            'original_points' => 200,
            'remaining_points' => 200,
            'earned_at' => Carbon::now(),
            'expired_at' => Carbon::now()->addDays(10),
            'origin_transaction_id' => $originTransaction2->id,
        ]);

        $token = JWTAuth::fromUser($user);

        // 訪問客戶2的API，應該只能看到客戶2自己的點數
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Tenant-ID' => $tenant->id])
            ->getJson("/api/v1/customers/{$customer2->id}/point-transactions/expiring");

        $response->assertStatus(200);
        // 只返回客戶2的1筆資料
        $this->assertCount(1, $response->json('data'));
        // 驗證回傳的是客戶2的點數（200剩餘點數）
        $this->assertEquals(200, $response->json('data.0.remaining_points'));
        // 驗證不會包含客戶1的100點數資料（確保不會跨客戶讀取）
        $customer1Points = collect($response->json('data'))->pluck('remaining_points')->filter(fn($points) => $points === 100);
        $this->assertCount(0, $customer1Points);
    }

    public function test_expiring_points_respects_tenant_isolation()
    {
        // 建立兩個租戶
        $tenant1 = Tenant::create([
            'name' => 'Test Tenant 1',
            'domain' => 'test1.example.com',
            'is_active' => true,
            'settings' => [],
        ]);

        $tenant2 = Tenant::create([
            'name' => 'Test Tenant 2',
            'domain' => 'test2.example.com',
            'is_active' => true,
            'settings' => [],
        ]);

        $user1 = User::factory()->create(['tenant_id' => $tenant1->id]);
        $customer1 = Customer::create([
            'tenant_id' => $tenant1->id,
            'name' => 'Customer 1',
            'email' => 'customer1@example.com',
            'phone' => '1234567890',
        ]);

        $customer2 = Customer::create([
            'tenant_id' => $tenant2->id,
            'name' => 'Customer 2',
            'email' => 'customer2@example.com',
            'phone' => '0987654321',
        ]);

        $account1 = PointAccount::create([
            'tenant_id' => $tenant1->id,
            'customer_id' => $customer1->id,
            'balance' => 100,
        ]);
        $account2 = PointAccount::create([
            'tenant_id' => $tenant2->id,
            'customer_id' => $customer2->id,
            'balance' => 100,
        ]);

        $originTransaction = PointTransaction::create([
            'tenant_id' => $tenant1->id,
            'customer_id' => $customer1->id,
            'point_account_id' => $account1->id,
            'type' => 'earn',
            'amount' => 100,
            'balance_before' => 0,
            'balance_after' => 100,
        ]);

        // 為租戶1的客戶建立即將過期的點數
        PointLot::create([
            'tenant_id' => $tenant1->id,
            'customer_id' => $customer1->id,
            'point_account_id' => $account1->id,
            'original_points' => 100,
            'remaining_points' => 100,
            'earned_at' => Carbon::now(),
            'expired_at' => Carbon::now()->addDays(5),
            'origin_transaction_id' => $originTransaction->id,
        ]);

        $token = JWTAuth::fromUser($user1);

        // 嘗試用租戶1的token訪問租戶2的客戶數據
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Tenant-ID' => $tenant1->id])
            ->getJson("/api/v1/customers/{$customer2->id}/point-transactions/expiring");

        $response->assertStatus(404);
    }
}
