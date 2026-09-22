<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantAdminPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_tenant_admin_can_access_panel_and_has_role(): void
    {
        // 建立測試租戶和使用者，避免依賴 Seeder 的固定資料
        $tenant = Tenant::create(['name' => 'Test Tenant', 'domain' => 'test-tenant.local', 'is_active' => true]);

        /** @var User $tenantAdmin */
        $tenantAdmin = User::factory()->create([
            'name' => 'Tenant Admin',
            'tenant_id' => $tenant->id,
        ]);

        // 為該租戶建立角色並指派給使用者
        setPermissionsTeamId($tenant->id);
        Role::create(['name' => 'tenant_admin', 'team_id' => $tenant->id]);
        $tenantAdmin->assignRole('tenant_admin');

        $this->assertNotNull($tenantAdmin);
        $this->actingAs($tenantAdmin);

        // Verify Team ID context can be set and role checked
        setPermissionsTeamId($tenantAdmin->tenant_id);
        $tenantAdmin->unsetRelation('roles');

        $this->assertEquals($tenant->id, getPermissionsTeamId());
        $this->assertTrue($tenantAdmin->hasRole('tenant_admin'));
        $this->assertTrue($tenantAdmin->canAccessPanel(Filament::getCurrentOrDefaultPanel()));
    }

    public function test_tenant_admin_cannot_access_other_tenant(): void
    {
        // 建立兩個獨立的租戶
        $tenantA = Tenant::create(['name' => 'Tenant A', 'domain' => 'tenant-a.local', 'is_active' => true]);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'domain' => 'tenant-b.local', 'is_active' => true]);

        /** @var User $tenantAdminA */
        $tenantAdminA = User::factory()->create([
            'name' => 'Tenant Admin A',
            'tenant_id' => $tenantA->id,
        ]);

        $this->actingAs($tenantAdminA);
        $this->assertFalse($tenantAdminA->canAccessTenant($tenantB));
    }

    public function test_tenant_admin_only_sees_own_tenant_roles(): void
    {
        // 建立兩個獨立的租戶
        $tenantA = Tenant::create(['name' => 'Tenant A', 'domain' => 'tenant-a.local', 'is_active' => true]);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'domain' => 'tenant-b.local', 'is_active' => true]);

        // 在兩個租戶中分別建立角色
        setPermissionsTeamId($tenantA->id);
        \Spatie\Permission\Models\Role::create(['name' => 'tenant_admin', 'team_id' => $tenantA->id]);
        \Spatie\Permission\Models\Role::create(['name' => 'tenant_staff', 'team_id' => $tenantA->id]);

        setPermissionsTeamId($tenantB->id);
        \Spatie\Permission\Models\Role::create(['name' => 'tenant_admin', 'team_id' => $tenantB->id]);
        
        // 建立租戶A的管理員
        /** @var User $tenantAdminA */
        $tenantAdminA = User::factory()->create([
            'name' => 'Tenant Admin A',
            'tenant_id' => $tenantA->id,
        ]);
        setPermissionsTeamId($tenantA->id);
        $tenantAdminA->assignRole('tenant_admin');

        $this->actingAs($tenantAdminA);
        setPermissionsTeamId($tenantAdminA->tenant_id);

        $rolesQuery = RoleResource::getEloquentQuery();

        // Should NOT contain super_admin or roles from other tenant
        $this->assertFalse((clone $rolesQuery)->where('name', 'super_admin')->exists());
        $this->assertFalse((clone $rolesQuery)->where('team_id', $tenantB->id)->exists());

        // Should contain tenant_admin and tenant_staff for current tenant
        $this->assertTrue((clone $rolesQuery)->where('team_id', $tenantA->id)->where('name', 'tenant_admin')->exists());
        $this->assertTrue((clone $rolesQuery)->where('team_id', $tenantA->id)->where('name', 'tenant_staff')->exists());
    }

    public function test_super_admin_sees_all_roles(): void
    {
        // 建立兩個獨立的租戶
        $tenantA = Tenant::create(['name' => 'Tenant A', 'domain' => 'tenant-a.local', 'is_active' => true]);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'domain' => 'tenant-b.local', 'is_active' => true]);

        // 在兩個租戶中建立角色，以及全域的超級管理員角色（使用 firstOrCreate 避免重複建立）
        Role::firstOrCreate(['name' => 'super_admin', 'team_id' => null]);
        setPermissionsTeamId($tenantA->id);
        Role::create(['name' => 'tenant_admin', 'team_id' => $tenantA->id]);
        setPermissionsTeamId($tenantB->id);
        Role::create(['name' => 'tenant_admin', 'team_id' => $tenantB->id]);

        /** @var User $superAdmin */
        $superAdmin = User::factory()->create([
            'name' => 'Super Admin',
            'tenant_id' => null,
        ]);
        $this->assertNotNull($superAdmin);

        $this->actingAs($superAdmin);

        $rolesQuery = RoleResource::getEloquentQuery();

        $this->assertTrue((clone $rolesQuery)->where('name', 'super_admin')->exists());
        $this->assertTrue((clone $rolesQuery)->where('team_id', $tenantA->id)->exists());
        $this->assertTrue((clone $rolesQuery)->where('team_id', $tenantB->id)->exists());
    }
}
