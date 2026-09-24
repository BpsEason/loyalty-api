<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PointRedeemed
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

    public function getEventId(): string
    {
        return sprintf('point_redeemed_%d', $this->pointTransactionId);
    }

    public function getAggregateType(): string
    {
        return 'point_transaction';
    }

    public function getAggregateId(): string
    {
        return (string) $this->pointTransactionId;
    }

    public function getEventType(): string
    {
        return 'PointRedeemed';
    }

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
