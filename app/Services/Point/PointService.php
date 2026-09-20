<?php

namespace App\Services\Point;

use App\Models\Customer;
use App\Models\PointAccount;
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
    protected int $lockWaitSeconds = 5;

    /**
     * 鎖定自動釋放 TTL（秒）
     */
    protected int $lockTTL = 10;

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
    protected function executeInLock(Customer $customer, callable $callback): PointTransaction
    {
        $lockKey = $this->getLockKey($customer);
        $lock = Cache::lock($lockKey, $this->lockTTL);

        try {
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
     */
    public function earn(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        $this->validatePositiveAmount($amount, '點數必須為正數');

        return $this->executeInLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore + $amount;

            $account->update([
                'balance' => $balanceAfter,
                'total_earned' => $account->total_earned + $amount,
            ]);

            return $this->createTransaction(
                PointTransaction::TYPE_EARN,
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
     * 客戶兌換點數
     * 
     * 最重要的並發敏感操作，使用雙重檢查保證餘額不會為負：
     * 1. 行鎖確保串行化讀取
     * 2. 資料庫更新條件 WHERE balance >= amount 作為最終保險
     */
    public function redeem(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        $this->validatePositiveAmount($amount, '點數必須為正數');

        return $this->executeInLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
            if ($account->balance < $amount) {
                throw new RuntimeException('點數餘額不足');
            }

            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore - $amount;

            // The database condition is intentionally retained as a second
            // concurrency guard even though the account row is locked above.
            // This provides defense-in-depth against any scenario where the
            // row lock might not be properly held.
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

            $updateData = ['balance' => $balanceAfter];

            if ($amount > 0) {
                $updateData['total_earned'] = $account->total_earned + $amount;
            } else {
                $updateData['total_redeemed'] = $account->total_redeemed + abs($amount);
            }

            // 如果是扣減點數，使用where條件確保餘額足夠，同樣的 defense-in-depth 策略
            if ($amount < 0) {
                $updated = $account->where('id', $account->id)
                    ->where('balance', '>=', abs($amount))
                    ->update($updateData);

                if ($updated === 0) {
                    throw new RuntimeException('點數餘額不足，交易失敗');
                }
            } else {
                $account->update($updateData);
            }

            return $this->createTransaction(
                PointTransaction::TYPE_ADJUST,
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

            return $this->createTransaction(
                PointTransaction::TYPE_REFUND,
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
     * 點數過期
     * 
     * 獨立的過期操作，保持 domain intent 清晰
     * 不影響 total_redeemed，因為過期不是用戶主動兌換
     */
    public function expire(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        $this->validatePositiveAmount($amount, '過期點數必須為正數');

        return $this->executeInLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
            if ($account->balance < $amount) {
                throw new RuntimeException('點數餘額不足');
            }

            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore - $amount;

            // 同樣使用資料庫條件作為雙重保險
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
