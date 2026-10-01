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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PointConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // 用 __DIR__ 計算專案根目錄路徑，不依賴 Laravel 的 base_path() (避免在 Application 初始化前呼叫)
        // __DIR__ = tests/Feature/Services/Point，往上跳 4 層到專案根目錄
        $rootPath = dirname(dirname(dirname(dirname(__DIR__))));
        $envPath = $rootPath . '/.env';
        $envContents = file_get_contents($envPath);
        $envValues = [];

        // 解析 .env 檔案內容做為預設值
        preg_match_all('/^([A-Z_]+)=(.*)$/m', $envContents, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $envValues[$match[1]] = trim($match[2], '"');
        }

        // 在 parent::setUp() 之前，先修改 $_ENV 和 $_SERVER 超全局變數
        // 只在環境變數尚未被外部設定時才使用 .env 的值，尊重 CI 或其他外部環境提供的設定
        if (!isset($_ENV['DB_CONNECTION'])) {
            $_ENV['DB_CONNECTION'] = 'mysql';
        }
        if (!isset($_SERVER['DB_CONNECTION'])) {
            $_SERVER['DB_CONNECTION'] = 'mysql';
        }
        if (!isset($_ENV['DB_HOST'])) {
            $_ENV['DB_HOST'] = $envValues['DB_HOST'] ?? '127.0.0.1';
        }
        if (!isset($_SERVER['DB_HOST'])) {
            $_SERVER['DB_HOST'] = $envValues['DB_HOST'] ?? '127.0.0.1';
        }
        if (!isset($_ENV['DB_PORT'])) {
            $_ENV['DB_PORT'] = $envValues['DB_PORT'] ?? '3306';
        }
        if (!isset($_SERVER['DB_PORT'])) {
            $_SERVER['DB_PORT'] = $envValues['DB_PORT'] ?? '3306';
        }
        if (!isset($_ENV['DB_DATABASE'])) {
            $_ENV['DB_DATABASE'] = $envValues['DB_DATABASE'] ?? 'laravel';
        }
        if (!isset($_SERVER['DB_DATABASE'])) {
            $_SERVER['DB_DATABASE'] = $envValues['DB_DATABASE'] ?? 'laravel';
        }
        if (!isset($_ENV['DB_USERNAME'])) {
            $_ENV['DB_USERNAME'] = $envValues['DB_USERNAME'] ?? 'root';
        }
        if (!isset($_SERVER['DB_USERNAME'])) {
            $_SERVER['DB_USERNAME'] = $envValues['DB_USERNAME'] ?? 'root';
        }
        if (!isset($_ENV['DB_PASSWORD'])) {
            $_ENV['DB_PASSWORD'] = $envValues['DB_PASSWORD'] ?? '';
        }
        if (!isset($_SERVER['DB_PASSWORD'])) {
            $_SERVER['DB_PASSWORD'] = $envValues['DB_PASSWORD'] ?? '';
        }

        // 現在呼叫 parent::setUp()，Laravel 會使用正確的環境變數
        parent::setUp();
    }

    protected function clearRedisLocks(int $customerId, int $tenantId): void
    {
        // 清除與本測試相關的Redis鎖，避免影響其他測試
        $lockKey = sprintf('point_customer:%d:tenant:%d', $customerId, $tenantId);
        Cache::lock($lockKey)->forceRelease();
    }

    /**
     * 啟動多個真實的獨立PHP進程來測試process-level concurrency
     * 使用Symfony Process並行啟動所有worker，然後等待全部完成
     */
    protected function startWorkers(int $count, string $operation, int $amount, int $tenantId, int $customerId): array
    {
        $processes = [];
        $basePath = base_path();
        $phpBinary = PHP_BINARY; // 使用當前PHP執行檔路徑
        $barrierId = sprintf(
            '%d_%d_%s',
            $tenantId,
            $customerId,
            uniqid()
        );

        try {
            // 建立Barrier
            $this->createBarrier(
                $barrierId,
                $count
            );

            // 明確設定所有 DB 相關環境變數，確保子進程與父進程使用完全相同的 MySQL 連線
            $env = [];

            // 複製所有父進程的環境變數
            foreach (getenv() as $key => $value) {
                $env[$key] = $value;
            }

            // 強制覆寫 DB 相關設定，確保 Worker 絕對使用與 Parent 相同的 MySQL
            $env['DB_CONNECTION'] = 'mysql';
            $env['DB_HOST'] = config('database.connections.mysql.host');
            $env['DB_PORT'] = config('database.connections.mysql.port');
            $env['DB_DATABASE'] = config('database.connections.mysql.database');
            $env['DB_USERNAME'] = config('database.connections.mysql.username');
            $env['DB_PASSWORD'] = config('database.connections.mysql.password');

            // 確保 APP_ENV 正確
            $env['APP_ENV'] = 'testing';

            // 先建立所有worker進程，設定環境變數後再啟動
            for ($i = 0; $i < $count; $i++) {
                $command = [
                    $phpBinary,
                    'artisan',
                    'point:concurrency-worker',
                    $operation,
                    (string) $amount,
                    (string) $tenantId,
                    (string) $customerId,
                    (string) $i,
                    $barrierId,
                    '--no-ansi'
                ];

                $process = new Process($command, $basePath);
                $process->setTimeout(300); // 設定足夠長的超時時間
                $process->setEnv($env); // 為子進程設定正確的環境變數，確保子進程能連接正確資料庫

                $process->start();
                $processes[] = $process;
            }

            // 等待所有worker都報到完成
            $this->waitUntilAllReady(
                $barrierId,
                $count
            );

            // 釋放Barrier，讓所有worker同時開始執行
            $this->releaseBarrier(
                $barrierId
            );

            // 等待所有進程完成並收集結果
            $results = [];
            foreach ($processes as $index => $process) {
                $process->wait();

                $output = trim($process->getOutput());
                $errorOutput = trim($process->getErrorOutput());
                $exitCode = $process->getExitCode();

                $decoded = null;
                if ($output !== '') {
                    $decoded = json_decode($output, true);
                }

                if (is_array($decoded)) {
                    $results[] = $decoded;
                } else {
                    $results[] = [
                        'success' => false,
                        'message' => 'Worker JSON output 無法解析',
                        'worker_index' => $index,
                        'exit_code' => $exitCode,
                        'stdout' => $output,
                        'stderr' => $errorOutput,
                    ];
                }
            }

            // 驗證結果數量與worker數量一致
            $this->assertCount($count, $results, '所有worker都必須返回結果');

            // 收集所有成功worker的started_at
            $startedAts = [];
            foreach ($results as $result) {
                if (isset($result['success']) && $result['success'] && isset($result['started_at'])) {
                    $startedAts[] = (float) $result['started_at'];
                }
            }

            // 計算spread_ms（如果有足夠的數據）
            $spreadMs = 0;
            if (count($startedAts) >= 2) {
                $minTime = min($startedAts);
                $maxTime = max($startedAts);
                $spreadMs = ($maxTime - $minTime) * 1000;
            }

            // 統計成功數量
            $successCount = count(array_filter($results, fn($r) => $r['success']));

            // 輸出診斷信息
            echo sprintf(
                "Worker stats: worker_count=%d, success_count=%d, spread_ms=%.2f\n",
                $count,
                $successCount,
                $spreadMs
            );

            return [
                'results' => $results,
                'success_count' => $successCount,
                'spread_ms' => $spreadMs,
                'worker_count' => $count
            ];
        } finally {
            // 無論成功或失敗，都清理Redis Barrier相關鍵
            Redis::del("barrier:{$barrierId}:ready");
            Redis::del("barrier:{$barrierId}:start");
            Redis::del("barrier:{$barrierId}:expected");
        }
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
            (int) $account->balance,
            (int) $sumRemainingPoints,
            '帳戶餘額必須等於所有點數批次剩餘點數之總和'
        );

        // 驗證沒有負數的剩餘點數
        $negativeLots = PointLot::where('point_account_id', $account->id)
            ->where('remaining_points', '<', 0)
            ->count();

        $this->assertSame(0, (int) $negativeLots, '不應該有任何點數批次出現負數的剩餘點數');

        // 驗證沒有剩餘點數超過原始點數的狀況
        $invalidLots = PointLot::where('point_account_id', $account->id)
            ->whereRaw('remaining_points > original_points')
            ->count();

        $this->assertSame(0, (int) $invalidLots, '不應該有任何點數批次的剩餘點數超過原始點數');

        // 驗證帳戶餘額不為負數
        $this->assertGreaterThanOrEqual(0, (int) $account->balance, '帳戶餘額不能為負數');
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

        // 清除與本測試相關的Redis鎖
        $this->clearRedisLocks($customer->id, $tenant->id);

        // 啟動100個worker進行真正的併發測試
        $workerStats = $this->startWorkers(100, 'earn', 10, $tenant->id, $customer->id);
        $successCount = $workerStats['success_count'];

        $pointAccount->refresh();

        // 額外確認所有100個worker都成功
        $this->assertSame(100, $successCount, '所有100個earn worker都必須成功');

        // 驗證最終餘額為1000（100個worker各加10點）
        $this->assertSame(1000, (int) $pointAccount->balance, '所有earn操作完成後，帳戶餘額應為1000');

        // 驗證有100個earn交易
        $earnTransactions = PointTransaction::where('point_account_id', $pointAccount->id)
            ->where('type', PointTransaction::TYPE_EARN)
            ->count();
        $this->assertSame(100, $earnTransactions, '應該產生100筆earn交易記錄');

        // 驗證所有不變條件都滿足
        $sumRemainingPoints = PointLot::where('point_account_id', $pointAccount->id)->sum('remaining_points');
        $this->assertPointInvariants($pointAccount);
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

        // 清除與本測試相關的Redis鎖
        $this->clearRedisLocks($customer->id, $tenant->id);

        // 啟動100個worker進行真正的併發測試
        $workerStats = $this->startWorkers(100, 'redeem', 5, $tenant->id, $customer->id);
        $successCount = $workerStats['success_count'];

        $pointAccount->refresh();

        // 驗證最終餘額為500（100個worker各減5點）
        $this->assertSame(500, (int) $pointAccount->balance, '所有redeem操作完成後，帳戶餘額應為500');

        // 驗證有100個redeem交易
        $redeemTransactions = PointTransaction::where('point_account_id', $pointAccount->id)
            ->where('type', PointTransaction::TYPE_REDEEM)
            ->count();
        $this->assertSame(100, $redeemTransactions, '應該產生100筆redeem交易記錄');

        // 驗證所有不變條件都滿足
        $sumRemainingPoints = PointLot::where('point_account_id', $pointAccount->id)->sum('remaining_points');
        $this->assertPointInvariants($pointAccount);
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

        // 清除與本測試相關的Redis鎖
        $this->clearRedisLocks($customer->id, $tenant->id);

        // 啟動100個worker進行真正的併發測試
        $workerStats = $this->startWorkers(100, 'redeem', 10, $tenant->id, $customer->id);
        $successCount = $workerStats['success_count'];

        $pointAccount->refresh();

        // 驗證最多只能成功50次（50*10=500）
        $this->assertLessThanOrEqual(50, $successCount, '最多只能有50個兌換成功');
        $this->assertGreaterThanOrEqual(0, $pointAccount->balance, '帳戶餘額不能為負數');

        // 計算總共成功兌換的點數
        $totalRedeemed = PointTransaction::where('point_account_id', $pointAccount->id)
            ->where('type', PointTransaction::TYPE_REDEEM)
            ->sum('amount');

        $this->assertLessThanOrEqual(500, $totalRedeemed, '總兌換點數不能超過初始的500點');

        // 驗證所有不變條件都滿足
        $sumRemainingPoints = PointLot::where('point_account_id', $pointAccount->id)->sum('remaining_points');
        $this->assertPointInvariants($pointAccount);
    }

    protected function createBarrier(
        string $barrierId,
        int $workerCount
    ): void {
        Redis::del("barrier:{$barrierId}:ready");
        Redis::del("barrier:{$barrierId}:start");
        Redis::del("barrier:{$barrierId}:expected");

        Redis::set(
            "barrier:{$barrierId}:expected",
            $workerCount
        );
        Redis::expire("barrier:{$barrierId}:expected", 60);
    }

    protected function waitUntilAllReady(
        string $barrierId,
        int $workerCount
    ): void {
        $timeout = now()->addSeconds(30);
        $ready = 0;

        while (now()->lt($timeout)) {
            $ready = (int) Redis::get(
                "barrier:{$barrierId}:ready"
            ) ?: 0;

            // 每5秒輸出一次當前狀態
            if ((int)(now()->timestamp - ($timeout->timestamp - 30)) % 5 === 0) {
                info('Parent waiting for workers', [
                    'barrier_id' => $barrierId,
                    'ready' => $ready,
                    'expected' => $workerCount,
                ]);
            }

            if ($ready >= $workerCount) {
                info('All workers ready, releasing barrier', [
                    'barrier_id' => $barrierId,
                    'ready' => $ready,
                    'expected' => $workerCount,
                ]);
                return;
            }

            usleep(10000);
        }

        $this->fail(
            "Barrier timeout: only {$ready}/{$workerCount} workers ready"
        );
    }

    protected function releaseBarrier(
        string $barrierId
    ): void {
        Redis::set(
            "barrier:{$barrierId}:start",
            1
        );
        Redis::expire("barrier:{$barrierId}:start", 60);
    }

    /**
     * 測試結束後清理
     */
    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
