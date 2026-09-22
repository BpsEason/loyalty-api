<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Tenant;
use App\Services\Point\PointService;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Console\Command;

class RedeemPointsCommand extends Command
{
    protected $signature = 'app:redeem-points 
        {--tenant-id= : 租戶ID}
        {--customer-id= : 客戶ID}
        {--amount= : 兌換金額}';

    protected $description = '執行點數兌換（用於併發測試）';

    public function handle(PointService $pointService, \App\Support\Tenancy\TenantContext $tenantContext): int
    {
        $tenantId = $this->option('tenant-id');
        $customerId = $this->option('customer-id');
        $amount = (int) $this->option('amount');

        if (!$tenantId || !$customerId || !$amount) {
            $this->error('缺少必要參數');
            return 1;
        }

        try {
            // 透過正式的 TenantContext 設定租戶上下文
            $tenant = Tenant::findOrFail($tenantId);
            $tenantContext->setTenant($tenant);

            $customer = Customer::where('tenant_id', $tenantId)->findOrFail($customerId);

            $transaction = $pointService->redeem(
                $customer,
                $amount,
                '併發測試兌換',
                null,
                null
            );

            $this->info("兌換成功: 交易ID {$transaction->id}");
            return 0;
        } catch (\Exception $e) {
            $this->warn("兌換失敗: {$e->getMessage()}");
            return 1;
        }
    }
}
