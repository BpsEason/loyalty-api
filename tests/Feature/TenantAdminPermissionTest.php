<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        /** @var User $tenantAdmin */
        $tenantAdmin = User::where('email', 'admin-a@example.com')->first();
        $this->assertNotNull($tenantAdmin);

        $this->actingAs($tenantAdmin);

        // Verify Team ID context can be set and role checked
        setPermissionsTeamId($tenantAdmin->tenant_id);
        $tenantAdmin->unsetRelation('roles');

        $this->assertEquals(1, getPermissionsTeamId());
        $this->assertTrue($tenantAdmin->hasRole('tenant_admin'));
        $this->assertTrue($tenantAdmin->canAccessPanel(Filament::getCurrentOrDefaultPanel()));
    }

    public function test_tenant_admin_cannot_access_other_tenant(): void
    {
        $tenantB = Tenant::where('id', 2)->first();
        $this->assertNotNull($tenantB);

        /** @var User $tenantAdminA */
        $tenantAdminA = User::where('email', 'admin-a@example.com')->first();
        $this->assertNotNull($tenantAdminA);

        $this->actingAs($tenantAdminA);

        $this->assertFalse($tenantAdminA->canAccessTenant($tenantB));
    }

    public function test_tenant_admin_only_sees_own_tenant_roles(): void
    {
        /** @var User $tenantAdminA */
        $tenantAdminA = User::where('email', 'admin-a@example.com')->first();
        $this->actingAs($tenantAdminA);

        $rolesQuery = RoleResource::getEloquentQuery();

        // Should NOT contain super_admin or roles from team_id 2
        $this->assertFalse((clone $rolesQuery)->where('name', 'super_admin')->exists());
        $this->assertFalse((clone $rolesQuery)->where('team_id', 2)->exists());

        // Should contain tenant_admin and tenant_staff for team_id 1
        $this->assertTrue((clone $rolesQuery)->where('team_id', 1)->where('name', 'tenant_admin')->exists());
        $this->assertTrue((clone $rolesQuery)->where('team_id', 1)->where('name', 'tenant_staff')->exists());
    }

    public function test_super_admin_sees_all_roles(): void
    {
        /** @var User $superAdmin */
        $superAdmin = User::where('email', 'superadmin@example.com')->first();
        $this->actingAs($superAdmin);

        $rolesQuery = RoleResource::getEloquentQuery();

        $this->assertTrue((clone $rolesQuery)->where('name', 'super_admin')->exists());
        $this->assertTrue((clone $rolesQuery)->where('team_id', 1)->exists());
        $this->assertTrue((clone $rolesQuery)->where('team_id', 2)->exists());
    }
}
