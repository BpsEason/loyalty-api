<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Customer;
use App\Models\PointAccount;
use App\Models\PointLot;
use App\Models\PointTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class PointsExpirationTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_point_lot_gets_processed()
    {
        // 手動建立租戶
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'domain' => 'test.example.com',
            'is_active' => true,
            'settings' => [],
        ]);

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
            'balance' => 100,
        ]);

        // 建立原始交易
        $originTransaction = PointTransaction::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $account->id,
            'type' => 'earn',
            'amount' => 100,
            'balance_before' => 0,
            'balance_after' => 100,
        ]);

        // 建立一個已過期的 PointLot
        $expiredLot = PointLot::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $account->id,
            'original_points' => 100,
            'remaining_points' => 100,
            'earned_at' => Carbon::now()->subMonths(6),
            'expired_at' => Carbon::now()->subDay(), // 已過期
            'origin_transaction_id' => $originTransaction->id,
        ]);

        // 執行過期命令
        $this->artisan('points:expire')->assertExitCode(0);

        // 重新載入資料庫
        $expiredLot->refresh();
        $account->refresh();

        // 驗證剩餘點數已變為 0
        $this->assertEquals(0, $expiredLot->remaining_points);

        // 驗證帳戶餘額已扣除
        $this->assertEquals(0, $account->balance);

        // 驗證已建立過期交易
        $this->assertDatabaseHas('point_transactions', [
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $account->id,
            'type' => PointTransaction::TYPE_EXPIRE,
            'amount' => -100,
        ]);
    }

    public function test_non_expired_lot_is_not_processed()
    {
        // 手動建立租戶
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'domain' => 'test.example.com',
            'is_active' => true,
            'settings' => [],
        ]);

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
            'balance' => 100,
        ]);

        // 建立原始交易
        $originTransaction = PointTransaction::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $account->id,
            'type' => 'earn',
            'amount' => 100,
            'balance_before' => 0,
            'balance_after' => 100,
        ]);

        // 建立一個尚未過期的 PointLot
        $activeLot = PointLot::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $account->id,
            'original_points' => 100,
            'remaining_points' => 100,
            'earned_at' => Carbon::now(),
            'expired_at' => Carbon::now()->addMonths(6), // 未過期
            'origin_transaction_id' => $originTransaction->id,
        ]);

        // 執行過期命令
        $this->artisan('points:expire')->assertExitCode(0);

        // 重新載入
        $activeLot->refresh();
        $account->refresh();

        // 驗證點數未變
        $this->assertEquals(100, $activeLot->remaining_points);
        $this->assertEquals(100, $account->balance);

        // 驗證沒有建立過期交易
        $this->assertDatabaseMissing('point_transactions', [
            'type' => PointTransaction::TYPE_EXPIRE,
            'point_account_id' => $account->id,
        ]);
    }

    public function test_zero_remaining_lot_is_not_processed()
    {
        // 手動建立租戶
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'domain' => 'test.example.com',
            'is_active' => true,
            'settings' => [],
        ]);

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
            'balance' => 0,
        ]);

        // 建立原始交易
        $originTransaction = PointTransaction::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $account->id,
            'type' => 'earn',
            'amount' => 100,
            'balance_before' => 0,
            'balance_after' => 100,
        ]);

        // 建立一個已用完的 PointLot（即使過期也不處理）
        $zeroLot = PointLot::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $account->id,
            'original_points' => 100,
            'remaining_points' => 0,
            'earned_at' => Carbon::now()->subMonths(6),
            'expired_at' => Carbon::now()->subDay(),
            'origin_transaction_id' => $originTransaction->id,
        ]);

        // 執行過期命令
        $this->artisan('points:expire')->assertExitCode(0);

        // 驗證沒有建立新的交易
        $this->assertDatabaseMissing('point_transactions', [
            'type' => PointTransaction::TYPE_EXPIRE,
            'point_account_id' => $account->id,
        ]);
    }

    public function test_command_is_idempotent()
    {
        // 手動建立租戶
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'domain' => 'test.example.com',
            'is_active' => true,
            'settings' => [],
        ]);

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
            'balance' => 100,
        ]);

        // 建立原始交易
        $originTransaction = PointTransaction::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $account->id,
            'type' => 'earn',
            'amount' => 100,
            'balance_before' => 0,
            'balance_after' => 100,
        ]);

        // 建立一個已過期的 PointLot
        $expiredLot = PointLot::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $account->id,
            'original_points' => 100,
            'remaining_points' => 100,
            'earned_at' => Carbon::now()->subMonths(6),
            'expired_at' => Carbon::now()->subDay(),
            'origin_transaction_id' => $originTransaction->id,
        ]);

        // 第一次執行
        $this->artisan('points:expire')->assertExitCode(0);

        // 記錄當前的過期交易數量
        $expireTransactionsCount = PointTransaction::where('type', PointTransaction::TYPE_EXPIRE)->count();

        // 第二次執行 - 冪等性測試
        $this->artisan('points:expire')->assertExitCode(0);

        // 驗證過期交易數量沒有增加（不會重複處理）
        $this->assertEquals($expireTransactionsCount, PointTransaction::where('type', PointTransaction::TYPE_EXPIRE)->count());
    }

    public function test_tenant_isolation_is_maintained()
    {
        // 建立兩個不同租戶
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

        // 租戶1的客戶和帳戶
        $customer1 = Customer::create([
            'tenant_id' => $tenant1->id,
            'name' => 'Customer 1',
            'email' => 'customer1@example.com',
            'phone' => '1234567890',
        ]);

        $account1 = PointAccount::create([
            'tenant_id' => $tenant1->id,
            'customer_id' => $customer1->id,
            'balance' => 100,
        ]);

        // 租戶2的客戶和帳戶
        $customer2 = Customer::create([
            'tenant_id' => $tenant2->id,
            'name' => 'Customer 2',
            'email' => 'customer2@example.com',
            'phone' => '0987654321',
        ]);

        $account2 = PointAccount::create([
            'tenant_id' => $tenant2->id,
            'customer_id' => $customer2->id,
            'balance' => 200,
        ]);

        // 建立原始交易
        $originTransaction1 = PointTransaction::create([
            'tenant_id' => $tenant1->id,
            'customer_id' => $customer1->id,
            'point_account_id' => $account1->id,
            'type' => 'earn',
            'amount' => 100,
            'balance_before' => 0,
            'balance_after' => 100,
        ]);

        $originTransaction2 = PointTransaction::create([
            'tenant_id' => $tenant2->id,
            'customer_id' => $customer2->id,
            'point_account_id' => $account2->id,
            'type' => 'earn',
            'amount' => 200,
            'balance_before' => 0,
            'balance_after' => 200,
        ]);

        // 建立兩個租戶的過期批次
        PointLot::create([
            'tenant_id' => $tenant1->id,
            'customer_id' => $customer1->id,
            'point_account_id' => $account1->id,
            'original_points' => 100,
            'remaining_points' => 100,
            'earned_at' => Carbon::now()->subMonths(6),
            'expired_at' => Carbon::now()->subDay(),
            'origin_transaction_id' => $originTransaction1->id,
        ]);
        PointLot::create([
            'tenant_id' => $tenant2->id,
            'customer_id' => $customer2->id,
            'point_account_id' => $account2->id,
            'original_points' => 200,
            'remaining_points' => 200,
            'earned_at' => Carbon::now()->subMonths(6),
            'expired_at' => Carbon::now()->subDay(),
            'origin_transaction_id' => $originTransaction2->id,
        ]);

        // 執行過期命令
        $this->artisan('points:expire')->assertExitCode(0);

        // 驗證兩個租戶的點數都正確扣除
        $account1->refresh();
        $account2->refresh();
        $this->assertEquals(0, $account1->balance);
        $this->assertEquals(0, $account2->balance);

        // 驗證兩個租戶都有各自的過期交易
        $this->assertDatabaseHas('point_transactions', [
            'tenant_id' => $tenant1->id,
            'type' => PointTransaction::TYPE_EXPIRE,
            'amount' => -100,
        ]);
        $this->assertDatabaseHas('point_transactions', [
            'tenant_id' => $tenant2->id,
            'type' => PointTransaction::TYPE_EXPIRE,
            'amount' => -200,
        ]);
    }

    public function test_insufficient_account_balance_does_not_lose_point_lot_points()
    {
        // 手動建立租戶
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'domain' => 'test.example.com',
            'is_active' => true,
            'settings' => [],
        ]);

        // 手動建立客戶
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'phone' => '1234567890',
        ]);

        // 手動建立點數帳戶 - 餘額60，不足要過期的100點
        $account = PointAccount::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'balance' => 60,
        ]);

        // 建立原始交易
        $originTransaction = PointTransaction::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $account->id,
            'type' => 'earn',
            'amount' => 100,
            'balance_before' => 0,
            'balance_after' => 100,
        ]);

        // 建立一個已過期的 PointLot，剩餘點數100
        $pointLot = PointLot::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $account->id,
            'original_points' => 100,
            'remaining_points' => 100,
            'earned_at' => Carbon::now()->subMonths(6),
            'expired_at' => Carbon::now()->subDay(), // 已過期
            'origin_transaction_id' => $originTransaction->id,
        ]);

        // 再建立另一個正常的租戶和點數，確保command不會因為這個錯誤而停止處理其他資料
        $tenant2 = Tenant::create([
            'name' => 'Test Tenant 2',
            'domain' => 'test2.example.com',
            'is_active' => true,
            'settings' => [],
        ]);
        $customer2 = Customer::create([
            'tenant_id' => $tenant2->id,
            'name' => 'Customer 2',
            'email' => 'customer2@example.com',
            'phone' => '0987654321',
        ]);
        $account2 = PointAccount::create([
            'tenant_id' => $tenant2->id,
            'customer_id' => $customer2->id,
            'balance' => 150,
        ]);
        $originTransaction2 = PointTransaction::create([
            'tenant_id' => $tenant2->id,
            'customer_id' => $customer2->id,
            'point_account_id' => $account2->id,
            'type' => 'earn',
            'amount' => 150,
            'balance_before' => 0,
            'balance_after' => 150,
        ]);
        $pointLot2 = PointLot::create([
            'tenant_id' => $tenant2->id,
            'customer_id' => $customer2->id,
            'point_account_id' => $account2->id,
            'original_points' => 150,
            'remaining_points' => 150,
            'earned_at' => Carbon::now()->subMonths(6),
            'expired_at' => Carbon::now()->subDay(),
            'origin_transaction_id' => $originTransaction2->id,
        ]);

        // 執行過期命令，預期命令仍然完成（exit code 0），不會中斷整批處理
        $this->artisan('points:expire')->assertExitCode(0);

        // 驗證原本餘額不足的PointLot仍然保持100，沒有被歸零
        $pointLot->refresh();
        $this->assertEquals(100, $pointLot->remaining_points);

        // 驗證帳戶餘額仍然是60，沒有被修改
        $account->refresh();
        $this->assertEquals(60, $account->balance);

        // 驗證沒有為這個不足的帳戶建立TYPE_EXPIRE交易
        $this->assertDatabaseMissing('point_transactions', [
            'point_account_id' => $account->id,
            'type' => PointTransaction::TYPE_EXPIRE,
        ]);

        // 驗證另一個正常的帳戶仍然被正確處理（確保command沒有中斷）
        $account2->refresh();
        $pointLot2->refresh();
        $this->assertEquals(0, $account2->balance);
        $this->assertEquals(0, $pointLot2->remaining_points);
        $this->assertDatabaseHas('point_transactions', [
            'point_account_id' => $account2->id,
            'type' => PointTransaction::TYPE_EXPIRE,
            'amount' => -150,
        ]);
    }
}
