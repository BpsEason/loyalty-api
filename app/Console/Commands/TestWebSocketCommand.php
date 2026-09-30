<?php

namespace App\Console\Commands;

use App\Events\PointsUpdated;
use Illuminate\Console\Command;

class TestWebSocketCommand extends Command
{
    protected $signature = 'websocket:test {tenant_id=1} {member_id=1}';

    protected $description = 'Test WebSocket broadcasting';

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $memberId = (int) $this->argument('member_id');

        $transactionId = random_int(1000, 9999);
        $delta = 100;
        $balance = 1200;

        $this->info('Testing WebSocket broadcasting...');
        $this->line("Tenant ID: {$tenantId}");
        $this->line("Member ID: {$memberId}");

        PointsUpdated::dispatch(
            $tenantId,
            $memberId,
            $transactionId,
            $delta,
            $balance
        );

        $this->info('✅ PointsUpdated event dispatched.');
        $this->line('');
        $this->line("Frontend Echo usage:");
        $this->line("Echo.private('tenant.{$tenantId}.member.{$memberId}')");
        $this->line("  .listen('.points.updated', (data) => {");
        $this->line("      console.log('Points updated:', data);");
        $this->line("  });");
        $this->line('');
        $this->line("Event name: points.updated");
        $this->line("Payload:");
        $this->line(json_encode([
            'member_id' => $memberId,
            'transaction_id' => $transactionId,
            'delta' => $delta,
            'balance' => $balance,
            'occurred_at' => now()->toISOString(),
        ], JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }
}
