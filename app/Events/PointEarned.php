<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PointEarned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $customerId,
        public readonly int $pointTransactionId,
        public readonly int $points,
        public readonly mixed $reference,
        public readonly string $occurredAt
    ) {}

    /**
     * 取得事件的唯一識別
     */
    public function getEventId(): string
    {
        return sprintf('point_earned_%d', $this->pointTransactionId);
    }

    /**
     * 取得聚合類型
     */
    public function getAggregateType(): string
    {
        return 'point_transaction';
    }

    /**
     * 取得聚合ID
     */
    public function getAggregateId(): string
    {
        return (string) $this->pointTransactionId;
    }

    /**
     * 取得事件類型
     */
    public function getEventType(): string
    {
        return 'PointEarned';
    }

    /**
     * 序列化為數組，用於存儲到outbox
     */
    public function toPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'customer_id' => $this->customerId,
            'point_transaction_id' => $this->pointTransactionId,
            'points' => $this->points,
            'reference' => $this->reference,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
