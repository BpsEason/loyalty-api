<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Services\Point\PointService;
use Illuminate\Support\Facades\DB;

class EnsurePointAccountCommand extends Command
{
    protected $signature = 'app:ensure-point-account 
        {--tenant-id= : Tenant ID}
        {--customer-id= : Customer ID}';

    protected $description = 'Ensure point account exists for customer (used for concurrency testing)';

    public function handle(PointService $pointService)
    {
        $tenantId = $this->option('tenant-id');
        $customerId = $this->option('customer-id');

        $customer = Customer::findOrFail($customerId);

        try {
            DB::beginTransaction();

            $account = $pointService->getOrCreatePointAccount($customer);

            DB::commit();

            $this->info('ACCOUNT_ENSURED: ' . $account->id);
            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('ACCOUNT_ERROR: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
