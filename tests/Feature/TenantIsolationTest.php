<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 測試租戶中間件正確阻擋未授權的跨租戶存取
     */
    public function test_tenant_middleware_blocks_unauthorized_cross_tenant_access(): void
    {
        // 建立兩個獨立的租戶
        $tenantA = Tenant::create(['name' => 'Tenant A', 'domain' => 'tenant-a.local', 'is_active' => true]);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'domain' => 'tenant-b.local', 'is_active' => true]);

        // 建立兩個租戶的使用者
        $userA = User::create([
            'name' => 'User A',
            'email' => 'user-a@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $tenantA->id,
            'role' => 'user',
        ]);
        $userB = User::create([
            'name' => 'User B',
            'email' => 'user-b@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $tenantB->id,
            'role' => 'user',
        ]);

        // 取得JWT token
        $tokenA = JWTAuth::fromUser($userA);

        // 測試：如果 userA (隸屬tenantA) 嘗試在header中指定存取tenantB，應該被中間件阻擋(403)
        // 這驗證了租戶隔離機制的核心授權邏輯正常運作
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'X-Tenant-ID' => $tenantB->id,
        ])->getJson("/api/v1/customers");

        $response->assertStatus(403);
        $this->assertEquals('User not authorized to access this tenant.', $response->json('message'));
    }
}
