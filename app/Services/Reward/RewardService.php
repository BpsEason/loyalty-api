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
     * 取得客戶+獎勵的鎖定鍵，防止同一個 reward grant 被並發重複發放
     */
    protected function getRewardGrantLockKey(Customer $customer, CampaignReward $campaignReward): string
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
     * 取得交易+規則的鎖定鍵，防止同一交易的 campaign rule 重複發放
     */
    protected function getTransactionRuleRewardLockKey(Customer $customer, $transactionId, \App\Models\CampaignRule $campaignRule): string
    {
        return sprintf(
            'campaign_rule:tenant:%d:customer:%d:transaction:%d:rule:%d',
            $customer->tenant_id,
            $customer->id,
            $transactionId,
            $campaignRule->id
        );
    }

    /**
     * 根據消費交易評估並發放符合條件的 Campaign Rule 獎勵
     *
     * Rule-level idempotency / duplicate detection：
     * 使用 metadata.rule_id + metadata.transaction_id 作為重複檢測鍵
     *
     * 設計原因：
     * - 同一 transaction 可能觸發多個 campaign rules（一個消費可能符合多個活動條件）
     * - 因此 key 必須包含 rule identity，才能區分同一交易的不同規則獎勵
     * - transaction identity 用來避免同一交易重複觸發同一 rule，保證冪等性
     */
    public function processTransactionForCampaignRules(Customer $customer, float $spendAmount, array $purchasedProducts, $transactionId = null)
    {
        return $this->evaluateAndGrantTransactionRewards($customer, $spendAmount, $purchasedProducts, $transactionId);
    }

    protected function evaluateAndGrantTransactionRewards(Customer $customer, float $spendAmount, array $purchasedProducts, $transactionId = null)
    {
        if (!$transactionId) {
            $transactionId = \Illuminate\Support\Str::orderedUuid();
        }

        $activeCampaigns = \App\Models\Campaign::where('tenant_id', $customer->tenant_id)
            ->where('status', \App\Models\Campaign::STATUS_ACTIVE)
            ->where('starts_at', '<=', now())
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->get();

        $grantedRewards = [];

        foreach ($activeCampaigns as $campaign) {
            $rules = \App\Models\CampaignRule::getActiveRulesForCampaign($campaign);

            foreach ($rules as $rule) {
                if ($rule->isEligible($spendAmount, $purchasedProducts)) {
                    $lockKey = $this->getTransactionRuleRewardLockKey($customer, $transactionId, $rule);
                    $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 10);

                    try {
                        $lock->block(5, function () use ($customer, $rule, &$grantedRewards, $transactionId) {
                            $existingGrant = \App\Models\RewardGrant::where('tenant_id', $customer->tenant_id)
                                ->where('customer_id', $customer->id)
                                ->where('campaign_id', $rule->campaign_id)
                                ->where('metadata->rule_id', $rule->id)
                                ->where('metadata->transaction_id', $transactionId)
                                ->first();

                            if (!$existingGrant) {
                                if ($rule->points_reward > 0) {
                                    $customer->addTotalPointsEarned($rule->points_reward);

                                    $pointTransaction = $this->pointService->earn(
                                        $customer,
                                        $rule->points_reward,
                                        'campaign_rule',
                                        [
                                            'campaign_id' => $rule->campaign_id,
                                            'rule_id' => $rule->id,
                                            'transaction_id' => request()->input('reference'),
                                        ]
                                    );

                                    $rewardGrant = \App\Models\RewardGrant::create([
                                        'tenant_id' => $customer->tenant_id,
                                        'campaign_id' => $rule->campaign_id,
                                        'campaign_reward_id' => null,
                                        'customer_id' => $customer->id,
                                        'status' => \App\Models\RewardGrant::STATUS_GRANTED,
                                        'metadata' => [
                                            'rule_id' => $rule->id,
                                            'points_awarded' => $rule->points_reward,
                                            'transaction_id' => $transactionId,
                                            'transaction_reference' => request()->input('reference'),
                                        ],
                                    ]);

                                    $grantedRewards[] = [
                                        'rule' => $rule,
                                        'points_awarded' => $rule->points_reward,
                                        'point_transaction_id' => $pointTransaction->id,
                                        'reward_grant_id' => $rewardGrant->id,
                                    ];
                                }
                            }
                        });
                    } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                        report($e);
                        continue;
                    }
                }
            }
        }

        $customer->addTotalSpend($spendAmount);

        return $grantedRewards;
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
     * 
     * RewardGrant lifecycle：
     * PENDING → GRANTED (success)
     * PENDING → FAILED (any error path)
     * 
     * Preserve an auditable record of attempted reward delivery：
     * 先在事務外建立 PENDING 狀態的記錄，確保即使後續流程失敗，
     * 這次發放嘗試仍然會被持久化為 FAILED 狀態，留下完整審計軌跡。
     * 
     * Concurrency control：
     * Lock key includes tenant + customer + campaign + reward
     * → prevent concurrent duplicate reward grants for the same business scope.
     */
    public function grantRewardToCustomer(Customer $customer, CampaignReward $campaignReward): RewardGrant
    {
        $lockKey = $this->getRewardGrantLockKey($customer, $campaignReward);
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
