<?php

namespace App\Filament\Widgets;

use App\Models\PointTransaction;
use Filament\Widgets\ChartWidget;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

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

        $earnData = $this->getTransactionDataByType(PointTransaction::TYPE_EARN, $startDate, $endDate, $dates);
        $redeemData = $this->getTransactionDataByType(PointTransaction::TYPE_REDEEM, $startDate, $endDate, $dates);
        $expireData = $this->getTransactionDataByType(PointTransaction::TYPE_EXPIRE, $startDate, $endDate, $dates);

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

    protected function getTransactionDataByType(string $type, Carbon $startDate, Carbon $endDate, $dates): array
    {
        $transactions = PointTransaction::where('type', $type)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('date')
            ->pluck('total', 'date');

        return $dates->map(fn($date) => $transactions->get($date) ?? 0)->toArray();
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
