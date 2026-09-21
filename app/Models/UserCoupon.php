<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UserCoupon extends Model
{
    use BelongsToTenant;

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_USED = 'used';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'coupon_template_id',
        'status',
        'issued_at',
        'used_at',
        'expired_at',
        'reference',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'used_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function couponTemplate(): BelongsTo
    {
        return $this->belongsTo(CouponTemplate::class);
    }

    public function redemption(): HasOne
    {
        return $this->hasOne(CouponRedemption::class);
    }

    /**
     * 檢查優惠券是否可以被使用
     */
    public function isRedeemable(): bool
    {
        if ($this->status !== self::STATUS_AVAILABLE) {
            return false;
        }

        // 檢查是否已過期
        if ($this->expired_at && $this->expired_at->isPast()) {
            return false;
        }

        // 檢查模板是否仍然有效
        if (!$this->couponTemplate->isValid()) {
            return false;
        }

        return true;
    }

    /**
     * 計算此優惠券可提供的折扣金額
     */
    public function calculateDiscount(int $orderAmount): int
    {
        $template = $this->couponTemplate;

        if ($orderAmount < $template->minimum_order_amount) {
            return 0;
        }

        return match ($template->type) {
            CouponTemplate::TYPE_FIXED_AMOUNT => $template->discount_amount ?? 0,
            CouponTemplate::TYPE_PERCENTAGE => $this->calculatePercentageDiscount($orderAmount, $template),
            default => 0,
        };
    }

    /**
     * 計算百分比折扣
     */
    protected function calculatePercentageDiscount(int $orderAmount, CouponTemplate $template): int
    {
        $percentage = $template->discount_percentage ?? 0;
        $discount = (int) floor($orderAmount * ($percentage / 100));

        // 套用最大折扣限制
        if ($template->max_discount_amount && $discount > $template->max_discount_amount) {
            return $template->max_discount_amount;
        }

        return $discount;
    }
}
