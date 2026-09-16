<?php

namespace App\Services\Reward;

use App\Models\Campaign;
use App\Models\CampaignReward;
use App\Models\Customer;
use App\Models\RewardGrant;
use App\Services\Point\PointService;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RewardService
{
    /**
     * 嘗試取得鎖的最長等待時間（秒）
     */
    protected int $lockWaitSeconds = 5;

    /**
     * 鎖定自動釋放 TTL（秒）
     */
    protected int $lockTTL = 10;

    public function __construct(
        protected PointService $pointService,
        protected TenantResolver $tenantResolver
    ) {}

    /**
     * 取得客戶+獎勵的鎖定鍵，防止並發重複發放
     */
    protected function getLockKey(Customer $customer, CampaignReward $campaignReward): string
    {
        return sprintf(
            'reward_grant:tenant:%d:customer:%d:campaign:%d:reward:%d',
            $customer->tenant_id,
            $customer->id,
            $campaignReward->campaign_id,
            $campaignReward->id
        );
    }

    /**
     * 建立新的活動
     */
    public function createCampaign(array $data): Campaign
    {
        return Campaign::create($data);
    }

    /**
     * 建立活動獎勵
     */
    public function createCampaignReward(Campaign $campaign, array $data): CampaignReward
    {
        return $campaign->rewards()->create($data);
    }

    /**
     * 對指定客戶發放獎勵
     */
    public function grantRewardToCustomer(Customer $customer, CampaignReward $campaignReward): RewardGrant
    {
        $lockKey = $this->getLockKey($customer, $campaignReward);
        $lock = Cache::lock($lockKey, $this->lockTTL);

        try {
            return $lock->block($this->lockWaitSeconds, function () use ($customer, $campaignReward) {
                // 先獲取活動實例
                $campaign = $campaignReward->campaign;
                // 先建立待處理的獎勵發放記錄（不在事務內，確保失敗也能持久化）
                /** @var RewardGrant $rewardGrant */
                $rewardGrant = RewardGrant::create([
                    'tenant_id' => $customer->tenant_id,
                    'campaign_id' => $campaign->id,
                    'campaign_reward_id' => $campaignReward->id,
                    'customer_id' => $customer->id,
                    'status' => RewardGrant::STATUS_PENDING,
                ]);

                try {
                    // 所有驗證和成功邏輯放在數據庫事務中，確保只有成功時才提交點數變更
                    return DB::transaction(function () use ($customer, $campaignReward, $campaign, $rewardGrant) {
                        // 先檢查活動是否有效
                        if ($campaign->status !== Campaign::STATUS_ACTIVE) {
                            throw new RuntimeException('活動未處於活躍狀態');
                        }

                        if (!$campaignReward->enabled) {
                            throw new RuntimeException('此獎勵已停用');
                        }

                        // 檢查活動時間
                        $now = now();
                        if ($campaign->starts_at && $now->lt($campaign->starts_at)) {
                            throw new RuntimeException('活動尚未開始');
                        }
                        if ($campaign->ends_at && $now->gt($campaign->ends_at)) {
                            throw new RuntimeException('活動已結束');
                        }

                        // 檢查租戶一致性
                        if ($customer->tenant_id !== $campaign->tenant_id) {
                            throw new RuntimeException('客戶與活動租戶不一致');
                        }

                        // 如果是點數獎勵，調用PointService發放點數
                        if ($campaignReward->reward_type === CampaignReward::TYPE_POINTS) {
                            if ($campaignReward->points <= 0) {
                                throw new RuntimeException('點數獎勵必須為正數');
                            }

                            $pointTransaction = $this->pointService->earn(
                                $customer,
                                $campaignReward->points,
                                sprintf('活動獎勵：%s', $campaign->name),
                                $rewardGrant // 多態關聯，讓PointTransaction可以追溯到RewardGrant
                            );

                            // 更新發放記錄為成功
                            $rewardGrant->update([
                                'status' => RewardGrant::STATUS_GRANTED,
                                'granted_at' => now(),
                                'point_transaction_id' => $pointTransaction->id,
                            ]);
                        } else {
                            // 非點數獎勵直接標記為成功
                            $rewardGrant->update([
                                'status' => RewardGrant::STATUS_GRANTED,
                                'granted_at' => now(),
                            ]);
                        }

                        return $rewardGrant->fresh();
                    });
                } catch (RuntimeException $e) {
                    // 任何驗證或發放失敗，都標記發放記錄為失敗（此時RewardGrant已持久化，不會被事務回滾）
                    $rewardGrant->update([
                        'status' => RewardGrant::STATUS_FAILED,
                        'failure_reason' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            });
        } catch (LockTimeoutException $e) {
            throw new RuntimeException('系統繁忙，請稍後再試', 0, $e);
        } catch (UniqueConstraintViolationException $e) {
            throw new RuntimeException('此客戶已獲得過該獎勵，無法重複發放', 0, $e);
        }
    }
}
