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
                Forms\Components\Select::make('campaign_id')
                    ->label('活動')
                    ->relationship('campaign', 'name', function (Builder $query) {
                        $user = auth()->user();

                        if ($user && $user->hasRole('super_admin')) {
                            return $query->withoutGlobalScope('tenant');
                        }

                        return $query->where('tenant_id', $user?->tenant_id);
                    })
                    ->required()
                    ->searchable(),
                Forms\Components\Select::make('reward_type')
                    ->label('獎勵類型')
                    ->options([
                        CampaignReward::TYPE_POINTS => '點數',
                        CampaignReward::TYPE_BADGE => '徽章',
                        CampaignReward::TYPE_COUPON => '優惠券',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('points')
                    ->label('點數數量')
                    ->numeric()
                    ->default(0)
                    ->required(),
                Forms\Components\Toggle::make('enabled')
                    ->label('是否啟用')
                    ->default(true)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('campaign.name')
                    ->label('活動')
                    ->searchable(),
                Tables\Columns\TextColumn::make('reward_type')
                    ->label('獎勵類型')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        CampaignReward::TYPE_POINTS => 'success',
                        CampaignReward::TYPE_BADGE => 'warning',
                        CampaignReward::TYPE_COUPON => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('points')
                    ->label('點數數量')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('enabled')
                    ->label('是否啟用')
                    ->boolean(),
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
                    ->label('獎勵類型')
                    ->options([
                        CampaignReward::TYPE_POINTS => '點數',
                        CampaignReward::TYPE_BADGE => '徽章',
                        CampaignReward::TYPE_COUPON => '優惠券',
                    ]),
                Tables\Filters\TernaryFilter::make('enabled')
                    ->label('是否啟用'),
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
