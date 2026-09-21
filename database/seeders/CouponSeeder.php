<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Customer;
use App\Models\CouponTemplate;
use App\Models\UserCoupon;
use App\Models\CouponRedemption;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CouponSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 獲取所有租戶
        $tenants = Tenant::withoutGlobalScopes()->get();

        foreach ($tenants as $tenant) {
            $this->createCouponTemplatesForTenant($tenant);
            $this->createUserCouponsForTenant($tenant);
            $this->createCouponRedemptionsForTenant($tenant);
        }
    }

    /**
     * 為每個租戶建立優惠券模板
     */
    protected function createCouponTemplatesForTenant(Tenant $tenant): void
    {
        $templates = [
            // 固定金額折抵 - 折價券
            [
                'name' => '新客首購折100元',
                'code' => 'NEW100',
                'type' => CouponTemplate::TYPE_FIXED_AMOUNT,
                'discount_amount' => 100,
                'minimum_order_amount' => 500,
                'starts_at' => now()->subDays(30),
                'expires_at' => now()->addDays(180),
                'total_quantity' => 1000,
                'issued_quantity' => 35,
                'per_customer_limit' => 1,
                'status' => CouponTemplate::STATUS_ACTIVE,
            ],
            // 比例折扣 - 折扣券
            [
                'name' => '會員85折優惠',
                'code' => 'MEMBER15',
                'type' => CouponTemplate::TYPE_PERCENTAGE,
                'discount_percentage' => 15,
                'max_discount_amount' => 300,
                'minimum_order_amount' => 1000,
                'starts_at' => now()->subDays(10),
                'expires_at' => now()->addDays(90),
                'total_quantity' => 500,
                'issued_quantity' => 28,
                'per_customer_limit' => 2,
                'status' => CouponTemplate::STATUS_ACTIVE,
            ],
            // 免運費券
            [
                'name' => '全館免運券',
                'code' => 'FREESHIP',
                'type' => CouponTemplate::TYPE_FREE_SHIPPING,
                'minimum_order_amount' => 300,
                'starts_at' => now()->subDays(5),
                'expires_at' => now()->addDays(60),
                'total_quantity' => 2000,
                'issued_quantity' => 156,
                'per_customer_limit' => 3,
                'status' => CouponTemplate::STATUS_ACTIVE,
            ],
            // 已過期的折扣券
            [
                'name' => '夏季限定9折',
                'code' => 'SUMMER10',
                'type' => CouponTemplate::TYPE_PERCENTAGE,
                'discount_percentage' => 10,
                'max_discount_amount' => 200,
                'minimum_order_amount' => 500,
                'starts_at' => now()->subDays(120),
                'expires_at' => now()->subDays(10),
                'total_quantity' => 300,
                'issued_quantity' => 89,
                'per_customer_limit' => 2,
                'status' => CouponTemplate::STATUS_INACTIVE,
            ],
            // 草稿狀態的新品贈品券
            [
                'name' => '新品上市兌換券',
                'code' => 'NEWGIFT',
                'type' => CouponTemplate::TYPE_GIFT,
                'minimum_order_amount' => 0,
                'starts_at' => now()->addDays(7),
                'expires_at' => now()->addDays(37),
                'total_quantity' => 100,
                'issued_quantity' => 0,
                'per_customer_limit' => 1,
                'status' => CouponTemplate::STATUS_DRAFT,
            ],
        ];

        foreach ($templates as $template) {
            CouponTemplate::firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $template['code']],
                $template
            );
        }
    }

    /**
     * 為租戶的會員建立會員優惠券
     */
    protected function createUserCouponsForTenant(Tenant $tenant): void
    {
        $customers = Customer::where('tenant_id', $tenant->id)->get();
        if ($customers->isEmpty()) {
            return;
        }

        $activeTemplates = CouponTemplate::where('tenant_id', $tenant->id)
            ->where('status', CouponTemplate::STATUS_ACTIVE)
            ->get();

        foreach ($customers as $customer) {
            foreach ($activeTemplates as $template) {
                // 隨機發放一些優惠券給會員
                if (rand(1, 100) <= 40) { // 40% 機率獲得該優惠券
                    $issuedAt = now()->subDays(rand(1, 60));
                    $expiredAt = $template->expires_at;

                    // 檢查是否已使用
                    $isUsed = rand(1, 100) <= 30; // 30% 已使用
                    $isExpired = $expiredAt->isPast();

                    // 使用穩定的 reference：TENANTCODE_TEMPLATECODE_CUSTOMERID
                    // 確保每次執行都能識別同一筆記錄，避免隨機值導致重複建立
                    $reference = sprintf('%s_%s_C%03d', $tenant->domain, $template->code, $customer->id);

                    UserCoupon::firstOrCreate(
                        [
                            'tenant_id' => $tenant->id,
                            'customer_id' => $customer->id,
                            'coupon_template_id' => $template->id,
                        ],
                        [
                            'reference' => $reference,
                            'status' => $isExpired ? UserCoupon::STATUS_EXPIRED : ($isUsed ? UserCoupon::STATUS_USED : UserCoupon::STATUS_AVAILABLE),
                            'issued_at' => $issuedAt,
                            'used_at' => $isUsed ? $issuedAt->addDays(rand(1, 30)) : null,
                            'expired_at' => $expiredAt,
                        ]
                    );
                }
            }
        }
    }

    /**
     * 建立已使用的優惠券核銷記錄
     */
    protected function createCouponRedemptionsForTenant(Tenant $tenant): void
    {
        $usedCoupons = UserCoupon::where('tenant_id', $tenant->id)
            ->where('status', UserCoupon::STATUS_USED)
            ->whereDoesntHave('redemption')
            ->get();

        $creators = \App\Models\User::where('tenant_id', $tenant->id)->get();

        foreach ($usedCoupons as $userCoupon) {
            $creator = $creators->random();

            // 使用穩定的核銷記錄 reference：RED_UserCouponID
            // 確保同一個 user_coupon 只會有一個核銷記錄
            $redemptionReference = 'RED_' . str_pad($userCoupon->id, 6, '0', STR_PAD_LEFT);
            $orderReference = 'ORD_' . $tenant->domain . '_' . str_pad($userCoupon->customer_id, 4, '0', STR_PAD_LEFT);

            CouponRedemption::firstOrCreate(
                ['user_coupon_id' => $userCoupon->id],
                [
                    'tenant_id' => $tenant->id,
                    'customer_id' => $userCoupon->customer_id,
                    'reference' => $redemptionReference,
                    'order_reference' => $orderReference,
                    'discount_amount' => $userCoupon->calculateDiscount(1500), // 假設訂單金額 1500
                    'redeemed_at' => $userCoupon->used_at,
                    'created_by' => $creator->id,
                ]
            );
        }
    }
}
