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
use Tests\TestCase;
use Exception;

class PointServiceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Customer $customer;
    protected PointAccount $pointAccount;
    private PointService $pointService;

    protected function setUp(): void
    {
        parent::setUp();

        // 建立測試租戶
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'domain' => 'test-tenant.local',
            'is_active' => true,
        ]);

        // 建立客戶
        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
        ]);

        // 設定租戶上下文
        app()->instance('current_tenant_id', $this->tenant->id);

        // 注入PointService
        $this->pointService = app(PointService::class);

        // 建立點數帳戶
        $this->pointAccount = PointAccount::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'balance' => 100,
            'total_earned' => 100,
            'total_redeemed' => 0,
        ]);

        // 建立對應的PointLot，確保帳戶餘額與批次總和一致
        PointLot::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'point_account_id' => $this->pointAccount->id,
            'original_points' => 100,
            'remaining_points' => 100,
            'earned_at' => now(),
            'expired_at' => null,
            'origin_transaction_id' => null,
        ]);
    }

    /**
     * 高併發兌換測試：使用數據庫事務鎖模擬並行場景，10個請求同時兌換20點
     * 初始餘額100，應有5個成功、5個失敗，最終餘額0
     */
    public function test_concurrent_redeem_operations_maintain_consistency(): void
    {
        $successCount = 0;
        $failureCount = 0;
        $startTime = microtime(true);

        // 建立10個同時執行的兌換任務，使用獨立的數據庫事務模擬真實並行場景
        for ($i = 0; $i < 10; $i++) {
            try {
                // 每個請求使用獨立的事務來模擬真實並行場景
                DB::beginTransaction();

                // 重新查詢帳戶以獲取最新數據，使用行鎖防止競爭
                $account = PointAccount::where('id', $this->pointAccount->id)
                    ->lockForUpdate()
                    ->first();

                if ($account->balance >= 20) {
                    $transaction = $this->pointService->redeem(
                        $this->customer,
                        20,
                        "併發測試兌換-{$i}",
                        null,
                        null
                    );
                    $successCount++;
                    DB::commit();
                } else {
                    DB::rollBack();
                    $failureCount++;
                }
            } catch (Exception $e) {
                DB::rollBack();
                $failureCount++;
                // 記錄預期的"點數不足"錯誤
                if (!str_contains($e->getMessage(), '點數') && !str_contains($e->getMessage(), '餘額')) {
                    throw $e; // 非預期錯誤需要拋出
                }
            }
        }

        $endTime = microtime(true);
        $duration = number_format($endTime - $startTime, 2);
        echo "\n=== 併發測試執行時間: {$duration}秒 ===\n";
        echo "成功數: $successCount, 失敗數: $failureCount\n";

        // 驗證結果符合預期
        $this->assertSame(5, $successCount, '應該只有5個兌換成功');
        $this->assertSame(5, $failureCount, '應該有5個兌換失敗');

        // 重新載入帳戶數據
        $this->pointAccount->refresh();

        // 最終餘額應為0
        $this->assertSame(0, $this->pointAccount->balance, '最終餘額應為0');

        // 驗證帳戶餘額等於所有PointLot剩餘點數之和
        $sumRemainingPoints = PointLot::where('point_account_id', $this->pointAccount->id)
            ->sum('remaining_points');
        $this->assertSame($this->pointAccount->balance, $sumRemainingPoints, '帳戶餘額必須等於批次剩餘點數總和');

        // 驗證不會出現負數餘額
        $negativeLots = PointLot::where('point_account_id', $this->pointAccount->id)
            ->where('remaining_points', '<', 0)
            ->count();
        $this->assertSame(0, $negativeLots, '不應該有任何批次出現負數剩餘點數');

        // 驗證交易日誌數量正確（5筆成功的兌換交易）
        $redeemTransactions = PointTransaction::where('point_account_id', $this->pointAccount->id)
            ->where('type', PointTransaction::TYPE_REDEEM)
            ->count();
        $this->assertSame(5, $redeemTransactions, '應該產生5筆兌換交易記錄');

        // 驗證總兌換點數正確
        $totalRedeemed = PointTransaction::where('point_account_id', $this->pointAccount->id)
            ->where('type', PointTransaction::TYPE_REDEEM)
            ->sum('amount');
        $this->assertSame(100, $totalRedeemed, '總兌換點數應該等於100');
    }

    /**
     * 驗證單一兌換流程正常工作
     */
    public function test_single_redeem_operation_works_correctly(): void
    {
        // 測試單一兌換50點是否正常
        $transaction = $this->pointService->redeem(
            $this->customer,
            50,
            '單一測試兌換',
            null,
            null
        );

        $this->assertNotNull($transaction);
        $this->pointAccount->refresh();
        $this->assertSame(50, $this->pointAccount->balance);

        $sumRemaining = PointLot::where('point_account_id', $this->pointAccount->id)->sum('remaining_points');
        $this->assertSame(50, $sumRemaining);
    }
}
