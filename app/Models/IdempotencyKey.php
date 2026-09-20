<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyKey extends Model
{
    use BelongsToTenant;

    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'idempotency_key',
        'request_hash',
        'status',
        'response_body',
        'response_status',
        'started_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * 檢查processing狀態是否過期（超過5分鐘）
     */
    public function isStale(): bool
    {
        if ($this->status !== self::STATUS_PROCESSING) {
            return false;
        }

        return $this->started_at->addMinutes(5)->isPast();
    }
}
