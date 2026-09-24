<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use App\Models\IdempotencyKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ProcessOutboxEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;
    public int $backoff = 60;

    public function __construct(protected OutboxEvent $outboxEvent) {}

    /**
     * 執行Outbox事件處理
     * 使用Redis鎖防止並發處理同一事件，確保冪等性
     */
    public function handle(): void
    {
        $lockKey = sprintf('outbox_processing:%d', $this->outboxEvent->id);
        $lock = Cache::lock($lockKey, 30);

        if (!$lock->get()) {
            // 另一個worker正在處理這個事件，退出
            return;
        }

        try {
            // 重新檢查事件是否已經被處理
            if ($this->outboxEvent->fresh()->processed_at !== null) {
                return;
            }

            // 使用冪等性鍵確保事件只處理一次
            $idempotencyKey = $this->acquireIdempotencyKey();
            if (!$idempotencyKey) {
                // 已經被處理過了
                return;
            }

            // 根據事件類型重建並分派領域事件
            $event = $this->reconstructDomainEvent();

            if ($event) {
                Event::dispatch($event);
            }

            // 標記事件為已處理
            DB::transaction(function () {
                $this->outboxEvent->markAsProcessed();

                // 更新冪等性鍵狀態
                IdempotencyKey::where('id', $idempotencyKey->id)->update([
                    'status' => IdempotencyKey::STATUS_COMPLETED,
                ]);
            });
        } catch (\Exception $e) {
            // 記錄處理失敗
            DB::transaction(function () use ($e) {
                $this->outboxEvent->recordFailure($e->getMessage());
            });

            report($e);

            // 釋放鎖並重新拋出異常，讓隊列重試
            $lock->release();
            throw $e;
        }

        $lock->release();
    }

    /**
     * 取得冪等性鎖，防止重複處理
     */
    protected function acquireIdempotencyKey(): ?IdempotencyKey
    {
        try {
            return DB::transaction(function () {
                $existing = IdempotencyKey::where('tenant_id', $this->outboxEvent->tenant_id)
                    ->where('idempotency_key', $this->outboxEvent->event_id)
                    ->first();

                if ($existing) {
                    if ($existing->status === IdempotencyKey::STATUS_COMPLETED) {
                        return null;
                    }
                    if ($existing->status === IdempotencyKey::STATUS_PROCESSING && !$existing->isStale()) {
                        return null;
                    }
                    // 處理過期的processing狀態，重新使用
                    $existing->update([
                        'status' => IdempotencyKey::STATUS_PROCESSING,
                        'started_at' => now(),
                    ]);
                    return $existing;
                }

                // 建立新的冪等性鍵
                return IdempotencyKey::create([
                    'tenant_id' => $this->outboxEvent->tenant_id,
                    'idempotency_key' => $this->outboxEvent->event_id,
                    'request_hash' => $this->outboxEvent->event_id,
                    'status' => IdempotencyKey::STATUS_PROCESSING,
                    'started_at' => now(),
                ]);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // 唯一鍵衝突，表示另一個處理流程已經建立了這個鍵
            return null;
        }
    }

    /**
     * 從Outbox payload重建領域事件實例
     */
    protected function reconstructDomainEvent(): ?object
    {
        $payload = $this->outboxEvent->payload;
        $eventClass = $this->getEventClass($this->outboxEvent->event_type);

        if (!$eventClass || !class_exists($eventClass)) {
            return null;
        }

        return match ($this->outboxEvent->event_type) {
            'PointEarned' => new \App\Events\PointEarned(
                $payload['tenant_id'],
                $payload['customer_id'],
                $payload['point_transaction_id'],
                $payload['points'],
                $payload['reference'] ?? null,
                $payload['occurred_at']
            ),
            'PointRedeemed' => new \App\Events\PointRedeemed(
                $payload['tenant_id'],
                $payload['customer_id'],
                $payload['point_transaction_id'],
                $payload['points'],
                $payload['reference'] ?? null,
                $payload['occurred_at']
            ),
            'CouponClaimed' => new \App\Events\CouponClaimed(
                $payload['tenant_id'],
                $payload['customer_id'],
                $payload['user_coupon_id'],
                $payload['coupon_template_id'],
                $payload['occurred_at']
            ),
            'CouponRedeemed' => new \App\Events\CouponRedeemed(
                $payload['tenant_id'],
                $payload['customer_id'],
                $payload['coupon_redemption_id'],
                $payload['user_coupon_id'],
                $payload['discount_amount'],
                $payload['reference'] ?? null,
                $payload['occurred_at']
            ),
            'RewardGranted' => new \App\Events\RewardGranted(
                $payload['tenant_id'],
                $payload['customer_id'],
                $payload['reward_grant_id'],
                $payload['campaign_id'],
                $payload['points_awarded'] ?? null,
                $payload['occurred_at']
            ),
            default => null,
        };
    }

    /**
     * 取得事件類別名稱
     */
    protected function getEventClass(string $eventType): ?string
    {
        $map = [
            'PointEarned' => \App\Events\PointEarned::class,
            'PointRedeemed' => \App\Events\PointRedeemed::class,
            'CouponClaimed' => \App\Events\CouponClaimed::class,
            'CouponRedeemed' => \App\Events\CouponRedeemed::class,
            'RewardGranted' => \App\Events\RewardGranted::class,
        ];

        return $map[$eventType] ?? null;
    }
}
