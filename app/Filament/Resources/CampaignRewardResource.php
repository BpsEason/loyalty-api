<?php

namespace App\Filament\Resources;

use App\Models\CampaignReward;
use App\Filament\Resources\CampaignRewardResource\Pages;
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
    protected static ?string $model = CampaignReward::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-gift';
    protected static string|UnitEnum|null $navigationGroup = 'Rewards';
    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Select::make('campaign_id')
                    ->label('Campaign')
                    ->relationship('campaign', 'name')
                    ->required()
                    ->searchable(),
                Forms\Components\Select::make('reward_type')
                    ->label('Reward Type')
                    ->options([
                        CampaignReward::TYPE_POINTS => 'Points',
                        CampaignReward::TYPE_BADGE => 'Badge',
                        CampaignReward::TYPE_COUPON => 'Coupon',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('points')
                    ->label('Points Amount')
                    ->numeric()
                    ->default(0)
                    ->required(),
                Forms\Components\Toggle::make('enabled')
                    ->label('Enabled')
                    ->default(true)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $user = auth()->user();
                if ($user->hasRole('super_admin')) {
                    return CampaignReward::with(['campaign.tenant']);
                }
                return CampaignReward::whereHas('campaign', function (Builder $query) use ($user) {
                    $query->where('tenant_id', $user->tenant_id);
                })->with(['campaign.tenant']);
            })
            ->columns([
                Tables\Columns\TextColumn::make('campaign.name')
                    ->label('Campaign')
                    ->searchable(),
                Tables\Columns\TextColumn::make('reward_type')
                    ->badge(),
                Tables\Columns\TextColumn::make('points')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('enabled')
                    ->boolean(),
                Tables\Columns\TextColumn::make('campaign.tenant.name')
                    ->label('Tenant')
                    ->searchable()
                    ->visible(fn() => auth()->user()->hasRole('super_admin')),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('reward_type')
                    ->options([
                        CampaignReward::TYPE_POINTS => 'Points',
                        CampaignReward::TYPE_BADGE => 'Badge',
                        CampaignReward::TYPE_COUPON => 'Coupon',
                    ]),
                Tables\Filters\TernaryFilter::make('enabled'),
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
        return $user->hasRole('tenant_admin') && $record->campaign->tenant_id === $user->tenant_id;
    }

    public static function canDelete(Model $record): bool
    {
        $user = auth()->user();
        if ($user->hasRole('super_admin')) {
            return true;
        }
        return $user->hasRole('tenant_admin') && $record->campaign->tenant_id === $user->tenant_id;
    }
}
