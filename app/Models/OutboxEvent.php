<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboxEvent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'event_id',
        'payload',
        'occurred_at',
        'processed_at',
        'attempts',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * 標記事件為已處理
     */
    public function markAsProcessed(): void
    {
        $this->update([
            'processed_at' => now(),
        ]);
    }

    /**
     * 記錄處理失敗
     */
    public function recordFailure(string $error): void
    {
        $this->increment('attempts');
        $this->update([
            'last_error' => $error,
        ]);
    }

    /**
     * 判斷事件是否可以重試
     */
    public function canRetry(int $maxAttempts = 10): bool
    {
        return $this->processed_at === null && $this->attempts < $maxAttempts;
    }
}
