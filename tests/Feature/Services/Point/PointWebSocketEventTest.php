<?php

namespace Tests\Feature\Services\Point;

use App\Events\PointsUpdated;
use App\Models\Customer;
use App\Models\PointLot;
use App\Models\PointTransaction;
use App\Models\Tenant;
use App\Services\Point\PointService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class PointWebSocketEventTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Customer $customer;
    protected PointService $pointService;

    protected function setUp(): void
    {
        parent::setUp();

        // 建立租戶
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'domain' => 'test.example.com',
        ]);

        // 建立客戶
        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'phone' => '1234567890',
        ]);

        $this->pointService = app(PointService::class);
    }

    #[Test]
    public function adjust_method_dispatches_points_updated_after_commit(): void
    {
        Event::fake();

        // 先預存一些點數
        $this->pointService->earn($this->customer, 100, 'Initial balance');
        Event::assertDispatched(PointsUpdated::class);
        Event::fake(); // 重置事件假

        // 執行adjust
        $adjustAmount = 50;
        $transaction = $this->pointService->adjust($this->customer, $adjustAmount, 'Adjust test');

        // 確認事件已被dispatch
        Event::assertDispatched(PointsUpdated::class, function (PointsUpdated $event) use ($adjustAmount, $transaction) {
            $account = $this->customer->pointAccount;

            return $event->tenantId === $this->tenant->id
                && $event->memberId === $this->customer->id
                && $event->transactionId === $transaction->id
                && $event->delta === $adjustAmount
                && $event->balance === $account->balance;
        });
    }

    #[Test]
    public function refund_method_dispatches_points_updated_after_commit(): void
    {
        Event::fake();

        // 先建立點數並兌換
        $this->pointService->earn($this->customer, 100, 'Initial balance');
        $redeemTransaction = $this->pointService->redeem($this->customer, 50, 'Redeem for test');
        Event::fake(); // 重置事件假

        // 執行refund
        $refundAmount = 30;
        $refundTransaction = $this->pointService->refund($this->customer, $refundAmount, 'Refund test', $redeemTransaction);

        // 確認事件已被dispatch
        Event::assertDispatched(PointsUpdated::class, function (PointsUpdated $event) use ($refundAmount, $refundTransaction) {
            $account = $this->customer->pointAccount;

            return $event->tenantId === $this->tenant->id
                && $event->memberId === $this->customer->id
                && $event->transactionId === $refundTransaction->id
                && $event->delta === $refundAmount
                && $event->balance === $account->balance;
        });
    }

    #[Test]
    public function expire_method_dispatches_points_updated_after_commit(): void
    {
        Event::fake();

        // 先建立點數
        $this->pointService->earn($this->customer, 100, 'Initial balance');
        Event::fake(); // 重置事件假

        // 執行expire
        $expireAmount = 40;
        $expireTransaction = $this->pointService->expire($this->customer, $expireAmount, 'Expire test');

        // 確認事件已被dispatch
        Event::assertDispatched(PointsUpdated::class, function (PointsUpdated $event) use ($expireAmount, $expireTransaction) {
            $account = $this->customer->pointAccount;
            $expectedBalance = 60; // 100 - 40

            return $event->tenantId === $this->tenant->id
                && $event->memberId === $this->customer->id
                && $event->transactionId === $expireTransaction->id
                && $event->delta === -$expireAmount
                && $event->balance === $expectedBalance;
        });
    }

    #[Test]
    public function expireAllExpiredLots_dispatches_points_updated_after_commit(): void
    {
        Event::fake();

        // 先建立點數並手動設定某個批次為已過期
        $this->pointService->earn($this->customer, 100, 'Initial balance');

        // 將第一個批次設定為已過期
        $lot = PointLot::where('customer_id', $this->customer->id)->first();
        $lot->update([
            'expired_at' => now()->subDay(),
        ]);

        Event::fake(); // 重置事件假

        // 執行批次過期
        [$totalExpired, $expireTransaction] = $this->pointService->expireAllExpiredLots($this->customer);

        $this->assertEquals(100, $totalExpired);
        $this->assertNotNull($expireTransaction);

        // 確認事件已被dispatch
        Event::assertDispatched(PointsUpdated::class, function (PointsUpdated $event) use ($totalExpired, $expireTransaction) {
            $account = $this->customer->pointAccount;
            $expectedBalance = 0; // 100 - 100

            return $event->tenantId === $this->tenant->id
                && $event->memberId === $this->customer->id
                && $event->transactionId === $expireTransaction->id
                && $event->delta === -$totalExpired
                && $event->balance === $expectedBalance;
        });
    }

    #[Test]
    public function points_updated_not_dispatched_when_transaction_rolls_back(): void
    {
        Event::fake();

        // 先建立初始點數
        $this->pointService->earn($this->customer, 50, 'Initial balance');

        // 手動建立一個會被rollback的交易，直接測試afterCommit行為
        Event::fake(); // 重置事件假

        try {
            DB::transaction(function () {
                // 在交易內執行一個會成功的操作，但最後主動拋出例外使交易rollback
                $this->pointService->earn($this->customer, 50, 'This should be rolled back');

                // 主動拋出例外來觸發rollback
                throw new RuntimeException('Force transaction rollback');
            });
        } catch (RuntimeException $e) {
            // 預期會失敗，交易已rollback
        }

        // 確認事件沒有被dispatch，因為afterCommit只有在交易成功提交後才會執行
        Event::assertNotDispatched(PointsUpdated::class);
    }

    #[Test]
    public function points_updated_broadcast_configuration_is_correct(): void
    {
        // 建立一個PointsUpdated事件實例來測試broadcast設定
        $event = new PointsUpdated(
            tenantId: $this->tenant->id,
            memberId: $this->customer->id,
            transactionId: 123,
            delta: 50,
            balance: 150
        );

        // 測試broadcastOn回傳正確的私有頻道
        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class, $channels[0]);
        // Laravel的PrivateChannel會自動在name前加上'private-'前綴，所以原始定義的名稱是正確的
        $expectedChannelName = 'tenant.' . $this->tenant->id . '.member.' . $this->customer->id;
        $this->assertEquals('private-' . $expectedChannelName, $channels[0]->name);

        // 測試broadcastAs回傳正確的事件名稱
        $this->assertEquals('points.updated', $event->broadcastAs());

        // 測試broadcastWith包含正確的payload結構
        $payload = $event->broadcastWith();
        $this->assertArrayHasKey('member_id', $payload);
        $this->assertArrayHasKey('transaction_id', $payload);
        $this->assertArrayHasKey('delta', $payload);
        $this->assertArrayHasKey('balance', $payload);
        $this->assertArrayHasKey('occurred_at', $payload);
        $this->assertEquals($this->customer->id, $payload['member_id']);
        $this->assertEquals(123, $payload['transaction_id']);
        $this->assertEquals(50, $payload['delta']);
        $this->assertEquals(150, $payload['balance']);
    }

    #[Test]
    public function tenant_isolation_is_maintained_in_events(): void
    {
        Event::fake();

        // 建立第二個租戶和客戶
        $tenant2 = Tenant::create([
            'name' => 'Second Tenant',
            'domain' => 'second.example.com',
        ]);

        $customer2 = Customer::create([
            'tenant_id' => $tenant2->id,
            'name' => 'Second Customer',
            'email' => 'customer2@example.com',
            'phone' => '0987654321',
        ]);

        // 在第一個租戶的客戶上執行操作
        $this->pointService->earn($this->customer, 100, 'Test for tenant isolation');

        // 確認事件只dispatch給正確的租戶和客戶（客戶A的事件必須是租戶A+客戶A）
        Event::assertDispatched(PointsUpdated::class, function (PointsUpdated $event) {
            return $event->tenantId === $this->tenant->id && $event->memberId === $this->customer->id;
        });

        // 第二個客戶的操作也會產生自己的事件（客戶B的事件必須是租戶B+客戶B）
        Event::fake();
        $this->pointService->earn($customer2, 50, 'Second customer earn');

        Event::assertDispatched(PointsUpdated::class, function (PointsUpdated $event) use ($tenant2, $customer2) {
            return $event->tenantId === $tenant2->id && $event->memberId === $customer2->id;
        });
    }

    #[Test]
    public function transaction_id_matches_actual_created_transaction(): void
    {
        Event::fake();

        // 執行點數操作，直接取得服務回傳的transaction實體
        $transaction = $this->pointService->earn($this->customer, 100, 'Test transaction ID match');

        // 直接使用服務回傳的transaction來驗證事件中的ID，避免查詢可能取得錯誤的記錄
        Event::assertDispatched(PointsUpdated::class, function (PointsUpdated $event) use ($transaction) {
            return $event->transactionId === $transaction->id;
        });
    }
}
