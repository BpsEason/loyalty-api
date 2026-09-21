<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CouponTemplate extends Model
{
    use BelongsToTenant;

    public const TYPE_FIXED_AMOUNT = 'FIXED_AMOUNT';
    public const TYPE_PERCENTAGE = 'PERCENTAGE';
    public const TYPE_FREE_SHIPPING = 'FREE_SHIPPING';
    public const TYPE_GIFT = 'GIFT';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_DRAFT = 'draft';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'type',
        'discount_amount',
        'discount_percentage',
        'max_discount_amount',
        'minimum_order_amount',
        'starts_at',
        'expires_at',
        'total_quantity',
        'issued_quantity',
        'per_customer_limit',
        'status',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'discount_amount' => 'integer',
        'discount_percentage' => 'integer',
        'max_discount_amount' => 'integer',
        'minimum_order_amount' => 'integer',
        'total_quantity' => 'integer',
        'issued_quantity' => 'integer',
        'per_customer_limit' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function userCoupons(): HasMany
    {
        return $this->hasMany(UserCoupon::class);
    }

    /**
     * 檢查優惠券模板是否有效
     */
    public function isValid(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        // 檢查是否還有剩餘數量
        if ($this->total_quantity !== null && $this->issued_quantity >= $this->total_quantity) {
            return false;
        }

        return true;
    }

    /**
     * 檢查客戶是否可以領取此優惠券
     */
    public function canBeClaimedByCustomer(Customer $customer): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        // 檢查客戶是否已達到領取上限
        $customerClaimedCount = $this->userCoupons()
            ->where('customer_id', $customer->id)
            ->count();

        return $customerClaimedCount < $this->per_customer_limit;
    }
}
