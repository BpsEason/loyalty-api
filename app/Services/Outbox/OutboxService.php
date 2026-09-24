<?php

namespace App\Services\Outbox;

use App\Models\OutboxEvent;
use Illuminate\Support\Str;

class OutboxService
{
    /**
     * 記錄領域事件到Outbox，確保與業務事務原子性
     * 必須在DB::transaction()內呼叫，才能保證原子性
     */
    public function recordDomainEvent(object $event): OutboxEvent
    {
        // 所有領域事件必須實現這些方法來提供必要的中繼資料
        if (
            !method_exists($event, 'getEventId') ||
            !method_exists($event, 'getAggregateType') ||
            !method_exists($event, 'getAggregateId') ||
            !method_exists($event, 'getEventType') ||
            !method_exists($event, 'toPayload')
        ) {
            throw new \RuntimeException('Domain event must implement required methods');
        }

        $payload = $event->toPayload();
        $tenantId = $payload['tenant_id'];

        return OutboxEvent::create([
            'tenant_id' => $tenantId,
            'event_type' => $event->getEventType(),
            'aggregate_type' => $event->getAggregateType(),
            'aggregate_id' => $event->getAggregateId(),
            'event_id' => $event->getEventId(),
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
