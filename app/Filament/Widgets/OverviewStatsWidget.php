<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Models\PointAccount;
use App\Models\PointTransaction;
use App\Models\Campaign;
use App\Models\RewardGrant;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Carbon\Carbon;

class OverviewStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $today = Carbon::today();
        $thisMonthStart = Carbon::now()->startOfMonth();

        // 活躍會員（30天內有交易的會員）
        $activeCustomers = Customer::whereHas('pointAccounts.pointTransactions', function ($query) use ($today) {
            $query->where('created_at', '>=', $today->copy()->subDays(30));
        })->count();

        // 會員統計
        $totalCustomers = Customer::count();
        $todayNewCustomers = Customer::whereDate('created_at', $today)->count();

        // 點數發放與兌換統計
        $monthEarnPoints = PointTransaction::where('type', PointTransaction::TYPE_EARN)
            ->where('created_at', '>=', $thisMonthStart)
            ->sum('amount');
        $monthRedeemPoints = PointTransaction::where('type', PointTransaction::TYPE_REDEEM)
            ->where('created_at', '>=', $thisMonthStart)
            ->sum('amount');
        $todayEarnPoints = PointTransaction::where('type', PointTransaction::TYPE_EARN)
            ->whereDate('created_at', $today)
            ->sum('amount');
        $todayRedeemPoints = PointTransaction::where('type', PointTransaction::TYPE_REDEEM)
            ->whereDate('created_at', $today)
            ->sum('amount');

        // 活動統計
        $activeCampaigns = Campaign::where('status', Campaign::STATUS_ACTIVE)->count();
        $draftCampaigns = Campaign::where('status', Campaign::STATUS_DRAFT)->count();
        $completedCampaigns = Campaign::where('status', Campaign::STATUS_COMPLETED)->count();

        return [
            Stat::make('會員總數', number_format($totalCustomers))
                ->description("今日新增 {$todayNewCustomers} 位 · 活躍會員 " . number_format($activeCustomers))
                ->descriptionIcon('heroicon-m-users')
                ->color('success'),

            Stat::make('活躍會員', number_format($activeCustomers))
                ->description("佔總會員 " . number_format(($totalCustomers > 0 ? ($activeCustomers / $totalCustomers * 100) : 0), 1) . "%")
                ->descriptionIcon('heroicon-m-user')
                ->color('primary'),

            Stat::make('本月點數發放', number_format($monthEarnPoints))
                ->description("今日新增 " . number_format($todayEarnPoints))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('本月點數兌換', number_format($monthRedeemPoints))
                ->description("今日消耗 " . number_format($todayRedeemPoints))
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->color('warning'),
        ];
    }
}
