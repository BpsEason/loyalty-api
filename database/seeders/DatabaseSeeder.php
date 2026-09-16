<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Customer;
use App\Models\PointAccount;
use App\Models\PointTransaction;
use Spatie\Permission\PermissionRegistrar;
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
        // 先清除權限快取
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. 先建立平台級 Super Admin (tenant_id = null) - 使用firstOrCreate避免重複建立
        $globalTeamId = config('permission.default_team_id', 0);
        // 🔑 分配全域角色前必須先切換到全域團隊ID，才能找到super_admin角色
        setPermissionsTeamId($globalTeamId);

        // 先建立平台級 Super Admin 角色
        $columnNames = config('permission.column_names');
        $teamForeignKey = $columnNames['team_foreign_key'];
        $superAdminRole = \Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'web', $teamForeignKey => $globalTeamId],
            [$teamForeignKey => $globalTeamId]
        );
        // 確保 Super Admin 擁有所有權限
        $superAdminRole->syncPermissions(\Spatie\Permission\Models\Permission::all());

        // 建立 Super Admin 使用者 - 使用updateOrCreate確保既有使用者也會更新密碼
        $superAdmin = User::updateOrCreate(
            ['email' => 'superadmin@example.com'],
            [
                'tenant_id' => null,
                'name' => 'Super Admin',
                'password' => Hash::make('password123'),
            ]
        );
        // 先移除所有既有角色再重新分配，避免重複插入錯誤
        $superAdmin->roles()->detach();
        $superAdmin->assignRole($superAdminRole);

        // 2. 建立兩個測試租戶
        $tenantsData = [
            [
                'name' => 'Demo Coffee',
                'domain' => 'coffee.localhost',
                'is_active' => true,
                'settings' => [
                    'currency' => 'TWD',
                    'timezone' => 'Asia/Taipei',
                    'type' => 'coffee_shop'
                ]
            ],
            [
                'name' => 'Demo Fitness',
                'domain' => 'fitness.localhost',
                'is_active' => true,
                'settings' => [
                    'currency' => 'TWD',
                    'timezone' => 'Asia/Taipei',
                    'type' => 'fitness_center'
                ]
            ]
        ];

        $tenants = collect();
        foreach ($tenantsData as $tenantData) {
            $tenant = Tenant::firstOrCreate(
                ['domain' => $tenantData['domain']],
                $tenantData
            );
            $tenants->push($tenant);
        }

        // 先確保所有權限都已建立
        $this->ensureAllPermissionsExist();

        // 3. 為每個租戶建立專屬角色、管理員和員工
        foreach ($tenants as $index => $tenant) {
            $tenantLetter = $index === 0 ? 'a' : 'b';
            $tenantName = $index === 0 ? 'A' : 'B';

            // 🔑 第一步：切換到當前租戶的team_id，所有後續角色操作都會在這個租戶的作用域下
            setPermissionsTeamId($tenant->id);

            // 為這個租戶建立專屬的 tenant_admin 和 tenant_staff 角色
            $tenantAdminRole = \Spatie\Permission\Models\Role::firstOrCreate(
                ['name' => 'tenant_admin', 'guard_name' => 'web', $teamForeignKey => $tenant->id],
                [$teamForeignKey => $tenant->id]
            );
            $tenantStaffRole = \Spatie\Permission\Models\Role::firstOrCreate(
                ['name' => 'tenant_staff', 'guard_name' => 'web', $teamForeignKey => $tenant->id],
                [$teamForeignKey => $tenant->id]
            );

            // 同步權限到該租戶的角色
            $tenantAdminRole->syncPermissions(\Spatie\Permission\Models\Permission::all());
            $basicPermissions = \Spatie\Permission\Models\Permission::whereIn('name', [
                'ViewAny::Customer',
                'View::Customer',
                'ViewAny::PointTransaction',
                'View::PointTransaction',
                'ViewAny::PointAccount',
                'View::PointAccount',
                'ViewAny::Reward',
                'View::Reward',
            ])->get();
            $tenantStaffRole->syncPermissions($basicPermissions);

            // 建立Tenant Admin - 使用updateOrCreate確保既有使用者也會更新密碼
            $adminEmail = "admin-{$tenantLetter}@example.com";
            $tenantAdmin = User::updateOrCreate(
                ['email' => $adminEmail],
                [
                    'tenant_id' => $tenant->id,
                    'name' => "Tenant {$tenantName} Admin",
                    'password' => Hash::make('password123'),
                ]
            );
            // 先移除所有既有角色再重新分配，避免重複插入錯誤
            $tenantAdmin->roles()->detach();
            $tenantAdmin->assignRole($tenantAdminRole);

            // 建立第一個Tenant Staff - 使用updateOrCreate確保既有使用者也會更新密碼
            $staff1Email = "staff-{$tenantLetter}1@example.com";
            $tenantStaff1 = User::updateOrCreate(
                ['email' => $staff1Email],
                [
                    'tenant_id' => $tenant->id,
                    'name' => "Tenant {$tenantName} Staff 1",
                    'password' => Hash::make('password123'),
                ]
            );
            $tenantStaff1->roles()->detach();
            $tenantStaff1->assignRole($tenantStaffRole);

            // 建立第二個Tenant Staff - 使用updateOrCreate確保既有使用者也會更新密碼
            $staff2Email = "staff-{$tenantLetter}2@example.com";
            $tenantStaff2 = User::updateOrCreate(
                ['email' => $staff2Email],
                [
                    'tenant_id' => $tenant->id,
                    'name' => "Tenant {$tenantName} Staff 2",
                    'password' => Hash::make('password123'),
                ]
            );
            $tenantStaff2->roles()->detach();
            $tenantStaff2->assignRole($tenantStaffRole);

            // 5. 為每個租戶建立5個Customer
            $customers = collect();
            for ($i = 1; $i <= 5; $i++) {
                $customerEmail = "customer{$tenantLetter}{$i}@example.com";
                $customer = Customer::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'email' => $customerEmail],
                    [
                        'name' => "Customer {$tenantName}{$i}",
                        'phone' => "09{$tenantLetter}{$i}" . str_pad((string)rand(100000, 999999), 6, '0', STR_PAD_LEFT),
                        'metadata' => [
                            'member_since' => '2026-01-01',
                            'tier' => $i <= 2 ? 'gold' : ($i <= 4 ? 'silver' : 'bronze'),
                        ]
                    ]
                );
                $customers->push($customer);
            }

            // 6. 為每個Customer建立PointAccount
            $pointAccounts = collect();
            $balances = $index === 0
                ? [1000, 2500, 500, 3200, 800]  // Tenant A 的餘額
                : [1500, 400, 2800, 700, 5000];   // Tenant B 的餘額

            foreach ($customers as $cIndex => $customer) {
                $balance = $balances[$cIndex];
                $pointAccount = PointAccount::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'customer_id' => $customer->id],
                    [
                        'balance' => $balance,
                        'total_earned' => $balance,  // 初始狀態下所有點數都是累積的
                        'total_redeemed' => 0,
                    ]
                );
                $pointAccounts->push($pointAccount);

                // 7. 為部分Customer建立測試交易紀錄
                if ($cIndex === 0) { // 只為第一個客戶建立交易記錄
                    $this->createSampleTransactions($tenant, $customer, $pointAccount, $tenantAdmin->id);
                }
            }
        }

        // 執行獎勵系統測試資料
        $this->call(RewardSeeder::class);
    }

    /**
     * 確保所有基本權限都已建立
     */
    private function ensureAllPermissionsExist(): void
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
            \Spatie\Permission\Models\Permission::firstOrCreate(
                ['name' => $permissionName, 'guard_name' => 'web']
            );
        }
    }

    /**
     * 為客戶建立樣本交易記錄
     */
    private function createSampleTransactions($tenant, $customer, $pointAccount, $createdById)
    {
        $transactions = [
            [
                'type' => PointTransaction::TYPE_EARN,
                'amount' => 500,
                'balance_before' => 0,
                'balance_after' => 500,
                'description' => '首次消費累積點數',
            ],
            [
                'type' => PointTransaction::TYPE_EARN,
                'amount' => 500,
                'balance_before' => 500,
                'balance_after' => 1000,
                'description' => '二次消費累積點數',
            ],
        ];

        foreach ($transactions as $transaction) {
            PointTransaction::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'point_account_id' => $pointAccount->id,
                    'description' => $transaction['description'],
                ],
                array_merge($transaction, [
                    'customer_id' => $customer->id,
                    'created_by' => $createdById,
                ])
            );
        }
    }
}
