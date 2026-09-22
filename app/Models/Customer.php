<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OwenIt\Auditing\Contracts\Auditable;

class Customer extends Model implements Auditable
{
    use BelongsToTenant;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'metadata',
        'member_code',
        'qr_token',
        'membership_tier_id',
        'total_spend',
        'total_points_earned',
        'tier_updated_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'total_spend' => 'decimal:2',
        'total_points_earned' => 'decimal:2',
        'tier_updated_at' => 'datetime',
    ];

    /**
     * 建立 Customer 時自動生成 member_code 和 qr_token
     */
    protected static function booted(): void
    {
        static::creating(function ($customer) {
            // 如果尚未設定 member_code，自動生成
            if (! $customer->member_code) {
                $customer->member_code = static::generateMemberCode($customer->tenant_id);
            }
            // 如果尚未設定 qr_token，自動生成
            if (! $customer->qr_token) {
                $customer->qr_token = static::generateQrToken();
            }
        });
    }

    /**
     * 生成租戶內唯一的會員編號
     */
    protected static function generateMemberCode($tenantId): string
    {
        $prefix = 'M';
        $maxRetries = 3;
        $retryCount = 0;

        while ($retryCount < $maxRetries) {
            // 使用 lockForUpdate() 加入資料庫行鎖，避免並發讀取同一筆最後客戶
            $lastCustomer = static::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('member_code')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($lastCustomer && preg_match('/^M(\d+)$/', $lastCustomer->member_code, $matches)) {
                $nextNumber = intval($matches[1]) + 1;
            } else {
                $nextNumber = 1001; // 從 1001 開始
            }

            $candidateCode = $prefix . str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);

            // 預先檢查此編號是否已存在，提早避免唯一約束異常
            $exists = static::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('member_code', $candidateCode)
                ->exists();

            if (!$exists) {
                return $candidateCode;
            }

            $retryCount++;
            usleep(100000); // 等待100ms再重試
        }

        // 若重試多次仍失敗，使用時間戳作為後備方案，確保不會卡住
        return $prefix . str_pad((string)(1000 + time() % 900000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 生成隨機的不透明 QR 令牌
     */
    protected static function generateQrToken(): string
    {
        return strtolower(str_replace(['+', '/', '='], '', base64_encode(random_bytes(32))));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * 取得客戶的點數帳戶 (一對一關聯)
     */
    public function pointAccount(): HasOne
    {
        return $this->hasOne(PointAccount::class);
    }

    /**
     * 舊關聯別名 (避免其他地方已使用複數形式造成斷裂)
     */
    public function pointAccounts(): HasOne
    {
        return $this->pointAccount();
    }

    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }

    /**
     * 客戶的所有點數批次
     */
    public function pointLots(): HasMany
    {
        return $this->hasMany(\App\Models\PointLot::class);
    }

    /**
     * 客戶的所有優惠券
     */
    public function userCoupons(): HasMany
    {
        return $this->hasMany(\App\Models\UserCoupon::class);
    }

    /**
     * 客戶的會員等級
     */
    public function membershipTier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\MembershipTier::class);
    }

    /**
     * 更新客戶的會員等級
     */
    public function updateMembershipTier()
    {
        if (!$this->membershipTier || !$this->membershipTier->threshold_type) {
            // 如果沒有當前等級或閾值類型，嘗試取得第一個可用的等級
            $tier = \App\Models\MembershipTier::where('tenant_id', $this->tenant_id)
                ->where('status', true)
                ->orderBy('upgrade_threshold', 'asc')
                ->first();

            if ($tier) {
                $this->membership_tier_id = $tier->id;
                $this->tier_updated_at = now();
                $this->save();
            }
            return;
        }

        // 根據當前等級的閾值類型計算是否需要升級
        $thresholdType = $this->membershipTier->threshold_type;
        $totalValue = $thresholdType === 'spend' ? $this->total_spend : $this->total_points_earned;

        $eligibleTier = \App\Models\MembershipTier::getEligibleTier($totalValue, $thresholdType, $this->tenant_id);

        if ($eligibleTier && $eligibleTier->id !== $this->membership_tier_id) {
            $this->membership_tier_id = $eligibleTier->id;
            $this->tier_updated_at = now();
            $this->save();
        }
    }

    /**
     * 增加累積消費金額
     */
    public function addTotalSpend($amount)
    {
        $this->total_spend += $amount;
        $this->save();
        $this->updateMembershipTier();
    }

    /**
     * 增加累積獲得點數
     */
    public function addTotalPointsEarned($points)
    {
        $this->total_points_earned += $points;
        $this->save();
        $this->updateMembershipTier();
    }
}
