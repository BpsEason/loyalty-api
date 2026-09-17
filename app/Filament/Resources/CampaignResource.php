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
                \App\Forms\Components\TenantSelect::make(),

                \Filament\Schemas\Components\Section::make('基本資訊')
                    ->description('設定活動的核心資訊')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('活動名稱')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),
                        Forms\Components\Textarea::make('description')
                            ->label('活動描述')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible(),

                \Filament\Schemas\Components\Section::make('活動設定')
                    ->description('設定活動的時間與狀態')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('活動狀態')
                            ->options([
                                Campaign::STATUS_DRAFT => '草稿',
                                Campaign::STATUS_ACTIVE => '進行中',
                                Campaign::STATUS_INACTIVE => '暫停',
                                Campaign::STATUS_COMPLETED => '已結束',
                            ])
                            ->required()
                            ->default(Campaign::STATUS_DRAFT),
                        Forms\Components\DateTimePicker::make('starts_at')
                            ->label('活動開始時間')
                            ->nullable()
                            ->helperText('留空表示立即開始'),
                        Forms\Components\DateTimePicker::make('ends_at')
                            ->label('活動結束時間')
                            ->nullable()
                            ->helperText('留空表示永久有效'),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(function () {
                // 統一使用getEloquentQuery()，避免重複邏輯導致衝突
                return static::getEloquentQuery();
            })
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('活動名稱')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        Campaign::STATUS_DRAFT => 'gray',
                        Campaign::STATUS_ACTIVE => 'success',
                        Campaign::STATUS_INACTIVE => 'warning',
                        Campaign::STATUS_COMPLETED => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('starts_at')
                    ->label('開始時間')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ends_at')
                    ->label('結束時間')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user() && is_null(auth()->user()->tenant_id)),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('狀態')
                    ->options([
                        Campaign::STATUS_DRAFT => '草稿',
                        Campaign::STATUS_ACTIVE => '啟用',
                        Campaign::STATUS_INACTIVE => '停用',
                        Campaign::STATUS_COMPLETED => '已完成',
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
