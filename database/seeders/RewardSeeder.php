<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Customer;
use App\Models\Campaign;
use App\Models\CampaignReward;
use App\Models\RewardGrant;
use App\Models\PointAccount;
use App\Models\PointTransaction;
use App\Services\Reward\RewardService;
use App\Services\Point\PointService;
use Illuminate\Database\Seeder;
use RuntimeException;

class RewardSeeder extends Seeder
{
    // 定義三個具有不同商業角色的Demo租戶
    protected array $demoTenants = [
        'retail' => [
            'name' => '零售通',
            'domain' => 'retail-demo.example',
            'is_active' => true,
        ],
        'coffee' => [
            'name' => '咖啡日常',
            'domain' => 'coffee-demo.example',
            'is_active' => true,
        ],
        'fitness' => [
            'name' => '動力健身',
            'domain' => 'fitness-demo.example',
            'is_active' => true,
        ],
    ];

    // 每個租戶專屬的客戶資料（確保email全域唯一，使用各自的域名）
    protected array $tenantCustomerData = [
        'retail' => [
            ['name' => '張小明', 'email' => 'ming.zhang', 'phone' => '0912111222'],
            ['name' => '李佳穎', 'email' => 'jiaying.li', 'phone' => '0923333444'],
            ['name' => '王美玲', 'email' => 'meiling.wang', 'phone' => '0934555666'],
            ['name' => '陳冠宇', 'email' => 'guanyu.chen', 'phone' => '0945777888'],
            ['name' => '林怡君', 'email' => 'yijun.lin', 'phone' => '0956999000'],
            ['name' => '黃子軒', 'email' => 'zixuan.huang', 'phone' => '0967111333'],
            ['name' => '吳佩珊', 'email' => 'peishan.wu', 'phone' => '0978444555'],
        ],
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
        'retail' => [
            [
                'name' => '新會員首購回饋',
                'description' => '首次消費滿500元，立即獲得100點歡迎獎勵',
                'status' => Campaign::STATUS_ACTIVE,
                'starts_at_days' => -30,
                'ends_at_days' => 365,
                'rewards' => [
                    ['type' => CampaignReward::TYPE_POINTS, 'points' => 100, 'enabled' => true],
                    ['type' => CampaignReward::TYPE_BADGE, 'points' => 0, 'enabled' => true],
                ]
            ],
            [
                'name' => '週末消費雙倍點數',
                'description' => '每週五六日消費，點數雙倍送',
                'status' => Campaign::STATUS_ACTIVE,
                'starts_at_days' => -7,
                'ends_at_days' => 60,
                'rewards' => [
                    ['type' => CampaignReward::TYPE_POINTS, 'points' => 200, 'enabled' => true],
                    ['type' => CampaignReward::TYPE_POINTS, 'points' => 400, 'enabled' => true],
                    ['type' => CampaignReward::TYPE_COUPON, 'points' => 0, 'enabled' => true],
                ]
            ],
            [
                'name' => '2026冬季採購節',
                'description' => '年底採購旺季，累積消費滿額送超高點數',
                'status' => Campaign::STATUS_DRAFT,
                'starts_at_days' => 30,
                'ends_at_days' => 90,
                'rewards' => [
                    ['type' => CampaignReward::TYPE_POINTS, 'points' => 800, 'enabled' => false],
                ]
            ],
            [
                'name' => 'VIP會員感謝回饋',
                'description' => '年度VIP會員專屬，感謝過去一年的支持',
                'status' => Campaign::STATUS_COMPLETED,
                'starts_at_days' => -60,
                'ends_at_days' => -1,
                'rewards' => [
                    ['type' => CampaignReward::TYPE_POINTS, 'points' => 500, 'enabled' => true],
                ]
            ],
        ],
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
        'retail' => [
            // 高活躍會員：獲得多個活動的多個獎勵
            0 => [0 => [0], 1 => [0, 1], 3 => [0]],
            // 一般會員：獲得少數獎勵
            1 => [0 => [0], 1 => [0]],
            // 活躍會員
            2 => [1 => [1], 3 => [0]],
            // 新會員：只有新會員獎勵
            3 => [0 => [0]],
            // 尚未參與活動：沒有任何獎勵
            4 => [],
            // 活躍會員
            5 => [0 => [0], 1 => [0]],
            // 歷史活動會員
            6 => [3 => [0]],
        ],
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
        'retail' => [
            0 => [ // 張小明（高活躍）
                ['days_ago' => 1, 'type' => PointTransaction::TYPE_EARN, 'amount' => 100, 'description' => '週末消費雙倍點數'],
                ['days_ago' => 5, 'type' => PointTransaction::TYPE_REDEEM, 'amount' => 100, 'description' => '兌換購物券'],
                ['days_ago' => 15, 'type' => PointTransaction::TYPE_EARN, 'amount' => 500, 'description' => 'VIP會員感謝回饋'],
                ['days_ago' => 30, 'type' => PointTransaction::TYPE_EARN, 'amount' => 100, 'description' => '新會員首購回饋'],
            ],
            2 => [ // 王美玲（活躍）
                ['days_ago' => 3, 'type' => PointTransaction::TYPE_EARN, 'amount' => 200, 'description' => '週末消費累積'],
                ['days_ago' => 20, 'type' => PointTransaction::TYPE_EARN, 'amount' => 500, 'description' => 'VIP會員感謝回饋'],
            ],
        ],
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
            $this->command->info("處理租戶：{$tenantData['name']}");
            $this->command->line(str_repeat('-', 60));

            // 1. 建立或取得租戶
            $tenant = $this->createTenant($tenantData, $stats);

            // 2. 建立租戶的客戶
            $tenantCustomers = $this->createTenantCustomers($tenantKey, $tenant, $stats);
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
     * 建立或取得租戶
     */
    protected function createTenant(array $tenantData, array &$stats): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::firstOrCreate(
            ['domain' => $tenantData['domain']],
            [
                'name' => $tenantData['name'],
                'domain' => $tenantData['domain'],
                'is_active' => $tenantData['is_active'],
            ]
        );

        if ($tenant->wasRecentlyCreated) {
            $stats['tenants_created']++;
            $this->command->line("  ✓ 建立新租戶：{$tenant->name} (ID: {$tenant->id})");
        } else {
            $this->command->line("  租戶已存在，重用：{$tenant->name} (ID: {$tenant->id})");
        }

        return $tenant;
    }

    /**
     * 為租戶建立客戶，使用自然的email格式（不同租戶使用不同域名確保全域唯一）
     */
    protected function createTenantCustomers(string $tenantKey, Tenant $tenant, array &$stats): array
    {
        $customers = [];
        $emailDomains = [
            'retail' => 'retail-demo.example',
            'coffee' => 'coffee-demo.example',
            'fitness' => 'fitness-demo.example',
        ];
        $emailDomain = $emailDomains[$tenantKey];
        $customerData = $this->tenantCustomerData[$tenantKey] ?? [];

        foreach ($customerData as $customerInfo) {
            $fullEmail = $customerInfo['email'] . '@' . $emailDomain;

            /** @var Customer $customer */
            $customer = Customer::firstOrCreate(
                ['tenant_id' => $tenant->id, 'email' => $fullEmail],
                [
                    'tenant_id' => $tenant->id,
                    'name' => $customerInfo['name'],
                    'email' => $fullEmail,
                    'phone' => $customerInfo['phone'],
                ]
            );

            if ($customer->wasRecentlyCreated) {
                $stats['customers_created']++;
                $this->command->line("    ✓ 建立客戶：{$customer->name} ({$fullEmail})");
            }

            $customers[] = $customer;
        }

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
    protected function grantRewardsToCustomers(string $tenantKey, Tenant $tenant, array $customers, array $campaigns, array &$stats): void
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
    protected function createHistoricalTransactions(string $tenantKey, Tenant $tenant, array $customers, array &$stats): void
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
        $this->command->line(sprintf("新增租戶：%d", $stats['tenants_created']));
        $this->command->line(sprintf("新增客戶：%d", $stats['customers_created']));
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
        $this->command->line(sprintf("Customer 總數：%d", \App\Models\Customer::count()));
        $this->command->line(sprintf("Campaign 總數：%d", \App\Models\Campaign::count()));
        $this->command->line(sprintf("CampaignReward 總數：%d", \App\Models\CampaignReward::count()));
        $this->command->line(sprintf("RewardGrant 總數：%d", \App\Models\RewardGrant::count()));
        $this->command->line(sprintf("PointAccount 總數：%d", \App\Models\PointAccount::count()));
        $this->command->line(sprintf("PointTransaction 總數：%d", \App\Models\PointTransaction::count()));
    }
}
