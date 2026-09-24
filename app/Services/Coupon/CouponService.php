<?php

namespace App\Services\Coupon;

use App\Events\CouponClaimed;
use App\Events\CouponRedeemed;
use App\Models\CouponTemplate;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\UserCoupon;
use App\Services\Outbox\OutboxService;
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
     * 原子性保證：Coupon redemption + point deduction 必須保持在同一 transaction。
     * 任何步驟失敗都會完整 rollback，不會出現部分成功的狀態。
     *
     * Lock ordering：
     * Customer Lock → PointAccount Row Lock → UserCoupon Row Lock
     * Lock ordering is intentionally kept consistent across related operations to reduce deadlock risk.
     * 此順序與 PointService 的所有點數操作保持一致，避免循環等待。
     *
     * Business rule 計算順序：
     * Coupon Discount → Remaining Amount → Point Deduction
     * 必須先套用優惠券折扣，再用點數折抵剩餘金額，這是業務規則要求。
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
        $this->assertMixedPaymentTenantConsistency($customer, $userCoupon);

        if (!$userCoupon && $pointsAmount <= 0) {
            throw new RuntimeException('至少需要使用一項優惠（優惠券或點數）');
        }

        return $this->executeWithCustomerLoyaltyLock($customer, function () use (
            $customer,
            $userCoupon,
            $orderAmount,
            $pointsAmount,
            $reference,
            $orderReference,
            $createdBy
        ) {
            return $this->executeMixedPayment(
                $customer,
                $userCoupon,
                $orderAmount,
                $pointsAmount,
                $reference,
                $orderReference,
                $createdBy
            );
        });
    }

    protected function assertMixedPaymentTenantConsistency(Customer $customer, ?UserCoupon $userCoupon): void
    {
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
    }

    protected function executeWithCustomerLoyaltyLock(Customer $customer, callable $callback): mixed
    {
        $customerLockKey = sprintf('point_customer:%d:tenant:%d', $customer->id, $customer->tenant_id);

        try {
            return Cache::lock($customerLockKey, $this->lockTTL)->block($this->lockWaitSeconds, function () use ($callback) {
                return DB::transaction(function () use ($callback) {
                    return $callback();
                }, 3);
            });
        } catch (LockTimeoutException $e) {
            throw new RuntimeException('系統繁忙，請稍後再試', 0, $e);
        } catch (\Exception $e) {
            report($e);
            throw $e;
        }
    }

    protected function executeMixedPayment(
        Customer $customer,
        ?UserCoupon $userCoupon,
        int $orderAmount,
        int $pointsAmount,
        string $reference,
        ?string $orderReference = null,
        ?int $createdBy = null
    ): array {
        $discountAmount = 0;
        $redemption = null;
        $pointTransaction = null;
        $pointAccount = null;

        if ($pointsAmount > 0) {
            $pointAccount = $customer->pointAccount()->lockForUpdate()->firstOrFail();

            if ($pointAccount->balance < $pointsAmount) {
                throw new RuntimeException('點數餘額不足');
            }
        }

        if ($userCoupon) {
            /** @var UserCoupon $userCoupon */
            $userCoupon = UserCoupon::where('id', $userCoupon->id)->lockForUpdate()->firstOrFail();

            if ($userCoupon->customer_id !== $customer->id) {
                throw new RuntimeException('優惠券不屬於此客戶');
            }

            if (!$userCoupon->isRedeemable()) {
                throw new RuntimeException('優惠券無法使用，可能已過期或已使用');
            }

            $discountAmount = $userCoupon->calculateDiscount($orderAmount);

            $userCoupon->update([
                'status' => UserCoupon::STATUS_USED,
                'used_at' => now(),
                'reference' => $reference,
            ]);

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

        $afterCouponAmount = $orderAmount - $discountAmount;

        if ($pointsAmount > 0) {
            $this->assertPointDeductionAmountDoesNotExceedPayableAmount($pointsAmount, $afterCouponAmount);

            $pointTransaction = $this->pointService->deductPointsFromAccount(
                $pointAccount,
                $pointsAmount,
                '混合支付點數折抵',
                $reference,
                $createdBy
            );
        }

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
    }

    protected function assertPointDeductionAmountDoesNotExceedPayableAmount(int $pointsAmount, int $afterCouponAmount): void
    {
        if ($pointsAmount > $afterCouponAmount) {
            throw new RuntimeException('點數折抵不能超過優惠券折扣後的應付金額');
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
        protected PointService $pointService,
        protected OutboxService $outboxService
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
     * 
     * 兩層鎖定機制：
     * Template-level serialization (Redis Lock + DB row lock)
     * → protects global issuance limit (issued_quantity validation)
     * 
     * Customer-level serialization (Redis Lock + DB count lock)
     * → protects per-customer claim limit (per_customer_limit validation)
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

                        // 記錄領域事件到Outbox，與業務事務保持原子性
                        $this->outboxService->recordDomainEvent(new CouponClaimed(
                            $userCoupon->tenant_id,
                            $userCoupon->customer_id,
                            $userCoupon->id,
                            $userCoupon->coupon_template_id,
                            now()->toIso8601String()
                        ));

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

                    // 記錄領域事件到Outbox，與業務事務保持原子性
                    $this->outboxService->recordDomainEvent(new CouponRedeemed(
                        $redemption->tenant_id,
                        $redemption->customer_id,
                        $redemption->id,
                        $redemption->user_coupon_id,
                        $redemption->discount_amount,
                        $redemption->reference,
                        now()->toIso8601String()
                    ));

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
