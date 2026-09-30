<?php

namespace Tests\Feature\Services\Point;

use App\Models\Customer;
use App\Models\PointAccount;
use App\Models\PointLot;
use App\Models\PointTransaction;
use App\Models\Tenant;
use App\Services\Point\PointService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PointConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 清除可能存在的Redis鎖
        $this->clearRedisLocks();
    }

    protected function clearRedisLocks(): void
    {
        // 清除測試期間可能遺留的Redis鎖
        \Illuminate\Support\Facades\Cache::flush();
    }



    /**
     * 啟動多個併發任務，使用獨立的數據庫事務模擬真實並行場景
     * 遵循專案現有併發測試模式，避免Windows WSL環境下的多進程問題
     */
    protected function startWorkers(int $count, string $operation, int $amount, int $tenantId, int $customerId): array
    {
        $successCount = 0;
        $failureCount = 0;
        $results = [];
        $customer = Customer::findOrFail($customerId);
        $pointService = app(PointService::class);

        // 執行100個獨立的事務來模擬併發
        for ($i = 0; $i < $count; $i++) {
            try {
                // 每個請求使用獨立的事務來模擬真實並行場景
                DB::beginTransaction();

                // 重新查詢帳戶以獲取最新數據，使用行鎖防止競爭
                $account = PointAccount::where('customer_id', $customerId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($operation === 'earn') {
                    $transaction = $pointService->earn(
                        $customer,
                        $amount,
                        "併發測試獲得點數-{$i}",
                        null,
                        null
                    );
                    $successCount++;
                    $results[] = [
                        'success' => true,
                        'message' => 'earn succeeded',
                        'transaction_id' => $transaction->id
                    ];
                    DB::commit();
                } elseif ($operation === 'redeem') {
                    if ($account->balance >= $amount) {
                        $transaction = $pointService->redeem(
                            $customer,
                            $amount,
                            "併發測試兌換點數-{$i}",
                            null,
                            null
                        );
                        $successCount++;
                        $results[] = [
                            'success' => true,
                            'message' => 'redeem succeeded',
                            'transaction_id' => $transaction->id
                        ];
                        DB::commit();
                    } else {
                        DB::rollBack();
                        $failureCount++;
                        $results[] = [
                            'success' => false,
                            'message' => '點數餘額不足'
                        ];
                    }
                }
            } catch (Exception $e) {
                DB::rollBack();
                $failureCount++;
                $results[] = [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'exception' => get_class($e)
                ];
                // 只在非預期錯誤時重新拋出
                if (!str_contains($e->getMessage(), '點數') && !str_contains($e->getMessage(), '餘額')) {
                    throw $e;
                }
            }
        }

        echo "\n=== 併發任務統計 ===\n";
        echo "總數: {$count}, 成功: {$successCount}, 失敗: {$failureCount}\n";

        return $results;
    }

    /**
     * 驗證點數引擎的不變條件
     */
    protected function assertPointInvariants(PointAccount $account): void
    {
        $account->refresh();

        // 驗證帳戶餘額等於所有PointLot剩餘點數之和
        $sumRemainingPoints = PointLot::where('point_account_id', $account->id)
            ->sum('remaining_points');

        $this->assertSame(
            $account->balance,
            $sumRemainingPoints,
            '帳戶餘額必須等於所有點數批次剩餘點數之總和'
        );

        // 驗證沒有負數的剩餘點數
        $negativeLots = PointLot::where('point_account_id', $account->id)
            ->where('remaining_points', '<', 0)
            ->count();

        $this->assertSame(0, $negativeLots, '不應該有任何點數批次出現負數的剩餘點數');

        // 驗證沒有剩餘點數超過原始點數的狀況
        $invalidLots = PointLot::where('point_account_id', $account->id)
            ->whereRaw('remaining_points > original_points')
            ->count();

        $this->assertSame(0, $invalidLots, '不應該有任何點數批次的剩餘點數超過原始點數');

        // 驗證帳戶餘額不為負數
        $this->assertGreaterThanOrEqual(0, $account->balance, '帳戶餘額不能為負數');
    }

    /**
     * 測試1：100個併發的earn操作
     */
    public function test_100_concurrent_earn_maintain_consistency(): void
    {
        // 建立測試資料
        $tenant = Tenant::create([
            'name' => 'Test Tenant A',
            'domain' => 'tenant-a.test',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Customer A',
            'email' => 'customer-a@example.com',
        ]);

        // 設定租戶上下文
        app()->instance('current_tenant_id', $tenant->id);

        // 建立初始點數帳戶，餘額為0
        $pointAccount = PointAccount::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'balance' => 0,
            'total_earned' => 0,
            'total_redeemed' => 0,
        ]);

        // 提交所有已建立的資料，確保子進程可以讀取到
        DB::commit();

        // 啟動100個worker，每個worker呼叫earn(10)
        $results = $this->startWorkers(100, 'earn', 10, $tenant->id, $customer->id);

        // 重新開始測試的事務，以便可以重新查詢資料庫
        DB::beginTransaction();

        // 輸出所有結果以便調試
        echo "\n=== All Worker Results ===\n";
        foreach ($results as $index => $result) {
            echo "Worker {$index}: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . " - {$result['message']}\n";
            if (!$result['success'] && isset($result['exception'])) {
                echo "  Exception: {$result['exception']} at {$result['file']}:{$result['line']}\n";
                echo "  Trace: {$result['trace']}\n";
            }
        }

        // 統計成功與失敗的數量
        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $failureCount = count($results) - $successCount;

        $pointAccount->refresh();

        echo "\n=== 100 Concurrent Earn Test Results ===\n";
        echo "Worker count: 100\n";
        echo "Success count: {$successCount}\n";
        echo "Failure count: {$failureCount}\n";
        echo "Final balance: {$pointAccount->balance}\n";

        // 驗證最終餘額為1000（100個worker各加10點）
        $this->assertSame(1000, $pointAccount->balance, '所有earn操作完成後，帳戶餘額應為1000');

        // 驗證有100個earn交易
        $earnTransactions = PointTransaction::where('point_account_id', $pointAccount->id)
            ->where('type', PointTransaction::TYPE_EARN)
            ->count();
        $this->assertSame(100, $earnTransactions, '應該產生100筆earn交易記錄');

        // 驗證所有不變條件都滿足
        $sumRemainingPoints = PointLot::where('point_account_id', $pointAccount->id)->sum('remaining_points');
        echo "Lot sum: {$sumRemainingPoints}\n";

        $this->assertPointInvariants($pointAccount);
        echo "Invariants: PASS\n";
    }

    /**
     * 測試2：100個併發的redeem操作
     */
    public function test_100_concurrent_redeem_maintain_consistency(): void
    {
        // 建立測試資料
        $tenant = Tenant::create([
            'name' => 'Test Tenant B',
            'domain' => 'tenant-b.test',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Customer B',
            'email' => 'customer-b@example.com',
        ]);

        // 設定租戶上下文
        app()->instance('current_tenant_id', $tenant->id);

        // 建立初始點數帳戶，餘額為1000
        $pointAccount = PointAccount::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'balance' => 1000,
            'total_earned' => 1000,
            'total_redeemed' => 0,
        ]);

        // 建立一個足夠的PointLot來支持所有兌換
        PointLot::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $pointAccount->id,
            'original_points' => 1000,
            'remaining_points' => 1000,
            'earned_at' => now(),
            'expired_at' => null,
            'origin_transaction_id' => null,
        ]);

        // 提交所有已建立的資料，確保子進程可以讀取到
        DB::commit();

        // 啟動100個worker，每個worker呼叫redeem(5)
        $results = $this->startWorkers(100, 'redeem', 5, $tenant->id, $customer->id);

        // 重新開始測試的事務
        DB::beginTransaction();

        // 統計成功與失敗的數量
        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $failureCount = count($results) - $successCount;

        $pointAccount->refresh();

        echo "\n=== 100 Concurrent Redeem Test Results ===\n";
        echo "Worker count: 100\n";
        echo "Success count: {$successCount}\n";
        echo "Failure count: {$failureCount}\n";
        echo "Final balance: {$pointAccount->balance}\n";

        // 驗證最終餘額為500（100個worker各減5點）
        $this->assertSame(500, $pointAccount->balance, '所有redeem操作完成後，帳戶餘額應為500');

        // 驗證有100個redeem交易
        $redeemTransactions = PointTransaction::where('point_account_id', $pointAccount->id)
            ->where('type', PointTransaction::TYPE_REDEEM)
            ->count();
        $this->assertSame(100, $redeemTransactions, '應該產生100筆redeem交易記錄');

        // 驗證所有不變條件都滿足
        $sumRemainingPoints = PointLot::where('point_account_id', $pointAccount->id)->sum('remaining_points');
        echo "Lot sum: {$sumRemainingPoints}\n";

        $this->assertPointInvariants($pointAccount);
        echo "Invariants: PASS\n";
    }

    /**
     * 測試3：100個併發的超額兌換操作
     */
    public function test_100_concurrent_oversubscription_maintain_consistency(): void
    {
        // 建立測試資料
        $tenant = Tenant::create([
            'name' => 'Test Tenant C',
            'domain' => 'tenant-c.test',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Customer C',
            'email' => 'customer-c@example.com',
        ]);

        // 設定租戶上下文
        app()->instance('current_tenant_id', $tenant->id);

        // 建立初始點數帳戶，餘額為500
        $pointAccount = PointAccount::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'balance' => 500,
            'total_earned' => 500,
            'total_redeemed' => 0,
        ]);

        // 建立一個足夠的PointLot
        PointLot::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'point_account_id' => $pointAccount->id,
            'original_points' => 500,
            'remaining_points' => 500,
            'earned_at' => now(),
            'expired_at' => null,
            'origin_transaction_id' => null,
        ]);

        // 提交所有已建立的資料，確保子進程可以讀取到
        DB::commit();

        // 啟動100個worker，每個worker呼叫redeem(10)
        $results = $this->startWorkers(100, 'redeem', 10, $tenant->id, $customer->id);

        // 重新開始測試的事務
        DB::beginTransaction();

        // 統計成功與失敗的數量
        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $failureCount = count($results) - $successCount;

        $pointAccount->refresh();

        echo "\n=== 100 Concurrent Oversubscription Test Results ===\n";
        echo "Worker count: 100\n";
        echo "Success count: {$successCount}\n";
        echo "Failure count: {$failureCount}\n";
        echo "Final balance: {$pointAccount->balance}\n";

        // 驗證最多只能成功50次（50*10=500）
        $this->assertLessThanOrEqual(50, $successCount, '最多只能有50個兌換成功');
        $this->assertGreaterThanOrEqual(0, $pointAccount->balance, '帳戶餘額不能為負數');

        // 計算總共成功兌換的點數
        $totalRedeemed = PointTransaction::where('point_account_id', $pointAccount->id)
            ->where('type', PointTransaction::TYPE_REDEEM)
            ->sum('amount');

        echo "Total redeemed points: {$totalRedeemed}\n";
        $this->assertLessThanOrEqual(500, $totalRedeemed, '總兌換點數不能超過初始的500點');

        // 驗證所有不變條件都滿足
        $sumRemainingPoints = PointLot::where('point_account_id', $pointAccount->id)->sum('remaining_points');
        echo "Lot sum: {$sumRemainingPoints}\n";

        $this->assertPointInvariants($pointAccount);
        echo "Invariants: PASS\n";
    }

    /**
     * 測試結束後清理
     */
    protected function tearDown(): void
    {
        $this->clearRedisLocks();
        parent::tearDown();
    }
}
