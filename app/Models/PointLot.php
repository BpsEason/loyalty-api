<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointLot extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'point_account_id',
        'original_points',
        'remaining_points',
        'earned_at',
        'expired_at',
        'origin_transaction_id',
    ];

    protected $casts = [
        'original_points' => 'integer',
        'remaining_points' => 'integer',
        'earned_at' => 'datetime',
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

    public function pointAccount(): BelongsTo
    {
        return $this->belongsTo(PointAccount::class);
    }

    public function originTransaction(): BelongsTo
    {
        return $this->belongsTo(PointTransaction::class);
    }

    /**
     * 确保original_points属性始终返回整数类型
     */
    public function getOriginalPointsAttribute($value): int
    {
        return (int) $value;
    }

    /**
     * 确保remaining_points属性始终返回整数类型
     */
    public function getRemainingPointsAttribute($value): int
    {
        return (int) $value;
    }

    /**
     * 檢查批次是否還有可用點數
     */
    public function hasRemaining(): bool
    {
        return $this->remaining_points > 0;
    }

    /**
     * 檢查批次是否已過期
     */
    public function isExpired(): bool
    {
        return $this->expired_at !== null && $this->expired_at->isPast();
    }
}
