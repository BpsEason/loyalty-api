<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignReward;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\RewardGrant;
use App\Models\Tenant;
use App\Services\Reward\RewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use RuntimeException;

class RewardEngineTest extends TestCase
{
    use RefreshDatabase;

    protected RewardService $rewardService;
    protected Tenant $tenant;
    protected Customer $customer;
    protected Campaign $campaign;
    protected CampaignReward $campaignReward;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rewardService = app(RewardService::class);

        // 建立測試租戶
        $this->tenant = Tenant::create([
            'name' => '測試租戶',
            'domain' => 'test.local',
            'is_active' => true,
        ]);

        // 建立測試客戶
        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => '測試客戶',
            'email' => 'customer@test.com',
            'phone' => '0912345678',
        ]);

        // 建立測試活動
        $this->campaign = $this->rewardService->createCampaign([
            'tenant_id' => $this->tenant->id,
            'name' => '測試活動',
            'description' => '測試活動描述',
            'status' => Campaign::STATUS_ACTIVE,
            'starts_at' => now()->subDays(7),
            'ends_at' => now()->addDays(30),
        ]);

        // 建立測試獎勵
        $this->campaignReward = $this->rewardService->createCampaignReward($this->campaign, [
            'reward_type' => CampaignReward::TYPE_POINTS,
            'points' => 100,
            'enabled' => true,
        ]);
    }

    /** @test */
    public function it_can_create_a_campaign()
    {
        $this->assertInstanceOf(Campaign::class, $this->campaign);
        $this->assertEquals('測試活動', $this->campaign->name);
        $this->assertEquals(Campaign::STATUS_ACTIVE, $this->campaign->status);
        $this->assertEquals($this->tenant->id, $this->campaign->tenant_id);
    }

    /** @test */
    public function it_can_create_a_campaign_reward()
    {
        $this->assertInstanceOf(CampaignReward::class, $this->campaignReward);
        $this->assertEquals(CampaignReward::TYPE_POINTS, $this->campaignReward->reward_type);
        $this->assertEquals(100, $this->campaignReward->points);
        $this->assertEquals($this->campaign->id, $this->campaignReward->campaign_id);
        $this->assertTrue($this->campaignReward->enabled);
    }

    /** @test */
    public function it_can_grant_reward_to_customer_successfully()
    {
        $rewardGrant = $this->rewardService->grantRewardToCustomer($this->customer, $this->campaignReward);

        $this->assertInstanceOf(RewardGrant::class, $rewardGrant);
        $this->assertEquals(RewardGrant::STATUS_GRANTED, $rewardGrant->status);
        $this->assertNotNull($rewardGrant->granted_at);
        $this->assertNotNull($rewardGrant->point_transaction_id);

        // 驗證PointTransaction已建立
        $pointTransaction = PointTransaction::find($rewardGrant->point_transaction_id);
        $this->assertNotNull($pointTransaction);
        $this->assertEquals(PointTransaction::TYPE_EARN, $pointTransaction->type);
        $this->assertEquals(100, $pointTransaction->amount);
        $this->assertEquals('活動獎勵：測試活動', $pointTransaction->description);

        // 驗證可以追溯到RewardGrant
        $this->assertNotNull($pointTransaction->reference);
        $this->assertInstanceOf(RewardGrant::class, $pointTransaction->reference);
        $this->assertEquals($rewardGrant->id, $pointTransaction->reference->id);
    }

    /** @test */
    public function it_cannot_grant_same_reward_to_customer_twice()
    {
        // 第一次發放成功
        $this->rewardService->grantRewardToCustomer($this->customer, $this->campaignReward);

        // 第二次發放應該失敗
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('此客戶已獲得過該獎勵，無法重複發放');

        $this->rewardService->grantRewardToCustomer($this->customer, $this->campaignReward);
    }

    /** @test */
    public function different_tenants_cannot_interfere_with_each_other()
    {
        // 建立第二個租戶
        $tenant2 = Tenant::create([
            'name' => '第二個租戶',
            'domain' => 'test2.local',
            'is_active' => true,
        ]);

        // 建立第二個租戶的客戶
        $customer2 = Customer::create([
            'tenant_id' => $tenant2->id,
            'name' => '客戶2',
            'email' => 'customer2@test.com',
            'phone' => '0987654321',
        ]);

        // 建立第二個租戶的活動和獎勵
        $campaign2 = $this->rewardService->createCampaign([
            'tenant_id' => $tenant2->id,
            'name' => '租戶2的活動',
            'status' => Campaign::STATUS_ACTIVE,
        ]);

        $reward2 = $this->rewardService->createCampaignReward($campaign2, [
            'reward_type' => CampaignReward::TYPE_POINTS,
            'points' => 100,
            'enabled' => true,
        ]);

        // 兩個租戶的客戶都可以獲得相同的獎勵，不會衝突
        $grant1 = $this->rewardService->grantRewardToCustomer($this->customer, $this->campaignReward);
        $grant2 = $this->rewardService->grantRewardToCustomer($customer2, $reward2);

        $this->assertEquals(RewardGrant::STATUS_GRANTED, $grant1->status);
        $this->assertEquals(RewardGrant::STATUS_GRANTED, $grant2->status);
        $this->assertNotEquals($grant1->id, $grant2->id);
    }

    /** @test */
    public function it_marks_grant_as_failed_when_point_service_fails()
    {
        // 建立一個點數為0的獎勵不會觸發PointService，測試負數點數會觸發錯誤
        $invalidReward = $this->rewardService->createCampaignReward($this->campaign, [
            'reward_type' => CampaignReward::TYPE_POINTS,
            'points' => -100, // 無效的負數點數，會讓PointService拋出錯誤
            'enabled' => true,
        ]);

        try {
            $this->rewardService->grantRewardToCustomer($this->customer, $invalidReward);
            $this->fail('應該拋出異常');
        } catch (RuntimeException $e) {
            // 驗證RewardGrant被標記為失敗
            $failedGrant = RewardGrant::where('campaign_reward_id', $invalidReward->id)
                ->where('customer_id', $this->customer->id)
                ->first();

            $this->assertNotNull($failedGrant);
            $this->assertEquals(RewardGrant::STATUS_FAILED, $failedGrant->status);
            $this->assertNotNull($failedGrant->failure_reason);
            $this->assertNull($failedGrant->point_transaction_id);
        }
    }

    /** @test */
    public function it_cannot_grant_reward_from_inactive_campaign()
    {
        // 將活動設為草稿狀態
        $this->campaign->update(['status' => Campaign::STATUS_DRAFT]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('活動未處於活躍狀態');

        $this->rewardService->grantRewardToCustomer($this->customer, $this->campaignReward);
    }

    /** @test */
    public function it_cannot_grant_reward_from_disabled_reward()
    {
        // 將獎勵停用
        $this->campaignReward->update(['enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('此獎勵已停用');

        $this->rewardService->grantRewardToCustomer($this->customer, $this->campaignReward);
    }
}
