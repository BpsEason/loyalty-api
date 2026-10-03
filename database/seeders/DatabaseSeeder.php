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
                'name' => 'Demo Retail',
                'domain' => 'retail.localhost',
                'is_active' => true,
                'settings' => [
                    'currency' => 'TWD',
                    'timezone' => 'Asia/Taipei',
                    'type' => 'retail_store',
                ],
            ],
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
            $tenantLetter = match ($index) {
                0 => 'r', // retail
                1 => 'c', // coffee
                2 => 'f', // fitness
                default => 'x',
            };
            $tenantName = match ($index) {
                0 => 'R', // Retail
                1 => 'C', // Coffee
                2 => 'F', // Fitness
                default => 'X',
            };

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
             * 5. 建立 Point Accounts 與正確的 PointLot / PointTransaction
             * ========================================================
             * 使用 PointService::earn() 確保所有 Point Domain 資料一致性
             */
            $pointService = app(\App\Services\Point\PointService::class);

            // 為每個客戶建立固定的點數批次，用於驗證 FIFO 和過期功能
            foreach ($customers as $customerIndex => $customer) {
                // 先確保 PointAccount 存在
                $pointAccount = PointAccount::firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'customer_id' => $customer->id,
                    ],
                    [
                        'balance' => 0,
                        'total_earned' => 0,
                        'total_redeemed' => 0,
                    ]
                );

                // 只為有需要的客戶補齊點數，避免重複執行時累積
                if ($pointAccount->balance === 0) {
                    // 根據客戶索引和租戶類型，建立對應的點數場景
                    $tenantDomain = $tenant->domain;
                    $pointTransactions = [];

                    // 每個租戶的第一個客戶（高活躍VIP會員）：用於展示FIFO功能
                    if ($customerIndex === 0) {
                        // 為所有租戶的第一個客戶建立FIFO演示的多個時間點點數批次
                        $fifoTransactions = [
                            // 30天前：百貨周年慶消費賺取500點 - 已過期
                            ['date' => '2026-09-03', 'amount' => 500, 'description' => $tenantDomain === 'retail.localhost' ? '周年慶消費累積' : ($tenantDomain === 'coffee.localhost' ? '夏季冰品活動消費' : '年度健身挑戰參與'), 'expire' => '2026-09-23'],
                            // 20天前：日常消費賺取1000點 - 剩餘200點（已消耗800）
                            ['date' => '2026-09-13', 'amount' => 1000, 'description' => $tenantDomain === 'retail.localhost' ? '日用百貨消費累積' : ($tenantDomain === 'coffee.localhost' ? '早餐組合消費累積' : '拳擊課程報名'), 'expire' => null],
                            // 10天前：促銷活動賺取2000點 - 全數剩餘
                            ['date' => '2026-09-23', 'amount' => 2000, 'description' => $tenantDomain === 'retail.localhost' ? '中秋節預購消費' : ($tenantDomain === 'coffee.localhost' ? '新產品試飲活動' : '瑜伽課程套票購買'), 'expire' => null],
                            // 今天：最近消費賺取2000點 - 全數剩餘
                            ['date' => '2026-10-03', 'amount' => 2000, 'description' => $tenantDomain === 'retail.localhost' ? '國慶檔期消費' : ($tenantDomain === 'coffee.localhost' ? '國慶優惠套餐消費' : '國慶健身挑戰營參與'), 'expire' => null],
                        ];
                        $pointTransactions = $fifoTransactions;

                        // 檢查是否已經建立過這些交易，避免重複執行重複建立
                        $existingLots = \App\Models\PointLot::where('customer_id', $customer->id)->count();
                        if ($existingLots === 0) {
                            foreach ($pointTransactions as $txData) {
                                $earnedAt = \Carbon\Carbon::parse($txData['date']);
                                $tx = $pointService->earn($customer, $txData['amount'], $txData['description']);
                                // 更新PointLot的時間
                                $lot = \App\Models\PointLot::where('origin_transaction_id', $tx->id)->first();
                                if ($lot) {
                                    $lot->earned_at = $earnedAt;
                                    if (!empty($txData['expire'])) {
                                        $lot->expired_at = \Carbon\Carbon::parse($txData['expire']);
                                    }
                                    $lot->save();
                                }
                                // 更新交易的建立時間
                                $tx->created_at = $earnedAt;
                                $tx->save();
                            }

                            // 執行一次redeem，消耗800點，展示FIFO機制：從最早的有效批次（2026-09-13的1000點）扣除
                            try {
                                $pointService->redeem($customer, 800, $tenantDomain === 'retail.localhost' ? '家電消費兌換點數' : ($tenantDomain === 'coffee.localhost' ? '季卡兌換點數' : '健身周邊商品兌換'));
                            } catch (\RuntimeException $e) {
                                // 忽略重複執行的錯誤
                            }

                            // 處理所有已過期的點數，確保帳戶餘額與有效點數一致
                            try {
                                $pointService->expireAllExpiredLots($customer);
                            } catch (\RuntimeException $e) {
                                // 忽略沒有過期點數的情況
                            }
                        }
                    }
                    // 每個租戶的第二個客戶（一般會員）：建立基本點數
                    elseif ($customerIndex === 1) {
                        $normalTransactions = [
                            ['date' => '2026-09-20', 'amount' => 2500, 'description' => '日常消費累積點數', 'expire' => null],
                        ];
                        $pointTransactions = $normalTransactions;

                        // 檢查是否已有點數，避免重複建立
                        $existingAccount = PointAccount::where('customer_id', $customer->id)->first();
                        if (!$existingAccount || $existingAccount->balance === 0) {
                            foreach ($pointTransactions as $txData) {
                                $earnedAt = \Carbon\Carbon::parse($txData['date']);
                                $tx = $pointService->earn($customer, $txData['amount'], $txData['description']);
                                $lot = \App\Models\PointLot::where('origin_transaction_id', $tx->id)->first();
                                if ($lot) {
                                    $lot->earned_at = $earnedAt;
                                    if (!empty($txData['expire'])) {
                                        $lot->expired_at = \Carbon\Carbon::parse($txData['expire']);
                                    }
                                    $lot->save();
                                }
                                $tx->created_at = $earnedAt;
                                $tx->save();
                            }

                            // 處理所有已過期的點數，確保帳戶餘額與有效點數一致
                            try {
                                $pointService->expireAllExpiredLots($customer);
                            } catch (\RuntimeException $e) {
                                // 忽略沒有過期點數的情況
                            }
                        }
                    }
                    // 每個租戶的第三個客戶（即將過期/即將失效會員）：用於展示點數過期功能
                    elseif ($customerIndex === 2) {
                        $expiryTransactions = [
                            // 已過期的點數批次
                            ['date' => '2026-08-01', 'amount' => 300, 'description' => '年初消費累積', 'expire' => '2026-09-30'],
                            // 即將過期的點數批次（5天後過期）
                            ['date' => '2026-09-28', 'amount' => 800, 'description' => '上月消費累積', 'expire' => '2026-10-08'],
                            // 有效點數批次
                            ['date' => '2026-10-01', 'amount' => 1200, 'description' => '本月消費累積', 'expire' => '2027-04-01'],
                        ];
                        $pointTransactions = $expiryTransactions;

                        $existingAccount = PointAccount::where('customer_id', $customer->id)->first();
                        if (!$existingAccount || $existingAccount->balance === 0) {
                            foreach ($pointTransactions as $txData) {
                                $earnedAt = \Carbon\Carbon::parse($txData['date']);
                                $tx = $pointService->earn($customer, $txData['amount'], $txData['description']);
                                $lot = \App\Models\PointLot::where('origin_transaction_id', $tx->id)->first();
                                if ($lot) {
                                    $lot->earned_at = $earnedAt;
                                    if (!empty($txData['expire'])) {
                                        $lot->expired_at = \Carbon\Carbon::parse($txData['expire']);
                                    }
                                    $lot->save();
                                }
                                $tx->created_at = $earnedAt;
                                $tx->save();
                            }

                            // 處理所有已過期的點數，確保帳戶餘額與有效點數一致
                            try {
                                $pointService->expireAllExpiredLots($customer);
                            } catch (\RuntimeException $e) {
                                // 忽略沒有過期點數的情況
                            }
                        }
                    }
                    // 每個租戶的第四個客戶（新進會員）：只有少量點數，展示新會員場景
                    elseif ($customerIndex === 3) {
                        $newMemberTransactions = [
                            ['date' => '2026-09-30', 'amount' => 300, 'description' => '首次註冊歡迎點數', 'expire' => null],
                        ];
                        $pointTransactions = $newMemberTransactions;

                        $existingAccount = PointAccount::where('customer_id', $customer->id)->first();
                        if (!$existingAccount || $existingAccount->balance === 0) {
                            foreach ($pointTransactions as $txData) {
                                $earnedAt = \Carbon\Carbon::parse($txData['date']);
                                $tx = $pointService->earn($customer, $txData['amount'], $txData['description']);
                                $lot = \App\Models\PointLot::where('origin_transaction_id', $tx->id)->first();
                                if ($lot) {
                                    $lot->earned_at = $earnedAt;
                                    if (!empty($txData['expire'])) {
                                        $lot->expired_at = \Carbon\Carbon::parse($txData['expire']);
                                    }
                                    $lot->save();
                                }
                                $tx->created_at = $earnedAt;
                                $tx->save();
                            }
                        }
                    }
                    // 每個租戶的第五個客戶（沉睡會員）：有歷史點數但長時間未活躍，展示沉睡會員場景
                    elseif ($customerIndex === 4) {
                        $dormantTransactions = [
                            // 半年前的歷史點數，部分已過期
                            ['date' => '2026-04-01', 'amount' => 1500, 'description' => '年度會員回饋點數', 'expire' => '2026-09-30'],
                            // 最後一次消費的點數，3個月前
                            ['date' => '2026-07-03', 'amount' => 800, 'description' => '最後一次消費累積', 'expire' => '2027-01-03'],
                        ];
                        $pointTransactions = $dormantTransactions;

                        $existingAccount = PointAccount::where('customer_id', $customer->id)->first();
                        if (!$existingAccount || $existingAccount->balance === 0) {
                            foreach ($pointTransactions as $txData) {
                                $earnedAt = \Carbon\Carbon::parse($txData['date']);
                                $tx = $pointService->earn($customer, $txData['amount'], $txData['description']);
                                $lot = \App\Models\PointLot::where('origin_transaction_id', $tx->id)->first();
                                if ($lot) {
                                    $lot->earned_at = $earnedAt;
                                    if (!empty($txData['expire'])) {
                                        $lot->expired_at = \Carbon\Carbon::parse($txData['expire']);
                                    }
                                    $lot->save();
                                }
                                $tx->created_at = $earnedAt;
                                $tx->save();
                            }
                        }
                    }
                }
            }
        }

        /*
         * ============================================================
         * 7. Reward & Coupon Demo Data
         * ============================================================
         * 執行順序非常重要：
         * 1. RewardSeeder 先執行，建立所有 Tenant / Customer / Campaign 等基礎 Demo 資料
         * 2. CouponSeeder 依賴 RewardSeeder 已建立的資料，只負責建立 Coupon 相關資料
         * CouponSeeder 是所有 Coupon Demo Data 的唯一來源，避免重複建立
         */
        $this->call(RewardSeeder::class);
        $this->call(CouponSeeder::class);

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
        // 根據租戶類型建立對應的業務故事客戶
        $customerSeeds = match ($tenant->domain) {
            'retail.localhost' => [
                // Retail 零售租戶的客戶persona
                [
                    'name' => '陳美玲',
                    'email' => "customer{$tenantLetter}1@example.com",
                    'phone' => '0911000001',
                    'tier' => 'platinum',
                    'persona' => 'VIP高活躍會員',
                    'description' => '百貨公司高消費會員，每月固定消費，有大量歷史點數累積'
                ],
                [
                    'name' => '王大偉',
                    'email' => "customer{$tenantLetter}2@example.com",
                    'phone' => '0911000002',
                    'tier' => 'gold',
                    'persona' => '一般會員',
                    'description' => '偶爾消費的中等活躍會員'
                ],
                [
                    'name' => '林小芳',
                    'email' => "customer{$tenantLetter}3@example.com",
                    'phone' => '0911000003',
                    'tier' => 'silver',
                    'persona' => '即將過期會員',
                    'description' => '有即將過期的點數，需要喚回'
                ],
                [
                    'name' => '張建國',
                    'email' => "customer{$tenantLetter}4@example.com",
                    'phone' => '0911000004',
                    'tier' => 'silver',
                    'persona' => '新進會員',
                    'description' => '剛加入的新會員'
                ],
                [
                    'name' => '吳美麗',
                    'email' => "customer{$tenantLetter}5@example.com",
                    'phone' => '0911000005',
                    'tier' => 'bronze',
                    'persona' => '沉睡會員',
                    'description' => '長時間未消費的沉睡會員'
                ],
            ],
            'coffee.localhost' => [
                // Coffee 咖啡租戶的客戶persona
                [
                    'name' => '劉雅婷',
                    'email' => "customer{$tenantLetter}1@example.com",
                    'phone' => '0911555666',
                    'tier' => 'gold',
                    'persona' => '早餐高頻會員',
                    'description' => '每日早上來店消費早餐的高頻會員'
                ],
                [
                    'name' => '周慧雯',
                    'email' => "customer{$tenantLetter}2@example.com",
                    'phone' => '0933999000',
                    'tier' => 'gold',
                    'persona' => '生日會員',
                    'description' => '本月生日會員，已獲得生日獎勵'
                ],
                [
                    'name' => '鄭建宏',
                    'email' => "customer{$tenantLetter}3@example.com",
                    'phone' => '0944111222',
                    'tier' => 'silver',
                    'persona' => '沉睡會員',
                    'description' => '三個月未消費的沉睡會員'
                ],
                [
                    'name' => '何欣宜',
                    'email' => "customer{$tenantLetter}4@example.com",
                    'phone' => '0955333444',
                    'tier' => 'silver',
                    'persona' => '周末會員',
                    'description' => '只有周末來店消費的會員'
                ],
                [
                    'name' => '楊志偉',
                    'email' => "customer{$tenantLetter}5@example.com",
                    'phone' => '0922777888',
                    'tier' => 'bronze',
                    'persona' => '新客',
                    'description' => '首次來店消費的新會員'
                ],
            ],
            'fitness.localhost' => [
                // Fitness 健身租戶的客戶persona
                [
                    'name' => '曾俊傑',
                    'email' => "customer{$tenantLetter}1@example.com",
                    'phone' => '0911888999',
                    'tier' => 'platinum',
                    'persona' => '高價值VIP會員',
                    'description' => '健身房頂級會員，長期參與各種課程'
                ],
                [
                    'name' => '彭建宇',
                    'email' => "customer{$tenantLetter}2@example.com",
                    'phone' => '0933222333',
                    'tier' => 'gold',
                    'persona' => '課程消費會員',
                    'description' => '持續報名各種健身課程的會員'
                ],
                [
                    'name' => '蔡淑華',
                    'email' => "customer{$tenantLetter}3@example.com",
                    'phone' => '0922000111',
                    'tier' => 'gold',
                    'persona' => '續約會員',
                    'description' => '剛續約年費的忠實會員'
                ],
                [
                    'name' => '蘇美華',
                    'email' => "customer{$tenantLetter}4@example.com",
                    'phone' => '0944444555',
                    'tier' => 'silver',
                    'persona' => '即將失效會員',
                    'description' => '會員資格即將到期，點數即將過期'
                ],
                [
                    'name' => '鄧文彬',
                    'email' => "customer{$tenantLetter}5@example.com",
                    'phone' => '0955666777',
                    'tier' => 'bronze',
                    'persona' => '健身新手',
                    'description' => '剛加入的健身新手'
                ],
            ],
            default => [
                // 預設通用客戶
                [
                    'name' => "Customer {$tenantName}1",
                    'email' => "customer{$tenantLetter}1@example.com",
                    'phone' => '0911000001',
                    'tier' => 'gold',
                    'persona' => '一般會員',
                    'description' => '通用會員'
                ],
                [
                    'name' => "Customer {$tenantName}2",
                    'email' => "customer{$tenantLetter}2@example.com",
                    'phone' => '0911000002',
                    'tier' => 'gold',
                    'persona' => '一般會員',
                    'description' => '通用會員'
                ],
                [
                    'name' => "Customer {$tenantName}3",
                    'email' => "customer{$tenantLetter}3@example.com",
                    'phone' => '0911000003',
                    'tier' => 'silver',
                    'persona' => '一般會員',
                    'description' => '通用會員'
                ],
                [
                    'name' => "Customer {$tenantName}4",
                    'email' => "customer{$tenantLetter}4@example.com",
                    'phone' => '0911000004',
                    'tier' => 'silver',
                    'persona' => '一般會員',
                    'description' => '通用會員'
                ],
                [
                    'name' => "Customer {$tenantName}5",
                    'email' => "customer{$tenantLetter}5@example.com",
                    'phone' => '0911000005',
                    'tier' => 'bronze',
                    'persona' => '一般會員',
                    'description' => '通用會員'
                ],
            ]
        };

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
                        'persona' => $customerData['persona'],
                        'description' => $customerData['description'],
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
}
