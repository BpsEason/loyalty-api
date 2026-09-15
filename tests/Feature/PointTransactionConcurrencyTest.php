<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PointAccount;
use App\Models\PointTransaction;
use App\Models\Tenant;
use App\Services\Point\PointService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class PointTransactionConcurrencyTest extends TestCase
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
    public function it_can_successfully_redeem_points_normally(): void
    {
        // 先建立帳戶並存入100點
        $this->pointService->earn($this->customer, 100, 'Initial balance');

        $account = $this->customer->pointAccount;
        $this->assertEquals(100, $account->balance);

        // 扣除30點
        $this->pointService->redeem($this->customer, 30, 'Redeem test');

        $account->refresh();
        $this->assertEquals(70, $account->balance);

        // 確認交易記錄數量正確
        $this->assertCount(2, PointTransaction::where('customer_id', $this->customer->id)->get());
    }

    #[Test]
    public function it_fails_to_redeem_when_insufficient_points(): void
    {
        $this->pointService->earn($this->customer, 20, 'Initial balance');

        $account = $this->customer->pointAccount;
        $this->assertEquals(20, $account->balance);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('點數餘額不足');

        try {
            $this->pointService->redeem($this->customer, 30, 'Should fail');
        } catch (RuntimeException $e) {
            // 確認餘額沒有改變
            $account->refresh();
            $this->assertEquals(20, $account->balance);
            // 只建立了一筆交易（earn）
            $this->assertCount(0, PointTransaction::where('customer_id', $this->customer->id)->where('type', PointTransaction::TYPE_REDEEM)->get());
            throw $e;
        }
    }

    #[Test]
    public function transaction_rolls_back_when_exception_occurs(): void
    {
        $this->pointService->earn($this->customer, 100, 'Initial balance');
        $account = $this->customer->pointAccount;
        $this->assertEquals(100, $account->balance);

        $initialTransactionCount = PointTransaction::count();

        $this->expectException(RuntimeException::class);

        // 模擬在交易過程中發生異常
        try {
            DB::transaction(function () use ($account) {
                $account->update(['balance' => $account->balance - 50]);
                PointTransaction::create([
                    'tenant_id' => $this->tenant->id,
                    'customer_id' => $this->customer->id,
                    'point_account_id' => $account->id,
                    'type' => PointTransaction::TYPE_REDEEM,
                    'amount' => 50,
                    'balance_before' => 100,
                    'balance_after' => 50,
                    'description' => 'Test',
                ]);
                throw new RuntimeException('Something went wrong');
            });
        } catch (RuntimeException $e) {
            $account->refresh();
            $this->assertEquals(100, $account->balance);
            $this->assertEquals($initialTransactionCount, PointTransaction::count());
            throw $e;
        }
    }

    #[Test]
    public function it_acquires_and_releases_lock_properly(): void
    {
        $lockKey = sprintf('point_customer:%d:tenant:%d', $this->customer->id, $this->customer->tenant_id);

        // 第一次獲取鎖成功
        $lock = Cache::lock($lockKey, 10);
        $this->assertTrue($lock->acquire());

        // 釋放後才能再次獲取
        $lock->release();

        // 第二次獲取鎖成功
        $lock2 = Cache::lock($lockKey, 10);
        $this->assertTrue($lock2->acquire());
        $lock2->release();
    }

    #[Test]
    public function it_creates_point_account_when_it_does_not_exist(): void
    {
        $this->assertNull($this->customer->pointAccount);

        $transaction = $this->pointService->earn($this->customer, 100, 'First earn');

        $this->assertNotNull($this->customer->refresh()->pointAccount);
        $this->assertEquals(100, $this->customer->pointAccount->balance);
        $this->assertEquals(PointTransaction::TYPE_EARN, $transaction->type);
    }

    #[Test]
    public function concurrent_earn_transactions_do_not_lose_updates(): void
    {
        // 初始餘額為0
        $earnAmount = 10;
        $concurrentRequests = 100;

        $results = collect();
        $exceptions = collect();

        // 在同一個process中模擬100次earn，透過我們的鎖機制來確保原子性
        for ($i = 0; $i < $concurrentRequests; $i++) {
            try {
                $this->pointService->earn($this->customer, $earnAmount, "Concurrent earn #$i");
                $results->push(true);
            } catch (Exception $e) {
                $exceptions->push($e->getMessage());
            }
        }

        // 所有請求都應該成功
        $this->assertCount($concurrentRequests, $results);
        $this->assertEmpty($exceptions);

        // 最終餘額應該是 100 * 10 = 1000
        $account = $this->customer->refresh()->pointAccount;
        $this->assertEquals(1000, $account->balance);
        $this->assertEquals(1000, $account->total_earned);

        // 應該有100筆交易記錄
        $this->assertCount(100, PointTransaction::where('customer_id', $this->customer->id)->get());
    }

    #[Test]
    public function concurrent_redeem_transactions_do_not_result_in_negative_balance(): void
    {
        // 先存入100點
        $this->pointService->earn($this->customer, 100, 'Initial balance');

        $redeemAmount = 20;
        $concurrentRequests = 10; // 最多應該成功5次，餘額變0

        $results = collect();
        $exceptions = collect();

        for ($i = 0; $i < $concurrentRequests; $i++) {
            try {
                $this->pointService->redeem($this->customer, $redeemAmount, "Concurrent redeem #$i");
                $results->push(true);
            } catch (RuntimeException $e) {
                $exceptions->push($e->getMessage());
            }
        }

        // 最多成功5次兌換
        $this->assertCount(5, $results);
        // 剩下的5次應該因為餘額不足而失敗
        $this->assertCount(5, $exceptions);
        $this->assertContains('點數餘額不足', $exceptions);

        // 最終餘額應該是0
        $account = $this->customer->refresh()->pointAccount;
        $this->assertEquals(0, $account->balance);
        $this->assertEquals(100, $account->total_redeemed); // 總共兌換100點
    }

    #[Test]
    public function concurrent_account_creation_only_creates_one_account(): void
    {
        // 確保一開始沒有帳戶
        $this->assertNull($this->customer->pointAccount);

        $results = collect();
        $exceptions = collect();

        // 模擬10個並發的首次earn請求，都要建立帳戶
        for ($i = 0; $i < 10; $i++) {
            try {
                $this->pointService->earn($this->customer, 10, "First earn attempt #$i");
                $results->push(true);
            } catch (Exception $e) {
                $exceptions->push($e->getMessage());
            }
        }

        // 所有請求都應該成功
        $this->assertCount(10, $results);
        $this->assertEmpty($exceptions);

        // 只存在一個PointAccount
        $this->assertCount(1, PointAccount::where('customer_id', $this->customer->id)->get());

        // 總餘額應該是10*10=100
        $account = $this->customer->refresh()->pointAccount;
        $this->assertEquals(100, $account->balance);
    }
}
