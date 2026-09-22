<?php

namespace App\Filament\Widgets;

use App\Models\UserCoupon;
use App\Models\CouponRedemption;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CouponStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $now = Carbon::now();

        // 本月領取的優惠券數
        $totalClaimed = UserCoupon::whereBetween('issued_at', [$startOfMonth, $now])->count();

        // 本月核銷的優惠券數
        $totalRedeemed = CouponRedemption::whereBetween('redeemed_at', [$startOfMonth, $now])->count();

        // 使用率
        $usageRate = $totalClaimed > 0 ? round(($totalRedeemed / $totalClaimed) * 100, 1) : 0;

        return [
            Stat::make('本月領取優惠券', $totalClaimed)
                ->description('本月顧客累計領取')
                ->color('blue'),

            Stat::make('本月核銷優惠券', $totalRedeemed)
                ->description('本月顧客累計使用')
                ->color('success'),

            Stat::make('使用率', $usageRate . '%')
                ->description('核銷/領取比率')
                ->color($usageRate > 50 ? 'success' : ($usageRate > 30 ? 'warning' : 'danger')),
        ];
    }
}
