<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PointTransaction\PointTransactionStoreRequest;
use App\Http\Resources\Api\V1\PointTransaction\PointTransactionResource;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Services\Point\PointService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Point Transaction REST API Controller
 */
class PointTransactionController extends Controller
{
    public function __construct()
    {
        // 移除不存在的 authorizeResource 方法呼叫
    }

    #[OA\Get(
        path: '/customers/{customer}/point-transactions',
        summary: 'Get list of point transactions for a customer',
        tags: ['Point Transactions'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Point transactions retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Point transactions retrieved successfully'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/PointTransaction')
                        ),
                    ]
                )
            ),
        ]
    )]
    public function index(Request $request, Customer $customer, \App\Support\Tenancy\TenantContext $tenantContext): JsonResponse
    {
        // 確保客戶屬於當前租戶
        $tenant = $tenantContext->getTenant();
        // 只有當租戶上下文存在時才驗證，否則依賴模型層的全域範圍
        if ($tenant && $customer->tenant_id !== $tenant->id) {
            return ApiResponse::error('Customer not found', null, [], 404);
        }

        $perPage = min($request->input('per_page', 15), 100);
        $transactions = $customer->pointTransactions()->latest()->paginate($perPage);

        return ApiResponse::success(
            data: PointTransactionResource::collection($transactions),
            message: 'Point transactions retrieved successfully'
        );
    }

    #[OA\Get(
        path: '/customers/{customer}/point-transactions/{pointTransaction}',
        summary: 'Get a specific point transaction',
        tags: ['Point Transactions'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'pointTransaction', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Point transaction retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Point transaction retrieved successfully'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/PointTransaction'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Point transaction not found',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'Point transaction not found'),
                    ]
                )
            ),
        ]
    )]
    public function show(Request $request, Customer $customer, PointTransaction $pointTransaction, \App\Support\Tenancy\TenantContext $tenantContext): JsonResponse
    {
        // 確保客戶屬於當前租戶
        $tenant = $tenantContext->getTenant();
        // 只有當租戶上下文存在時才驗證，否則依賴模型層的全域範圍
        if ($tenant && $customer->tenant_id !== $tenant->id) {
            return ApiResponse::error('Customer not found', null, [], 404);
        }

        // 確保點數交易屬於該 Customer，避免跨權限讀取漏洞
        if ($pointTransaction->customer_id !== $customer->id) {
            return ApiResponse::error('Point transaction not found', null, [], 404);
        }

        return ApiResponse::success(
            data: new PointTransactionResource($pointTransaction),
            message: 'Point transaction retrieved successfully'
        );
    }

    #[OA\Post(
        path: '/customers/{customer}/point-transactions',
        summary: 'Create a new point transaction',
        tags: ['Point Transactions'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Tenant-ID', in: 'header', required: false, schema: new OA\Schema(type: 'integer'), description: 'Tenant ID for external system integration'),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string'), description: 'Unique key to prevent duplicate transactions'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'amount'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', example: 'earn', enum: ['earn', 'redeem', 'adjust', 'refund', 'expire']),
                    new OA\Property(property: 'amount', type: 'integer', example: 100, minimum: 1),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Purchase reward'),
                    new OA\Property(property: 'reference', type: 'string', nullable: true, example: 'ORDER-12345', description: 'External order reference ID'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Point transaction created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Point transaction created successfully'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/PointTransaction'),
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error or business logic error',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'Insufficient points to redeem'),
                        new OA\Property(property: 'errors', type: 'object'),
                    ]
                )
            ),
            new OA\Response(
                response: 409,
                description: 'Conflict',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'Transaction could not be processed'),
                    ]
                )
            ),
        ]
    )]
    public function store(PointTransactionStoreRequest $request, Customer $customer, PointService $pointService, \App\Support\Tenancy\TenantContext $tenantContext): JsonResponse
    {
        // 確保客戶屬於當前租戶
        $tenant = $tenantContext->getTenant();
        // 只有當租戶上下文存在時才驗證，否則依賴模型層的全域範圍
        if ($tenant && $customer->tenant_id !== $tenant->id) {
            return ApiResponse::error('Customer not found', null, [], 404);
        }

        $validated = $request->validated();

        try {
            $method = match ($validated['type']) {
                PointTransaction::TYPE_EARN => 'earn',
                PointTransaction::TYPE_REDEEM => 'redeem',
                PointTransaction::TYPE_ADJUST => 'adjust',
                PointTransaction::TYPE_REFUND => 'refund',
                PointTransaction::TYPE_EXPIRE => 'expire',
                default => throw new \InvalidArgumentException('Invalid transaction type'),
            };

            $transaction = $pointService->{$method}(
                customer: $customer,
                amount: $validated['amount'],
                description: $validated['description'] ?? null,
                reference: $validated['reference'] ?? null,
                createdBy: auth()->id()
            );

            return ApiResponse::success(
                data: new PointTransactionResource($transaction),
                message: 'Point transaction created successfully',
                status: 201
            );
        } catch (\RuntimeException $e) {
            return ApiResponse::error(
                message: $e->getMessage(),
                status: 422
            );
        }
    }

    #[OA\Post(
        path: '/customers/{customer}/points/redeem',
        summary: 'Redeem points for a customer (POS-specific endpoint)',
        tags: ['Point Transactions'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Tenant-ID', in: 'header', required: false, schema: new OA\Schema(type: 'integer'), description: 'Tenant ID for external system integration'),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string'), description: 'Unique key to prevent duplicate transactions'),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount'],
                properties: [
                    new OA\Property(property: 'amount', type: 'integer', example: 100, minimum: 1),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Redeemed at POS'),
                    new OA\Property(property: 'reference', type: 'string', nullable: true, example: 'POS-ORDER-12345', description: 'POS order reference ID'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Points redeemed successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Points redeemed successfully'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/PointTransaction'),
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error or insufficient points',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'Insufficient points to redeem'),
                    ]
                )
            ),
        ]
    )]
    public function redeem(PointTransactionStoreRequest $request, Customer $customer, PointService $pointService, \App\Support\Tenancy\TenantContext $tenantContext): JsonResponse
    {
        // 確保客戶屬於當前租戶
        $tenant = $tenantContext->getTenant();
        // 只有當租戶上下文存在時才驗證，否則依賴模型層的全域範圍
        if ($tenant && $customer->tenant_id !== $tenant->id) {
            return ApiResponse::error('Customer not found', null, [], 404);
        }

        $validated = $request->validated();

        try {
            $transaction = $pointService->redeem(
                customer: $customer,
                amount: $validated['amount'],
                description: $validated['description'] ?? 'Redeemed at POS',
                reference: $validated['reference'] ?? null,
                createdBy: auth()->id()
            );

            return ApiResponse::success(
                data: new PointTransactionResource($transaction),
                message: 'Points redeemed successfully',
                status: 201
            );
        } catch (\RuntimeException $e) {
            return ApiResponse::error(
                message: $e->getMessage(),
                status: 422
            );
        }
    }
}
