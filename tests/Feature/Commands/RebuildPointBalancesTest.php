<?php

namespace Tests\Feature\Commands;

use App\Models\Customer;
use App\Models\PointAccount;
use App\Models\PointTransaction;
use App\Models\Tenant;
use App\Services\Point\PointService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RebuildPointBalancesTest extends TestCase
{
    use RefreshDatabase;

    protected PointService $pointService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pointService = app(PointService::class);
    }

    /** @test */
    public function it_verifies_that_normal_account_balances_are_consistent()
    {
        // 建立租戶、客戶
        $tenant = Tenant::create(['name' => 'Test Tenant', 'domain' => 'test.local', 'is_active' => true]);
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Customer',
            'email' => 'customer@test.com',
            'phone' => '1234567890',
        ]);

        // 執行一些點數操作，這會自動建立PointAccount
        $this->pointService->earn($customer, 100, '首次註冊贈送');
        $this->pointService->redeem($customer, 30, '消費扣點');
        $this->pointService->earn($customer, 50, '活動贈送');

        // 點數操作後才取得Account
        $account = PointAccount::firstWhere('customer_id', $customer->id);

        // 確認目前餘額正確
        $this->assertEquals(120, $account->fresh()->balance);

        // 執行檢查指令
        $this->artisan('points:rebuild-balance')
            ->expectsOutput('已檢查帳戶數：1')
            ->expectsOutput('不一致帳戶數：0')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_detects_balance_mismatches()
    {
        // 建立租戶、客戶和點數帳戶
        $tenant = Tenant::create(['name' => 'Test Tenant', 'domain' => 'test.local', 'is_active' => true]);
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Customer',
            'email' => 'customer@test.com',
            'phone' => '1234567890',
        ]);

        // 執行一些點數操作，這會自動建立PointAccount
        $this->pointService->earn($customer, 100, '首次註冊贈送');
        $this->pointService->redeem($customer, 30, '消費扣點');

        // 點數操作後才取得Account
        $account = PointAccount::firstWhere('customer_id', $customer->id);

        // 人為製造不一致
        $account->update(['balance' => 80]); // 正確應該是70

        // 執行檢查指令，應該偵測到不一致
        $this->artisan('points:rebuild-balance')
            ->expectsOutput('  帳戶 #' . $account->id . '（客戶 #' . $customer->id . '）餘額不一致')
            ->expectsOutput('    目前餘額：80')
            ->expectsOutput('    重建餘額：70')
            ->expectsOutput('    差異：-10')
            ->expectsOutput('已檢查帳戶數：1')
            ->expectsOutput('不一致帳戶數：1')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_repairs_balance_mismatches_when_repair_option_is_used()
    {
        // 建立租戶、客戶和點數帳戶
        $tenant = Tenant::create(['name' => 'Test Tenant', 'domain' => 'test.local', 'is_active' => true]);
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Customer',
            'email' => 'customer@test.com',
            'phone' => '1234567890',
        ]);

        // 執行一些點數操作，這會自動建立PointAccount
        $this->pointService->earn($customer, 100, '首次註冊贈送');
        $this->pointService->redeem($customer, 30, '消費扣點');

        // 點數操作後才取得Account
        $account = PointAccount::firstWhere('customer_id', $customer->id);

        // 人為製造不一致
        $account->update(['balance' => 80]); // 正確應該是70
        $this->assertEquals(80, $account->fresh()->balance);

        // 執行修復指令
        $this->artisan('points:rebuild-balance --repair')
            ->expectsOutput('    已修復餘額')
            ->assertExitCode(0);

        // 確認餘額已被修復
        $this->assertEquals(70, $account->fresh()->balance);
    }

    /** @test */
    public function it_maintains_tenant_isolation_and_processes_all_tenants()
    {
        // 建立兩個不同的租戶
        $tenant1 = Tenant::create(['name' => 'Tenant 1', 'domain' => 'tenant1.local', 'is_active' => true]);
        $tenant2 = Tenant::create(['name' => 'Tenant 2', 'domain' => 'tenant2.local', 'is_active' => true]);

        // 建立兩個租戶的客戶和帳戶
        $customer1 = Customer::create([
            'tenant_id' => $tenant1->id,
            'name' => 'Customer 1',
            'email' => 'customer1@tenant1.com',
            'phone' => '1111111111',
        ]);
        $customer2 = Customer::create([
            'tenant_id' => $tenant2->id,
            'name' => 'Customer 2',
            'email' => 'customer2@tenant2.com',
            'phone' => '2222222222',
        ]);

        // 在不同租戶執行點數操作（這會自動建立PointAccount）
        $this->pointService->earn($customer1, 100, '租戶1的點數');
        $this->pointService->earn($customer2, 200, '租戶2的點數');

        // 點數操作完成後才重新取得帳戶
        $account1 = PointAccount::firstWhere('customer_id', $customer1->id);
        $account2 = PointAccount::firstWhere('customer_id', $customer2->id);

        // 人為製造不一致
        $account1->update(['balance' => 90]); // 正確應該是100
        $account2->update(['balance' => 190]); // 正確應該是200

        // 執行檢查指令，應該同時處理兩個租戶
        $this->artisan('points:rebuild-balance')
            ->expectsOutput('處理租戶 #' . $tenant1->id . ' 的帳戶...')
            ->expectsOutput('處理租戶 #' . $tenant2->id . ' 的帳戶...')
            ->expectsOutput('已檢查帳戶數：2')
            ->expectsOutput('不一致帳戶數：2')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_handles_all_transaction_types_correctly()
    {
        // 建立租戶、客戶
        $tenant = Tenant::create(['name' => 'Test Tenant', 'domain' => 'test.local', 'is_active' => true]);
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Customer',
            'email' => 'customer@test.com',
            'phone' => '1234567890',
        ]);

        // 測試所有交易類型，這些操作會自動建立PointAccount
        $this->pointService->earn($customer, 100); // earn: +100 => 100
        $this->pointService->redeem($customer, 20); // redeem: -20 => 80
        $this->pointService->adjust($customer, 30); // adjust: +30 => 110
        $this->pointService->adjust($customer, -10); // adjust: -10 => 100
        $this->pointService->refund($customer, 15); // refund: +15 => 115
        $this->pointService->expire($customer, 5); // expire: -5 => 110

        // 點數操作後才取得Account
        $account = PointAccount::firstWhere('customer_id', $customer->id);

        // 確認最終餘額
        $this->assertEquals(110, $account->fresh()->balance);

        // 人為製造不一致
        $account->update(['balance' => 100]);

        // 執行修復指令
        $this->artisan('points:rebuild-balance --repair')
            ->assertExitCode(0);

        // 確認餘額已正確重建
        $this->assertEquals(110, $account->fresh()->balance);
    }
}
