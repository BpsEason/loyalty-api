<?php

namespace Database\Seeders;

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 建立平台級 Super Admin 角色
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        // Super Admin 自動獲得所有權限（Shield 內建機制）

        // 建立租戶級 Tenant Admin 角色
        $tenantAdmin = Role::firstOrCreate(['name' => 'tenant_admin', 'guard_name' => 'web']);
        // 給予 Tenant Admin 所有資源的權限
        $tenantAdmin->syncPermissions(Permission::all());

        // 建立租戶員工 Tenant Staff 角色
        $tenantStaff = Role::firstOrCreate(['name' => 'tenant_staff', 'guard_name' => 'web']);
        // 給予基本檢視權限
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
