<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reward\GrantRewardRequest;
use App\Http\Resources\Api\V1\Reward\RewardGrantResource;
use App\Models\CampaignReward;
use App\Models\Customer;
use App\Models\RewardGrant;
use App\Services\Reward\RewardService;
use App\Support\Api\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Reward REST API Controller
 */
class RewardController extends Controller
{
    public function __construct()
    {
        //
    }

    #[OA\Get(
        path: '/customers/{customer}/reward-grants',
        summary: 'Get reward grant history for a customer',
        tags: ['Rewards'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
            new OA\Parameter(name: 'X-Tenant-ID', in: 'header', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Reward grants retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Reward grants retrieved successfully'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/RewardGrant')
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Customer not found',
            ),
        ]
    )]
    public function index(Request $request, Customer $customer, TenantContext $tenantContext): JsonResponse
    {
        // 驗證租戶隔離
        $tenant = $tenantContext->getTenant();
        if ($tenant && $customer->tenant_id !== $tenant->id) {
            return ApiResponse::error('Customer not found', null, [], 404);
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        $grants = RewardGrant::where('customer_id', $customer->id)
            ->latest()
            ->paginate($perPage);

        return ApiResponse::success(
            data: RewardGrantResource::collection($grants),
            message: 'Reward grants retrieved successfully'
        );
    }

    #[OA\Post(
        path: '/customers/{customer}/rewards/grant',
        summary: 'Grant a reward to a customer',
        tags: ['Rewards'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'customer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'X-Tenant-ID', in: 'header', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['campaign_reward_id'],
                properties: [
                    new OA\Property(property: 'campaign_reward_id', type: 'integer', example: 1, description: 'The ID of the campaign reward to grant'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Reward granted successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Reward granted successfully'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/RewardGrant'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Failed to grant reward',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'Failed to grant reward'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Customer or campaign reward not found',
            ),
        ]
    )]
    public function grant(GrantRewardRequest $request, Customer $customer, RewardService $rewardService, TenantContext $tenantContext): JsonResponse
    {
        // 驗證租戶：確認 customer 屬於當前 tenant
        $tenant = $tenantContext->getTenant();
        if ($tenant && $customer->tenant_id !== $tenant->id) {
            return ApiResponse::error('Customer not found', null, [], 404);
        }

        // 查找 CampaignReward，並透過 campaign 的 tenant_id 確認跨租戶隔離
        $campaignReward = CampaignReward::with('campaign')
            ->find($request->campaign_reward_id);

        if (!$campaignReward) {
            return ApiResponse::error('Campaign reward not found', null, [], 404);
        }

        // 驗證 campaign reward 的 campaign 屬於同一 tenant
        // 注意：campaign 使用 BelongsToTenant，跨租戶的 campaign 在當前 tenant context 下會被 global scope 過濾為 null
        if ($tenant && (!$campaignReward->campaign || $campaignReward->campaign->tenant_id !== $tenant->id)) {
            return ApiResponse::error('Campaign reward not found', null, [], 404);
        }

        try {
            $rewardGrant = $rewardService->grantRewardToCustomer($customer, $campaignReward);

            return ApiResponse::success(
                data: new RewardGrantResource($rewardGrant),
                message: 'Reward granted successfully',
                status: 201
            );
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), null, [], 400);
        }
    }
}
