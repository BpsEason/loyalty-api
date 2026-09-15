<?php

namespace Database\Seeders;

use Spatie\Permission\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 建立平台級 Super Admin 角色
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        // 建立租戶級 Tenant Admin 角色
        Role::firstOrCreate(['name' => 'tenant_admin', 'guard_name' => 'web']);
    }
}
