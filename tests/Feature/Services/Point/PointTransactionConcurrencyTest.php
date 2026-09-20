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

/**
 * 點數交易相關測試
 * 
 * 本測試覆蓋 sequential stress 與 lock-protected domain behavior，但不聲稱驗證真正的 multi-process concurrency。
 * 目前的 PHPUnit 環境在 Windows 上無法可靠地建立真正的多進程並發測試，所有的並行操作測試實際上都是在同一進程中依序執行。
 * 若需要驗證真實並發場景，需使用外部工具或在 CI 環境中執行獨立的並發測試。
 */
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
    public function lock_protected_sequential_earn_operations_do_not_lose_updates(): void
    {
        // 初始餘額為0
        $earnAmount = 10;
        $operations = 100;

        $results = collect();
        $exceptions = collect();

        // 在同一個process中依序執行多次earn，透過鎖機制確保原子性
        // 這是 sequential stress test，不是真正的multi-process concurrency test
        for ($i = 0; $i < $operations; $i++) {
            try {
                $this->pointService->earn($this->customer, $earnAmount, "Sequential earn #$i");
                $results->push(true);
            } catch (Exception $e) {
                $exceptions->push($e->getMessage());
            }
        }

        // 所有請求都應該成功
        $this->assertCount($operations, $results);
        $this->assertEmpty($exceptions);

        // 最終餘額應該是 100 * 10 = 1000
        $account = $this->customer->refresh()->pointAccount;
        $this->assertEquals(1000, $account->balance);
        $this->assertEquals(1000, $account->total_earned);

        // 應該有100筆交易記錄
        $this->assertCount(100, PointTransaction::where('customer_id', $this->customer->id)->get());
    }

    #[Test]
    public function lock_protected_sequential_redeem_operations_do_not_result_in_negative_balance(): void
    {
        // 先存入100點
        $this->pointService->earn($this->customer, 100, 'Initial balance');

        $redeemAmount = 20;
        $operations = 10; // 最多應該成功5次，餘額變0

        $results = collect();
        $exceptions = collect();

        // 在同一個process中依序執行多次redeem，透過鎖機制確保原子性
        // 這是 sequential stress test，不是真正的multi-process concurrency test
        for ($i = 0; $i < $operations; $i++) {
            try {
                $this->pointService->redeem($this->customer, $redeemAmount, "Sequential redeem #$i");
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
    public function lock_protected_sequential_account_creation_attempts_only_create_one_account(): void
    {
        // 確保一開始沒有帳戶
        $this->assertNull($this->customer->pointAccount);

        $results = collect();
        $exceptions = collect();

        // 在同一個process中依序執行多次首次earn，都要建立帳戶
        // 這是 sequential stress test，不是真正的multi-process concurrency test
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



    // ==============================================
    // adjust() method tests
    // ==============================================

    #[Test]
    public function adjust_positive_amount_increases_balance_and_total_earned(): void
    {
        $this->pointService->earn($this->customer, 100, 'Initial balance');
        $account = $this->customer->refresh()->pointAccount;

        $this->pointService->adjust($this->customer, 50, 'Positive adjust test');

        $account->refresh();
        $this->assertEquals(150, $account->balance);
        $this->assertEquals(150, $account->total_earned);

        // 驗證交易記錄
        $transaction = PointTransaction::where('type', PointTransaction::TYPE_ADJUST)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(50, $transaction->amount);
        $this->assertEquals(100, $transaction->balance_before);
        $this->assertEquals(150, $transaction->balance_after);
        $this->assertEquals($this->tenant->id, $transaction->tenant_id);
        $this->assertEquals($this->customer->id, $transaction->customer_id);
        $this->assertEquals($account->id, $transaction->point_account_id);
    }

    #[Test]
    public function adjust_negative_amount_decreases_balance_and_increases_total_redeemed(): void
    {
        $this->pointService->earn($this->customer, 100, 'Initial balance');
        $account = $this->customer->refresh()->pointAccount;

        $this->pointService->adjust($this->customer, -30, 'Negative adjust test');

        $account->refresh();
        $this->assertEquals(70, $account->balance);
        $this->assertEquals(30, $account->total_redeemed);

        // 驗證交易記錄
        $transaction = PointTransaction::where('type', PointTransaction::TYPE_ADJUST)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals(-30, $transaction->amount);
        $this->assertEquals(100, $transaction->balance_before);
        $this->assertEquals(70, $transaction->balance_after);
    }

    #[Test]
    public function adjust_zero_amount_throws_runtime_exception(): void
    {
        $this->pointService->earn($this->customer, 100, 'Initial balance');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('調整點數不能為0');

        $this->pointService->adjust($this->customer, 0, 'Zero adjust test');
    }

    #[Test]
    public function adjust_negative_amount_cannot_result_in_negative_balance(): void
    {
        $this->pointService->earn($this->customer, 50, 'Initial balance');
        $account = $this->customer->refresh()->pointAccount;
        $initialBalance = $account->balance;
        $initialTotalRedeemed = $account->total_redeemed;
        $initialTransactionCount = PointTransaction::count();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('調整後點數不可為負數');

        try {
            $this->pointService->adjust($this->customer, -60, 'Insufficient balance adjust test');
        } catch (RuntimeException $e) {
            $account->refresh();
            $this->assertEquals($initialBalance, $account->balance);
            $this->assertEquals($initialTotalRedeemed, $account->total_redeemed);
            $this->assertEquals($initialTransactionCount, PointTransaction::count());
            throw $e;
        }
    }

    #[Test]
    public function adjust_creates_type_adjust_transaction_with_correct_sign(): void
    {
        $this->pointService->earn($this->customer, 100, 'Initial balance');

        // 正數調整
        $transaction1 = $this->pointService->adjust($this->customer, 25, 'Positive adjust');
        $this->assertEquals(PointTransaction::TYPE_ADJUST, $transaction1->type);
        $this->assertEquals(25, $transaction1->amount);

        // 負數調整
        $transaction2 = $this->pointService->adjust($this->customer, -15, 'Negative adjust');
        $this->assertEquals(PointTransaction::TYPE_ADJUST, $transaction2->type);
        $this->assertEquals(-15, $transaction2->amount);
    }

    // ==============================================
    // expire() method tests
    // ==============================================

    #[Test]
    public function expire_successfully_deducts_balance_creates_expire_transaction(): void
    {
        $this->pointService->earn($this->customer, 100, 'Initial balance');
        $account = $this->customer->refresh()->pointAccount;
        $initialTotalRedeemed = $account->total_redeemed;

        $transaction = $this->pointService->expire($this->customer, 30, 'Points expire test');

        $account->refresh();
        $this->assertEquals(70, $account->balance);
        $this->assertEquals($initialTotalRedeemed, $account->total_redeemed); // total_redeemed 不增加
        $this->assertEquals(PointTransaction::TYPE_EXPIRE, $transaction->type);
        $this->assertEquals(30, $transaction->amount);
        $this->assertEquals(100, $transaction->balance_before);
        $this->assertEquals(70, $transaction->balance_after);
        $this->assertEquals($this->tenant->id, $transaction->tenant_id);
        $this->assertEquals($this->customer->id, $transaction->customer_id);
        $this->assertEquals($account->id, $transaction->point_account_id);
    }

    #[Test]
    public function expire_fails_when_insufficient_balance_and_leaves_data_unchanged(): void
    {
        $this->pointService->earn($this->customer, 50, 'Initial balance');
        $account = $this->customer->refresh()->pointAccount;
        $initialBalance = $account->balance;
        $initialTransactionCount = PointTransaction::count();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('點數餘額不足');

        try {
            $this->pointService->expire($this->customer, 60, 'Insufficient expire test');
        } catch (RuntimeException $e) {
            $account->refresh();
            $this->assertEquals($initialBalance, $account->balance);
            $this->assertEquals($initialTransactionCount, PointTransaction::count());
            $this->assertNull(PointTransaction::where('type', PointTransaction::TYPE_EXPIRE)->first());
            throw $e;
        }
    }

    // ==============================================
    // refund() method tests
    // ==============================================

    #[Test]
    public function refund_successfully_adds_balance_and_references_original_redeem(): void
    {
        $this->pointService->earn($this->customer, 100, 'Initial balance');
        $redeemTransaction = $this->pointService->redeem($this->customer, 30, 'Original redeem');
        $account = $this->customer->refresh()->pointAccount;
        $initialTotalRedeemed = $account->total_redeemed;

        $refundTransaction = $this->pointService->refund($this->customer, 30, 'Refund test', $redeemTransaction);

        $account->refresh();
        $this->assertEquals(100, $account->balance);
        $this->assertEquals($initialTotalRedeemed, $account->total_redeemed); // total_redeemed 不倒扣
        $this->assertEquals(PointTransaction::TYPE_REFUND, $refundTransaction->type);
        $this->assertEquals(30, $refundTransaction->amount);
        $this->assertEquals(70, $refundTransaction->balance_before);
        $this->assertEquals(100, $refundTransaction->balance_after);
        $this->assertEquals(get_class($redeemTransaction), $refundTransaction->reference_type);
        $this->assertEquals($redeemTransaction->id, $refundTransaction->reference_id);
    }

    #[Test]
    public function refund_rejects_non_redeem_reference(): void
    {
        $this->pointService->earn($this->customer, 100, 'Initial balance');
        $earnTransaction = PointTransaction::where('type', PointTransaction::TYPE_EARN)->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('退款只能針對兌換交易');

        $this->pointService->refund($this->customer, 30, 'Invalid refund', $earnTransaction);
    }

    #[Test]
    public function refund_rejects_other_customer_reference(): void
    {
        // 建立另一個客戶
        $otherCustomer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Customer',
            'email' => 'other@example.com',
            'phone' => '0987654321',
        ]);

        $this->pointService->earn($otherCustomer, 100, 'Other initial');
        $otherRedeem = $this->pointService->redeem($otherCustomer, 30, 'Other redeem');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('退款參考交易的客戶與當前客戶不一致');

        $this->pointService->refund($this->customer, 30, 'Wrong customer refund', $otherRedeem);
    }

    #[Test]
    public function refund_rejects_other_tenant_reference_same_customer(): void
    {
        // 先為當前客戶建立pointAccount，才能獲得其ID
        $this->pointService->earn($this->customer, 100, 'Initial balance');
        $account = $this->customer->refresh()->pointAccount;

        // 建立另一個租戶
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'domain' => 'other.example.com',
        ]);

        // 建立一個假的交易，手動修改其tenant_id來觸發租戶不一致錯誤
        $fakeTransaction = new PointTransaction([
            'tenant_id' => $otherTenant->id,
            'customer_id' => $this->customer->id,
            'point_account_id' => $account->id,
            'type' => PointTransaction::TYPE_REDEEM,
            'amount' => 30,
            'balance_before' => 100,
            'balance_after' => 70,
            'description' => 'Fake transaction',
        ]);
        $fakeTransaction->id = 99999;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('退款參考交易的租戶與當前租戶不一致');

        $this->pointService->refund($this->customer, 30, 'Wrong tenant refund', $fakeTransaction);
    }

    #[Test]
    public function refund_rejects_other_point_account_reference(): void
    {
        // 先為當前客戶建立pointAccount
        $this->pointService->earn($this->customer, 100, 'Initial balance for current customer');
        $currentAccount = $this->customer->refresh()->pointAccount;

        // 建立另一個客戶，但使用相同的customer_id（當前客戶），但不同的point_account_id
        // 手動建立一個假的交易，customer_id相同但point_account_id不同
        $fakeTransaction = new PointTransaction([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id, // 同一個客戶
            'point_account_id' => 99999, // 不存在的point_account_id，與當前帳戶不同
            'type' => PointTransaction::TYPE_REDEEM,
            'amount' => 30,
            'balance_before' => 100,
            'balance_after' => 70,
            'description' => 'Fake transaction with wrong point account id',
        ]);
        $fakeTransaction->id = 99999;

        // 驗證參考交易的point_account_id與當前帳戶不同
        $this->assertNotEquals($fakeTransaction->point_account_id, $currentAccount->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('退款參考交易的點數帳戶與當前帳戶不一致');

        $this->pointService->refund($this->customer, 30, 'Wrong point account refund', $fakeTransaction);
    }

    #[Test]
    public function double_refund_on_same_redeem_is_rejected_and_balance_unchanged(): void
    {
        $this->pointService->earn($this->customer, 100, 'Initial balance');
        $redeemTransaction = $this->pointService->redeem($this->customer, 30, 'Original redeem');
        $account = $this->customer->refresh()->pointAccount;
        $initialBalance = $account->balance;

        // 第一次退款成功
        $this->pointService->refund($this->customer, 30, 'First refund', $redeemTransaction);

        $account->refresh();
        $this->assertEquals(100, $account->balance);

        // 第二次退款應該失敗
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('該兌換交易已經退款過');

        $initialRefundCount = PointTransaction::where('type', PointTransaction::TYPE_REFUND)->count();

        try {
            $this->pointService->refund($this->customer, 30, 'Second refund attempt', $redeemTransaction);
        } catch (RuntimeException $e) {
            $account->refresh();
            $this->assertEquals(100, $account->balance); // 餘額不變
            $this->assertEquals($initialRefundCount, PointTransaction::where('type', PointTransaction::TYPE_REFUND)->count()); // 不新增第二筆退款
            throw $e;
        }
    }

    // ==============================================
    // Tenant isolation tests
    // ==============================================

    #[Test]
    public function tenant_a_cannot_operate_on_tenant_b_customer(): void
    {
        // 建立另一個租戶
        $tenantB = Tenant::create([
            'name' => 'Tenant B',
            'domain' => 'tenant-b.example.com',
        ]);

        $tenantBCustomer = Customer::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Tenant B Customer',
            'email' => 'tenant-b@example.com',
            'phone' => '5566778899',
        ]);

        // 模擬當前租戶是tenant A（本測試的this->tenant）
        $tenantResolver = app(\App\Support\Tenancy\TenantResolver::class);
        $tenantContext = app(\App\Support\Tenancy\TenantContext::class);
        $tenantContext->setTenant($this->tenant);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('無法操作其他租戶的客戶');

        $this->pointService->earn($tenantBCustomer, 100, 'Cross tenant attempt');
    }

    // ==============================================
    // All transaction types consistency tests
    // ==============================================

    #[Test]
    public function all_transaction_types_have_consistent_foreign_keys_and_balance_calculations(): void
    {
        // 從頭開始建立全新的交易序列，確保每一步的餘額變化都是可預測的
        $this->pointService->earn($this->customer, 100, 'Earn test'); // 100
        $account = $this->customer->refresh()->pointAccount;

        // EARN 交易
        $earnTx = PointTransaction::where('type', PointTransaction::TYPE_EARN)->first();
        $this->assertTransactionConsistency($earnTx, $account);

        // REDEEM 交易 - 扣除30，餘額70
        $redeemTx = $this->pointService->redeem($this->customer, 30, 'Redeem test');
        $this->assertTransactionConsistency($redeemTx, $this->customer->refresh()->pointAccount);

        // ADJUST 正數 - 增加20，餘額90
        $adjustPosTx = $this->pointService->adjust($this->customer, 20, 'Adjust positive');
        $this->assertTransactionConsistency($adjustPosTx, $this->customer->refresh()->pointAccount);

        // ADJUST 負數 - 減少10，餘額80
        $adjustNegTx = $this->pointService->adjust($this->customer, -10, 'Adjust negative');
        $this->assertTransactionConsistency($adjustNegTx, $this->customer->refresh()->pointAccount);

        // EXPIRE 交易 - 扣除15，餘額65
        $expireTx = $this->pointService->expire($this->customer, 15, 'Expire test');
        $this->assertTransactionConsistency($expireTx, $this->customer->refresh()->pointAccount);

        // REFUND 交易 - 針對剛才的redeem交易退回10點，餘額75
        $refundTx = $this->pointService->refund($this->customer, 10, 'Refund test', $redeemTx);
        $this->assertTransactionConsistency($refundTx, $this->customer->refresh()->pointAccount);
    }

    /**
     * 驗證交易的一致性：外鍵正確、餘額計算正確
     */
    protected function assertTransactionConsistency(PointTransaction $transaction, PointAccount $account): void
    {
        // 直接從資料庫查詢交易，避免lazy loading帶來的問題
        $dbTransaction = PointTransaction::where('id', $transaction->id)->first();

        $this->assertEquals($account->tenant_id, $dbTransaction->tenant_id);
        $this->assertEquals($account->customer_id, $dbTransaction->customer_id);
        $this->assertEquals($account->id, $dbTransaction->point_account_id);

        // 根據交易類型驗證餘額計算
        // 增加餘額的交易：balance_after = balance_before + amount
        // 減少餘額的交易：balance_after = balance_before - amount
        $addTypes = [PointTransaction::TYPE_EARN, PointTransaction::TYPE_REFUND];
        $subtractTypes = [PointTransaction::TYPE_REDEEM, PointTransaction::TYPE_EXPIRE];

        if (in_array($dbTransaction->type, $addTypes)) {
            $calculated = $dbTransaction->balance_before + $dbTransaction->amount;
        } elseif (in_array($dbTransaction->type, $subtractTypes)) {
            $calculated = $dbTransaction->balance_before - $dbTransaction->amount;
        } elseif ($dbTransaction->type === PointTransaction::TYPE_ADJUST) {
            // ADJUST交易amount保留原始正負號
            $calculated = $dbTransaction->balance_before + $dbTransaction->amount;
        } else {
            $calculated = $dbTransaction->balance_before; // 未知類型，不應該發生
        }

        $this->assertEquals(
            $calculated,
            $dbTransaction->balance_after,
            sprintf(
                "交易類型: %s, balance_before: %d, amount: %d, 計算結果: %d, 實際balance_after: %d",
                $dbTransaction->type,
                $dbTransaction->balance_before,
                $dbTransaction->amount,
                $calculated,
                $dbTransaction->balance_after
            )
        );
    }
}
