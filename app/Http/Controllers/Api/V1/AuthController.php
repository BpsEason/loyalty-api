<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Support\Api\ApiResponse;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use OpenApi\Attributes as OA;
use Illuminate\Http\Request;

/**
 * JWT Authentication Controller
 */
class AuthController extends Controller
{
    #[OA\Post(
        path: "/auth/login",
        summary: "User login",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["email", "password"],
                properties: [
                    new OA\Property(property: "email", type: "string", format: "email"),
                    new OA\Property(property: "password", type: "string")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Login successful",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Login successful"),
                        new OA\Property(
                            property: "data",
                            type: "object",
                            properties: [
                                new OA\Property(property: "access_token", type: "string"),
                                new OA\Property(property: "token_type", type: "string", example: "Bearer"),
                                new OA\Property(property: "expires_in", type: "integer", example: 3600),
                                new OA\Property(property: "user", type: "object")
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: "Invalid credentials",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: false),
                        new OA\Property(property: "message", type: "string", example: "Invalid credentials"),
                        new OA\Property(property: "data", type: "null", nullable: true),
                        new OA\Property(property: "errors", type: "object", example: [])
                    ]
                )
            ),
            new OA\Response(
                response: 500,
                description: "Could not create token",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: false),
                        new OA\Property(property: "message", type: "string", example: "Could not create token"),
                        new OA\Property(property: "data", type: "null", nullable: true)
                    ]
                )
            )
        ]
    )]
    public function login(LoginRequest $request)
    {
        try {
            if (!$token = JWTAuth::attempt($request->only('email', 'password'))) {
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
                'user' => new UserResource($user)
            ],
            message: 'Login successful'
        );
    }

    #[OA\Post(
        path: "/auth/logout",
        summary: "User logout",
        security: [["bearerAuth" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Logout successful",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Successfully logged out"),
                        new OA\Property(property: "data", type: "null", nullable: true)
                    ]
                )
            )
        ]
    )]
    public function logout(Request $request)
    {
        JWTAuth::invalidate(JWTAuth::getToken());

        return ApiResponse::success(
            data: null,
            message: 'Successfully logged out'
        );
    }

    #[OA\Post(
        path: "/auth/refresh",
        summary: "Refresh JWT token",
        security: [["bearerAuth" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Token refreshed successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Token refreshed successfully"),
                        new OA\Property(
                            property: "data",
                            type: "object",
                            properties: [
                                new OA\Property(property: "access_token", type: "string"),
                                new OA\Property(property: "token_type", type: "string", example: "Bearer"),
                                new OA\Property(property: "expires_in", type: "integer", example: 3600)
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(
                response: 500,
                description: "Could not refresh token",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: false),
                        new OA\Property(property: "message", type: "string", example: "Could not refresh token"),
                        new OA\Property(property: "data", type: "null", nullable: true)
                    ]
                )
            )
        ]
    )]
    public function refresh()
    {
        try {
            $token = JWTAuth::refresh(JWTAuth::getToken());
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
                'expires_in' => JWTAuth::factory()->getTTL() * 60
            ],
            message: 'Token refreshed successfully'
        );
    }

    #[OA\Get(
        path: "/auth/me",
        summary: "Get current user",
        security: [["bearerAuth" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "User data retrieved successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "User retrieved successfully"),
                        new OA\Property(property: "data", type: "object"),
                        new OA\Property(property: "errors", type: "object", example: [])
                    ]
                )
            )
        ]
    )]
    public function me()
    {
        $user = auth()->user();

        return ApiResponse::success(
            data: new UserResource($user),
            message: 'User retrieved successfully'
        );
    }
}
