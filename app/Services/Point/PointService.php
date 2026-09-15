<?php

namespace App\Services\Point;

use App\Models\Customer;
use App\Models\PointAccount;
use App\Models\PointTransaction;
use Illuminate\Support\Facades\DB;
use App\Support\Tenancy\TenantResolver;

class PointService
{
    public function __construct(protected TenantResolver $tenantResolver) {}

    /**
     * 為客戶新增點數
     */
    public function earn(Customer $customer, int $amount, string $description = null, $reference = null, int $createdBy = null): PointTransaction
    {
        return DB::transaction(function () use ($customer, $amount, $description, $reference, $createdBy) {
            // 先檢查帳戶是否存在，不存在則建立
            $existingAccount = $customer->pointAccount()->first();

            if (!$existingAccount) {
                $account = $customer->pointAccount()->create([
                    'balance' => 0,
                    'total_earned' => 0,
                    'total_redeemed' => 0,
                ]);
            } else {
                // 使用行鎖防止並發更新問題
                $account = $customer->pointAccount()->lockForUpdate()->firstOrFail();
            }

            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore + $amount;

            // 更新帳戶餘額
            $account->update([
                'balance' => $balanceAfter,
                'total_earned' => $account->total_earned + $amount,
            ]);

            // 建立交易記錄
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
    public function redeem(Customer $customer, int $amount, string $description = null, $reference = null, int $createdBy = null): PointTransaction
    {
        return DB::transaction(function () use ($customer, $amount, $description, $reference, $createdBy) {
            // 使用行鎖防止並發更新問題
            /** @var PointAccount $account */
            $account = $customer->pointAccount()->lockForUpdate()->firstOrFail();

            if ($account->balance < $amount) {
                throw new \RuntimeException('Insufficient points to redeem');
            }

            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore - $amount;

            // 更新帳戶餘額
            $account->update([
                'balance' => $balanceAfter,
                'total_redeemed' => $account->total_redeemed + $amount,
            ]);

            // 建立交易記錄
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
    public function adjust(Customer $customer, int $amount, string $description = null, $reference = null, int $createdBy = null): PointTransaction
    {
        return DB::transaction(function () use ($customer, $amount, $description, $reference, $createdBy) {
            // 先檢查帳戶是否存在，不存在則建立
            $existingAccount = $customer->pointAccount()->first();

            if (!$existingAccount) {
                $account = $customer->pointAccount()->create([
                    'balance' => 0,
                    'total_earned' => 0,
                    'total_redeemed' => 0,
                ]);
            } else {
                // 使用行鎖防止並發更新問題
                $account = $customer->pointAccount()->lockForUpdate()->firstOrFail();
            }

            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore + $amount;

            // 更新帳戶餘額
            $account->update(['balance' => $balanceAfter]);

            if ($amount > 0) {
                $account->increment('total_earned', $amount);
            } else {
                $account->increment('total_redeemed', abs($amount));
            }

            // 建立交易記錄
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
     * 建立點數交易記錄
     */
    protected function createTransaction(
        string $type,
        PointAccount $account,
        int $amount,
        int $balanceBefore,
        int $balanceAfter,
        ?string $description,
        $reference = null,
        ?int $createdBy = null
    ): PointTransaction {
        $transaction = new PointTransaction([
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'description' => $description,
            'created_by' => $createdBy,
        ]);

        $transaction->customer()->associate($account->customer);
        $transaction->pointAccount()->associate($account);

        if ($reference) {
            $transaction->reference()->associate($reference);
        }

        $transaction->save();

        return $transaction;
    }

    /**
     * 退款點數（將兌換的點數退回）
     */
    public function refund(Customer $customer, int $amount, string $description = null, $reference = null, int $createdBy = null): PointTransaction
    {
        return DB::transaction(function () use ($customer, $amount, $description, $reference, $createdBy) {
            // 使用行鎖防止並發更新問題
            /** @var PointAccount $account */
            $account = $customer->pointAccount()->lockForUpdate()->firstOrFail();

            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore + $amount;

            // 更新帳戶餘額，退款視為退回點數，所以從 total_redeemed 中扣除
            $account->update([
                'balance' => $balanceAfter,
                'total_redeemed' => max(0, $account->total_redeemed - $amount),
            ]);

            // 建立交易記錄
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
    public function expire(Customer $customer, int $amount, string $description = null, $reference = null, int $createdBy = null): PointTransaction
    {
        return DB::transaction(function () use ($customer, $amount, $description, $reference, $createdBy) {
            // 使用行鎖防止並發更新問題
            /** @var PointAccount $account */
            $account = $customer->pointAccount()->lockForUpdate()->firstOrFail();

            if ($account->balance < $amount) {
                throw new \RuntimeException('Insufficient points to expire');
            }

            $balanceBefore = $account->balance;
            $balanceAfter = $balanceBefore - $amount;

            // 更新帳戶餘額
            $account->update([
                'balance' => $balanceAfter,
            ]);

            // 建立交易記錄
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
}
