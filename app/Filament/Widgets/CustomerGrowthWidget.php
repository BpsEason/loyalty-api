<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use Filament\Widgets\ChartWidget;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class CustomerGrowthWidget extends ChartWidget
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

        $newCustomers = Customer::whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('date')
            ->pluck('count', 'date');

        // 計算累積會員數
        $cumulativeData = [];
        $runningTotal = Customer::where('created_at', '<', $startDate)->count();

        foreach ($dates as $date) {
            $dailyCount = $newCustomers->get($date) ?? 0;
            $runningTotal += $dailyCount;
            $cumulativeData[] = $runningTotal;
        }

        return [
            'datasets' => [
                [
                    'label' => '累積會員數',
                    'data' => $cumulativeData,
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => '每日新增',
                    'data' => $dates->map(fn($date) => $newCustomers->get($date) ?? 0)->toArray(),
                    'borderColor' => '#8b5cf6',
                    'backgroundColor' => 'rgba(139, 92, 246, 0.1)',
                    'fill' => false,
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
        return '會員成長趨勢';
    }
}
