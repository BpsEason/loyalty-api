<?php

namespace Tests\Feature\Services\Point;

use App\Models\Customer;
use App\Models\PointLot;
use App\Models\Tenant;
use App\Services\Point\PointService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PointLotFifoTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Customer $customer;
    protected PointService $pointService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'domain' => 'test.example.com',
        ]);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'phone' => '1234567890',
        ]);

        $this->pointService = app(PointService::class);
    }

    #[Test]
    public function fifo_consumption_works_correctly(): void
    {
        // 先獲得100點，然後再獲得50點
        $this->pointService->earn($this->customer, 100, 'First earn');
        sleep(1); // 確保時間戳不同
        $this->pointService->earn($this->customer, 50, 'Second earn');

        $account = $this->customer->refresh()->pointAccount;
        $this->assertEquals(150, $account->balance);

        // 檢查建立了兩個批次
        $lots = $account->pointLots()->orderBy('earned_at', 'asc')->get();
        $this->assertCount(2, $lots);
        $this->assertEquals(100, $lots[0]->original_points);
        $this->assertEquals(50, $lots[1]->original_points);

        // 兌換120點，應該先消耗第一個批次的全部100點，再消耗第二個批次的20點
        $this->pointService->redeem($this->customer, 120, 'Redeem 120');

        // 重新從數據庫加載批次數據
        $updatedLots = $account->pointLots()->orderBy('earned_at', 'asc')->get();
        $this->assertEquals(0, $updatedLots[0]->remaining_points);
        $this->assertEquals(30, $updatedLots[1]->remaining_points);

        $account->refresh();
        $this->assertEquals(30, $account->balance);
    }

    #[Test]
    public function exact_consumption_works(): void
    {
        $this->pointService->earn($this->customer, 100, 'Earn 100');

        $account = $this->customer->refresh()->pointAccount;
        $lots = $account->pointLots()->first();
        $this->assertEquals(100, $lots->remaining_points);

        // 剛好消耗全部100點
        $this->pointService->redeem($this->customer, 100, 'Redeem all');

        $updatedLot = $account->pointLots()->first();
        $this->assertEquals(0, $updatedLot->remaining_points);
        $this->assertEquals(0, $account->refresh()->balance);
    }

    #[Test]
    public function multiple_lots_consumption(): void
    {
        // 連續獲得三個100點的批次
        $this->pointService->earn($this->customer, 100, 'First 100');
        sleep(1);
        $this->pointService->earn($this->customer, 100, 'Second 100');
        sleep(1);
        $this->pointService->earn($this->customer, 100, 'Third 100');

        $account = $this->customer->refresh()->pointAccount;
        $this->assertEquals(300, $account->balance);

        // 兌換250點
        $this->pointService->redeem($this->customer, 250, 'Redeem 250');

        $lots = $account->pointLots()->orderBy('earned_at', 'asc')->get();
        $this->assertEquals(0, $lots[0]->remaining_points);
        $this->assertEquals(0, $lots[1]->remaining_points);
        $this->assertEquals(50, $lots[2]->remaining_points);
        $this->assertEquals(50, $account->refresh()->balance);
    }

    #[Test]
    public function insufficient_balance_prevents_consumption(): void
    {
        $this->pointService->earn($this->customer, 100, 'Earn 100');
        $account = $this->customer->refresh()->pointAccount;
        $lots = $account->pointLots()->first();
        $originalRemaining = $lots->remaining_points;

        // 嘗試兌換超過餘額的點數
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('點數餘額不足');

        try {
            $this->pointService->redeem($this->customer, 150, 'Try to redeem 150');
        } catch (\RuntimeException $e) {
            // 確保點數和批次都沒有被修改
            $lots->refresh();
            $account->refresh();
            $this->assertEquals($originalRemaining, $lots->remaining_points);
            $this->assertEquals(100, $account->balance);
            throw $e;
        }
    }

    #[Test]
    public function tenant_isolation_prevents_cross_tenant_access(): void
    {
        $this->pointService->earn($this->customer, 100, 'Earn for tenant 1');

        // 建立第二個租戶
        $tenant2 = Tenant::create([
            'name' => 'Second Tenant',
            'domain' => 'tenant2.example.com',
        ]);

        $customer2 = Customer::create([
            'tenant_id' => $tenant2->id,
            'name' => 'Customer 2',
            'email' => 'customer2@example.com',
            'phone' => '0987654321',
        ]);

        // 第二個租戶的客戶只能看到自己的批次
        $this->pointService->earn($customer2, 200, 'Earn for tenant 2');
        $lotsTenant2 = $customer2->refresh()->pointAccount->pointLots;
        $this->assertCount(1, $lotsTenant2);
        $this->assertEquals(200, $lotsTenant2[0]->original_points);

        // 第一個租戶的批次不會被第二個租戶看到
        $allLots = PointLot::all();
        $this->assertCount(2, $allLots);
        $this->assertEquals(100, $allLots[0]->original_points);
        $this->assertEquals(200, $allLots[1]->original_points);
    }
}
