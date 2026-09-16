<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'metadata',
        'member_code',
        'qr_token',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * 建立Customer時自動生成member_code和qr_token
     */
    protected static function booted(): void
    {
        static::creating(function ($customer) {
            // 如果尚未設定member_code，自動生成
            if (!$customer->member_code) {
                $customer->member_code = static::generateMemberCode($customer->tenant_id);
            }
            // 如果尚未設定qr_token，自動生成
            if (!$customer->qr_token) {
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
        // 使用withoutGlobalScope移除租戶全域範圍，才能正確查詢同一租戶下的所有客戶
        $lastCustomer = static::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('member_code')
            ->latest('id')
            ->first();

        if ($lastCustomer && preg_match('/^M(\d+)$/', $lastCustomer->member_code, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        } else {
            $nextNumber = 1001; // 從1001開始
        }

        return $prefix . str_pad((string)$nextNumber, 6, '0', STR_PAD_LEFT);
    }

    /**
     * 生成隨機的不透明QR令牌
     */
    protected static function generateQrToken(): string
    {
        return strtolower(str_replace(['+', '/', '='], '', base64_encode(random_bytes(32))));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function pointAccount(): HasOne
    {
        return $this->hasOne(PointAccount::class);
    }

    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }
}
