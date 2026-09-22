<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Point\PointService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ExpirePointsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'points:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically expire points that have passed their expiration date and update account balances';

    /**
     * 鎖定鍵，防止命令重複執行
     */
    protected string $lockKey = 'commands:points:expire';

    /**
     * Execute the console command.
     */
    public function handle(\App\Support\Tenancy\TenantContext $tenantContext, PointService $pointService)
    {
        // 使用原子鎖防止重複執行
        if (!Cache::add($this->lockKey, true, 3600)) {
            $this->warn('points:expire command is already running.');
            return 1;
        }

        try {
            $this->info('Starting point expiration process...');

            $processedCustomers = 0;
            $totalExpiredPoints = 0;
            $lastProcessedTenantId = null;

            // 先找出所有有需要過期點數的客戶ID，按租戶排序以最佳化租戶切換
            Customer::whereHas('pointLots', function ($query) {
                $query->where('remaining_points', '>', 0)
                    ->whereNotNull('expired_at')
                    ->where('expired_at', '<', Carbon::now());
            })
                ->orderBy('tenant_id')
                ->orderBy('id')
                ->chunkById(100, function ($customers) use ($tenantContext, $pointService, &$processedCustomers, &$totalExpiredPoints, &$lastProcessedTenantId) {
                    foreach ($customers as $customer) {
                        try {
                            // 切換租戶上下文（只有當租戶改變時才更新）
                            if ($lastProcessedTenantId !== $customer->tenant_id) {
                                $tenant = \App\Models\Tenant::findOrFail($customer->tenant_id);
                                $tenantContext->setTenant($tenant);
                                $lastProcessedTenantId = $customer->tenant_id;
                            }

                            // 使用PointService處理該客戶的所有過期點數
                            [$expireAmount, $transaction] = $pointService->expireAllExpiredLots($customer);

                            if ($expireAmount > 0) {
                                $processedCustomers++;
                                $totalExpiredPoints += $expireAmount;
                                $this->info("Expired {$expireAmount} points for customer {$customer->id} (tenant: {$customer->tenant_id})");
                            }
                        } catch (\Exception $e) {
                            $this->error("Failed to process customer {$customer->id}: " . $e->getMessage());
                            continue;
                        }
                    }
                });

            $this->info("Point expiration completed. Processed {$processedCustomers} customers, expired {$totalExpiredPoints} points total.");

            return 0;
        } finally {
            // 確保無論成功或失敗都會釋放Cache鎖
            Cache::forget($this->lockKey);
        }
    }
}
