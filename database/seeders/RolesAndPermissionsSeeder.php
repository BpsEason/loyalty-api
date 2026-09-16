<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 清除權限快取，避免舊快取導致角色找不到
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $columnNames = config('permission.column_names');
        $teamForeignKey = $columnNames['team_foreign_key'];
        $globalTeamId = config('permission.default_team_id', 0);

        // 1. 建立平台級 Super Admin 角色 - 先切換到全域團隊ID
        setPermissionsTeamId($globalTeamId);
        $superAdmin = Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web', $teamForeignKey => $globalTeamId],
            [$teamForeignKey => $globalTeamId]
        );
        // Super Admin 同步所有權限
        $superAdmin->syncPermissions(Permission::all());

        // 2. 預先建立所有權限（確保每個團隊都能找到這些權限）
        $this->ensurePermissionsExist();

        // 3. 為每個現有的 Tenant 建立專屬的角色
        $tenants = Tenant::all();
        foreach ($tenants as $tenant) {
            $this->createTenantRolesAndPermissions($tenant->id);
        }
    }

    /**
     * 確保所有基本權限都已建立
     */
    private function ensurePermissionsExist(): void
    {
        $allPermissions = [
            'ViewAny::Customer',
            'View::Customer',
            'Create::Customer',
            'Update::Customer',
            'Delete::Customer',
            'ViewAny::PointTransaction',
            'View::PointTransaction',
            'Create::PointTransaction',
            'Update::PointTransaction',
            'Delete::PointTransaction',
            'ViewAny::PointAccount',
            'View::PointAccount',
            'Create::PointAccount',
            'Update::PointAccount',
            'Delete::PointAccount',
            'ViewAny::Reward',
            'View::Reward',
            'Create::Reward',
            'Update::Reward',
            'Delete::Reward',
        ];

        foreach ($allPermissions as $permissionName) {
            Permission::firstOrCreate(
                ['name' => $permissionName, 'guard_name' => 'web']
            );
        }
    }

    /**
     * 為特定 Tenant 建立專屬的角色和權限
     */
    private function createTenantRolesAndPermissions(int $tenantId): void
    {
        $columnNames = config('permission.column_names');
        $teamForeignKey = $columnNames['team_foreign_key'];

        // 切換到當前租戶的團隊ID
        setPermissionsTeamId($tenantId);

        // 建立該租戶專屬的 tenant_admin 角色（必須包含 team_id 在查找條件中）
        $tenantAdmin = Role::firstOrCreate(
            ['name' => 'tenant_admin', 'guard_name' => 'web', $teamForeignKey => $tenantId],
            [$teamForeignKey => $tenantId]
        );

        // 建立該租戶專屬的 tenant_staff 角色
        $tenantStaff = Role::firstOrCreate(
            ['name' => 'tenant_staff', 'guard_name' => 'web', $teamForeignKey => $tenantId],
            [$teamForeignKey => $tenantId]
        );

        // 為該租戶的 admin 同步所有權限
        $tenantAdmin->syncPermissions(Permission::all());

        // 為該租戶的 staff 同步基本檢視權限
        $basicPermissions = Permission::whereIn('name', [
            'ViewAny::Customer',
            'View::Customer',
            'ViewAny::PointTransaction',
            'View::PointTransaction',
            'ViewAny::PointAccount',
            'View::PointAccount',
            'ViewAny::Reward',
            'View::Reward',
        ])->get();

        $tenantStaff->syncPermissions($basicPermissions);
    }
}
