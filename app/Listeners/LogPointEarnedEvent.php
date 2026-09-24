<?php

namespace App\Listeners;

use App\Events\PointEarned;
use Illuminate\Support\Facades\Log;

class LogPointEarnedEvent
{
    /**
     * 處理發放點數事件
     * 僅用作測試，記錄事件到日誌，驗證整個pipeline正常運作
     */
    public function handle(PointEarned $event): void
    {
        Log::info('PointEarned事件已成功處理', [
            'tenant_id' => $event->tenantId,
            'customer_id' => $event->customerId,
            'point_transaction_id' => $event->pointTransactionId,
            'points' => $event->points,
            'reference' => $event->reference,
            'occurred_at' => $event->occurredAt,
        ]);
    }
}
