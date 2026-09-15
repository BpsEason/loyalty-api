<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customer\CustomerStoreRequest;
use App\Http\Requests\Api\V1\Customer\CustomerUpdateRequest;
use App\Http\Resources\Api\V1\Customer\CustomerResource;
use App\Models\Customer;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Customer REST API Controller
 */
class CustomerController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Customer::class, 'customer');
    }

    #[OA\Get(
        path: "/api/v1/customers",
        summary: "Get list of customers",
        security: [["bearerAuth" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Customers retrieved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Customers retrieved successfully"),
                        new OA\Property(property: "data", type: "array", items: new OA\Items(ref: "#/components/schemas/Customer"))
                    ]
                )
            )
        ]
    )]
    public function index(Request $request)
    {
        $customers = Customer::with('tenant')->paginate();

        return ApiResponse::success(
            data: CustomerResource::collection($customers),
            message: 'Customers retrieved successfully'
        );
    }

    #[OA\Post(
        path: "/api/v1/customers",
        summary: "Create a new customer",
        security: [["bearerAuth" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name", "email"],
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "email", type: "string", format: "email"),
                    new OA\Property(property: "phone", type: "string", nullable: true),
                    new OA\Property(property: "metadata", type: "object", nullable: true)
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: "Customer created successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Customer created successfully"),
                        new OA\Property(property: "data", ref: "#/components/schemas/Customer")
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: "Validation error",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: false),
                        new OA\Property(property: "message", type: "string", example: "Validation failed"),
                        new OA\Property(property: "errors", type: "object")
                    ]
                )
            )
        ]
    )]
    public function store(CustomerStoreRequest $request)
    {
        $customer = Customer::create($request->validated());

        return ApiResponse::success(
            data: new CustomerResource($customer),
            message: 'Customer created successfully',
            status: 201
        );
    }

    #[OA\Get(
        path: "/api/v1/customers/{customer}",
        summary: "Get a specific customer",
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "customer", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Customer retrieved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Customer retrieved successfully"),
                        new OA\Property(property: "data", ref: "#/components/schemas/Customer")
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: "Customer not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: false),
                        new OA\Property(property: "message", type: "string", example: "Customer not found")
                    ]
                )
            )
        ]
    )]
    public function show(Customer $customer)
    {
        $customer->load('tenant');

        return ApiResponse::success(
            data: new CustomerResource($customer),
            message: 'Customer retrieved successfully'
        );
    }

    #[OA\Put(
        path: "/api/v1/customers/{customer}",
        summary: "Update a customer",
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "customer", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "email", type: "string", format: "email"),
                    new OA\Property(property: "phone", type: "string", nullable: true),
                    new OA\Property(property: "metadata", type: "object", nullable: true)
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Customer updated successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Customer updated successfully"),
                        new OA\Property(property: "data", ref: "#/components/schemas/Customer")
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: "Customer not found"
            ),
            new OA\Response(
                response: 422,
                description: "Validation error"
            )
        ]
    )]
    public function update(CustomerUpdateRequest $request, Customer $customer)
    {
        $customer->update($request->validated());

        return ApiResponse::success(
            data: new CustomerResource($customer),
            message: 'Customer updated successfully'
        );
    }

    #[OA\Delete(
        path: "/api/v1/customers/{customer}",
        summary: "Delete a customer",
        security: [["bearerAuth" => []]],
        parameters: [
            new OA\Parameter(name: "customer", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Customer deleted successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Customer deleted successfully"),
                        new OA\Property(property: "data", type: "null", nullable: true)
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: "Customer not found"
            )
        ]
    )]
    public function destroy(Customer $customer)
    {
        $customer->delete();

        return ApiResponse::success(
            data: null,
            message: 'Customer deleted successfully'
        );
    }
}
