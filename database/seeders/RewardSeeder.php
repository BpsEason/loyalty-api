<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Customer;
use App\Models\Campaign;
use App\Models\CampaignReward;
use App\Models\RewardGrant;
use App\Models\PointAccount;
use App\Models\PointTransaction;
use App\Services\Reward\RewardService;
use App\Services\Point\PointService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use RuntimeException;

class RewardSeeder extends Seeder
{
    // 使用DatabaseSeeder建立的正式Demo租戶（透過domain穩定識別）
    protected array $demoTenants = [
        'coffee' => [
            'domain' => 'coffee.localhost',
        ],
        'fitness' => [
            'domain' => 'fitness.localhost',
        ],
    ];



    // 每個租戶專屬的客戶資料（DatabaseSeeder已建立客戶，此處用於匹配索引）
    protected array $tenantCustomerData = [
        'coffee' => [
            ['name' => '劉雅婷', 'email' => 'yating.liu', 'phone' => '0911555666'],
            ['name' => '楊志偉', 'email' => 'zhiwei.yang', 'phone' => '0922777888'],
            ['name' => '周慧雯', 'email' => 'huiwen.zhou', 'phone' => '0933999000'],
            ['name' => '鄭建宏', 'email' => 'jianhong.zheng', 'phone' => '0944111222'],
            ['name' => '何欣宜', 'email' => 'xinyi.he', 'phone' => '0955333444'],
            ['name' => '謝國榮', 'email' => 'guorong.xie', 'phone' => '0966555666'],
        ],
        'fitness' => [
            ['name' => '曾俊傑', 'email' => 'junjie.zeng', 'phone' => '0911888999'],
            ['name' => '蔡淑華', 'email' => 'shuhua.cai', 'phone' => '0922000111'],
            ['name' => '彭建宇', 'email' => 'jianyu.peng', 'phone' => '0933222333'],
            ['name' => '蘇美華', 'email' => 'meihua.su', 'phone' => '0944444555'],
            ['name' => '鄧文彬', 'email' => 'wenbin.deng', 'phone' => '0955666777'],
            ['name' => '簡佳琪', 'email' => 'jiaqi.jian', 'phone' => '0966888999'],
            ['name' => '藍志明', 'email' => 'zhiming.lan', 'phone' => '0977000111'],
            ['name' => '賴佩如', 'email' => 'peiru.lai', 'phone' => '0988222333'],
        ],
    ];

    // 每個租戶專屬的活動配置，符合其產業特性
    protected array $tenantCampaignData = [
        'coffee' => [
            [
                'name' => '早安咖啡優惠',
                'description' => '每日早上10點前購買早餐組合，額外獲得50點',
                'status' => Campaign::STATUS_ACTIVE,
                'starts_at_days' => -45,
                'ends_at_days' => 180,
                'rewards' => [
                    ['type' => CampaignReward::TYPE_POINTS, 'points' => 50, 'enabled' => true],
                ]
            ],
            [
                'name' => '生日會員專屬獎勵',
                'description' => '生日當月消費，獲得300點生日禮金',
                'status' => Campaign::STATUS_ACTIVE,
                'starts_at_days' => -10,
                'ends_at_days' => 355,
                'rewards' => [
                    ['type' => CampaignReward::TYPE_POINTS, 'points' => 300, 'enabled' => true],
                    ['type' => CampaignReward::TYPE_COUPON, 'points' => 0, 'enabled' => true],
                ]
            ],
            [
                'name' => '聖誕限定飲料活動',
                'description' => '聖誕節期間購買限定飲料，獲得專屬徽章與點數',
                'status' => Campaign::STATUS_INACTIVE,
                'starts_at_days' => 60,
                'ends_at_days' => 80,
                'rewards' => [
                    ['type' => CampaignReward::TYPE_POINTS, 'points' => 150, 'enabled' => true],
                    ['type' => CampaignReward::TYPE_BADGE, 'points' => 0, 'enabled' => true],
                ]
            ],
            [
                'name' => '夏季冰品瘋',
                'description' => '夏天限定冰品系列，消費累積點數',
                'status' => Campaign::STATUS_COMPLETED,
                'starts_at_days' => -100,
                'ends_at_days' => -10,
                'rewards' => [
                    ['type' => CampaignReward::TYPE_POINTS, 'points' => 120, 'enabled' => true],
                ]
            ],
        ],
        'fitness' => [
            [
                'name' => '續約會員獎勵',
                'description' => '會員續約一年，立即獲得500點續約獎勵',
                'status' => Campaign::STATUS_ACTIVE,
                'starts_at_days' => -60,
                'ends_at_days' => 300,
                'rewards' => [
                    ['type' => CampaignReward::TYPE_POINTS, 'points' => 500, 'enabled' => true],
                ]
            ],
            [
                'name' => '課程消費集點',
                'description' => '報名付费課程，每消費100元累積10點',
                'status' => Campaign::STATUS_ACTIVE,
                'starts_at_days' => -20,
                'ends_at_days' => 160,
                'rewards' => [
                    ['type' => CampaignReward::TYPE_POINTS, 'points' => 250, 'enabled' => true],
                    ['type' => CampaignReward::TYPE_POINTS, 'points' => 500, 'enabled' => true],
                ]
            ],
            [
                'name' => '新春減重挑戰',
                'description' => '參加年度減重挑戰，完成目標獲得高額點數',
                'status' => Campaign::STATUS_DRAFT,
                'starts_at_days' => 45,
                'ends_at_days' => 135,
                'rewards' => [
                    ['type' => CampaignReward::TYPE_POINTS, 'points' => 1000, 'enabled' => false],
                ]
            ],
            [
                'name' => '2025年度活躍會員',
                'description' => '去年到館超過100次的活躍會員，獲得年度榮譽徽章',
                'status' => Campaign::STATUS_COMPLETED,
                'starts_at_days' => -45,
                'ends_at_days' => -5,
                'rewards' => [
                    ['type' => CampaignReward::TYPE_BADGE, 'points' => 0, 'enabled' => true],
                    ['type' => CampaignReward::TYPE_POINTS, 'points' => 600, 'enabled' => true],
                ]
            ],
        ],
    ];

    // 每個租戶內的獎勵發放配置，營造不同會員狀態
    protected array $tenantGrantConfigs = [
        'coffee' => [
            // 高活躍：每日來店，獲得多種獎勵
            0 => [0 => [0], 1 => [0], 3 => [0]],
            // 一般會員
            1 => [0 => [0]],
            // 生日會員：獲得生日獎勵
            2 => [1 => [0, 1]],
            // 新會員
            3 => [0 => [0]],
            // 未參與
            4 => [],
            // 參加過夏季活動
            5 => [3 => [0]],
        ],
        'fitness' => [
            // VIP活躍會員：參加過所有活動
            0 => [0 => [0], 1 => [1], 3 => [1]],
            // 續約會員
            1 => [0 => [0]],
            // 課程學員
            2 => [1 => [0]],
            // 年度活躍會員
            3 => [3 => [1]],
            // 新加入會員
            4 => [0 => [0]],
            // 尚未參與任何活動
            5 => [],
            // 參加過過去活動
            6 => [3 => [0]],
            // 新會員
            7 => [0 => [0]],
        ],
    ];

    // 額外為活躍會員建立歷史點數交易的時間軸
    protected array $historicalTransactions = [
        'coffee' => [
            0 => [ // 劉雅婷（高活躍）
                ['days_ago' => 0, 'type' => PointTransaction::TYPE_EARN, 'amount' => 50, 'description' => '早安咖啡優惠'],
                ['days_ago' => 7, 'type' => PointTransaction::TYPE_EARN, 'amount' => 50, 'description' => '早安咖啡優惠'],
                ['days_ago' => 14, 'type' => PointTransaction::TYPE_EARN, 'amount' => 120, 'description' => '夏季冰品瘋'],
                ['days_ago' => 30, 'type' => PointTransaction::TYPE_EARN, 'amount' => 50, 'description' => '早安咖啡優惠'],
            ],
        ],
        'fitness' => [
            0 => [ // 曾俊傑（VIP）
                ['days_ago' => 2, 'type' => PointTransaction::TYPE_EARN, 'amount' => 500, 'description' => '續約會員獎勵'],
                ['days_ago' => 10, 'type' => PointTransaction::TYPE_EARN, 'amount' => 500, 'description' => '拳擊課程消費'],
                ['days_ago' => 25, 'type' => PointTransaction::TYPE_EARN, 'amount' => 600, 'description' => '年度活躍會員獎勵'],
                ['days_ago' => 40, 'type' => PointTransaction::TYPE_REDEEM, 'amount' => 300, 'description' => '兌換運動毛巾'],
            ],
        ],
    ];

    public function __construct(
        protected RewardService $rewardService,
        protected PointService $pointService
    ) {}

    public function run(): void
    {
        $this->command->info('開始建立多租戶忠誠獎勵系統Demo資料集...');
        $this->command->newLine();

        $stats = [
            'tenants_created' => 0,
            'tenant_admins_created' => 0,
            'tenant_admins_reused' => 0,
            'customers_created' => 0,
            'campaigns_created' => 0,
            'rewards_created' => 0,
            'grants_success' => 0,
            'grants_skipped' => 0,
            'grants_failed' => 0,
            'historical_transactions_created' => 0,
        ];

        // 處理每個Demo租戶
        foreach ($this->demoTenants as $tenantKey => $tenantData) {
            // 1. 透過domain取得已存在的租戶（DatabaseSeeder建立的正式Demo租戶）
            $tenant = Tenant::where('domain', $tenantData['domain'])->first();

            if (!$tenant) {
                $this->command->error("  ✗ 找不到租戶：{$tenantData['domain']}，跳過處理");
                $this->command->newLine();
                continue;
            }

            $this->command->info("處理租戶：{$tenant->name} ({$tenant->domain})");
            $this->command->line(str_repeat('-', 60));

            // 2. 取得租戶已存在的客戶（DatabaseSeeder建立的）
            $tenantCustomers = $this->getExistingTenantCustomers($tenant, $stats);
            if (empty($tenantCustomers)) {
                $this->command->error("  ✗ 此租戶未建立任何客戶，跳過後續處理");
                $this->command->newLine();
                continue;
            }

            // 3. 建立租戶的活動與獎勵
            $tenantCampaigns = $this->createTenantCampaigns($tenantKey, $tenant, $stats);

            // 4. 依配置發放獎勵給客戶
            if (!empty($tenantCampaigns) && !empty($tenantCustomers)) {
                $this->grantRewardsToCustomers($tenantKey, $tenant, $tenantCustomers, $tenantCampaigns, $stats);
            }

            // 5. 為活躍會員建立歷史點數交易（時間軸）
            $this->createHistoricalTransactions($tenantKey, $tenant, $tenantCustomers, $stats);

            $this->command->newLine();
        }

        // 輸出最終統計
        $this->outputFinalStats($stats);
    }

    /**
     * 取得租戶已存在的客戶（DatabaseSeeder建立的）
     */
    protected function getExistingTenantCustomers(Tenant $tenant, array &$stats): \Illuminate\Database\Eloquent\Collection
    {
        $customers = Customer::where('tenant_id', $tenant->id)->get();

        $this->command->line("  取得租戶現有客戶：共 {$customers->count()} 位客戶");

        return $customers;
    }



    /**
     * 為租戶建立符合產業特性的活動與活動獎勵
     */
    protected function createTenantCampaigns(string $tenantKey, Tenant $tenant, array &$stats): array
    {
        $campaigns = [];
        $campaignData = $this->tenantCampaignData[$tenantKey] ?? [];

        foreach ($campaignData as $configIndex => $campaignConfig) {
            /** @var Campaign $campaign */
            $campaign = Campaign::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $campaignConfig['name']],
                [
                    'tenant_id' => $tenant->id,
                    'name' => $campaignConfig['name'],
                    'description' => $campaignConfig['description'],
                    'status' => $campaignConfig['status'],
                    'starts_at' => now()->addDays($campaignConfig['starts_at_days']),
                    'ends_at' => now()->addDays($campaignConfig['ends_at_days']),
                ]
            );

            if ($campaign->wasRecentlyCreated) {
                $stats['campaigns_created']++;
                $statusText = $this->getStatusText($campaign->status);
                $this->command->line("    ✓ 建立活動：{$campaign->name} [{$statusText}]");
            } else {
                $this->command->line("    活動已存在：{$campaign->name}");
            }

            // 建立活動的獎勵
            $campaignRewards = [];
            foreach ($campaignConfig['rewards'] as $rewardIndex => $rewardConfig) {
                /** @var CampaignReward $reward */
                $reward = CampaignReward::firstOrCreate(
                    [
                        'campaign_id' => $campaign->id,
                        'reward_type' => $rewardConfig['type'],
                        'points' => $rewardConfig['points'],
                    ],
                    [
                        'reward_type' => $rewardConfig['type'],
                        'points' => $rewardConfig['points'],
                        'enabled' => $rewardConfig['enabled'],
                    ]
                );

                if ($reward->wasRecentlyCreated) {
                    $stats['rewards_created']++;
                    $typeText = $this->getRewardTypeText($reward->reward_type);
                    $this->command->line("      ✦ 建立{$typeText}獎勵：{$reward->points}點");
                }

                $campaignRewards[] = $reward;
            }

            $campaigns[] = [
                'model' => $campaign,
                'rewards' => $campaignRewards,
                'config_index' => $configIndex,
            ];
        }

        return $campaigns;
    }

    /**
     * 根據配置發放獎勵，營造不同的會員狀態
     */
    protected function grantRewardsToCustomers(string $tenantKey, Tenant $tenant, \Illuminate\Database\Eloquent\Collection $customers, array $campaigns, array &$stats): void
    {
        $grantConfig = $this->tenantGrantConfigs[$tenantKey] ?? [];

        foreach ($grantConfig as $customerIndex => $campaignGrants) {
            if (!isset($customers[$customerIndex])) {
                continue;
            }

            $customer = $customers[$customerIndex];
            $this->command->line("    處理客戶：{$customer->name} (ID: {$customer->id})");

            foreach ($campaignGrants as $targetCampaignIndex => $rewardIndices) {
                $targetCampaign = collect($campaigns)->firstWhere('config_index', $targetCampaignIndex);
                if (!$targetCampaign) {
                    continue;
                }

                $campaignModel = $targetCampaign['model'];
                $campaignRewards = $targetCampaign['rewards'];

                foreach ($rewardIndices as $rewardIndex) {
                    if (!isset($campaignRewards[$rewardIndex])) {
                        continue;
                    }

                    $reward = $campaignRewards[$rewardIndex];

                    try {
                        // 檢查是否已經發放過
                        $existingGrant = RewardGrant::withoutGlobalScope('tenant')
                            ->where('tenant_id', $tenant->id)
                            ->where('campaign_id', $campaignModel->id)
                            ->where('campaign_reward_id', $reward->id)
                            ->where('customer_id', $customer->id)
                            ->first();

                        if ($existingGrant) {
                            $stats['grants_skipped']++;
                            $this->command->line("      ⏭ 跳過：客戶已獲得過「{$campaignModel->name}」的{$reward->points}點獎勵");
                            continue;
                        }

                        // 發放獎勵
                        $grant = $this->rewardService->grantRewardToCustomer($customer, $reward);

                        if ($grant->status === RewardGrant::STATUS_GRANTED) {
                            $stats['grants_success']++;
                            $this->command->line("      ✓ 成功發放：「{$campaignModel->name}」{$reward->points}點");
                        } else {
                            $stats['grants_failed']++;
                            $this->command->error("      ✗ 發放失敗：客戶 {$customer->name}，原因：{$grant->failure_reason}");
                        }
                    } catch (RuntimeException $e) {
                        if (str_contains($e->getMessage(), '已獲得過該獎勵')) {
                            $stats['grants_skipped']++;
                            $this->command->line("      ⏭ 跳過：客戶已獲得過此獎勵");
                        } else {
                            $stats['grants_failed']++;
                            $this->command->error(sprintf(
                                "      ✗ 發放異常：%s，錯誤：%s",
                                $campaignModel->name,
                                $e->getMessage()
                            ));
                        }
                    }
                }
            }
        }
    }

    /**
     * 為活躍會員建立歷史點數交易，形成時間軸
     */
    protected function createHistoricalTransactions(string $tenantKey, Tenant $tenant, \Illuminate\Database\Eloquent\Collection $customers, array &$stats): void
    {
        $transactionData = $this->historicalTransactions[$tenantKey] ?? [];
        if (empty($transactionData)) {
            return;
        }

        $this->command->line("    為活躍會員建立歷史點數交易...");

        foreach ($transactionData as $customerIndex => $transactions) {
            if (!isset($customers[$customerIndex])) {
                continue;
            }

            $customer = $customers[$customerIndex];

            foreach ($transactions as $txConfig) {
                // 檢查此交易是否已存在（通過描述和時間近似判斷）
                $txDate = now()->subDays($txConfig['days_ago']);
                $existingTx = PointTransaction::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenant->id)
                    ->where('customer_id', $customer->id)
                    ->where('description', $txConfig['description'])
                    ->whereDate('created_at', $txDate->toDateString())
                    ->first();

                if ($existingTx) {
                    continue;
                }

                // 建立歷史交易
                try {
                    if ($txConfig['type'] === PointTransaction::TYPE_EARN) {
                        $tx = $this->pointService->earn($customer, $txConfig['amount'], $txConfig['description']);
                    } elseif ($txConfig['type'] === PointTransaction::TYPE_REDEEM) {
                        $tx = $this->pointService->redeem($customer, $txConfig['amount'], $txConfig['description']);
                    } else {
                        $tx = $this->pointService->adjust($customer, $txConfig['amount'], $txConfig['description']);
                    }

                    // 修改交易的建立時間，使其符合歷史時間軸
                    $tx->update(['created_at' => $txDate]);
                    $stats['historical_transactions_created']++;
                } catch (RuntimeException $e) {
                    // 忽略餘額不足等錯誤，繼續處理
                    continue;
                }
            }

            $this->command->line("      ✓ 客戶 {$customer->name} 的歷史交易已建立");
        }
    }

    /**
     * 取得活動狀態的中文描述
     */
    protected function getStatusText(string $status): string
    {
        return match ($status) {
            Campaign::STATUS_ACTIVE => '進行中',
            Campaign::STATUS_DRAFT => '草稿',
            Campaign::STATUS_COMPLETED => '已結束',
            Campaign::STATUS_INACTIVE => '即將開始',
            default => $status,
        };
    }

    /**
     * 取得獎勵類型的中文描述
     */
    protected function getRewardTypeText(string $type): string
    {
        return match ($type) {
            CampaignReward::TYPE_POINTS => '點數',
            CampaignReward::TYPE_BADGE => '徽章',
            CampaignReward::TYPE_COUPON => '優惠券',
            default => $type,
        };
    }

    /**
     * 輸出最終統計資訊
     */
    protected function outputFinalStats(array $stats): void
    {
        $this->command->info('Demo資料集建立完成！');
        $this->command->line(str_repeat('=', 60));
        $this->command->line(sprintf("新增活動：%d", $stats['campaigns_created']));
        $this->command->line(sprintf("新增獎勵：%d", $stats['rewards_created']));
        $this->command->line(sprintf("成功發放：%d", $stats['grants_success']));
        $this->command->line(sprintf("跳過發放：%d（已發放過）", $stats['grants_skipped']));
        $this->command->line(sprintf("發放失敗：%d", $stats['grants_failed']));
        $this->command->line(sprintf("歷史交易：%d", $stats['historical_transactions_created']));
        $this->command->line(str_repeat('=', 60));

        // 查詢最終資料庫中的統計數據
        $this->command->newLine();
        $this->command->info('目前資料庫中相關資料總量：');
        $this->command->line(str_repeat('-', 50));
        $this->command->line(sprintf("Tenant 總數：%d", \App\Models\Tenant::count()));
        $this->command->line(sprintf("User 總數：%d", \App\Models\User::withoutGlobalScopes()->count()));
        $this->command->line(sprintf("Customer 總數：%d", \App\Models\Customer::count()));
        $this->command->line(sprintf("Campaign 總數：%d", \App\Models\Campaign::count()));
        $this->command->line(sprintf("CampaignReward 總數：%d", \App\Models\CampaignReward::count()));
        $this->command->line(sprintf("RewardGrant 總數：%d", \App\Models\RewardGrant::count()));
        $this->command->line(sprintf("PointAccount 總數：%d", \App\Models\PointAccount::count()));
        $this->command->line(sprintf("PointTransaction 總數：%d", \App\Models\PointTransaction::count()));
    }
}
