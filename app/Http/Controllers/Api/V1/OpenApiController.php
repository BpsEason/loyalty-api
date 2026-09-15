<?php

namespace App\Http\Controllers\Api\V1;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: "Multi-Tenant Loyalty API",
    version: "1.0.0",
    description: "Multi-tenant loyalty point management API"
)]
#[OA\Server(
    url: "/api/v1",
    description: "API Server"
)]
#[OA\SecurityScheme(
    securityScheme: "bearerAuth",
    type: "http",
    scheme: "bearer",
    bearerFormat: "JWT"
)]
class OpenApiController
{
    // This class only contains OpenAPI metadata
}
