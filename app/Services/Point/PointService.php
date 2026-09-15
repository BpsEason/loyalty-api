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
     * 取得客戶的鎖定鍵
     */
    protected function getLockKey(Customer $customer): string
    {
        return sprintf('point_customer:%d:tenant:%d', $customer->id, $customer->tenant_id);
    }

    /**
     * 在鎖定與交易中執行操作
     */
    protected function executeInLock(Customer $customer, callable $callback): PointTransaction
    {
        $lockKey = $this->getLockKey($customer);
        $lock = Cache::lock($lockKey, $this->lockTTL);

        try {
            // 使用 block 自旋等待，自動處理鎖釋放
            return $lock->block($this->lockWaitSeconds, function () use ($customer, $callback) {
                return DB::transaction(function () use ($customer, $callback) {
                    $account = $this->getOrCreatePointAccount($customer);

                    return $callback($account);
                });
            });
        } catch (LockTimeoutException $e) {
            throw new RuntimeException('系統繁忙，請稍後再試', 0, $e);
        }
    }

    /**
     * 取得或建立客戶的點數帳戶（保證回傳持有 lockForUpdate 的實體）
     */
    protected function getOrCreatePointAccount(Customer $customer): PointAccount
    {
        // 1. 先嘗試取得現有帳戶並加上行鎖 (FOR UPDATE)
        /** @var PointAccount|null $account */
        $account = $customer->pointAccount()->lockForUpdate()->first();

        if ($account) {
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
            return $customer->pointAccount()->lockForUpdate()->firstOrFail();
        } catch (UniqueConstraintViolationException $e) {
            // 並發狀況下若被其他請求先建立，再次以 lockForUpdate 鎖定讀取
            return $customer->pointAccount()->lockForUpdate()->firstOrFail();
        }
    }

    /**
     * 為客戶新增點數
     */
    public function earn(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        if ($amount <= 0) {
            throw new RuntimeException('點數必須為正數');
        }

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
     */
    public function redeem(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        if ($amount <= 0) {
            throw new RuntimeException('點數必須為正數');
        }

        return $this->executeInLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
            if ($account->balance < $amount) {
                throw new RuntimeException('點數餘額不足');
            }

            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore - $amount;

            $account->update([
                'balance' => $balanceAfter,
                'total_redeemed' => $account->total_redeemed + $amount,
            ]);

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

            $account->update($updateData);

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
     */
    public function refund(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        if ($amount <= 0) {
            throw new RuntimeException('退款點數必須為正數');
        }

        return $this->executeInLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore + $amount;

            $account->update([
                'balance' => $balanceAfter,
                'total_redeemed' => max(0, $account->total_redeemed - $amount),
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
     */
    public function expire(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        if ($amount <= 0) {
            throw new RuntimeException('過期點數必須為正數');
        }

        return $this->executeInLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
            if ($account->balance < $amount) {
                throw new RuntimeException('點數餘額不足');
            }

            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore - $amount;

            $account->update(['balance' => $balanceAfter]);

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
        $transaction = new PointTransaction([
            'tenant_id' => $account->tenant_id,
            'customer_id' => $account->customer_id, // 直接填入 FK，避免觸發 Customer Lazy Loading
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
