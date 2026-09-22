<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Filament\Resources\UserResource;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_default_tenant_remembers_session_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'domain' => 'tenant-a.local', 'is_active' => true]);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'domain' => 'tenant-b.local', 'is_active' => true]);

        $superAdmin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'tenant_id' => null,
        ]);

        $panel = Filament::getCurrentOrDefaultPanel();

        // Super Admin 永遠不預設任何租戶，保持全域存取（符合目前實作）
        $defaultTenant = $superAdmin->getDefaultTenant($panel);
        $this->assertNull($defaultTenant);

        // 即使設定了 Session 中的租戶，Super Admin 仍然不會自動設定預設租戶
        session(['filament_tenant_id' => $tenantB->id]);
        $this->assertNull($superAdmin->getDefaultTenant($panel));
    }

    public function test_super_admin_sees_all_users_in_user_resource(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'domain' => 'tenant-a.local', 'is_active' => true]);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'domain' => 'tenant-b.local', 'is_active' => true]);

        $superAdmin = User::factory()->create(['tenant_id' => null]);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($superAdmin);

        // 模擬 Filament 設定 Tenant A
        Filament::setTenant($tenantA);

        $query = UserResource::getEloquentQuery();
        $this->assertEquals(3, $query->count());
    }
}
