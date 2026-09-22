<?php

namespace App\Console\Commands;

use App\Models\PointLot;
use App\Models\PointTransaction;
use App\Models\PointAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ExpirePointsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'points:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically expire points that have passed their expiration date and update account balances';

    /**
     * 鎖定鍵，防止命令重複執行
     */
    protected string $lockKey = 'commands:points:expire';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // 使用原子鎖防止重複執行
        if (!Cache::add($this->lockKey, true, 3600)) {
            $this->warn('points:expire command is already running.');
            return 1;
        }

        try {
            $this->info('Starting point expiration process...');

            $expiredCount = 0;
            $totalExpiredPoints = 0;

            // 找出所有需要過期的 PointLot：已過期、仍有剩餘點數、且尚未被處理過
            $lotsToExpire = PointLot::where('remaining_points', '>', 0)
                ->whereNotNull('expired_at')
                ->where('expired_at', '<', Carbon::now())
                ->orderBy('tenant_id')
                ->orderBy('point_account_id')
                ->get();

            $this->info("Found {$lotsToExpire->count()} point lots to process.");

            foreach ($lotsToExpire as $lot) {
                try {
                    DB::transaction(function () use ($lot, &$expiredCount, &$totalExpiredPoints) {
                        // 重新鎖定該批次以確保一致性
                        $lockedLot = PointLot::where('id', $lot->id)
                            ->lockForUpdate()
                            ->first();

                        if (!$lockedLot || $lockedLot->remaining_points <= 0) {
                            $this->warn("Skipping lot {$lot->id}: already processed.");
                            return;
                        }

                        $expireAmount = $lockedLot->remaining_points;

                        // 取得並鎖定點數帳戶
                        $account = PointAccount::where('id', $lockedLot->point_account_id)
                            ->lockForUpdate()
                            ->firstOrFail();

                        // 檢查帳戶餘額是否足夠過期所有剩餘點數，不足則拋出例外讓transaction rollback
                        if ($account->balance < $lockedLot->remaining_points) {
                            throw new \RuntimeException(
                                "Insufficient account balance ({$account->balance}) to expire all remaining points ({$lockedLot->remaining_points}) for lot {$lot->id}."
                            );
                        }

                        $balanceBefore = $account->balance;
                        $balanceAfter = $balanceBefore - $expireAmount;

                        // 更新帳戶餘額
                        $account->update([
                            'balance' => $balanceAfter,
                        ]);

                        // 更新 PointLot 的剩餘點數為 0
                        $lockedLot->update([
                            'remaining_points' => 0,
                        ]);

                        // 建立過期交易記錄
                        PointTransaction::create([
                            'tenant_id' => $lockedLot->tenant_id,
                            'customer_id' => $lockedLot->customer_id,
                            'point_account_id' => $account->id,
                            'type' => PointTransaction::TYPE_EXPIRE,
                            'amount' => -$expireAmount,
                            'balance_before' => $balanceBefore,
                            'balance_after' => $balanceAfter,
                            'description' => "Points expired from lot {$lockedLot->id}",
                            'reference_type' => PointLot::class,
                            'reference_id' => $lockedLot->id,
                        ]);

                        $expiredCount++;
                        $totalExpiredPoints += $expireAmount;

                        $this->info("Expired {$expireAmount} points from lot {$lot->id} (tenant: {$lot->tenant_id}, account: {$account->id})");
                    });
                } catch (\Exception $e) {
                    $this->error("Failed to process lot {$lot->id}: " . $e->getMessage());
                    continue;
                }
            }

            $this->info("Point expiration completed. Processed {$expiredCount} lots, expired {$totalExpiredPoints} points total.");

            return 0;
        } finally {
            // 確保無論成功或失敗都會釋放Cache鎖
            Cache::forget($this->lockKey);
        }
    }
}
