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
}
