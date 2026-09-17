<?php

namespace App\Filament\Widgets;

use App\Models\RewardGrant;
use App\Models\CampaignReward;
use Filament\Widgets\ChartWidget;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class RewardOverviewWidget extends ChartWidget
{
    protected static ?int $sort = 5;
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

        // 各狀態的獎勵發放數據
        $grantedData = $this->getRewardGrantDataByStatus(RewardGrant::STATUS_GRANTED, $startDate, $endDate, $dates);
        $pendingData = $this->getRewardGrantDataByStatus(RewardGrant::STATUS_PENDING, $startDate, $endDate, $dates);
        $failedData = $this->getRewardGrantDataByStatus(RewardGrant::STATUS_FAILED, $startDate, $endDate, $dates);

        return [
            'datasets' => [
                [
                    'label' => '已發放',
                    'data' => $grantedData,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.5)',
                ],
                [
                    'label' => '處理中',
                    'data' => $pendingData,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.5)',
                ],
                [
                    'label' => '發放失敗',
                    'data' => $failedData,
                    'borderColor' => '#ef4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.5)',
                ],
            ],
            'labels' => $dates->map(fn($date) => Carbon::parse($date)->format('m/d'))->toArray(),
        ];
    }

    protected function getRewardGrantDataByStatus(string $status, Carbon $startDate, Carbon $endDate, $dates): array
    {
        $grants = RewardGrant::where('status', $status)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('date')
            ->pluck('count', 'date');

        return $dates->map(fn($date) => $grants->get($date) ?? 0)->toArray();
    }

    public function getType(): string
    {
        return 'bar';
    }

    public function getHeading(): string
    {
        return '獎勵發放概況';
    }
}
