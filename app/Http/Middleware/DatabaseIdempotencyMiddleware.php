<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\Response;
use RuntimeException;

class DatabaseIdempotencyMiddleware
{
    public function __construct(protected TenantContext $tenantContext) {}

    /**
     * 處理傳入請求
     */
    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        // 只對POST請求強制冪等性，其他請求直接通過
        if (!$idempotencyKey || !$request->isMethod('POST')) {
            return $next($request);
        }

        // 獲取當前租戶
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            throw new RuntimeException('無法解析租戶，冪等性處理失敗');
        }

        // 計算請求體雜湊
        $requestBody = $request->getContent();
        $requestHash = hash('sha256', $requestBody);

        // 開始處理冪等性邏輯
        return $this->processIdempotentRequest(
            $request,
            $next,
            $tenant->id,
            $idempotencyKey,
            $requestHash
        );
    }

    /**
     * 處理冪等性請求的核心邏輯
     */
    protected function processIdempotentRequest(Request $request, Closure $next, int $tenantId, string $idempotencyKey, string $requestHash): Response
    {
        // 使用資料庫事務確保冪等性記錄的原子性
        return DB::transaction(function () use ($request, $next, $tenantId, $idempotencyKey, $requestHash) {
            // 先查詢是否存在現有記錄，使用lockForUpdate避免並發競爭
            /** @var IdempotencyKey|null $record */
            $record = IdempotencyKey::where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            // 如果記錄存在，處理各種狀況
            if ($record) {
                return $this->handleExistingRecord($record, $requestHash);
            }

            // 記錄不存在，建立新的processing狀態記錄
            /** @var IdempotencyKey $record */
            $record = IdempotencyKey::create([
                'tenant_id' => $tenantId,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'status' => IdempotencyKey::STATUS_PROCESSING,
                'started_at' => now(),
            ]);

            try {
                // 執行實際請求
                /** @var Response $response */
                $response = $next($request);

                // 請求成功完成，更新記錄狀態
                $record->update([
                    'status' => IdempotencyKey::STATUS_COMPLETED,
                    'response_body' => $response->getContent(),
                    'response_status' => $response->getStatusCode(),
                ]);

                return $response;
            } catch (UniqueConstraintViolationException $e) {
                // 併發場景下，另一個請求先插入了相同的冪等性鍵，此時查詢現有記錄並返回
                $existingRecord = IdempotencyKey::where('tenant_id', $tenantId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->handleExistingRecord($existingRecord, $requestHash);
            } catch (\Exception $e) {
                // 請求失敗，標記記錄為失敗
                $record->update([
                    'status' => IdempotencyKey::STATUS_FAILED,
                    'response_body' => json_encode([
                        'error' => $e->getMessage(),
                        'message' => '請求處理失敗'
                    ]),
                    'response_status' => 500,
                ]);

                throw $e;
            }
        }, 3); // 加入死鎖重試
    }

    /**
     * 處理現有的冪等性記錄
     */
    protected function handleExistingRecord(IdempotencyKey $record, string $requestHash): Response
    {
        // 檢查請求體是否一致
        if ($record->request_hash !== $requestHash) {
            return response()->json([
                'message' => '冪等性鍵已被使用且請求內容不一致',
                'error' => 'Idempotency key conflict'
            ], 409);
        }

        // 根據狀態處理
        return match ($record->status) {
            IdempotencyKey::STATUS_COMPLETED => $this->returnCompletedResponse($record),
            IdempotencyKey::STATUS_PROCESSING => $this->handleProcessingRecord($record),
            IdempotencyKey::STATUS_FAILED => $this->handleFailedRecord($record, $requestHash),
            default => throw new RuntimeException('未知的冪等性記錄狀態'),
        };
    }

    /**
     * 返回已完成的請求回應
     */
    protected function returnCompletedResponse(IdempotencyKey $record): Response
    {
        return response()->json(
            array_merge(
                json_decode($record->response_body, true) ?: [],
                ['message' => '點數交易已取回（冪等性重試）', 'idempotent' => true]
            ),
            $record->response_status ?: 200
        );
    }

    /**
     * 處理processing狀態的記錄
     */
    protected function handleProcessingRecord(IdempotencyKey $record): Response
    {
        // 檢查是否過期（超過5分鐘）
        if ($record->isStale()) {
            // 標記為失敗，允許下次重試
            $record->update([
                'status' => IdempotencyKey::STATUS_FAILED,
                'response_body' => json_encode(['error' => '請求處理超時']),
                'response_status' => 408,
            ]);

            return response()->json([
                'message' => '先前的請求處理超時，請重新發送請求',
                'error' => 'Request timeout'
            ], 408);
        }

        // 請求仍在處理中
        return response()->json([
            'message' => '請求正在處理中，請稍後再查詢',
            'error' => 'Request still processing'
        ], 425);
    }

    /**
     * 處理失敗狀態的記錄 - 允許重試，建立新的processing記錄
     */
    protected function handleFailedRecord(IdempotencyKey $record, string $requestHash): Response
    {
        // 更新現有記錄為重新處理
        $record->update([
            'status' => IdempotencyKey::STATUS_PROCESSING,
            'started_at' => now(),
        ]);

        try {
            // 重新執行請求
            $response = app()->handle(request());

            // 更新為完成
            $record->update([
                'status' => IdempotencyKey::STATUS_COMPLETED,
                'response_body' => $response->getContent(),
                'response_status' => $response->getStatusCode(),
            ]);

            return $response;
        } catch (\Exception $e) {
            $record->update([
                'status' => IdempotencyKey::STATUS_FAILED,
                'response_body' => json_encode(['error' => $e->getMessage()]),
                'response_status' => 500,
            ]);
            throw $e;
        }
    }
}
