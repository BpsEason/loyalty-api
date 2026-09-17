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

        // 會員統計
        $totalCustomers = Customer::count();
        $todayNewCustomers = Customer::whereDate('created_at', $today)->count();
        $thisMonthNewCustomers = Customer::where('created_at', '>=', $thisMonthStart)->count();

        // 點數帳戶統計
        $totalPointAccounts = PointAccount::count();
        $totalCurrentBalance = PointAccount::sum('balance');

        // 點數交易統計
        $todayEarnPoints = PointTransaction::where('type', PointTransaction::TYPE_EARN)
            ->whereDate('created_at', $today)
            ->sum('amount');
        $todayRedeemPoints = PointTransaction::where('type', PointTransaction::TYPE_REDEEM)
            ->whereDate('created_at', $today)
            ->sum('amount');
        $totalTransactions = PointTransaction::count();

        // 活動統計
        $activeCampaigns = Campaign::where('status', Campaign::STATUS_ACTIVE)->count();
        $totalCampaigns = Campaign::count();

        // 獎勵發放統計
        $totalRewardGrants = RewardGrant::count();
        $todayRewardGrants = RewardGrant::whereDate('granted_at', $today)->count();

        return [
            Stat::make('會員總數', number_format($totalCustomers))
                ->description("今日新增 {$todayNewCustomers} · 本月新增 {$thisMonthNewCustomers}")
                ->descriptionIcon('heroicon-m-users')
                ->color('success'),

            Stat::make('點數帳戶數', number_format($totalPointAccounts))
                ->description("目前流通點數 " . number_format($totalCurrentBalance))
                ->descriptionIcon('heroicon-m-wallet')
                ->color('primary'),

            Stat::make('交易筆數', number_format($totalTransactions))
                ->description("今日獲得 {$todayEarnPoints} · 今日消耗 {$todayRedeemPoints}")
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('warning'),

            Stat::make('進行中活動', number_format($activeCampaigns))
                ->description("總共 {$totalCampaigns} 個活動")
                ->descriptionIcon('heroicon-m-megaphone')
                ->color('info'),
        ];
    }
}
