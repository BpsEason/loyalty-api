<?php

namespace App\Filament\Widgets;

use App\Models\PointTransaction;
use Filament\Widgets\ChartWidget;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class PointTrendWidget extends ChartWidget
{
    protected static ?int $sort = 2;
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

        // 使用資料庫條件聚合，由MySQL處理GROUP BY和SUM，減少PHP記憶體使用
        $transactions = PointTransaction::whereIn('type', [
            PointTransaction::TYPE_EARN,
            PointTransaction::TYPE_REDEEM,
            PointTransaction::TYPE_EXPIRE,
        ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as transaction_date,
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as earn_total,
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as redeem_total,
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as expire_total', [
                PointTransaction::TYPE_EARN,
                PointTransaction::TYPE_REDEEM,
                PointTransaction::TYPE_EXPIRE,
            ])
            ->groupBy('transaction_date')
            ->get()
            ->keyBy('transaction_date');

        // 整理數據
        $earnData = [];
        $redeemData = [];
        $expireData = [];

        foreach ($dates as $date) {
            $dayData = $transactions->get($date);
            $earnData[] = $dayData?->earn_total ?? 0;
            $redeemData[] = $dayData?->redeem_total ?? 0;
            $expireData[] = $dayData?->expire_total ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => '獲得點數',
                    'data' => $earnData,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => '使用點數',
                    'data' => $redeemData,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => '過期點數',
                    'data' => $expireData,
                    'borderColor' => '#ef4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
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

    public function getHeading(): string
    {
        return '點數流動趨勢';
    }
}
