<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\PointAccount;
use App\Models\PointLot;
use App\Models\PointTransaction;
use App\Models\Tenant;
use App\Services\Point\PointService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class PointConcurrencyWorkerCommand extends Command
{
    protected $signature = 'point:concurrency-worker {operation} {amount} {tenant_id} {customer_id} {index} {barrier_id}';
    protected $description = 'Worker command for point concurrency testing';

    public function handle(
        TenantContext $tenantContext,
        PointService $pointService
    ): int {
        try {
            // 禁用Laravel的所有輸出，確保只有我們的JSON被輸出
            $this->output->setVerbosity(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_QUIET);

            // 獲取輸入參數
            $operation = $this->argument('operation');
            $amount = (int) $this->argument('amount');
            $tenantId = (int) $this->argument('tenant_id');
            $customerId = (int) $this->argument('customer_id');
            $index = (int) $this->argument('index');
            $barrierId = $this->argument('barrier_id');

            // 診斷：輸出Cache和Redis連線資訊
            $cacheStore = config('cache.default');
            $cacheDriver = config('cache.stores.' . $cacheStore . '.driver');
            $redisConnection = config('cache.stores.redis.connection', 'unknown');
            $redisHost = config('database.redis.default.host');
            $redisPort = config('database.redis.default.port');
            $redisDb = config('database.redis.default.database');
            $redisCacheDb = config('database.redis.cache.database');

            // 測試Cache::increment是否正常工作
            $testKey = "diagnostic:worker:{$barrierId}:{$index}";
            try {
                $incrementResult = Cache::increment($testKey);
                $readResult = Cache::get($testKey);
                Cache::forget($testKey);
                $cacheWriteReadSuccess = ($incrementResult === 1 && $readResult === 1);
            } catch (\Exception $e) {
                $cacheWriteReadSuccess = false;
                $cacheException = $e->getMessage();
            }

            // Worker 啟動日誌（包含完整診斷資訊）
            Log::info('Point concurrency worker started', [
                'worker' => $index,
                'pid' => getmypid(),
                'operation' => $operation,
                'amount' => $amount,
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'barrier_id' => $barrierId,
                'db_connection' => config('database.default'),
                'db_host' => config('database.connections.' . config('database.default') . '.host'),
                'db_database' => config('database.connections.' . config('database.default') . '.database'),
                'db_username' => config('database.connections.' . config('database.default') . '.username'),
                // Cache診斷
                'cache_store' => $cacheStore,
                'cache_driver' => $cacheDriver,
                'cache_write_read_success' => $cacheWriteReadSuccess ?? false,
                'cache_exception' => $cacheException ?? null,
                // Redis診斷（如果使用redis的話）
                'redis_connection' => $redisConnection,
                'redis_host' => $redisHost,
                'redis_port' => $redisPort,
                'redis_database_default' => $redisDb,
                'redis_database_cache' => $redisCacheDb,
            ]);

            // 向Barrier報到，使用Redis Atomic Increment避免競爭
            Redis::incr("barrier:{$barrierId}:ready");

            Log::info('Point worker reported to barrier', [
                'worker' => $index,
                'barrier_id' => $barrierId,
                'pid' => getmypid(),
            ]);

            // 等待父進程釋放Barrier
            $timeout = time() + 30;
            while (time() < $timeout) {
                if (Redis::get("barrier:{$barrierId}:start")) {
                    break;
                }
                usleep(1000);
            }

            if (!Redis::get("barrier:{$barrierId}:start")) {
                throw new \RuntimeException('Barrier timeout');
            }

            // 記錄通過Barrier後的開始時間
            $startedAt = microtime(true);

            Log::info('Point worker barrier released, starting operation', [
                'worker' => $index,
                'barrier_id' => $barrierId,
                'pid' => getmypid(),
                'started_at' => $startedAt,
            ]);

            // 先查詢基礎資料，確認是否存在
            $tenant = Tenant::find($tenantId);
            $customer = Customer::find($customerId);
            $account = PointAccount::where('tenant_id', $tenantId)
                ->where('customer_id', $customerId)
                ->first();

            // 資料庫狀態檢查日誌
            Log::info('Point worker database state before operation', [
                'worker' => $index,
                'pid' => getmypid(),
                'tenant_exists' => $tenant !== null,
                'customer_exists' => $customer !== null,
                'account_exists' => $account !== null,
                'account_id' => $account?->id,
                'balance' => $account?->balance,
                'total_earned' => $account?->total_earned,
                'total_redeemed' => $account?->total_redeemed,
                'transaction_count' => $account ? PointTransaction::where('point_account_id', $account->id)->count() : null,
                'lot_count' => $account ? PointLot::where('point_account_id', $account->id)->count() : null,
            ]);

            // 操作前日誌
            Log::info('Point worker before operation', [
                'worker' => $index,
                'pid' => getmypid(),
                'operation' => $operation,
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
            ]);

            // 繼續原本的邏輯，使用 findOrFail 確保資料存在
            $tenant = Tenant::findOrFail($tenantId);
            $tenantContext->setTenant($tenant);
            app()->instance('current_tenant_id', $tenant->id);

            $customer = Customer::findOrFail($customerId);

            if ($customer->tenant_id !== $tenant->id) {
                throw new \RuntimeException('客戶租戶與指定租戶不一致');
            }

            // 執行操作
            $result = match ($operation) {
                'earn' => $pointService->earn(
                    $customer,
                    $amount,
                    "併發測試獲得點數-{$index}",
                    null,
                    null
                ),
                'redeem' => $pointService->redeem(
                    $customer,
                    $amount,
                    "併發測試兌換點數-{$index}",
                    null,
                    null
                ),
                default => throw new \InvalidArgumentException("Unsupported operation: {$operation}"),
            };

            // 操作完成後刷新帳戶狀態
            $account->refresh();

            Log::info('Point worker operation completed', [
                'worker' => $index,
                'pid' => getmypid(),
                'operation' => $operation,
                'success' => true,
                'account_id' => $account->id,
                'balance' => $account->balance,
                'total_earned' => $account->total_earned,
                'total_redeemed' => $account->total_redeemed,
                'transaction_count' => PointTransaction::where('point_account_id', $account->id)->count(),
                'lot_count' => PointLot::where('point_account_id', $account->id)->count(),
            ]);

            echo json_encode([
                'success' => true,
                'transaction_id' => $result->id,
                'message' => '操作成功',
                'started_at' => $startedAt
            ]) . PHP_EOL;

            return 0;
        } catch (\Throwable $e) {
            Log::error('Point concurrency worker failed', [
                'worker' => $index ?? null,
                'pid' => getmypid(),
                'operation' => $operation ?? null,
                'amount' => $amount ?? null,
                'tenant_id' => $tenantId ?? null,
                'customer_id' => $customerId ?? null,
                'db_connection' => config('database.default'),
                'db_database' => config('database.connections.' . config('database.default') . '.database'),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'exception' => get_class($e)
            ]) . PHP_EOL;

            return 1;
        }
    }
}
