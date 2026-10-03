<?php

namespace Tests\Feature\Services\Point;

use App\Models\Customer;
use App\Models\MembershipTier;
use App\Models\Tenant;
use App\Services\Point\PointService;
use App\Services\Point\Strategies\CampaignEarnStrategy;
use App\Services\Point\Strategies\PurchaseEarnStrategy;
use App\Services\Point\Strategies\ReferralEarnStrategy;
use App\Services\Point\Strategies\VipExclusiveEarnStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PointStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected PointService $pointService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'domain' => 'test.example.com',
        ]);
        $this->pointService = app(PointService::class);
    }

    /** @test */
    public function purchase_strategy_calculates_correct_points()
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
        ]);

        $strategy = new PurchaseEarnStrategy();
        $points = $strategy->calculate($customer, 1000); // 消費1000元

        $this->assertEquals(1000, $points);
    }

    /** @test */
    public function purchase_strategy_applies_membership_tier_multiplier()
    {
        $tier = MembershipTier::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Gold Member',
            'slug' => 'gold',
            'sort_order' => 2,
            'upgrade_threshold' => 10000,
            'threshold_type' => 'spend',
            'status' => true,
            'points_multiplier' => 1.5,
            'discount_rate' => 0.1,
            'free_shipping' => true,
        ]);

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Gold Customer',
            'email' => 'gold@example.com',
            'membership_tier_id' => $tier->id,
        ]);

        $customer->load('membershipTier');

        $strategy = new PurchaseEarnStrategy();
        $points = $strategy->calculate($customer, 1000);

        $this->assertEquals(1500, $points); // 1000 * 1.5
    }

    /** @test */
    public function campaign_strategy_adds_bonus_points()
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Campaign Customer',
            'email' => 'campaign@example.com',
        ]);

        $strategy = new CampaignEarnStrategy();
        $points = $strategy->calculate($customer, 1000, [
            'campaign_multiplier' => 2.0,
            'campaign_bonus_points' => 100
        ]);

        $this->assertEquals(2100, $points); // (1000 * 2) + 100
    }

    /** @test */
    public function referral_strategy_gives_fixed_bonus()
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Referral Customer',
            'email' => 'referral@example.com',
        ]);

        $strategy = new ReferralEarnStrategy();
        $points = $strategy->calculate($customer, 0);

        $this->assertEquals(500, $points); // 預設的推薦獎勵
    }

    /** @test */
    public function vip_strategy_adds_extra_multiplier()
    {
        $tier = MembershipTier::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'VIP Member',
            'slug' => 'vip',
            'sort_order' => 3,
            'upgrade_threshold' => 50000,
            'threshold_type' => 'spend',
            'status' => true,
            'points_multiplier' => 1.5,
            'discount_rate' => 0.2,
            'free_shipping' => true,
        ]);

        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'VIP Customer',
            'email' => 'vip@example.com',
            'membership_tier_id' => $tier->id
        ]);

        $customer->load('membershipTier');

        $strategy = new VipExclusiveEarnStrategy();
        $points = $strategy->calculate($customer, 1000);

        $this->assertEquals(2250, $points); // 1000 * 1.5 * 1.5
    }

    /** @test */
    public function point_service_integration_works_with_strategy()
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Integration Customer',
            'email' => 'integration@example.com',
        ]);

        $strategy = new PurchaseEarnStrategy();
        $transaction = $this->pointService->earnWithStrategy(
            $customer,
            $strategy,
            1000,
            [],
            '測試購物點數'
        );

        $this->assertEquals(1000, $transaction->amount);
        $this->assertEquals('earn', $transaction->type);

        // 驗證帳戶餘額正確更新
        $account = $customer->pointAccount;
        $this->assertEquals(1000, $account->balance);
        $this->assertEquals(1000, $account->total_earned);

        // 驗證PointLot已建立
        $this->assertDatabaseHas('point_lots', [
            'customer_id' => $customer->id,
            'original_points' => 1000,
            'remaining_points' => 1000
        ]);
    }

    /** @test */
    public function all_point_transaction_features_still_work_with_strategy()
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Full Feature Customer',
            'email' => 'fullfeature@example.com',
        ]);

        $strategy = new CampaignEarnStrategy();
        $transaction = $this->pointService->earnWithStrategy(
            $customer,
            $strategy,
            500,
            ['campaign_multiplier' => 2.0],
            '雙倍點數活動'
        );

        // 驗證基本交易資料正確
        $this->assertEquals(1000, $transaction->amount);
        $this->assertNotNull($transaction->description);
        $this->assertEquals('雙倍點數活動', $transaction->description);

        // 驗證可以正常兌換點數
        $redemptionTransaction = $this->pointService->redeem($customer, 500, '測試兌換');
        $this->assertEquals(500, $redemptionTransaction->amount);

        // 驗證餘額正確
        $account = $customer->fresh()->pointAccount;
        $this->assertEquals(500, $account->balance);
    }
}
