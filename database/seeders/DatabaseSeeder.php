<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Customer;
use App\Models\PointAccount;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. 先執行角色權限Seeder
        $this->call(RolesAndPermissionsSeeder::class);

        // 2. 建立平台級 Super Admin (tenant_id = null)
        $superAdmin = User::create([
            'tenant_id' => null,
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password123'),
        ]);
        $superAdmin->assignRole('super_admin');

        // 3. 建立Demo Tenant
        $tenant = Tenant::create([
            'name' => 'Demo Tenant',
            'domain' => 'demo.localhost',
            'is_active' => true,
            'settings' => [
                'currency' => 'TWD',
                'timezone' => 'Asia/Taipei',
            ],
        ]);

        // 4. 建立Tenant Admin並賦予tenant_admin角色
        $tenantAdmin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Admin',
            'email' => 'admin@demo.com',
            'password' => Hash::make('password123'),
        ]);
        $tenantAdmin->assignRole('tenant_admin');

        // 5. 建立Demo Customer
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Customer',
            'email' => 'customer@demo.com',
            'phone' => '0912345678',
            'metadata' => [
                'member_since' => '2026-01-01',
                'tier' => 'gold',
            ],
        ]);

        // 6. 建立Point Account
        PointAccount::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'balance' => 1000,
            'total_earned' => 1000,
            'total_redeemed' => 0,
        ]);
    }
}
