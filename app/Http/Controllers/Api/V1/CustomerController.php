<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customer\CustomerStoreRequest;
use App\Http\Requests\Api\V1\Customer\CustomerUpdateRequest;
use App\Http\Resources\Api\V1\Customer\CustomerResource;
use App\Models\Customer;
use App\Support\Api\ApiResponse;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Customer REST API Controller
 */
class CustomerController extends Controller
{
    public function __construct()
    {
        // 移除不存在的 authorizeResource 方法呼叫
    }

    #[OA\Get(
        path: '/customers',
        summary: 'Get list of customers',
        tags: ['Customers'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Customers retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Customers retrieved successfully'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Customer')
                        ),
                    ]
                )
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $customers = Customer::with('tenant')->paginate();

        return ApiResponse::success(
            data: CustomerResource::collection($customers),
            message: 'Customers retrieved successfully'
        );
    }

    #[OA\Post(
        path: '/customers',
        summary: 'Create a new customer',
        tags: ['Customers'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true),
                    new OA\Property(property: 'metadata', type: 'object', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Customer created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Customer created successfully'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Customer'),
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'Validation failed'),
                        new OA\Property(property: 'errors', type: 'object'),
                    ]
                )
            ),
        ]
    )]
    public function store(CustomerStoreRequest $request): JsonResponse
    {
        $customer = Customer::create($request->validated());

        return ApiResponse::success(
            data: new CustomerResource($customer),
            message: 'Customer created successfully',
            status: 201
        );
    }

    #[OA\Get(
        path: '/customers/{customer}',
        summary: 'Get a specific customer',
        tags: ['Customers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Customer retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Customer retrieved successfully'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Customer'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Customer not found',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'Customer not found'),
                    ]
                )
            ),
        ]
    )]
    public function show(Customer $customer): JsonResponse
    {
        $customer->load('tenant');

        return ApiResponse::success(
            data: new CustomerResource($customer),
            message: 'Customer retrieved successfully'
        );
    }

    #[OA\Get(
        path: '/customers/{customer}/qr-code',
        summary: 'Get customer QR code image as base64 data URI',
        tags: ['Customers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'QR code retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'QR code retrieved successfully'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'member_code', type: 'string', example: 'M001001'),
                                new OA\Property(property: 'qr_code', type: 'string', example: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmci...'),
                            ]
                        ),
                    ]
                )
            ),
        ]
    )]
    public function getQrCode(Customer $customer, \App\Support\Tenancy\TenantContext $tenantContext): JsonResponse
    {
        // 確保客戶屬於當前租戶
        $tenant = $tenantContext->getTenant();
        if ($tenant && $customer->tenant_id !== $tenant->id) {
            return ApiResponse::error('Customer not found', null, [], 404);
        }

        // 建立QR Code渲染器 - 使用SvgImageBackEnd (BaconQRCode 3.x僅支援SVG/Imagick/EPS)
        $renderer = new ImageRenderer(
            new RendererStyle(400),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);

        // 使用customer的qr_token作為QR Code內容
        $qrCodeImage = $writer->writeString($customer->qr_token);

        // 轉換為base64 data URI (SVG格式)
        $base64Image = base64_encode($qrCodeImage);
        $dataUri = 'data:image/svg+xml;base64,' . $base64Image;

        return ApiResponse::success(
            data: [
                'member_code' => $customer->member_code,
                'qr_code' => $dataUri,
            ],
            message: 'QR code retrieved successfully'
        );
    }

    #[OA\Post(
        path: '/customers/identify',
        summary: 'Identify customer by QR token (POS scan endpoint)',
        tags: ['Customers'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['qr_token'],
                properties: [
                    new OA\Property(property: 'qr_token', type: 'string', example: 'abc123xyz...'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Customer identified successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Customer identified successfully'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Customer'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Invalid QR token',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'Invalid or expired QR code'),
                    ]
                )
            ),
        ]
    )]
    public function identifyByQrToken(Request $request, \App\Support\Tenancy\TenantContext $tenantContext): JsonResponse
    {
        $request->validate([
            'qr_token' => 'required|string',
        ]);

        $tenant = $tenantContext->getTenant();
        if (!$tenant) {
            return ApiResponse::error('Tenant context required', null, [], 403);
        }

        $customer = Customer::where('qr_token', $request->qr_token)
            ->where('tenant_id', $tenant->id) // 確保只能識別當前租戶的客戶
            ->first();

        if (!$customer) {
            return ApiResponse::error(
                message: 'Invalid or expired QR code',
                status: 404
            );
        }

        $customer->load('tenant');

        return ApiResponse::success(
            data: new CustomerResource($customer),
            message: 'Customer identified successfully'
        );
    }

    #[OA\Put(
        path: '/customers/{customer}',
        summary: 'Update a customer',
        tags: ['Customers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true),
                    new OA\Property(property: 'metadata', type: 'object', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Customer updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Customer updated successfully'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Customer'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Customer not found'
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error'
            ),
        ]
    )]
    public function update(CustomerUpdateRequest $request, Customer $customer): JsonResponse
    {
        $customer->update($request->validated());

        return ApiResponse::success(
            data: new CustomerResource($customer),
            message: 'Customer updated successfully'
        );
    }

    #[OA\Delete(
        path: '/customers/{customer}',
        summary: 'Delete a customer',
        tags: ['Customers'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Customer deleted successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Customer deleted successfully'),
                        new OA\Property(property: 'data', type: 'null', nullable: true),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Customer not found'
            ),
        ]
    )]
    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();

        return ApiResponse::success(
            data: null,
            message: 'Customer deleted successfully'
        );
    }
}
