<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\PointAccount;
use App\Models\PointTransaction;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 清除 Spatie Permission 快取
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        /*
         * ============================================================
         * 1. 建立平台級 Super Admin
         * ============================================================
         *
         * Super Admin 不屬於任何 Tenant：
         *
         * tenant_id = null
         *
         * 同時使用 Spatie Permission 的 global team scope。
         */
        $globalTeamId = config('permission.default_team_id', 0);

        setPermissionsTeamId($globalTeamId);

        $columnNames = config('permission.column_names');
        $teamForeignKey = $columnNames['team_foreign_key'];

        $superAdminRole = Role::firstOrCreate(
            [
                'name' => 'super_admin',
                'guard_name' => 'web',
                $teamForeignKey => $globalTeamId,
            ],
            [
                $teamForeignKey => $globalTeamId,
            ]
        );

        /*
         * 先確保所有 Permission 都存在，
         * 再同步給 Super Admin。
         */
        $this->ensureAllPermissionsExist();

        $superAdminRole->syncPermissions(Permission::all());

        $superAdmin = User::updateOrCreate(
            [
                'email' => 'superadmin@example.com',
            ],
            [
                'tenant_id' => null,
                'name' => 'Super Admin',
                'password' => Hash::make('password123'),
            ]
        );

        // 清除既有角色，避免 Seeder 重複執行造成角色累積
        $superAdmin->roles()->detach();

        // 確保目前在 Global Team
        setPermissionsTeamId($globalTeamId);

        $superAdmin->assignRole($superAdminRole);

        /*
         * ============================================================
         * 2. 建立 Demo Tenants
         * ============================================================
         */
        $tenantsData = [
            [
                'name' => 'Demo Coffee',
                'domain' => 'coffee.localhost',
                'is_active' => true,
                'settings' => [
                    'currency' => 'TWD',
                    'timezone' => 'Asia/Taipei',
                    'type' => 'coffee_shop',
                ],
            ],
            [
                'name' => 'Demo Fitness',
                'domain' => 'fitness.localhost',
                'is_active' => true,
                'settings' => [
                    'currency' => 'TWD',
                    'timezone' => 'Asia/Taipei',
                    'type' => 'fitness_center',
                ],
            ],
        ];

        $tenants = collect();

        foreach ($tenantsData as $tenantData) {
            $tenant = Tenant::firstOrCreate(
                [
                    'domain' => $tenantData['domain'],
                ],
                $tenantData
            );

            $tenants->push($tenant);
        }

        /*
         * ============================================================
         * 3. 建立 Tenant Roles / Admin / Staff
         * ============================================================
         */
        foreach ($tenants as $index => $tenant) {
            $tenantLetter = $index === 0 ? 'a' : 'b';
            $tenantName = $index === 0 ? 'A' : 'B';

            // 切換到目前 Tenant 的 Spatie Team Scope
            setPermissionsTeamId($tenant->id);

            /*
             * Tenant Admin Role
             */
            $tenantAdminRole = Role::firstOrCreate(
                [
                    'name' => 'tenant_admin',
                    'guard_name' => 'web',
                    $teamForeignKey => $tenant->id,
                ],
                [
                    $teamForeignKey => $tenant->id,
                ]
            );

            /*
             * Tenant Staff Role
             */
            $tenantStaffRole = Role::firstOrCreate(
                [
                    'name' => 'tenant_staff',
                    'guard_name' => 'web',
                    $teamForeignKey => $tenant->id,
                ],
                [
                    $teamForeignKey => $tenant->id,
                ]
            );

            /*
             * Tenant Admin 擁有全部目前建立的 Permission
             */
            $tenantAdminRole->syncPermissions(Permission::all());

            /*
             * Tenant Staff 只有查詢權限。
             */
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

            $tenantStaffRole->syncPermissions($basicPermissions);

            /*
             * --------------------------------------------------------
             * Tenant Admin
             * --------------------------------------------------------
             */
            $adminEmail = "admin-{$tenantLetter}@example.com";

            $tenantAdmin = User::updateOrCreate(
                [
                    'email' => $adminEmail,
                ],
                [
                    'tenant_id' => $tenant->id,
                    'name' => "Tenant {$tenantName} Admin",
                    'password' => Hash::make('password123'),
                ]
            );

            $tenantAdmin->roles()->detach();

            setPermissionsTeamId($tenant->id);

            $tenantAdmin->assignRole($tenantAdminRole);

            /*
             * --------------------------------------------------------
             * Tenant Staff 1
             * --------------------------------------------------------
             */
            $staff1Email = "staff-{$tenantLetter}1@example.com";

            $tenantStaff1 = User::updateOrCreate(
                [
                    'email' => $staff1Email,
                ],
                [
                    'tenant_id' => $tenant->id,
                    'name' => "Tenant {$tenantName} Staff 1",
                    'password' => Hash::make('password123'),
                ]
            );

            $tenantStaff1->roles()->detach();
            $tenantStaff1->assignRole($tenantStaffRole);

            /*
             * --------------------------------------------------------
             * Tenant Staff 2
             * --------------------------------------------------------
             */
            $staff2Email = "staff-{$tenantLetter}2@example.com";

            $tenantStaff2 = User::updateOrCreate(
                [
                    'email' => $staff2Email,
                ],
                [
                    'tenant_id' => $tenant->id,
                    'name' => "Tenant {$tenantName} Staff 2",
                    'password' => Hash::make('password123'),
                ]
            );

            $tenantStaff2->roles()->detach();
            $tenantStaff2->assignRole($tenantStaffRole);

            /*
             * ========================================================
             * 4. 建立 Demo Customers
             * ========================================================
             *
             * Customer Model 的 creating event 負責自動產生：
             *
             * - member_code
             * - qr_token
             *
             * 注意：
             * DatabaseSeeder 不再使用 WithoutModelEvents，
             * 因此 Customer::creating() 會正常執行。
             */
            $customers = $this->seedCustomers(
                tenant: $tenant,
                tenantName: $tenantName,
                tenantLetter: $tenantLetter
            );

            /*
             * ========================================================
             * 5. 建立 Point Accounts
             * ========================================================
             */
            $balances = $index === 0
                ? [
                    1000,
                    2500,
                    500,
                    3200,
                    800,
                ]
                : [
                    1500,
                    400,
                    2800,
                    700,
                    5000,
                ];

            foreach ($customers as $customerIndex => $customer) {
                $balance = $balances[$customerIndex];

                $pointAccount = PointAccount::firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'customer_id' => $customer->id,
                    ],
                    [
                        'balance' => $balance,
                        'total_earned' => $balance,
                        'total_redeemed' => 0,
                    ]
                );

                /*
                 * ====================================================
                 * 6. 建立 Demo Point Transactions
                 * ====================================================
                 *
                 * 只為第一個 Customer 建立歷史交易，
                 * 讓 API Demo 有實際的 transaction history。
                 */
                if ($customerIndex === 0) {
                    $this->createSampleTransactions(
                        tenant: $tenant,
                        customer: $customer,
                        pointAccount: $pointAccount,
                        createdById: $tenantAdmin->id
                    );
                }
            }
        }

        /*
         * ============================================================
         * 7. Reward Demo Data
         * ============================================================
         */
        $this->call(RewardSeeder::class);

        /*
         * 最後恢復 Global Team Scope。
         *
         * 避免 Seeder 結束後 Spatie Permission
         * 還停留在最後一個 Tenant。
         */
        setPermissionsTeamId($globalTeamId);
    }

    /**
     * 建立 Tenant 的 Demo Customers。
     *
     * Customer Model 本身會自動生成：
     *
     * - member_code
     * - qr_token
     */
    private function seedCustomers(
        Tenant $tenant,
        string $tenantName,
        string $tenantLetter
    ): Collection {
        $customerSeeds = [
            [
                'name' => "Customer {$tenantName}1",
                'email' => "customer{$tenantLetter}1@example.com",
                'phone' => '0911000001',
                'tier' => 'gold',
            ],
            [
                'name' => "Customer {$tenantName}2",
                'email' => "customer{$tenantLetter}2@example.com",
                'phone' => '0911000002',
                'tier' => 'gold',
            ],
            [
                'name' => "Customer {$tenantName}3",
                'email' => "customer{$tenantLetter}3@example.com",
                'phone' => '0911000003',
                'tier' => 'silver',
            ],
            [
                'name' => "Customer {$tenantName}4",
                'email' => "customer{$tenantLetter}4@example.com",
                'phone' => '0911000004',
                'tier' => 'silver',
            ],
            [
                'name' => "Customer {$tenantName}5",
                'email' => "customer{$tenantLetter}5@example.com",
                'phone' => '0911000005',
                'tier' => 'bronze',
            ],
        ];

        $customers = collect();

        foreach ($customerSeeds as $customerData) {
            $customer = Customer::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'email' => $customerData['email'],
                ],
                [
                    'name' => $customerData['name'],
                    'phone' => $customerData['phone'],
                    'metadata' => [
                        'member_since' => '2026-01-01',
                        'tier' => $customerData['tier'],
                    ],
                ]
            );

            $customers->push($customer);
        }

        return $customers;
    }

    /**
     * 確保所有基本權限都已建立。
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
            Permission::firstOrCreate(
                [
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ]
            );
        }
    }

    /**
     * 為 Demo Customer 建立樣本交易記錄。
     */
    private function createSampleTransactions(
        Tenant $tenant,
        Customer $customer,
        PointAccount $pointAccount,
        int $createdById
    ): void {
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
                [
                    'customer_id' => $customer->id,
                    'type' => $transaction['type'],
                    'amount' => $transaction['amount'],
                    'balance_before' => $transaction['balance_before'],
                    'balance_after' => $transaction['balance_after'],
                    'created_by' => $createdById,
                ]
            );
        }
    }
}
