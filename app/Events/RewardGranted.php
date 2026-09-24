<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RewardGranted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $customerId,
        public readonly int $rewardGrantId,
        public readonly int $campaignId,
        public readonly ?int $pointsAwarded,
        public readonly string $occurredAt
    ) {}

    public function getEventId(): string
    {
        return sprintf('reward_granted_%d', $this->rewardGrantId);
    }

    public function getAggregateType(): string
    {
        return 'reward_grant';
    }

    public function getAggregateId(): string
    {
        return (string) $this->rewardGrantId;
    }

    public function getEventType(): string
    {
        return 'RewardGranted';
    }

    public function toPayload(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'customer_id' => $this->customerId,
            'reward_grant_id' => $this->rewardGrantId,
            'campaign_id' => $this->campaignId,
            'points_awarded' => $this->pointsAwarded,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
