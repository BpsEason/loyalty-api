<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Customer;
use App\Models\Campaign;
use App\Models\CampaignReward;
use App\Services\Reward\RewardService;
use Illuminate\Database\Seeder;

class RewardSeeder extends Seeder
{
    public function __construct(protected RewardService $rewardService) {}

    public function run(): void
    {
        // 取得第一個現有的租戶
        $tenant = Tenant::first();
        if (!$tenant) {
            $this->command->error('目前沒有任何租戶資料，無法建立獎勵測試資料');
            return;
        }

        // 從該租戶取得第一個現有的會員
        $customer = $tenant->customers()->first();
        if (!$customer) {
            $this->command->error('目前沒有會員資料，無法建立獎勵測試資料');
            return;
        }

        // 建立測試活動
        $campaign = $this->rewardService->createCampaign([
            'tenant_id' => $tenant->id,
            'name' => '2026 秋季感謝活動',
            'description' => '感謝所有客戶的支持，發放專屬點數獎勵',
            'status' => Campaign::STATUS_ACTIVE,
            'starts_at' => now()->subDays(7),
            'ends_at' => now()->addDays(30),
        ]);

        // 建立活動獎勵 - 歡迎獎勵100點
        $welcomeReward = $this->rewardService->createCampaignReward($campaign, [
            'reward_type' => CampaignReward::TYPE_POINTS,
            'points' => 100,
            'enabled' => true,
        ]);

        // 建立另一個獎勵 - 活躍用戶額外獎勵50點
        $activeUserReward = $this->rewardService->createCampaignReward($campaign, [
            'reward_type' => CampaignReward::TYPE_POINTS,
            'points' => 50,
            'enabled' => true,
        ]);

        // 發放歡迎獎勵給客戶
        try {
            $this->rewardService->grantRewardToCustomer($customer, $welcomeReward);
            $this->command->info('成功發放歡迎獎勵給客戶');
        } catch (\RuntimeException $e) {
            $this->command->info('發放歡迎獎勵失敗：' . $e->getMessage());
        }

        // 發放活躍用戶獎勵給客戶
        try {
            $this->rewardService->grantRewardToCustomer($customer, $activeUserReward);
            $this->command->info('成功發放活躍用戶獎勵給客戶');
        } catch (\RuntimeException $e) {
            $this->command->info('發放活躍用戶獎勵失敗：' . $e->getMessage());
        }

        $this->command->info('獎勵系統測試資料建立完成！');
    }
}
