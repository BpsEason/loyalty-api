<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Customer;
use App\Models\PointAccount;
use App\Models\PointTransaction;
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

        // 2. 建立平台級 Super Admin (tenant_id = null) - 使用firstOrCreate避免重複建立
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@example.com'],
            [
                'tenant_id' => null,
                'name' => 'Super Admin',
                'password' => Hash::make('password123'),
            ]
        );
        $superAdmin->assignRole('super_admin');

        // 3. 建立兩個測試租戶
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

        // 4. 為每個租戶建立管理員和員工
        foreach ($tenants as $index => $tenant) {
            $tenantLetter = $index === 0 ? 'a' : 'b';
            $tenantName = $index === 0 ? 'A' : 'B';

            // 建立Tenant Admin
            $adminEmail = "admin-{$tenantLetter}@example.com";
            $tenantAdmin = User::firstOrCreate(
                ['email' => $adminEmail],
                [
                    'tenant_id' => $tenant->id,
                    'name' => "Tenant {$tenantName} Admin",
                    'password' => Hash::make('password123'),
                ]
            );
            $tenantAdmin->assignRole('tenant_admin');

            // 建立第一個Tenant Staff
            $staff1Email = "staff-{$tenantLetter}1@example.com";
            $tenantStaff1 = User::firstOrCreate(
                ['email' => $staff1Email],
                [
                    'tenant_id' => $tenant->id,
                    'name' => "Tenant {$tenantName} Staff 1",
                    'password' => Hash::make('password123'),
                ]
            );
            $tenantStaff1->assignRole('tenant_staff');

            // 建立第二個Tenant Staff
            $staff2Email = "staff-{$tenantLetter}2@example.com";
            $tenantStaff2 = User::firstOrCreate(
                ['email' => $staff2Email],
                [
                    'tenant_id' => $tenant->id,
                    'name' => "Tenant {$tenantName} Staff 2",
                    'password' => Hash::make('password123'),
                ]
            );
            $tenantStaff2->assignRole('tenant_staff');

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
