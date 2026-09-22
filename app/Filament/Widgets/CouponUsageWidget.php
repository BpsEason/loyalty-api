<?php

namespace App\Filament\Widgets;

use App\Models\UserCoupon;
use App\Models\CouponRedemption;
use Filament\Widgets\ChartWidget;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class CouponUsageWidget extends ChartWidget
{
    protected static ?int $sort = 3;
    protected int | string | array $columnSpan = 'half';

    public ?string $filter = '7';

    protected function getFilters(): ?array
    {
        return [
            '7' => '最近7天',
            '30' => '最近30天',
            '90' => '最近90天',
        ];
    }

    protected function getData(): array
    {
        $days = (int) $this->filter;
        $startDate = Carbon::now()->subDays($days - 1)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $period = CarbonPeriod::create($startDate, $endDate);
        $dates = collect();
        foreach ($period as $date) {
            $dates->push($date->format('Y-m-d'));
        }

        // 查詢每日領取和核銷的優惠券數量
        $couponStats = UserCoupon::whereBetween('issued_at', [$startDate, $endDate])
            ->selectRaw('DATE(issued_at) as stat_date,
                COUNT(*) as claimed_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as used_count', [
                UserCoupon::STATUS_USED,
            ])
            ->groupBy('stat_date')
            ->get()
            ->keyBy('stat_date');

        // 整理數據
        $claimedData = [];
        $usedData = [];
        $usageRates = [];

        foreach ($dates as $date) {
            $dayData = $couponStats->get($date);
            $claimed = $dayData?->claimed_count ?? 0;
            $used = $dayData?->used_count ?? 0;

            $claimedData[] = $claimed;
            $usedData[] = $used;
            $usageRates[] = $claimed > 0 ? round(($used / $claimed) * 100, 1) : 0;
        }

        return [
            'datasets' => [
                [
                    'label' => '領取數量',
                    'data' => $claimedData,
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => '核銷數量',
                    'data' => $usedData,
                    'borderColor' => '#8b5cf6',
                    'backgroundColor' => 'rgba(139, 92, 246, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $dates->map(fn($date) => Carbon::parse($date)->format('m/d'))->toArray(),
        ];
    }

    public function getType(): string
    {
        return 'line';
    }
}
