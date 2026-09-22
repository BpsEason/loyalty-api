<?php

namespace App\Filament\Resources;

use App\Models\CampaignReward;
use App\Filament\Resources\CampaignRewardResource\Pages;
use App\Filament\Concerns\HandlesTenantScoping;
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

class CampaignRewardResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = CampaignReward::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-gift';
    protected static string|UnitEnum|null $navigationGroup = '忠誠計劃';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = '活動獎勵';
    protected static ?string $pluralModelLabel = '活動獎勵';
    protected static ?string $navigationLabel = '活動獎勵';
    protected static ?string $tenantOwnershipRelationshipName = 'campaign';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return static::applyTenantScoping($query, ['campaign.tenant']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make('活動資訊')
                    ->description('第一步：選擇這個獎勵所屬的活動，確定獎勵的歸屬關係')
                    ->schema([
                        Forms\Components\Select::make('campaign_id')
                            ->label('所屬活動')
                            ->placeholder('請搜尋並選擇一個活動')
                            ->relationship('campaign', 'name', function (Builder $query) {
                                $user = auth()->user();
                                $panel = filament()->getCurrentOrDefaultPanel();
                                $filamentTenancyScopeName = $panel?->hasTenancy() ? $panel->getTenancyScopeName() : null;

                                // 先移除所有租戶相關的全域範疇，和 HandlesTenantScoping 保持一致
                                if ($filamentTenancyScopeName) {
                                    $query->withoutGlobalScope($filamentTenancyScopeName);
                                }
                                $query->withoutGlobalScope('tenant');

                                // 如果不是 Super Admin，再套用目前使用者的租戶限制
                                if (!($user && $user->hasRole('super_admin'))) {
                                    return $query->where('tenant_id', $user?->tenant_id);
                                }

                                return $query;
                            })
                            ->required()
                            ->searchable()
                            ->columnSpanFull()
                            ->helperText('此獎勵將隸屬於您選擇的活動，只有對應活動啟用時此獎勵才會生效'),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),

                \Filament\Schemas\Components\Section::make('獎勵設定')
                    ->description('第二步：配置獎勵的具體參數，包括類型、數量和啟用狀態')
                    ->schema([
                        Forms\Components\Select::make('reward_type')
                            ->label('獎勵類型')
                            ->placeholder('請選擇獎勵類型')
                            ->options([
                                CampaignReward::TYPE_POINTS => '點數',
                                CampaignReward::TYPE_BADGE => '徽章',
                                CampaignReward::TYPE_COUPON => '優惠券',
                            ])
                            ->required()
                            ->helperText('點數：會員可累積的積分；徽章：成就類榮譽標誌；優惠券：可兌換的折扣券')
                            ->columnSpan(1),
                        Forms\Components\TextInput::make('points')
                            ->label('點數數量')
                            ->placeholder('輸入點數數量')
                            ->numeric()
                            ->default(0)
                            ->required()
                            ->helperText('設定獎勵發放的點數數量，所有類型的獎勵皆可設定')
                            ->columnSpan(1),
                        Forms\Components\Toggle::make('enabled')
                            ->label('立即啟用此獎勵')
                            ->default(true)
                            ->required()
                            ->helperText('開啟後，此獎勵將立即生效並可派發給符合條件的會員')
                            ->columnSpan(1),
                    ])
                    ->columns(3)
                    ->collapsible()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('campaign.name')
                    ->label('活動')
                    ->searchable()
                    ->weight(FontWeight::Bold),
                Tables\Columns\TextColumn::make('reward_type')
                    ->label('獎勵類型')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        CampaignReward::TYPE_POINTS => 'success',
                        CampaignReward::TYPE_BADGE => 'warning',
                        CampaignReward::TYPE_COUPON => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        CampaignReward::TYPE_POINTS => '點數',
                        CampaignReward::TYPE_BADGE => '徽章',
                        CampaignReward::TYPE_COUPON => '優惠券',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('points')
                    ->label('點數數量')
                    ->numeric()
                    ->sortable()
                    ->alignRight()
                    ->weight(FontWeight::Medium),
                Tables\Columns\IconColumn::make('enabled')
                    ->label('狀態')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),
                Tables\Columns\TextColumn::make('campaign.tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()->hasRole('super_admin')),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('reward_type')
                    ->label('篩選獎勵類型')
                    ->placeholder('全部類型')
                    ->options([
                        CampaignReward::TYPE_POINTS => '點數',
                        CampaignReward::TYPE_BADGE => '徽章',
                        CampaignReward::TYPE_COUPON => '優惠券',
                    ]),
                Tables\Filters\TernaryFilter::make('enabled')
                    ->label('篩選啟用狀態')
                    ->placeholder('全部狀態')
                    ->trueLabel('已啟用')
                    ->falseLabel('已停用'),
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
            'index' => Pages\ListCampaignRewards::route('/'),
            'create' => Pages\CreateCampaignReward::route('/create'),
            'edit' => Pages\EditCampaignReward::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'tenant_admin']) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'tenant_admin']) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        // 利用已加載的 campaign 進行比對，避免 Lazy Loading 產生額外查詢
        return $user->hasRole('tenant_admin') && $record->campaign?->tenant_id === $user->tenant_id;
    }

    public static function canDelete(Model $record): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->hasRole('tenant_admin') && $record->campaign?->tenant_id === $user->tenant_id;
    }
}
