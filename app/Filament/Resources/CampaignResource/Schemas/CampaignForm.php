<?php

namespace App\Filament\Resources\CampaignResource\Schemas;

use App\Models\Campaign;
use App\Forms\Components\TenantSelect;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;

class CampaignForm
{
    public static function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('租戶資訊')
                    ->description('設定此活動所屬的租戶')
                    ->schema([
                        TenantSelect::make('tenant_id')
                            ->label('所屬租戶')
                            ->helperText('請選擇此活動所屬的租戶')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('基本資訊')
                    ->description('活動的核心名稱與詳細描述')
                    ->schema([
                        TextInput::make('name')
                            ->label('活動名稱')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('請輸入活動名稱')
                            ->helperText('活動的顯示名稱，將會在會員端顯示')
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->label('活動描述')
                            ->placeholder('請輸入活動的詳細說明...')
                            ->helperText('詳細描述活動的參加方式、獎勵內容等資訊')
                            ->columnSpanFull()
                            ->rows(4),
                    ])
                    ->columnSpanFull(),

                Section::make('活動設定')
                    ->description('活動的狀態與生效時間範圍')
                    ->schema([
                        Grid::make(3)->schema([
                            Select::make('status')
                                ->label('活動狀態')
                                ->options([
                                    Campaign::STATUS_DRAFT => '草稿',
                                    Campaign::STATUS_ACTIVE => '啟用',
                                    Campaign::STATUS_INACTIVE => '停用',
                                    Campaign::STATUS_COMPLETED => '已完成',
                                ])
                                ->required()
                                ->helperText('控制活動是否對外公開'),
                            DateTimePicker::make('starts_at')
                                ->label('活動開始時間')
                                ->required()
                                ->helperText('活動開始生效的時間')
                                ->seconds(false),
                            DateTimePicker::make('ends_at')
                                ->label('活動結束時間')
                                ->required()
                                ->after('starts_at')
                                ->helperText('活動結束的時間，必須晚於開始時間')
                                ->seconds(false),
                        ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
