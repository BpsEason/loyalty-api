<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. 清除全域權限快取，確保重新載入
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $columnNames = config('permission.column_names');
        $teamForeignKey = $columnNames['team_foreign_key'];

        // 平台級（全域）Super Admin 採用的 Default Team ID（通常為 null 或 0，視 config 決定）
        $globalTeamId = config('permission.default_team_id', 0);

        // 2. 預先建立全域基礎權限（Permissions 在 Spatie Teams 中建議維持 Global，不綁定特定 team_id）
        $this->ensurePermissionsExist();

        // 3. 建立平台級 Super Admin 角色 (Platform Level)
        setPermissionsTeamId($globalTeamId);

        $superAdmin = Role::firstOrCreate(
            [
                'name' => 'super_admin',
                'guard_name' => 'web',
                $teamForeignKey => $globalTeamId,
            ]
        );

        // Super Admin 同步全域所有權限
        $superAdmin->syncPermissions(Permission::all());

        // 4. 為每個現有的 Tenant 獨立建立角色與權限分配 (Tenant Scoped)
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $this->createTenantRolesAndPermissions($tenant->id, $teamForeignKey);
        }

        // 5. 結束後重設 Team ID Context，避免影響後續 Seeder 或 HTTP Request
        setPermissionsTeamId($globalTeamId);
    }

    /**
     * 確保所有基本權限都已存在 (Global Permissions)
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
     * 為特定 Tenant 建立專屬的角色與配置權限
     */
    private function createTenantRolesAndPermissions(int $tenantId, string $teamForeignKey): void
    {
        // 切換 Spatie Permission 的當前團隊上下文
        setPermissionsTeamId($tenantId);

        // 建立該租戶專屬的 tenant_admin 角色
        $tenantAdmin = Role::firstOrCreate(
            [
                'name' => 'tenant_admin',
                'guard_name' => 'web',
                $teamForeignKey => $tenantId,
            ]
        );

        // 建立該租戶專屬的 tenant_staff 角色
        $tenantStaff = Role::firstOrCreate(
            [
                'name' => 'tenant_staff',
                'guard_name' => 'web',
                $teamForeignKey => $tenantId,
            ]
        );

        // 為該租戶的 tenant_admin 角色綁定所有可用權限
        $tenantAdmin->syncPermissions(Permission::all());

        // 為該租戶的 tenant_staff 角色綁定基本檢視權限
        $basicPermissionNames = [
            'ViewAny::Customer',
            'View::Customer',
            'ViewAny::PointTransaction',
            'View::PointTransaction',
            'ViewAny::PointAccount',
            'View::PointAccount',
            'ViewAny::Reward',
            'View::Reward',
        ];

        $basicPermissions = Permission::whereIn('name', $basicPermissionNames)->get();
        $tenantStaff->syncPermissions($basicPermissions);
    }
}
