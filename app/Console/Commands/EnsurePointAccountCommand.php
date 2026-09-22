<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Models\Tenant;
use App\Services\Point\PointService;
use App\Support\Tenancy\TenantResolver;

class EnsurePointAccountCommand extends Command
{
    protected $signature = 'app:ensure-point-account 
        {--tenant-id= : Tenant ID}
        {--customer-id= : Customer ID}';

    protected $description = 'Ensure point account exists for customer (used for concurrency testing)';

    public function handle(PointService $pointService, \App\Support\Tenancy\TenantContext $tenantContext)
    {
        $tenantId = $this->option('tenant-id');
        $customerId = $this->option('customer-id');

        if (!$tenantId || !$customerId) {
            $this->error('缺少必要參數：--tenant-id 和 --customer-id 都是必填的');
            return 1;
        }

        // 透過正式的 TenantContext 設定租戶上下文
        $tenant = Tenant::findOrFail($tenantId);
        $tenantContext->setTenant($tenant);

        $customer = Customer::where('tenant_id', $tenantId)->findOrFail($customerId);

        try {
            // PointService::getOrCreatePointAccount 內部已自行處理 transaction 和 locking
            $account = $pointService->getOrCreatePointAccount($customer);

            $this->info('ACCOUNT_ENSURED: ' . $account->id);
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('ACCOUNT_ERROR: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
