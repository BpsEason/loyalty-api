<?php

namespace App\Services\Point;

use App\Events\PointEarned;
use App\Events\PointRedeemed;
use App\Events\PointsUpdated;
use App\Models\Customer;
use App\Models\PointAccount;
use App\Models\PointLot;
use App\Models\PointTransaction;
use App\Services\Outbox\OutboxService;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PointService
{
    protected int $lockWaitSeconds = 10;
    protected int $lockTTL = 20;

    public function __construct(
        protected TenantResolver $tenantResolver,
        protected OutboxService $outboxService
    ) {}

    protected function validatePositiveAmount(int $amount, string $errorMessage): void
    {
        if ($amount <= 0) {
            throw new RuntimeException($errorMessage);
        }
    }

    protected function assertTenantConsistency(int $entityTenantId, int $expectedTenantId, string $errorMessage): void
    {
        if ($entityTenantId !== $expectedTenantId) {
            throw new RuntimeException($errorMessage);
        }
    }

    protected function getCustomerPointStateLockKey(Customer $customer): string
    {
        return sprintf('point_customer:%d:tenant:%d', $customer->id, $customer->tenant_id);
    }

    protected function executeWithCustomerPointStateLock(Customer $customer, callable $callback): mixed
    {
        $lockKey = $this->getCustomerPointStateLockKey($customer);
        $lock = Cache::lock($lockKey, $this->lockTTL);

        try {
            return $lock->block($this->lockWaitSeconds, function () use ($customer, $callback) {
                $this->ensurePointAccountExists($customer);

                return DB::transaction(function () use ($customer, $callback) {
                    // 直接查詢 + 行鎖，找不到就當場建立（徹底消掉 404）
                    $account = PointAccount::query()
                        ->where('customer_id', $customer->id)
                        ->where('tenant_id', $customer->tenant_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$account) {
                        $account = PointAccount::create([
                            'tenant_id' => $customer->tenant_id,
                            'customer_id' => $customer->id,
                            'balance' => 0,
                            'total_earned' => 0,
                            'total_redeemed' => 0,
                        ]);
                        // 重新取得並加上行鎖
                        $account = PointAccount::query()
                            ->where('id', $account->id)
                            ->lockForUpdate()
                            ->firstOrFail();
                    }

                    $this->assertTenantConsistency(
                        $account->tenant_id,
                        $customer->tenant_id,
                        '點數帳戶租戶與客戶租戶不一致'
                    );

                    return $callback($account);
                }, 3);
            });
        } catch (LockTimeoutException $e) {
            throw new RuntimeException('系統繁忙，請稍後再試', 0, $e);
        } catch (\Throwable $e) {
            report($e);
            throw $e;
        }
    }

    protected function ensurePointAccountExists(Customer $customer): void
    {
        $currentTenantId = $this->tenantResolver->getCurrentTenantId();
        if ($currentTenantId !== null) {
            $this->assertTenantConsistency(
                $customer->tenant_id,
                $currentTenantId,
                '無法操作其他租戶的客戶'
            );
        }

        $customer->unsetRelation('pointAccount');

        try {
            PointAccount::query()->firstOrCreate(
                [
                    'tenant_id' => $customer->tenant_id,
                    'customer_id' => $customer->id,
                ],
                [
                    'balance' => 0,
                    'total_earned' => 0,
                    'total_redeemed' => 0,
                ]
            );
        } catch (UniqueConstraintViolationException $e) {
            $customer->unsetRelation('pointAccount');
        }
    }

    protected function dispatchPointsUpdatedAfterCommit(
        int $tenantId,
        int $customerId,
        int $transactionId,
        int $amountDelta,
        int $balanceAfter
    ): void {
        try {
            PointsUpdated::dispatch(
                $tenantId,
                $customerId,
                $transactionId,
                $amountDelta,
                $balanceAfter
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function earn(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        $this->validatePositiveAmount($amount, '點數必須為正數');

        $transaction = $this->executeWithCustomerPointStateLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy, $customer) {
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

            $this->outboxService->recordDomainEvent(new PointEarned(
                $account->tenant_id,
                $customer->id,
                $transaction->id,
                $amount,
                $reference,
                now()->toIso8601String()
            ));

            return $transaction;
        });

        $this->dispatchPointsUpdatedAfterCommit(
            $transaction->tenant_id,
            $transaction->customer_id,
            $transaction->id,
            $amount,
            $transaction->balance_after
        );

        return $transaction;
    }

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

        $transaction = $this->recordPointTransaction(
            PointTransaction::TYPE_REDEEM,
            $account,
            $amount,
            $balanceBefore,
            $balanceAfter,
            $description,
            $reference,
            $createdBy
        );

        $this->outboxService->recordDomainEvent(new PointRedeemed(
            $account->tenant_id,
            $account->customer_id,
            $transaction->id,
            $amount,
            $reference,
            now()->toIso8601String()
        ));


        return $transaction;
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

    public function redeem(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        $this->validatePositiveAmount($amount, '點數必須為正數');

        $transaction = $this->executeWithCustomerPointStateLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
            if ($account->balance < $amount) {
                throw new RuntimeException('點數餘額不足');
            }

            return $this->deductPointsFromAccount($account, $amount, $description, $reference, $createdBy);
        });

        $this->dispatchPointsUpdatedAfterCommit(
            $transaction->tenant_id,
            $transaction->customer_id,
            $transaction->id,
            -$amount,
            $transaction->balance_after
        );

        return $transaction;
    }

    public function adjust(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        if ($amount === 0) {
            throw new RuntimeException('調整點數不能為0');
        }

        $transaction = $this->executeWithCustomerPointStateLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
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

        $this->dispatchPointsUpdatedAfterCommit(
            $transaction->tenant_id,
            $transaction->customer_id,
            $transaction->id,
            $amount,
            $transaction->balance_after
        );

        return $transaction;
    }

    public function ensurePointAccount(Customer $customer): PointAccount
    {
        return $this->executeWithCustomerPointStateLock($customer, function (PointAccount $account) {
            return $account;
        });
    }

    public function refund(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        $this->validatePositiveAmount($amount, '退款點數必須為正數');

        if ($reference instanceof PointTransaction) {
            if ($reference->type !== PointTransaction::TYPE_REDEEM) {
                throw new RuntimeException('退款只能針對兌換交易');
            }
        }

        $transaction = $this->executeWithCustomerPointStateLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
            if ($reference instanceof PointTransaction) {
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

        $this->dispatchPointsUpdatedAfterCommit(
            $transaction->tenant_id,
            $transaction->customer_id,
            $transaction->id,
            $amount,
            $transaction->balance_after
        );

        return $transaction;
    }

    public function expireAllExpiredLots(Customer $customer): array
    {
        $result = $this->executeWithCustomerPointStateLock($customer, function (PointAccount $account) {
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

            foreach ($expiredLots as $lot) {
                $expireAmount = $lot->remaining_points;
                $totalExpireAmount += $expireAmount;
                $lot->update(['remaining_points' => 0]);
            }

            if ($account->balance < $totalExpireAmount) {
                throw new RuntimeException(
                    "Insufficient account balance ({$account->balance}) to expire all expired points ({$totalExpireAmount}) for customer {$account->customer_id}."
                );
            }

            $balanceAfter = $balanceBefore - $totalExpireAmount;
            $account->update(['balance' => $balanceAfter]);

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

        if ($result[1] instanceof PointTransaction) {
            $this->dispatchPointsUpdatedAfterCommit(
                $result[1]->tenant_id,
                $result[1]->customer_id,
                $result[1]->id,
                -$result[0],
                $result[1]->balance_after
            );
        }

        return $result;
    }

    public function expire(Customer $customer, int $amount, ?string $description = null, mixed $reference = null, ?int $createdBy = null): PointTransaction
    {
        $this->validatePositiveAmount($amount, '過期點數必須為正數');

        $transaction = $this->executeWithCustomerPointStateLock($customer, function (PointAccount $account) use ($amount, $description, $reference, $createdBy) {
            if ($account->balance < $amount) {
                throw new RuntimeException('點數餘額不足');
            }

            $balanceBefore = $account->balance;
            $remainingToExpire = $amount;

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
                    'expired_at' => ($lot->remaining_points - $expireFromLot) <= 0 ? now() : $lot->expired_at,
                ]);
                $remainingToExpire -= $expireFromLot;
            }

            if ($remainingToExpire > 0) {
                throw new RuntimeException('點數批次不足，無法完成過期處理');
            }

            $balanceAfter = $balanceBefore - $amount;

            $updated = $account->where('id', $account->id)
                ->where('balance', '>=', $amount)
                ->update(['balance' => $balanceAfter]);

            if ($updated === 0) {
                throw new RuntimeException('點數餘額不足，交易失敗');
            }

            $transaction = $this->recordPointTransaction(
                PointTransaction::TYPE_EXPIRE,
                $account,
                $amount,
                $balanceBefore,
                $balanceAfter,
                $description,
                $reference,
                $createdBy
            );

            return $transaction;
        });

        $this->dispatchPointsUpdatedAfterCommit(
            $transaction->tenant_id,
            $transaction->customer_id,
            $transaction->id,
            -$amount,
            $transaction->balance_after
        );

        return $transaction;
    }

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
        $transaction = new PointTransaction([
            'tenant_id' => $account->tenant_id,
            'customer_id' => $account->customer_id,
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
