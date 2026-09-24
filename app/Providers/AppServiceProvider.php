<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 註冊租戶相關上下文為單例，確保整個請求生命週期中只有一個實例
        $this->app->singleton(\App\Support\Tenancy\TenantContext::class);
        $this->app->singleton(\App\Support\Tenancy\TenantResolver::class);

        $this->app->singleton(\App\Services\Reward\RewardService::class, function ($app) {
            return new \App\Services\Reward\RewardService(
                $app->make(\App\Services\Point\PointService::class),
                $app->make(\App\Support\Tenancy\TenantResolver::class),
                $app->make(\App\Services\Outbox\OutboxService::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 全域處理 super_admin 權限，所有Policy優先通過超級管理員檢查
        Gate::before(function ($user, $ability) {
            // 確保使用者已登入
            if ($user) {
                // 🔑 切換到全域Team ID（0）進行super_admin角色比對，解決Spatie Permission Teams作用域不匹配問題
                $originalTeamId = app(\Spatie\Permission\PermissionRegistrar::class)->getPermissionsTeamId();
                app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId(config('permission.default_team_id', 0));

                // 檢查是否為超級管理員
                $isSuperAdmin = $user->hasRole('super_admin');

                // 還原原本的team_id，避免影響後續其他權限檢查
                app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($originalTeamId);

                // 如果是super_admin，直接通過所有權限檢查
                if ($isSuperAdmin) {
                    return true;
                }
            }
        });

        RateLimiter::for('api', function ($request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // 🔍 啟用查詢日誌，專門追蹤角色相關查詢
        if (app()->environment('local')) {
            DB::listen(function ($query) {
                if (str_contains($query->sql, 'roles') || str_contains($query->sql, 'model_has_roles')) {
                    Log::debug('=== Role Query Debug ===', [
                        'sql' => $query->sql,
                        'bindings' => $query->bindings,
                        'permissions_team_id' => app(\Spatie\Permission\PermissionRegistrar::class)->getPermissionsTeamId(),
                        'filament_tenant' => filament()->getTenant()?->id,
                    ]);
                }
            });
        }

        // 🎯 確保在同步使用者角色時，pivot 表的 team_id 正確設置為使用者的 tenant_id
        \Illuminate\Support\Facades\Event::listen(\Spatie\Permission\Events\SyncingRoles::class, function ($event) {
            $model = $event->model;

            // 只有 User 模型且有 tenant_id 才需要處理
            if ($model instanceof \App\Models\User && $model->tenant_id) {
                // 保存當前使用者的 tenant_id 到 pivot 表的 team_id
                $teamsKey = app(\Spatie\Permission\PermissionRegistrar::class)->teamsKey;
                $event->pivotAttributes[$teamsKey] = $model->tenant_id;
            }
        });

        // 註冊Outbox相關服務
        $this->app->singleton(\App\Services\Outbox\OutboxService::class);

        // 註冊事件監聽器
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\PointEarned::class,
            \App\Listeners\LogPointEarnedEvent::class
        );
    }
}
