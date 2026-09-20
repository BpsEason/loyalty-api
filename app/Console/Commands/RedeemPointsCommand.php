<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Point\PointService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RedeemPointsCommand extends Command
{
    protected $signature = 'app:redeem-points 
        {--tenant-id= : 租戶ID}
        {--customer-id= : 客戶ID}
        {--amount= : 兌換金額}';

    protected $description = '執行點數兌換（用於併發測試）';

    public function handle(PointService $pointService): int
    {
        $tenantId = $this->option('tenant-id');
        $customerId = $this->option('customer-id');
        $amount = (int) $this->option('amount');

        if (!$tenantId || !$customerId || !$amount) {
            $this->error('缺少必要參數');
            return 1;
        }

        try {
            // 手動設定租戶上下文
            app()->instance('current_tenant_id', $tenantId);

            $customer = Customer::findOrFail($customerId);

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
