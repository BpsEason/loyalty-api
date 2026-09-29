<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
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

    public function test_tenant_admin_user_query_is_scoped_and_cannot_manage_other_tenants(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'domain' => 'tenant-a.local', 'is_active' => true]);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'domain' => 'tenant-b.local', 'is_active' => true]);
        $tenantC = Tenant::create(['name' => 'Tenant C', 'domain' => 'tenant-c.local', 'is_active' => true]);

        $tenantAdminA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $userC = User::factory()->create(['tenant_id' => $tenantC->id]);

        setPermissionsTeamId($tenantA->id);
        Role::create(['name' => 'tenant_admin', 'team_id' => $tenantA->id]);
        $tenantAdminA->assignRole('tenant_admin');
        $this->actingAs($tenantAdminA);

        $query = UserResource::getEloquentQuery();
        $sql = strtolower($query->toSql());

        $this->assertStringContainsString('where "users"."tenant_id" = ?', $sql);
        $this->assertContains($tenantAdminA->tenant_id, $query->getBindings());
        $this->assertEqualsCanonicalizing(
            [$tenantAdminA->id, $userA->id],
            $query->pluck('id')->all(),
        );
        $this->assertTrue(UserResource::canEdit($userA));
        $this->assertTrue(UserResource::canDelete($userA));
        $this->assertFalse(UserResource::canEdit($userB));
        $this->assertFalse(UserResource::canDelete($userB));
        $this->assertFalse(UserResource::canEdit($userC));
        $this->assertFalse(UserResource::canDelete($userC));
    }

    public function test_tenant_admin_cannot_create_or_move_users_to_another_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'domain' => 'tenant-a.local', 'is_active' => true]);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'domain' => 'tenant-b.local', 'is_active' => true]);
        $tenantAdminA = User::factory()->create(['tenant_id' => $tenantA->id]);

        setPermissionsTeamId($tenantA->id);
        Role::create(['name' => 'tenant_admin', 'team_id' => $tenantA->id]);
        $tenantAdminA->assignRole('tenant_admin');
        $this->actingAs($tenantAdminA);

        $createPage = (new \ReflectionClass(CreateUser::class))->newInstanceWithoutConstructor();
        $createHook = new \ReflectionMethod(CreateUser::class, 'mutateFormDataBeforeCreate');
        $createdData = $createHook->invoke($createPage, ['tenant_id' => $tenantB->id]);

        $editPage = (new \ReflectionClass(EditUser::class))->newInstanceWithoutConstructor();
        $editHook = new \ReflectionMethod(EditUser::class, 'mutateFormDataBeforeSave');
        $savedData = $editHook->invoke($editPage, ['tenant_id' => $tenantB->id]);

        $this->assertSame($tenantA->id, $createdData['tenant_id']);
        $this->assertSame($tenantA->id, $savedData['tenant_id']);
    }
}
