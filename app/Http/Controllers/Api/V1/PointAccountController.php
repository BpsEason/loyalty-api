<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PointAccount\PointAccountResource;
use App\Models\Customer;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Point Account REST API Controller
 */
class PointAccountController extends Controller
{
    #[OA\Get(
        path: "/api/v1/customers/{customer}/points",
        summary: "Get point account for a specific customer",
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "customer", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Point account retrieved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Point account retrieved successfully"),
                        new OA\Property(property: "data", ref: "#/components/schemas/PointAccount")
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: "Point account not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: false),
                        new OA\Property(property: "message", type: "string", example: "Point account not found")
                    ]
                )
            )
        ]
    )]
    public function show(Request $request, Customer $customer)
    {
        $pointAccount = $customer->pointAccount;

        if (!$pointAccount) {
            return ApiResponse::error('Point account not found', null, [], 404);
        }

        return ApiResponse::success(
            data: new PointAccountResource($pointAccount),
            message: 'Point account retrieved successfully'
        );
    }
}
