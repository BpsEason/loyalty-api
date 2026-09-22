<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Coupon\CouponClaimRequest;
use App\Http\Requests\Api\V1\Coupon\CouponRedeemRequest;
use App\Http\Resources\Api\V1\Coupon\CouponRedemptionResource;
use App\Http\Resources\Api\V1\Coupon\UserCouponResource;
use App\Models\CouponRedemption;
use App\Models\CouponTemplate;
use App\Models\Customer;
use App\Models\UserCoupon;
use App\Services\Coupon\CouponService;
use App\Support\Api\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Coupon REST API Controller
 */
class CouponController extends Controller
{
    public function __construct()
    {
        //
    }

    #[OA\Post(
        path: '/customers/{customer}/coupons/claim',
        summary: 'Claim a coupon for a customer',
        tags: ['Coupons'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Tenant-ID', in: 'header', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'SUMMER2024'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Coupon claimed successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Coupon claimed successfully'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/UserCoupon'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Failed to claim coupon',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'Failed to claim coupon'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Customer not found',
            ),
        ]
    )]
    public function claim(CouponClaimRequest $request, Customer $customer, CouponService $couponService, TenantContext $tenantContext): JsonResponse
    {
        // 驗證租戶
        $tenant = $tenantContext->getTenant();
        if ($tenant && $customer->tenant_id != $tenant->id) {
            return ApiResponse::error('Customer not found', null, [], 404);
        }

        try {
            // 查找優惠券模板
            $template = $couponService->findValidTemplateByCode($request->code);

            if (!$template) {
                return ApiResponse::error('Invalid or expired coupon code', null, [], 400);
            }

            // 領取優惠券
            $userCoupon = $couponService->claim($customer, $template);

            return ApiResponse::success(
                data: new UserCouponResource($userCoupon->load('couponTemplate')),
                message: 'Coupon claimed successfully',
                status: 201
            );
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), null, [], 400);
        }
    }

    #[OA\Get(
        path: '/customers/{customer}/coupon-redemptions',
        summary: 'Get list of coupon redemptions for a customer',
        tags: ['Coupons'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
            new OA\Parameter(name: 'start_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'end_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Coupon redemptions retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Coupon redemptions retrieved successfully'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/CouponRedemption')
                        ),
                    ]
                )
            ),
        ]
    )]
    public function redemptionHistory(Request $request, Customer $customer, TenantContext $tenantContext): JsonResponse
    {
        // 驗證租戶
        $tenant = $tenantContext->getTenant();
        if ($tenant && $customer->tenant_id !== $tenant->id) {
            return ApiResponse::error('Customer not found', null, [], 404);
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $query = CouponRedemption::where('customer_id', $customer->id);

        // 按日期範圍過濾
        if ($request->has('start_date')) {
            $query->whereDate('redeemed_at', '>=', $request->input('start_date'));
        }
        if ($request->has('end_date')) {
            $query->whereDate('redeemed_at', '<=', $request->input('end_date'));
        }

        $redemptions = $query->latest('redeemed_at')->paginate($perPage);

        return ApiResponse::success(
            data: CouponRedemptionResource::collection($redemptions),
            message: 'Coupon redemptions retrieved successfully'
        );
    }

    #[OA\Get(
        path: '/customers/{customer}/coupons',
        summary: 'Get list of coupons for a customer',
        tags: ['Coupons'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Coupons retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Coupons retrieved successfully'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/UserCoupon')
                        ),
                    ]
                )
            ),
        ]
    )]
    public function index(Request $request, Customer $customer, CouponService $couponService, TenantContext $tenantContext): JsonResponse
    {
        // 驗證租戶
        $tenant = $tenantContext->getTenant();
        if ($tenant && $customer->tenant_id !== $tenant->id) {
            return ApiResponse::error('Customer not found', null, [], 404);
        }

        $perPage = min($request->input('per_page', 15), 100);
        $coupons = $customer->userCoupons()->with('couponTemplate')->latest()->paginate($perPage);

        return ApiResponse::success(
            data: UserCouponResource::collection($coupons),
            message: 'Coupons retrieved successfully'
        );
    }

    #[OA\Get(
        path: '/customers/{customer}/coupons/{userCoupon}',
        summary: 'Get a specific coupon',
        tags: ['Coupons'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'userCoupon', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Coupon retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Coupon retrieved successfully'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/UserCoupon'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Coupon not found',
            ),
        ]
    )]
    public function show(Request $request, Customer $customer, UserCoupon $userCoupon, TenantContext $tenantContext): JsonResponse
    {
        // 驗證租戶
        $tenant = $tenantContext->getTenant();
        if ($tenant && $customer->tenant_id !== $tenant->id) {
            return ApiResponse::error('Customer not found', null, [], 404);
        }

        // 驗證用戶優惠券屬於此客戶
        if ($userCoupon->customer_id !== $customer->id) {
            return ApiResponse::error('Coupon not found', null, [], 404);
        }

        return ApiResponse::success(
            data: new UserCouponResource($userCoupon->load('couponTemplate')),
            message: 'Coupon retrieved successfully'
        );
    }

    #[OA\Post(
        path: '/customers/{customer}/coupons/{userCoupon}/redeem',
        summary: 'Redeem a coupon',
        tags: ['Coupons'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'userCoupon', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Tenant-ID', in: 'header', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reference'],
                properties: [
                    new OA\Property(property: 'reference', type: 'string', example: 'REDemption-12345'),
                    new OA\Property(property: 'order_reference', type: 'string', nullable: true, example: 'ORDER-67890'),
                    new OA\Property(property: 'order_amount', type: 'integer', nullable: true, example: 1000),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Coupon redeemed successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Coupon redeemed successfully'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/CouponRedemption'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Failed to redeem coupon',
            ),
            new OA\Response(
                response: 404,
                description: 'Coupon not found',
            ),
        ]
    )]
    public function redeem(CouponRedeemRequest $request, Customer $customer, UserCoupon $userCoupon, CouponService $couponService, TenantContext $tenantContext): JsonResponse
    {
        // 驗證租戶
        $tenant = $tenantContext->getTenant();
        if ($tenant && $customer->tenant_id !== $tenant->id) {
            return ApiResponse::error('Customer not found', null, [], 404);
        }

        // 驗證用戶優惠券屬於此客戶
        if ($userCoupon->customer_id !== $customer->id) {
            return ApiResponse::error('Coupon not found', null, [], 404);
        }

        try {
            $redemption = $couponService->redeem(
                $userCoupon,
                $request->reference,
                $request->order_reference,
                $request->order_amount,
                auth()->id()
            );

            return ApiResponse::success(
                data: new CouponRedemptionResource($redemption),
                message: 'Coupon redeemed successfully'
            );
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), null, [], 400);
        }
    }

    #[OA\Post(
        path: '/customers/{customer}/mixed-payment',
        summary: '混合支付：同時使用優惠券和點數',
        tags: ['Coupons'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Tenant-ID', in: 'header', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reference', 'order_amount'],
                properties: [
                    new OA\Property(property: 'reference', type: 'string', example: 'PAYMENT-12345'),
                    new OA\Property(property: 'order_reference', type: 'string', nullable: true, example: 'ORDER-67890'),
                    new OA\Property(property: 'order_amount', type: 'integer', example: 1000, minimum: 0),
                    new OA\Property(property: 'user_coupon_id', type: 'integer', nullable: true, example: 1),
                    new OA\Property(property: 'points_amount', type: 'integer', nullable: true, example: 300, minimum: 0),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: '混合支付處理成功',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: '混合支付處理成功'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'original_amount', type: 'integer', example: 1000),
                                new OA\Property(property: 'discount_amount', type: 'integer', example: 100),
                                new OA\Property(property: 'points_used', type: 'integer', example: 300),
                                new OA\Property(property: 'final_amount', type: 'integer', example: 600),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: '混合支付處理失敗',
            ),
            new OA\Response(
                response: 404,
                description: '客戶或資源未找到',
            ),
        ]
    )]
    public function mixedPayment(\App\Http\Requests\Api\V1\Coupon\MixedPaymentRequest $request, Customer $customer, CouponService $couponService, TenantContext $tenantContext): JsonResponse
    {
        // 驗證租戶
        $tenant = $tenantContext->getTenant();
        if ($tenant && $customer->tenant_id !== $tenant->id) {
            return ApiResponse::error('Customer not found', null, [], 404);
        }

        $validated = $request->validated();

        // 獲取用戶優惠券（如果提供了）
        $userCoupon = null;
        if (isset($validated['user_coupon_id'])) {
            $userCoupon = UserCoupon::where('id', $validated['user_coupon_id'])
                ->where('customer_id', $customer->id)
                ->first();

            if (!$userCoupon) {
                return ApiResponse::error('優惠券不存在或不屬於此客戶', null, [], 404);
            }
        }

        try {
            $result = $couponService->mixedPayment(
                $customer,
                $userCoupon,
                $validated['order_amount'],
                $validated['points_amount'] ?? 0,
                $validated['reference'],
                $validated['order_reference'] ?? null,
                auth()->id()
            );

            return ApiResponse::success(
                data: $result,
                message: '混合支付處理成功'
            );
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), null, [], 400);
        }
    }
}