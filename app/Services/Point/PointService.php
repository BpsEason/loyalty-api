<?php

namespace App\Services\Point;

use App\Models\Customer;
use App\Models\PointAccount;
use App\Models\PointLot;
use App\Models\PointTransaction;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PointService
{
    /**
     * 嘗試取得鎖的最長等待時間（秒）
     */
    protected int $lockWaitSeconds = 10;

    /**
     * 鎖定自動釋放 TTL（秒）- 延長至20秒避免鎖過早釋放
     */
    protected int $lockTTL = 20;

    public function __construct(protected TenantResolver $tenantResolver) {}

    /**
     * 驗證金額為正數
     */
    protected function validatePositiveAmount(int $amount, string $errorMessage): void
    {
        if ($amount <= 0) {
            throw new RuntimeException($errorMessage);
        }
    }

    /**
     * 驗證租戶一致性
     */
    protected function assertTenantConsistency(int $entityTenantId, int $expectedTenantId, string $errorMessage): void
    {
        if ($entityTenantId !== $expectedTenantId) {
            throw new RuntimeException($errorMessage);
        }
    }

    /**
     * 取得客戶的鎖定鍵
     */
    protected function getLockKey(Customer $customer): string
    {
        return sprintf('point_customer:%d:tenant:%d', $customer->id, $customer->tenant_id);
    }

    /**
     * 在鎖定與交易中執行操作
     * 
     * Cache Lock 用於降低同一客戶的並發競爭
     * DB Transaction 保證原子性
     * lockForUpdate() 保證資料庫級別的串行化，即使 Cache Lock 失效仍能保證一致性
     */
    protected function executeInLock(Customer $customer, callable $callback): mixed
    {
        $lockKey = $this->getLockKey($customer);

        try {
            $lock = Cache::lock($lockKey, $this->lockTTL);

            // 使用 block 自旋等待，自動處理鎖釋放
            return $lock->block($this->lockWaitSeconds, function () use ($customer, $callback) {
                // 加入 3 次重試處理短暫的資料庫死鎖
                return DB::transaction(function () use ($customer, $callback) {
                    $account = $this->getOrCreatePointAccount($customer);
                    return $callback($account);
                }, 3);
            });
        } catch (LockTimeoutException $e) {
            throw new RuntimeException('系統繁忙，請稍後再試', 0, $e);
        } catch (\Exception $e) {
            // Redis連接故障降級：依賴資料庫行鎖保證一致性
            report($e); // 記錄Redis故障日誌

            // 即使Redis不可用，仍然依賴資料庫的lockForUpdate行鎖來保護一致性
            return DB::transaction(function () use ($customer, $callback) {
                $account = $this->getOrCreatePointAccount($customer);
                return $callback($account);
            }, 3);
        }
    }

    /**
     * 取得或建立客戶的點數帳戶（保證回傳持有 lockForUpdate 的實體）
     * 
     * 處理並發建立帳戶的 race condition，依賴資料庫的 unique(['tenant_id', 'customer_id']) 約束
     * 永遠回傳已持有資料庫行鎖的帳戶實體
     */
    protected function getOrCreatePointAccount(Customer $customer): PointAccount
    {
        // 驗證當前租戶與客戶租戶一致
        $currentTenantId = $this->tenantResolver->getCurrentTenantId();
        if ($currentTenantId !== null) {
            $this->assertTenantConsistency(
                $customer->tenant_id,
                $currentTenantId,
                '無法操作其他租戶的客戶'
            );
        }

        // 1. 先嘗試取得現有帳戶並加上行鎖 (FOR UPDATE)
        /** @var PointAccount|null $account */
        $account = $customer->pointAccount()->lockForUpdate()->first();

        if ($account) {
            $this->assertTenantConsistency(
                $account->tenant_id,
                $customer->tenant_id,
                '點數帳戶租戶與客戶租戶不一致'
            );
            return $account;
        }

        // 2. 帳戶不存在時嘗試建立
        try {
            $customer->pointAccount()->create([
                'tenant_id' => $customer->tenant_id,
                'balance' => 0,
                'total_earned' => 0,
                'total_redeemed' => 0,
            ]);

            // 建立後重新以 lockForUpdate 載入，確保本事務內一致持有 DB 行鎖
            $account = $customer->pointAccount()->lockForUpdate()->firstOrFail();

            $this->assertTenantConsistency(
                $account->tenant_id,
                $customer->tenant_id,
                '點數帳戶租戶與客戶租戶不一致'
            );
            return $account;
        } catch (UniqueConstraintViolationException $e) {
            // 並發狀況下若被其他請求先建立，再次以 lockForUpdate 鎖定讀取
            $account = $customer->pointAccount()->lockForUpdate()->firstOrFail();

            $this->assertTenantConsistency(
                $account->tenant_id,
                $customer->tenant_id,
                '點數帳戶租戶與客戶租戶不一致'
            );
            return $account;
        }
    }

    /**
     * 為客戶新增點數
     * 
     * 實現Point Lot FIFO：建立新的點數批次，與餘額更新在同一事務中完成
     */
    public function earn(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        $this->validatePositiveAmount($amount, '點數必須為正數');

        return $this->executeInLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore + $amount;

            // 更新帳戶餘額
            $account->update([
                'balance' => $balanceAfter,
                'total_earned' => $account->total_earned + $amount,
            ]);

            // 建立交易記錄
            $transaction = $this->createTransaction(
                PointTransaction::TYPE_EARN,
                $account,
                $amount,
                $balanceBefore,
                $balanceAfter,
                $description,
                $reference,
                $createdBy
            );

            // 建立Point Lot（在同一事務中）
            PointLot::create([
                'tenant_id' => $account->tenant_id,
                'customer_id' => $account->customer_id,
                'point_account_id' => $account->id,
                'original_points' => $amount,
                'remaining_points' => $amount,
                'earned_at' => now(),
                'expired_at' => null, // 可由後續排程設定過期時間
                'origin_transaction_id' => $transaction->id,
            ]);

            return $transaction;
        });
    }

    /**
     * 客戶兌換點數
     * 
     * 實現Point Lot FIFO消耗：按earned_at + id的確定性順序消耗點數批次
     * 保持原有的並發安全機制：Redis鎖 + DB行鎖 + 雙重餘額檢查
     */
    public function redeem(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        $this->validatePositiveAmount($amount, '點數必須為正數');

        return $this->executeInLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
            if ($account->balance < $amount) {
                throw new RuntimeException('點數餘額不足');
            }

            $balanceBefore = $account->balance;
            $remainingToDeduct = $amount;

            // FIFO: 游標式消費，每次只鎖定當前需要的批次，降低死鎖風險
            while ($remainingToDeduct > 0) {
                $lot = $account->pointLots()
                    ->where('remaining_points', '>', 0)
                    ->whereNull('expired_at')
                    ->orderBy('earned_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate() // 只鎖定當前批次
                    ->first();

                if (!$lot) {
                    throw new RuntimeException('點數批次不足，無法完成兌換');
                }

                $deductFromLot = min($lot->remaining_points, $remainingToDeduct);
                $lot->update([
                    'remaining_points' => $lot->remaining_points - $deductFromLot
                ]);
                $remainingToDeduct -= $deductFromLot;
            }

            // 必須確保所有要扣除的點數都已從批次中消耗完畢
            if ($remainingToDeduct > 0) {
                throw new RuntimeException('點數批次不足，無法完成兌換');
            }

            $balanceAfter = $balanceBefore - $amount;

            // 保持原有的資料庫層級雙重保險
            $updated = $account->where('id', $account->id)
                ->where('balance', '>=', $amount)
                ->update([
                    'balance' => $balanceAfter,
                    'total_redeemed' => $account->total_redeemed + $amount,
                ]);

            if ($updated === 0) {
                throw new RuntimeException('點數餘額不足，交易失敗');
            }

            return $this->createTransaction(
                PointTransaction::TYPE_REDEEM,
                $account,
                $amount,
                $balanceBefore,
                $balanceAfter,
                $description,
                $reference,
                $createdBy
            );
        });
    }

    /**
     * 手動調整點數
     * 
     * 正數調整等同於獲得點數，負數調整等同於使用點數
     * 永遠保證調整後餘額 >= 0
     */
    public function adjust(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        if ($amount === 0) {
            throw new RuntimeException('調整點數不能為0');
        }

        return $this->executeInLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore + $amount;

            if ($balanceAfter < 0) {
                throw new RuntimeException('調整後點數不可為負數');
            }

            $absAmount = abs($amount);
            $updateData = ['balance' => $balanceAfter];

            if ($amount > 0) {
                $updateData['total_earned'] = $account->total_earned + $amount;
            } else {
                $updateData['total_redeemed'] = $account->total_redeemed + $absAmount;
            }

            // 負數調整：需要使用FIFO消耗點數批次
            if ($amount < 0) {
                $remainingToDeduct = $absAmount;

                // FIFO: 按earned_at ASC, id ASC排序，確保完全確定性的消耗順序
                $lots = $account->pointLots()
                    ->where('remaining_points', '>', 0)
                    ->whereNull('expired_at')
                    ->orderBy('earned_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                // 依序消耗每個批次的點數
                foreach ($lots as $lot) {
                    if ($remainingToDeduct <= 0) {
                        break;
                    }

                    $deductFromLot = min($lot->remaining_points, $remainingToDeduct);
                    $lot->update([
                        'remaining_points' => $lot->remaining_points - $deductFromLot
                    ]);
                    $remainingToDeduct -= $deductFromLot;
                }

                // 必須確保所有要扣除的點數都已從批次中消耗完畢
                if ($remainingToDeduct > 0) {
                    throw new RuntimeException('點數批次不足，無法完成調整');
                }

                // 使用where條件確保餘額足夠，同樣的 defense-in-depth 策略
                $updated = $account->where('id', $account->id)
                    ->where('balance', '>=', $absAmount)
                    ->update($updateData);
                if ($updated === 0) {
                    throw new RuntimeException('點數餘額不足，交易失敗');
                }
            } else {
                // 正數調整：更新帳戶餘額
                $account->update($updateData);
            }

            // 建立交易記錄
            $transaction = $this->createTransaction(
                PointTransaction::TYPE_ADJUST,
                $account,
                $amount,
                $balanceBefore,
                $balanceAfter,
                $description,
                $reference,
                $createdBy
            );

            // 正數調整需要建立新的PointLot
            if ($amount > 0) {
                PointLot::create([
                    'tenant_id' => $account->tenant_id,
                    'customer_id' => $account->customer_id,
                    'point_account_id' => $account->id,
                    'original_points' => $amount,
                    'remaining_points' => $amount,
                    'earned_at' => now(),
                    'expired_at' => null,
                    'origin_transaction_id' => $transaction->id,
                ]);
            }

            return $transaction;
        });
    }

    /**
     * 退款點數（將兌換的點數退回）
     * 
     * 保留歷史累計，不倒扣 total_redeemed，因為該欄位代表歷史總兌換量
     * 若提供原始兌換交易作為參考，會自動防止重複退款
     */
    public function refund(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        $this->validatePositiveAmount($amount, '退款點數必須為正數');

        // 如果參考是原始的 REDEEM 交易，先檢查是否已經退款過以防止重複退款
        // 這個檢查在executeInLock之外執行，提早失敗；但真正的原子性保證由交易內的檢查提供
        if ($reference instanceof PointTransaction) {
            if ($reference->type !== PointTransaction::TYPE_REDEEM) {
                throw new RuntimeException('退款只能針對兌換交易');
            }
        }

        return $this->executeInLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
            // 在 Customer Lock 保護下檢查是否已退款，避免同一兌換交易重複退款
            if ($reference instanceof PointTransaction) {
                // 驗證參考交易與當前帳戶的一致性，禁止跨客戶、跨帳戶、跨租戶退款
                if ($reference->customer_id !== $account->customer_id) {
                    throw new RuntimeException('退款參考交易的客戶與當前客戶不一致');
                }
                if ($reference->point_account_id !== $account->id) {
                    throw new RuntimeException('退款參考交易的點數帳戶與當前帳戶不一致');
                }
                if ($reference->tenant_id !== $account->tenant_id) {
                    throw new RuntimeException('退款參考交易的租戶與當前租戶不一致');
                }

                $existingRefund = PointTransaction::where('reference_type', get_class($reference))
                    ->where('reference_id', $reference->id)
                    ->where('type', PointTransaction::TYPE_REFUND)
                    ->exists();

                if ($existingRefund) {
                    throw new RuntimeException('該兌換交易已經退款過');
                }
            }

            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore + $amount;

            // 只更新餘額，保留 total_redeemed 的歷史記錄，不扣回
            // 這確保 ledger 可以完整追蹤所有發生過的兌換和退款事件
            $account->update([
                'balance' => $balanceAfter,
            ]);

            // 建立交易記錄
            $transaction = $this->createTransaction(
                PointTransaction::TYPE_REFUND,
                $account,
                $amount,
                $balanceBefore,
                $balanceAfter,
                $description,
                $reference,
                $createdBy
            );

            // 退款成功時建立新的PointLot（不恢復原有的已消耗批次，保持歷史可追蹤性）
            PointLot::create([
                'tenant_id' => $account->tenant_id,
                'customer_id' => $account->customer_id,
                'point_account_id' => $account->id,
                'original_points' => $amount,
                'remaining_points' => $amount,
                'earned_at' => now(),
                'expired_at' => null,
                'origin_transaction_id' => $transaction->id,
            ]);

            return $transaction;
        });
    }

    /**
     * 處理客戶所有已到期的點數批次
     * 
     * 自動找出所有已過期的批次，一次性處理所有過期點數
     * 保持與其他點數操作相同的locking和transaction機制
     * 回傳處理的過期點數總額和建立的交易記錄
     */
    public function expireAllExpiredLots(Customer $customer): array
    {
        return $this->executeInLock($customer, function (PointAccount $account) {
            // 找出該客戶所有需要過期的批次：remaining_points > 0 且 expired_at < now()
            $expiredLots = $account->pointLots()
                ->where('remaining_points', '>', 0)
                ->whereNotNull('expired_at')
                ->where('expired_at', '<', now())
                ->orderBy('expired_at', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            if ($expiredLots->isEmpty()) {
                return [0, null];
            }

            $totalExpireAmount = 0;
            $balanceBefore = $account->balance;

            // 處理每個過期批次
            foreach ($expiredLots as $lot) {
                $expireAmount = $lot->remaining_points;
                $totalExpireAmount += $expireAmount;

                // 將該批次的剩餘點數設為0
                $lot->update([
                    'remaining_points' => 0,
                ]);
            }

            // 檢查帳戶餘額是否足夠
            if ($account->balance < $totalExpireAmount) {
                throw new RuntimeException(
                    "Insufficient account balance ({$account->balance}) to expire all expired points ({$totalExpireAmount}) for customer {$customer->id}."
                );
            }

            // 更新帳戶餘額
            $balanceAfter = $balanceBefore - $totalExpireAmount;
            $account->update([
                'balance' => $balanceAfter,
            ]);

            // 建立單一的過期交易記錄
            $transaction = $this->createTransaction(
                PointTransaction::TYPE_EXPIRE,
                $account,
                -$totalExpireAmount,
                $balanceBefore,
                $balanceAfter,
                "Automatically expired {$totalExpireAmount} points from {$expiredLots->count()} lots",
                null
            );

            return [$totalExpireAmount, $transaction];
        });
    }

    /**
     * 點數過期
     * 
     * 與Point Lot系統整合：找到最舊的未過期批次，標記為過期並扣除相應點數
     * 保持原有的過期語義，同時實現批次級別的過期管理
     */
    public function expire(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        $this->validatePositiveAmount($amount, '過期點數必須為正數');

        return $this->executeInLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
            if ($account->balance < $amount) {
                throw new RuntimeException('點數餘額不足');
            }

            $balanceBefore = $account->balance;
            $remainingToExpire = $amount;

            // 同樣按FIFO順序處理過期，先過期最早獲得的點數
            $lots = $account->pointLots()
                ->where('remaining_points', '>', 0)
                ->whereNull('expired_at')
                ->orderBy('earned_at', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            foreach ($lots as $lot) {
                if ($remainingToExpire <= 0) {
                    break;
                }

                $expireFromLot = min($lot->remaining_points, $remainingToExpire);
                $lot->update([
                    'remaining_points' => $lot->remaining_points - $expireFromLot,
                    // 如果整個批次的點數都過期了，設定expired_at
                    'expired_at' => ($lot->remaining_points - $expireFromLot) <= 0 ? now() : $lot->expired_at,
                ]);
                $remainingToExpire -= $expireFromLot;
            }

            // 必須確保所有要過期的點數都已從批次中消耗完畢
            if ($remainingToExpire > 0) {
                throw new RuntimeException('點數批次不足，無法完成過期處理');
            }

            $balanceAfter = $balanceBefore - $amount;

            // 保持資料庫層級的雙重保險
            $updated = $account->where('id', $account->id)
                ->where('balance', '>=', $amount)
                ->update(['balance' => $balanceAfter]);

            if ($updated === 0) {
                throw new RuntimeException('點數餘額不足，交易失敗');
            }

            return $this->createTransaction(
                PointTransaction::TYPE_EXPIRE,
                $account,
                $amount,
                $balanceBefore,
                $balanceAfter,
                $description,
                $reference,
                $createdBy
            );
        });
    }

    /**
     * 建立點數交易記錄
     * 
     * 所有交易都保證 tenant_id、customer_id、point_account_id 一致性
     * 直接使用已知的 FK 欄位，避免不必要的 lazy loading
     */
    protected function createTransaction(
        string $type,
        PointAccount $account,
        int $amount,
        int $balanceBefore,
        int $balanceAfter,
        ?string $description,
        mixed $reference = null,
        ?int $createdBy = null
    ): PointTransaction {
        // 直接使用已驗證的 FK 欄位，不需要額外 lazy loading 客戶資料
        // getOrCreatePointAccount() 已經保證帳戶與客戶的租戶一致性

        $transaction = new PointTransaction([
            'tenant_id' => $account->tenant_id,
            'customer_id' => $account->customer_id, // 直接填入已知的 FK，避免觸發不必要的查詢
            'point_account_id' => $account->id,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'description' => $description,
            'created_by' => $createdBy,
        ]);

        if ($reference) {
            $transaction->reference()->associate($reference);
        }

        $transaction->save();

        return $transaction;
    }
}
