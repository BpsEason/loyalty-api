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

        // 合併為單一查詢，使用條件聚合減少資料庫掃描次數
        $grants = RewardGrant::whereIn('status', [
            RewardGrant::STATUS_GRANTED,
            RewardGrant::STATUS_PENDING,
            RewardGrant::STATUS_FAILED,
        ])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw(
                'DATE(created_at) as date,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as granted_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed_count',
                [
                    RewardGrant::STATUS_GRANTED,
                    RewardGrant::STATUS_PENDING,
                    RewardGrant::STATUS_FAILED,
                ]
            )
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        // 整理數據
        $grantedData = [];
        $pendingData = [];
        $failedData = [];

        foreach ($dates as $date) {
            $dayData = $grants->get($date);
            $grantedData[] = $dayData?->granted_count ?? 0;
            $pendingData[] = $dayData?->pending_count ?? 0;
            $failedData[] = $dayData?->failed_count ?? 0;
        }

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

    public function getType(): string
    {
        return 'bar';
    }

    public function getHeading(): string
    {
        return '獎勵發放概況';
    }
}
