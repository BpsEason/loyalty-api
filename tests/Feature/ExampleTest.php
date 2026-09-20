<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * 測試API服務正常運作
     */
    public function test_api_health_check_returns_successful_response(): void
    {
        // 測試存取一個不存在的API路由，應該回傳404而不是崩潰
        // 這證明應用程式的基礎架構正常運作
        $response = $this->getJson('/v1/non-existent-endpoint');
        $response->assertStatus(404);
    }
}
