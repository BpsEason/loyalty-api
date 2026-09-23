<?php

namespace App\Services\Coupon;

use App\Models\CouponTemplate;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\UserCoupon;
use App\Services\Point\PointService;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CouponService
{
    /**
     * 混合支付：同時使用優惠券和點數
     * 
     * 實現原子操作：優惠券核銷與點數扣除在同一事務中完成，任一失敗都會回滾
     */
    public function mixedPayment(
        Customer $customer,
        ?UserCoupon $userCoupon,
        int $orderAmount,
        int $pointsAmount,
        string $reference,
        ?string $orderReference = null,
        ?int $createdBy = null
    ): array {
        $currentTenantId = $this->tenantResolver->getCurrentTenantId();
        if ($currentTenantId !== null) {
            $this->assertTenantConsistency(
                $customer->tenant_id,
                $currentTenantId,
                '無法操作其他租戶的客戶'
            );
            if ($userCoupon) {
                $this->assertTenantConsistency(
                    $userCoupon->tenant_id,
                    $currentTenantId,
                    '無法使用其他租戶的優惠券'
                );
            }
        }

        // 如果沒有使用任何優惠，直接返回錯誤
        if (!$userCoupon && $pointsAmount <= 0) {
            throw new RuntimeException('至少需要使用一項優惠（優惠券或點數）');
        }

        // 建立客戶的鎖，使用與PointService一致的customer-level鎖，確保所有點數變更操作串行化
        $customerLockKey = sprintf('point_customer:%d:tenant:%d', $customer->id, $customer->tenant_id);

        try {
            return Cache::lock($customerLockKey, $this->lockTTL)->block($this->lockWaitSeconds, function () use (
                $customer,
                $userCoupon,
                $orderAmount,
                $pointsAmount,
                $reference,
                $orderReference,
                $createdBy
            ) {
                return DB::transaction(function () use (
                    $customer,
                    $userCoupon,
                    $orderAmount,
                    $pointsAmount,
                    $reference,
                    $orderReference,
                    $createdBy
                ) {
                    $discountAmount = 0;
                    $redemption = null;
                    $pointTransaction = null;
                    $pointAccount = null;

                    // 對齊PointService的lock order：先取得PointAccount鎖，再處理其他資源
                    // 確保與redeem()、adjust()等流程的鎖獲取順序一致，避免死鎖
                    if ($pointsAmount > 0) {
                        // 先獲取點數帳戶並鎖定（與PointService.executeInLock()的順序一致）
                        $pointAccount = $customer->pointAccount()->lockForUpdate()->firstOrFail();

                        // 驗證點數餘額
                        if ($pointAccount->balance < $pointsAmount) {
                            throw new RuntimeException('點數餘額不足');
                        }
                    }

                    // 1. 處理優惠券核銷（在PointAccount鎖取得後才鎖定優惠券，保持鎖順序一致）
                    if ($userCoupon) {
                        // 鎖定用戶優惠券
                        /** @var UserCoupon $userCoupon */
                        $userCoupon = UserCoupon::where('id', $userCoupon->id)->lockForUpdate()->firstOrFail();

                        // 檢查優惠券是否屬於該客戶
                        if ($userCoupon->customer_id !== $customer->id) {
                            throw new RuntimeException('優惠券不屬於此客戶');
                        }

                        // 檢查是否可以核銷
                        if (!$userCoupon->isRedeemable()) {
                            throw new RuntimeException('優惠券無法使用，可能已過期或已使用');
                        }

                        // 計算折扣金額
                        $discountAmount = $userCoupon->calculateDiscount($orderAmount);

                        // 更新優惠券狀態為已使用
                        $userCoupon->update([
                            'status' => UserCoupon::STATUS_USED,
                            'used_at' => now(),
                            'reference' => $reference,
                        ]);

                        // 建立核銷記錄
                        $redemption = CouponRedemption::create([
                            'tenant_id' => $userCoupon->tenant_id,
                            'user_coupon_id' => $userCoupon->id,
                            'customer_id' => $userCoupon->customer_id,
                            'reference' => $reference,
                            'order_reference' => $orderReference,
                            'discount_amount' => $discountAmount,
                            'redeemed_at' => now(),
                            'created_by' => $createdBy,
                        ]);
                    }

                    // 2. 計算折扣後的應付金額
                    $afterCouponAmount = $orderAmount - $discountAmount;

                    // 3. 處理點數扣除（PointAccount已在事務一開始就鎖定）
                    if ($pointsAmount > 0) {
                        // 驗證點數不能超過折扣後的金額
                        if ($pointsAmount > $afterCouponAmount) {
                            throw new RuntimeException('點數折抵不能超過優惠券折扣後的應付金額');
                        }

                        // 使用PointService共用的扣點邏輯，確保唯一的business rule
                        // 此時已滿足deductPointsFromAccount的呼叫契約：
                        // - Customer Redis lock 已取得
                        // - DB transaction 已開啟
                        // - PointAccount row lock 已持有
                        // - 已驗證balance足夠
                        $pointTransaction = $this->pointService->deductPointsFromAccount(
                            $pointAccount,
                            $pointsAmount,
                            '混合支付點數折抵',
                            $reference,
                            $createdBy
                        );
                    }

                    // 計算最終應付金額
                    $finalAmount = max(0, $afterCouponAmount - $pointsAmount);

                    return [
                        'success' => true,
                        'coupon_redemption' => $redemption,
                        'point_transaction' => $pointTransaction,
                        'original_amount' => $orderAmount,
                        'discount_amount' => $discountAmount,
                        'points_used' => $pointsAmount,
                        'final_amount' => $finalAmount,
                    ];
                }, 3);
            });
        } catch (LockTimeoutException $e) {
            throw new RuntimeException('系統繁忙，請稍後再試', 0, $e);
        } catch (\Exception $e) {
            report($e);
            throw $e;
        }
    }

    /**
     * 嘗試取得鎖的最長等待時間（秒）
     */
    protected int $lockWaitSeconds = 10;

    /**
     * 鎖定自動釋放 TTL（秒）
     */
    protected int $lockTTL = 20;

    public function __construct(
        protected TenantResolver $tenantResolver,
        protected PointService $pointService
    ) {}

    /**
     * 驗證租戶一致性
     */
    protected function assertTenantConsistency(int $entityTenantId, int $expectedTenantId, string $errorMessage): void
    {
        if ($entityTenantId !== $expectedTenantId) {
            throw new RuntimeException($errorMessage);
        }
    }

    /**
     * 取得優惠券模板的鎖定鍵
     */
    protected function getTemplateLockKey(CouponTemplate $template): string
    {
        return sprintf('coupon_template:%d:tenant:%d', $template->id, $template->tenant_id);
    }

    /**
     * 取得客戶+模板的鎖定鍵（用於每人限領的並發控制）
     */
    protected function getCustomerTemplateLockKey(Customer $customer, CouponTemplate $template): string
    {
        return sprintf('coupon_customer:%d:template:%d:tenant:%d', $customer->id, $template->id, $template->tenant_id);
    }

    /**
     * 客戶領取優惠券
     */
    public function claim(Customer $customer, CouponTemplate $template): UserCoupon
    {
        // 先驗證租戶一致性
        $currentTenantId = $this->tenantResolver->getCurrentTenantId();
        if ($currentTenantId !== null) {
            $this->assertTenantConsistency(
                $customer->tenant_id,
                $currentTenantId,
                '無法操作其他租戶的客戶'
            );
            $this->assertTenantConsistency(
                $template->tenant_id,
                $currentTenantId,
                '無法使用其他租戶的優惠券'
            );
        }

        // 先檢查模板是否有效
        if (!$template->isValid()) {
            throw new RuntimeException('優惠券模板無效或已過期');
        }

        // 使用兩層鎖：先鎖定模板（確保總量不超發），再鎖定客戶+模板（確保每人限領）
        $templateLockKey = $this->getTemplateLockKey($template);

        try {
            return Cache::lock($templateLockKey, $this->lockTTL)->block($this->lockWaitSeconds, function () use ($customer, $template) {
                $customerTemplateLockKey = $this->getCustomerTemplateLockKey($customer, $template);

                return Cache::lock($customerTemplateLockKey, $this->lockTTL)->block($this->lockWaitSeconds, function () use ($customer, $template) {
                    return DB::transaction(function () use ($customer, $template) {
                        // 重新鎖定模板並檢查條件
                        /** @var CouponTemplate $template */
                        $template = CouponTemplate::where('id', $template->id)->lockForUpdate()->firstOrFail();

                        // 再次驗證所有條件（在資料庫鎖下重新檢查）
                        if (!$template->isValid()) {
                            throw new RuntimeException('優惠券模板無效或已發完');
                        }

                        // 檢查客戶是否已達領取上限
                        $customerClaimedCount = UserCoupon::where('tenant_id', $template->tenant_id)
                            ->where('customer_id', $customer->id)
                            ->where('coupon_template_id', $template->id)
                            ->lockForUpdate()
                            ->count();

                        if ($customerClaimedCount >= $template->per_customer_limit) {
                            throw new RuntimeException('您已達到此優惠券的領取上限');
                        }

                        // 增加已發行數量
                        $template->increment('issued_quantity');

                        // 建立用戶優惠券
                        $userCoupon = UserCoupon::create([
                            'tenant_id' => $template->tenant_id,
                            'customer_id' => $customer->id,
                            'coupon_template_id' => $template->id,
                            'status' => UserCoupon::STATUS_AVAILABLE,
                            'issued_at' => now(),
                            'expired_at' => $template->expires_at,
                        ]);

                        return $userCoupon;
                    }, 3);
                });
            });
        } catch (LockTimeoutException $e) {
            throw new RuntimeException('系統繁忙，請稍後再試', 0, $e);
        } catch (UniqueConstraintViolationException $e) {
            // 唯一約束違反，表示客戶已領取過
            throw new RuntimeException('您已領取過此優惠券', 0, $e);
        } catch (\Exception $e) {
            report($e);
            throw $e;
        }
    }

    /**
     * 核銷優惠券
     */
    public function redeem(UserCoupon $userCoupon, string $reference, ?string $orderReference = null, ?int $orderAmount = null, ?int $createdBy = null): CouponRedemption
    {
        $currentTenantId = $this->tenantResolver->getCurrentTenantId();
        if ($currentTenantId !== null) {
            $this->assertTenantConsistency(
                $userCoupon->tenant_id,
                $currentTenantId,
                '無法操作其他租戶的優惠券'
            );
        }

        // 建立用戶優惠券的鎖
        $userCouponLockKey = sprintf('user_coupon:%d:tenant:%d', $userCoupon->id, $userCoupon->tenant_id);

        try {
            return Cache::lock($userCouponLockKey, $this->lockTTL)->block($this->lockWaitSeconds, function () use ($userCoupon, $reference, $orderReference, $orderAmount, $createdBy) {
                return DB::transaction(function () use ($userCoupon, $reference, $orderReference, $orderAmount, $createdBy) {
                    // 鎖定用戶優惠券
                    /** @var UserCoupon $userCoupon */
                    $userCoupon = UserCoupon::where('id', $userCoupon->id)->lockForUpdate()->firstOrFail();

                    // 檢查是否可以核銷
                    if (!$userCoupon->isRedeemable()) {
                        throw new RuntimeException('優惠券無法使用，可能已過期或已使用');
                    }

                    // 計算折扣金額
                    $discountAmount = $orderAmount ? $userCoupon->calculateDiscount($orderAmount) : 0;

                    // 更新優惠券狀態為已使用
                    $userCoupon->update([
                        'status' => UserCoupon::STATUS_USED,
                        'used_at' => now(),
                        'reference' => $reference,
                    ]);

                    // 建立核銷記錄
                    $redemption = CouponRedemption::create([
                        'tenant_id' => $userCoupon->tenant_id,
                        'user_coupon_id' => $userCoupon->id,
                        'customer_id' => $userCoupon->customer_id,
                        'reference' => $reference,
                        'order_reference' => $orderReference,
                        'discount_amount' => $discountAmount,
                        'redeemed_at' => now(),
                        'created_by' => $createdBy,
                    ]);

                    return $redemption;
                }, 3);
            });
        } catch (LockTimeoutException $e) {
            throw new RuntimeException('系統繁忙，請稍後再試', 0, $e);
        } catch (\Exception $e) {
            report($e);
            throw $e;
        }
    }

    /**
     * 透過代碼查找有效的優惠券模板
     */
    public function findValidTemplateByCode(string $code): ?CouponTemplate
    {
        $tenantId = $this->tenantResolver->getCurrentTenantId();

        if (!$tenantId) {
            return null;
        }

        $template = CouponTemplate::where('tenant_id', $tenantId)
            ->where('code', $code)
            ->first();

        if ($template && $template->isValid()) {
            return $template;
        }

        return null;
    }

    /**
     * 取得客戶的可用優惠券列表
     */
    public function getCustomerAvailableCoupons(Customer $customer)
    {
        $currentTenantId = $this->tenantResolver->getCurrentTenantId();
        if ($currentTenantId !== null) {
            $this->assertTenantConsistency(
                $customer->tenant_id,
                $currentTenantId,
                '無法操作其他租戶的客戶'
            );
        }

        return UserCoupon::where('customer_id', $customer->id)
            ->where('status', UserCoupon::STATUS_AVAILABLE)
            ->where(function ($query) {
                $query->whereNull('expired_at')
                    ->orWhere('expired_at', '>', now());
            })
            ->with('couponTemplate')
            ->latest()
            ->get();
    }
}
