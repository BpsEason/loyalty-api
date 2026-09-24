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
     * 取得客戶點數狀態的並發鎖定鍵
     */
    protected function getCustomerPointStateLockKey(Customer $customer): string
    {
        return sprintf('point_customer:%d:tenant:%d', $customer->id, $customer->tenant_id);
    }

    /**
     * 在客戶點數狀態鎖與資料庫交易邊界內執行點數操作
     *
     * Redis Lock (Cache::lock) 解決 application-level contention / serialization，
     * 但無法替代資料庫層的正確性保證。完整的保護鏈為：
     *
     * Redis Lock → DB Transaction → lockForUpdate()
     *
     * 各層負責不同級別的保護：
     * - Redis Lock：減少應用層的競爭，避免大量請求同時進入資料庫
     * - DB Transaction：保證所有操作的原子性
     * - lockForUpdate()：資料庫級別的行鎖，是最終的一致性邊界，即使 Redis Lock 失效仍能保護正確性
     *
     * Redis 不可用時 fallback 到 DB locking，correctness 不降低，但 performance / contention characteristics 可能改變。
     * 交易最多重試 3 次，處理短暫的資料庫死鎖。
     */
    protected function executeWithCustomerPointStateLock(Customer $customer, callable $callback): mixed
    {
        $lockKey = $this->getCustomerPointStateLockKey($customer);

        try {
            $lock = Cache::lock($lockKey, $this->lockTTL);

            return $lock->block($this->lockWaitSeconds, function () use ($customer, $callback) {
                return DB::transaction(function () use ($customer, $callback) {
                    $account = $this->lockOrCreatePointAccount($customer);
                    return $callback($account);
                }, 3);
            });
        } catch (LockTimeoutException $e) {
            throw new RuntimeException('系統繁忙，請稍後再試', 0, $e);
        } catch (\Exception $e) {
            report($e);

            return DB::transaction(function () use ($customer, $callback) {
                $account = $this->lockOrCreatePointAccount($customer);
                return $callback($account);
            }, 3);
        }
    }

    /**
     * 取得或建立客戶的點數帳戶，並持有 DB 行鎖以保護帳戶狀態初始化與更新
     *
     * 處理並發建立帳戶的 race condition，依賴資料庫的 unique(['tenant_id', 'customer_id']) 約束
     * 永遠回傳已持有資料庫行鎖的帳戶實體
     */
    protected function lockOrCreatePointAccount(Customer $customer): PointAccount
    {
        $currentTenantId = $this->tenantResolver->getCurrentTenantId();
        if ($currentTenantId !== null) {
            $this->assertTenantConsistency(
                $customer->tenant_id,
                $currentTenantId,
                '無法操作其他租戶的客戶'
            );
        }

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

        try {
            $customer->pointAccount()->create([
                'tenant_id' => $customer->tenant_id,
                'balance' => 0,
                'total_earned' => 0,
                'total_redeemed' => 0,
            ]);

            $account = $customer->pointAccount()->lockForUpdate()->firstOrFail();

            $this->assertTenantConsistency(
                $account->tenant_id,
                $customer->tenant_id,
                '點數帳戶租戶與客戶租戶不一致'
            );
            return $account;
        } catch (UniqueConstraintViolationException $e) {
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

        return $this->executeWithCustomerPointStateLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore + $amount;

            $account->update([
                'balance' => $balanceAfter,
                'total_earned' => $account->total_earned + $amount,
            ]);

            $transaction = $this->recordPointTransaction(
                PointTransaction::TYPE_EARN,
                $account,
                $amount,
                $balanceBefore,
                $balanceAfter,
                $description,
                $reference,
                $createdBy
            );

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
     * 從已鎖定的點數帳戶中扣除點數，依照 FIFO 批次順序消耗可用點數
     *
     * 呼叫者契約：
     * - 必須已取得 Customer 的 Redis 鎖
     * - 必須已取得 PointAccount 的 DB 行鎖 (lockForUpdate)
     * - 必須已處於 DB transaction 中
     * - 必須已驗證 account->balance >= $amount
     *
     * Point Lot FIFO 消費設計：
     * - 依 earned_at 排序：確保先獲得的點數先被消耗，符合先進先出原則
     * - id 作為 deterministic tie-breaker：處理同一毫秒獲得的多個批次，保證消耗順序永遠一致
     * - 逐筆消費（while loop + 每次只lock一個批次）：避免一次鎖定所有可用的 Lot，降低鎖範圍和死鎖風險
     * - 鎖範圍與交易範圍一致：所有批次鎖都在同一 DB transaction 中釋放
     *
     * Defense-in-depth against negative balance：最後的 WHERE balance >= $amount 原子更新
     * 是最後一道防線，即使前面的驗證都失效，資料庫層仍能阻止負餘額。
     */
    public function deductPointsFromAccount(PointAccount $account, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        $balanceBefore = $account->balance;

        $this->consumeAvailablePointLotsFIFO($account, $amount);

        $balanceAfter = $balanceBefore - $amount;

        $updated = $account->where('id', $account->id)
            ->where('balance', '>=', $amount)
            ->update([
                'balance' => $balanceAfter,
                'total_redeemed' => $account->total_redeemed + $amount,
            ]);

        if ($updated === 0) {
            throw new RuntimeException('點數餘額不足，交易失敗');
        }

        return $this->recordPointTransaction(
            PointTransaction::TYPE_REDEEM,
            $account,
            $amount,
            $balanceBefore,
            $balanceAfter,
            $description,
            $reference,
            $createdBy
        );
    }

    protected function consumeAvailablePointLotsFIFO(PointAccount $account, int $amount): void
    {
        $remainingToDeduct = $amount;

        while ($remainingToDeduct > 0) {
            $lot = $account->pointLots()
                ->where('remaining_points', '>', 0)
                ->where(function ($q) {
                    $q->whereNull('expired_at')
                        ->orWhere('expired_at', '>', now());
                })
                ->orderBy('earned_at', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->first();

            if (!$lot) {
                throw new RuntimeException('點數批次不足，無法完成兌換');
            }

            $deductFromLot = min($lot->remaining_points, $remainingToDeduct);
            $lot->update([
                'remaining_points' => $lot->remaining_points - $deductFromLot,
            ]);
            $remainingToDeduct -= $deductFromLot;
        }
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

        return $this->executeWithCustomerPointStateLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
            if ($account->balance < $amount) {
                throw new RuntimeException('點數餘額不足');
            }

            return $this->deductPointsFromAccount($account, $amount, $description, $reference, $createdBy);
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

        return $this->executeWithCustomerPointStateLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
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

            if ($amount < 0) {
                $remainingToDeduct = $absAmount;

                $lots = $account->pointLots()
                    ->where('remaining_points', '>', 0)
                    ->where(function ($q) {
                        $q->whereNull('expired_at')
                            ->orWhere('expired_at', '>', now());
                    })
                    ->orderBy('earned_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($lots as $lot) {
                    if ($remainingToDeduct <= 0) {
                        break;
                    }

                    $deductFromLot = min($lot->remaining_points, $remainingToDeduct);
                    $lot->update([
                        'remaining_points' => $lot->remaining_points - $deductFromLot,
                    ]);
                    $remainingToDeduct -= $deductFromLot;
                }

                if ($remainingToDeduct > 0) {
                    throw new RuntimeException('點數批次不足，無法完成調整');
                }

                $updated = $account->where('id', $account->id)
                    ->where('balance', '>=', $absAmount)
                    ->update($updateData);
                if ($updated === 0) {
                    throw new RuntimeException('點數餘額不足，交易失敗');
                }
            } else {
                $account->update($updateData);
            }

            $transaction = $this->recordPointTransaction(
                PointTransaction::TYPE_ADJUST,
                $account,
                $amount,
                $balanceBefore,
                $balanceAfter,
                $description,
                $reference,
                $createdBy
            );

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
     * 確保客戶的點數帳戶存在
     * 
     * 如果客戶還沒有點數帳戶，會自動建立；如果已存在，直接回傳
     * 保持與其他點數操作相同的locking和transaction機制
     */
    public function ensurePointAccount(Customer $customer): PointAccount
    {
        return $this->executeWithCustomerPointStateLock($customer, function (PointAccount $account) {
            return $account;
        });
    }

    /**
     * 退款點數（將兌換的點數退回）
     * 
     * Refund restores available balance without erasing historical redemption volume.
     * total_redeemed 是歷史累計指標，永遠只增不減，保留完整的審計軌跡。
     * 
     * 重複退款保護：
     * - reference_type + reference_id 記錄原始兌換交易
     * - 在 Customer Lock 保護下檢查是否已存在相同 reference 的退款記錄
     * - 所有操作在同一 DB transaction 中執行，保證原子性
     * - 退款會建立新的 PointLot，不恢復原有已消耗批次，保持歷史可追蹤性
     */
    public function refund(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        $this->validatePositiveAmount($amount, '退款點數必須為正數');

        // 如果參考是原始的 REDEEM 交易，先檢查是否已經退款過以防止重複退款
        // 這個檢查在 executeWithCustomerPointStateLock 之外執行，提早失敗；但真正的原子性保證由交易內的檢查提供
        if ($reference instanceof PointTransaction) {
            if ($reference->type !== PointTransaction::TYPE_REDEEM) {
                throw new RuntimeException('退款只能針對兌換交易');
            }
        }

        return $this->executeWithCustomerPointStateLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
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

            // Refund restores available balance without erasing historical redemption volume.
            // total_redeemed 保持不變，作為歷史累計指標，確保 ledger 可完整追蹤
            $account->update([
                'balance' => $balanceAfter,
            ]);

            $transaction = $this->recordPointTransaction(
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
        return $this->executeWithCustomerPointStateLock($customer, function (PointAccount $account) {
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

            $transaction = $this->recordPointTransaction(
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

        return $this->executeWithCustomerPointStateLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
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

            return $this->recordPointTransaction(
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
    protected function recordPointTransaction(
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
        // lockOrCreatePointAccount() 已經保證帳戶與客戶的租戶一致性

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
