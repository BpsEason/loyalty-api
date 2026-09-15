<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PointTransaction\PointTransactionStoreRequest;
use App\Http\Resources\Api\V1\PointTransaction\PointTransactionResource;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Services\Point\PointService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Point Transaction REST API Controller
 */
class PointTransactionController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(PointTransaction::class, 'pointTransaction');
    }

    #[OA\Get(
        path: "/api/v1/customers/{customer}/point-transactions",
        summary: "Get list of point transactions for a customer",
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "customer", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Point transactions retrieved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Point transactions retrieved successfully"),
                        new OA\Property(property: "data", type: "array", items: new OA\Items(ref: "#/components/schemas/PointTransaction"))
                    ]
                )
            )
        ]
    )]
    public function index(Request $request, Customer $customer)
    {
        $transactions = $customer->pointTransactions()->latest()->paginate();

        return ApiResponse::success(
            data: PointTransactionResource::collection($transactions),
            message: 'Point transactions retrieved successfully'
        );
    }

    #[OA\Get(
        path: "/api/v1/customers/{customer}/point-transactions/{pointTransaction}",
        summary: "Get a specific point transaction",
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "customer", in: "path", required: true, schema: new OA\Schema(type: "integer")),
            new OA\Parameter(name: "pointTransaction", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Point transaction retrieved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Point transaction retrieved successfully"),
                        new OA\Property(property: "data", ref: "#/components/schemas/PointTransaction")
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: "Point transaction not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: false),
                        new OA\Property(property: "message", type: "string", example: "Point transaction not found")
                    ]
                )
            )
        ]
    )]
    public function show(Request $request, Customer $customer, PointTransaction $pointTransaction)
    {
        return ApiResponse::success(
            data: new PointTransactionResource($pointTransaction),
            message: 'Point transaction retrieved successfully'
        );
    }

    #[OA\Post(
        path: "/api/v1/customers/{customer}/point-transactions",
        summary: "Create a new point transaction",
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "customer", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["type", "amount"],
                properties: [
                    new OA\Property(property: "type", type: "string", example: "earn", enum: ["earn", "redeem", "adjust", "refund", "expire"]),
                    new OA\Property(property: "amount", type: "integer", example: 100, minimum: 1),
                    new OA\Property(property: "description", type: "string", nullable: true, example: "Purchase reward")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: "Point transaction created successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Point transaction created successfully"),
                        new OA\Property(property: "data", ref: "#/components/schemas/PointTransaction")
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: "Validation error or business logic error",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: false),
                        new OA\Property(property: "message", type: "string", example: "Insufficient points to redeem"),
                        new OA\Property(property: "errors", type: "object")
                    ]
                )
            ),
            new OA\Response(
                response: 409,
                description: "Conflict",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: false),
                        new OA\Property(property: "message", type: "string", example: "Transaction could not be processed")
                    ]
                )
            )
        ]
    )]
    public function store(PointTransactionStoreRequest $request, Customer $customer, PointService $pointService)
    {
        $validated = $request->validated();

        try {
            $createdBy = auth()->id();
            $transaction = match ($validated['type']) {
                PointTransaction::TYPE_EARN => $pointService->earn(
                    $customer,
                    $validated['amount'],
                    $validated['description'] ?? null,
                    null,
                    $createdBy
                ),
                PointTransaction::TYPE_REDEEM => $pointService->redeem(
                    $customer,
                    $validated['amount'],
                    $validated['description'] ?? null,
                    null,
                    $createdBy
                ),
                PointTransaction::TYPE_ADJUST => $pointService->adjust(
                    $customer,
                    $validated['amount'],
                    $validated['description'] ?? null,
                    null,
                    $createdBy
                ),
                PointTransaction::TYPE_REFUND => $pointService->refund(
                    $customer,
                    $validated['amount'],
                    $validated['description'] ?? null,
                    null,
                    $createdBy
                ),
                PointTransaction::TYPE_EXPIRE => $pointService->expire(
                    $customer,
                    $validated['amount'],
                    $validated['description'] ?? null,
                    null,
                    $createdBy
                ),
                default => throw new \RuntimeException('Invalid transaction type'),
            };

            return ApiResponse::success(
                data: new PointTransactionResource($transaction),
                message: 'Point transaction created successfully',
                status: 201
            );
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), null, [], 422);
        } catch (\Exception $e) {
            return ApiResponse::error('Transaction could not be processed', null, [], 409);
        }
    }
}
