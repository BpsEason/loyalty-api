<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PointsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * 建立新的事件實例
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $memberId,
        public readonly int $transactionId,
        public readonly int $delta,
        public readonly int $balance
    ) {}

    /**
     * 取得事件應該廣播的頻道
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(sprintf('tenant.%d.member.%d', $this->tenantId, $this->memberId)),
        ];
    }

    /**
     * 取得廣播的事件名稱
     */
    public function broadcastAs(): string
    {
        return 'points.updated';
    }

    /**
     * 取得廣播的資料
     */
    public function broadcastWith(): array
    {
        return [
            'member_id' => $this->memberId,
            'transaction_id' => $this->transactionId,
            'delta' => $this->delta,
            'balance' => $this->balance,
            'occurred_at' => now()->toISOString(),
        ];
    }
}
