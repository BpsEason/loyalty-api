<?php

namespace App\Http\Controllers\Api\V1;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: "多租戶會員忠誠度系統 API",
    version: "1.0.0",
    description: "多租戶點數管理系統 API 服務文件"
)]
#[OA\Server(
    url: "/api/v1",
    description: "API 主要伺服器"
)]
#[OA\SecurityScheme(
    securityScheme: "bearerAuth",
    type: "http",
    scheme: "bearer",
    bearerFormat: "JWT"
)]
class OpenApiController
{
    // 本類別僅用於存放 OpenAPI / Swagger 的全域後設資料（Metadata）
}
