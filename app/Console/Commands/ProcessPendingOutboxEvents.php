<?php

namespace App\Console\Commands;

use App\Models\OutboxEvent;
use App\Jobs\ProcessOutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ProcessPendingOutboxEvents extends Command
{
    protected $signature = 'outbox:process-pending';
    protected $description = '處理所有待處理的Outbox事件';

    public function handle()
    {
        $this->info('開始搜尋待處理的Outbox事件...');

        $events = $this->getPendingOutboxEvents();

        if ($events->isEmpty()) {
            $this->info('沒有待處理的Outbox事件');
            return 0;
        }

        $this->info(sprintf('找到 %d 個待處理的事件，開始分派處理...', $events->count()));

        foreach ($events as $event) {
            ProcessOutboxEvent::dispatch($event)->onQueue('outbox');
            $this->line(sprintf('已分派事件 #%d (%s)', $event->id, $event->event_type));
        }

        $this->info('所有事件已分派完成');
        return 0;
    }

    /**
     * 取得待處理的Outbox事件
     * 只取可以重試的事件，並限制每次處理數量
     */
    protected function getPendingOutboxEvents(): Collection
    {
        return OutboxEvent::whereNull('processed_at')
            ->where('attempts', '<', 10)
            ->orderBy('occurred_at', 'asc')
            ->limit(100)
            ->get();
    }
}
