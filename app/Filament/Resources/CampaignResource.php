<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Models\Campaign;
use App\Filament\Resources\CampaignResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use BackedEnum;

class CampaignResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = Campaign::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';
    protected static string|UnitEnum|null $navigationGroup = '忠誠計劃';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = '活動';
    protected static ?string $pluralModelLabel = '活動';
    protected static ?string $navigationLabel = '活動';

    /**
     * 覆蓋Filament的全域範圍查詢，確保Super Admin能看到所有租戶的活動
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // 套用共用的租戶範圍邏輯
        return static::applyTenantScoping($query, ['tenant']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('租戶資訊')
                    ->description('第一步：選擇此活動所屬的租戶，確定活動的歸屬主體')
                    ->schema([
                        \App\Forms\Components\TenantSelect::make(),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),

                \Filament\Schemas\Components\Section::make('基本資訊')
                    ->description('第二步：設定活動的核心識別資訊，讓會員清楚瞭解活動內容')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('活動名稱')
                            ->placeholder('請輸入活動名稱，例如：2024周年慶積分加倍活動')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->helperText('活動名稱將顯示在會員端與後台列表，建議簡潔明瞭'),
                        Forms\Components\Textarea::make('description')
                            ->label('活動描述')
                            ->placeholder('詳細描述活動的參與規則、獎勵內容、參加條件等資訊...')
                            ->rows(5)
                            ->columnSpanFull()
                            ->helperText('完整的活動描述幫助會員理解如何參與，提升活動參與率'),
                    ])
                    ->columns(1)
                    ->collapsible()
                    ->columnSpanFull(),

                \Filament\Schemas\Components\Section::make('活動設定')
                    ->description('第三步：配置活動的生命週期，包括狀態與時間區間')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('活動狀態')
                            ->placeholder('請選擇活動當前狀態')
                            ->options([
                                Campaign::STATUS_DRAFT => '草稿',
                                Campaign::STATUS_ACTIVE => '進行中',
                                Campaign::STATUS_INACTIVE => '暫停',
                                Campaign::STATUS_COMPLETED => '已結束',
                            ])
                            ->required()
                            ->default(Campaign::STATUS_DRAFT)
                            ->columnSpanFull()
                            ->helperText('草稿：僅後台可見；進行中：會員可參與；暫停：暫停接受參與；已結束：活動正式結束'),
                        Forms\Components\DateTimePicker::make('starts_at')
                            ->label('活動開始時間')
                            ->placeholder('選擇活動開始的日期與時間')
                            ->nullable()
                            ->helperText('留空表示立即開始，活動建立後自動開放參與')
                            ->columnSpan(1),
                        Forms\Components\DateTimePicker::make('ends_at')
                            ->label('活動結束時間')
                            ->placeholder('選擇活動結束的日期與時間')
                            ->nullable()
                            ->helperText('留空表示永久有效，活動將一直開放參與')
                            ->columnSpan(1),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('活動名稱')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Bold),
                Tables\Columns\TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        Campaign::STATUS_DRAFT => 'gray',
                        Campaign::STATUS_ACTIVE => 'success',
                        Campaign::STATUS_INACTIVE => 'warning',
                        Campaign::STATUS_COMPLETED => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        Campaign::STATUS_DRAFT => '草稿',
                        Campaign::STATUS_ACTIVE => '進行中',
                        Campaign::STATUS_INACTIVE => '暫停',
                        Campaign::STATUS_COMPLETED => '已結束',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('starts_at')
                    ->label('開始時間')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('ends_at')
                    ->label('結束時間')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user() && is_null(auth()->user()->tenant_id)),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->color('gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('篩選活動狀態')
                    ->placeholder('全部狀態')
                    ->options([
                        Campaign::STATUS_DRAFT => '草稿',
                        Campaign::STATUS_ACTIVE => '進行中',
                        Campaign::STATUS_INACTIVE => '暫停',
                        Campaign::STATUS_COMPLETED => '已結束',
                    ]),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaigns::route('/'),
            'create' => Pages\CreateCampaign::route('/create'),
            'edit' => Pages\EditCampaign::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'tenant_admin']);
    }

    public static function canCreate(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'tenant_admin']);
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();
        if ($user->hasRole('super_admin')) {
            return true;
        }
        return $user->hasRole('tenant_admin') && $record->tenant_id === $user->tenant_id;
    }

    public static function canDelete(Model $record): bool
    {
        $user = auth()->user();
        if ($user->hasRole('super_admin')) {
            return true;
        }
        return $user->hasRole('tenant_admin') && $record->tenant_id === $user->tenant_id;
    }
}
