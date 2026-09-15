<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * JWT Authentication Controller
 */
class AuthController extends Controller
{
    #[OA\Post(
        path: '/auth/login',
        summary: 'User login',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login successful',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Login successful'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'access_token', type: 'string'),
                                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                                new OA\Property(property: 'expires_in', type: 'integer', example: 3600),
                                new OA\Property(property: 'user', type: 'object'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Invalid credentials',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'Invalid credentials'),
                        new OA\Property(property: 'data', type: 'null', nullable: true),
                        new OA\Property(property: 'errors', type: 'object', example: []),
                    ]
                )
            ),
            new OA\Response(
                response: 500,
                description: 'Could not create token',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'Could not create token'),
                        new OA\Property(property: 'data', type: 'null', nullable: true),
                    ]
                )
            ),
        ]
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            if (! $token = JWTAuth::attempt($request->only('email', 'password'))) {
                return ApiResponse::error(
                    message: 'Invalid credentials',
                    status: 401
                );
            }
        } catch (JWTException $e) {
            return ApiResponse::error(
                message: 'Could not create token',
                status: 500
            );
        }

        $user = auth()->user();

        return ApiResponse::success(
            data: [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
                'user' => new UserResource($user),
            ],
            message: 'Login successful'
        );
    }

    #[OA\Post(
        path: '/auth/logout',
        summary: 'User logout',
        tags: ['Authentication'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logout successful',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Successfully logged out'),
                        new OA\Property(property: 'data', type: 'null', nullable: true),
                    ]
                )
            ),
        ]
    )]
    public function logout(Request $request): JsonResponse
    {
        JWTAuth::invalidate(JWTAuth::getToken());

        return ApiResponse::success(
            data: null,
            message: 'Successfully logged out'
        );
    }

    #[OA\Post(
        path: '/auth/refresh',
        summary: 'Refresh JWT token',
        tags: ['Authentication'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Token refreshed successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Token refreshed successfully'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'access_token', type: 'string'),
                                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                                new OA\Property(property: 'expires_in', type: 'integer', example: 3600),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized / Token Invalid',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string', example: 'Token has expired or is invalid'),
                        new OA\Property(property: 'data', type: 'null', nullable: true),
                    ]
                )
            ),
        ]
    )]
    public function refresh(): JsonResponse
    {
        try {
            $token = JWTAuth::refresh(JWTAuth::getToken());
        } catch (TokenExpiredException | TokenInvalidException $e) {
            return ApiResponse::error(
                message: 'Token has expired or is invalid',
                status: 401
            );
        } catch (JWTException $e) {
            return ApiResponse::error(
                message: 'Could not refresh token',
                status: 500
            );
        }

        return ApiResponse::success(
            data: [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
            ],
            message: 'Token refreshed successfully'
        );
    }

    #[OA\Get(
        path: '/auth/me',
        summary: 'Get current user',
        tags: ['Authentication'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User data retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'User retrieved successfully'),
                        new OA\Property(property: 'data', type: 'object'),
                        new OA\Property(property: 'errors', type: 'object', example: []),
                    ]
                )
            ),
        ]
    )]
    public function me(): JsonResponse
    {
        $user = auth()->user();

        return ApiResponse::success(
            data: new UserResource($user),
            message: 'User retrieved successfully'
        );
    }
}
