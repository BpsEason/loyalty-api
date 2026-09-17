<?php

namespace App\Filament\Widgets;

use App\Models\Campaign;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class CampaignOverviewWidget extends ChartWidget
{
    protected static ?int $sort = 4;
    protected int | string | array $columnSpan = 'half';

    protected function getData(): array
    {
        $statusCounts = Campaign::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $statusLabels = [
            Campaign::STATUS_DRAFT => '草稿',
            Campaign::STATUS_ACTIVE => '進行中',
            Campaign::STATUS_INACTIVE => '已停用',
            Campaign::STATUS_COMPLETED => '已完成',
        ];

        $statusColors = [
            Campaign::STATUS_DRAFT => '#6b7280',
            Campaign::STATUS_ACTIVE => '#10b981',
            Campaign::STATUS_INACTIVE => '#f59e0b',
            Campaign::STATUS_COMPLETED => '#3b82f6',
        ];

        $labels = [];
        $data = [];
        $colors = [];

        foreach ($statusLabels as $status => $label) {
            $labels[] = $label;
            $data[] = $statusCounts->get($status, 0);
            $colors[] = $statusColors[$status];
        }

        return [
            'datasets' => [
                [
                    'data' => $data,
                    'backgroundColor' => $colors,
                    'borderWidth' => 0,
                ],
            ],
            'labels' => $labels,
        ];
    }

    public function getType(): string
    {
        return 'doughnut';
    }

    public function getHeading(): string
    {
        return '活動狀態總覽';
    }
}
