<?php

namespace App\Console\Commands;

use App\Models\PointAccount;
use App\Models\PointTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RebuildPointBalancesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'points:rebuild-balance {--repair : 修復不一致的餘額}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重新計算並驗證所有點數帳戶的餘額一致性';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('開始檢查點數帳戶餘額一致性...');
        $this->newLine();

        $checkedCount = 0;
        $mismatchedCount = 0;
        $mismatchedAccounts = collect();

        // 按租戶分組處理所有點數帳戶，維持租戶隔離
        PointAccount::with('pointTransactions')
            ->get()
            ->groupBy('tenant_id')
            ->each(function (Collection $accounts, int $tenantId) use (&$checkedCount, &$mismatchedCount, &$mismatchedAccounts) {
                $this->comment("處理租戶 #{$tenantId} 的帳戶...");

                $accounts->each(function (PointAccount $account) use (&$checkedCount, &$mismatchedCount, &$mismatchedAccounts) {
                    $checkedCount++;

                    // 重新計算帳戶餘額
                    $calculatedBalance = $this->calculateBalanceFromTransactions($account->pointTransactions);

                    // 檢查是否不一致
                    if ($calculatedBalance !== $account->balance) {
                        $mismatchedCount++;
                        $mismatchedAccounts->push([
                            'tenant_id' => $account->tenant_id,
                            'account_id' => $account->id,
                            'customer_id' => $account->customer_id,
                            'current_balance' => $account->balance,
                            'calculated_balance' => $calculatedBalance,
                            'difference' => $calculatedBalance - $account->balance,
                        ]);

                        $this->warn("  帳戶 #{$account->id}（客戶 #{$account->customer_id}）餘額不一致");
                        $this->line("    目前餘額：{$account->balance}");
                        $this->line("    重建餘額：{$calculatedBalance}");
                        $this->line("    差異：" . ($calculatedBalance - $account->balance));

                        // 如果有--repair選項，則更新餘額
                        if ($this->option('repair')) {
                            $account->update([
                                'balance' => $calculatedBalance,
                            ]);
                            $this->info("    已修復餘額");
                        }
                    }
                });
            });

        $this->newLine();
        $this->info('=== 檢查結果 ===');
        $this->line("已檢查帳戶數：{$checkedCount}");
        $this->line("不一致帳戶數：{$mismatchedCount}");

        if ($mismatchedCount > 0) {
            $this->newLine();
            $this->table(
                ['租戶ID', '帳戶ID', '客戶ID', '目前餘額', '重建餘額', '差異'],
                $mismatchedAccounts
            );
        }

        if (!$this->option('repair') && $mismatchedCount > 0) {
            $this->newLine();
            $this->comment('提示：使用 --repair 選項來修復這些不一致的餘額');
        }

        return Command::SUCCESS;
    }

    /**
     * 從交易記錄重新計算帳戶餘額
     */
    protected function calculateBalanceFromTransactions(Collection $transactions): int
    {
        $balance = 0;

        foreach ($transactions->sortBy('created_at')->sortBy('id') as $transaction) {
            match ($transaction->type) {
                PointTransaction::TYPE_EARN => $balance += $transaction->amount,
                PointTransaction::TYPE_REDEEM => $balance -= $transaction->amount,
                PointTransaction::TYPE_ADJUST => $balance += $transaction->amount,
                PointTransaction::TYPE_REFUND => $balance += $transaction->amount,
                PointTransaction::TYPE_EXPIRE => $balance -= $transaction->amount,
                default => $balance,
            };
        }

        return $balance;
    }
}
