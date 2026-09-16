<?php

namespace App\Filament\Resources;

use App\Models\RewardGrant;
use App\Filament\Resources\RewardGrantResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use BackedEnum;

class RewardGrantResource extends Resource
{
    protected static ?string $model = RewardGrant::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';
    protected static string|UnitEnum|null $navigationGroup = '獎勵管理';
    protected static ?int $navigationSort = 3;
    protected static ?string $modelLabel = '獎勵發放';
    protected static ?string $pluralModelLabel = '獎勵發放';
    protected static ?string $navigationLabel = '獎勵發放';

    /**
     * 是否將資源範圍限制在目前的租戶
     * Super Admin（tenant_id為null）可以存取所有租戶的資料
     */
    public static function isScopedToTenant(): bool
    {
        $user = auth()->user();

        // 如果是super_admin，不限制租戶範圍，可以看到所有資料
        if ($user && is_null($user->tenant_id)) {
            return false;
        }

        // 一般使用者維持租戶隔離
        return true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Select::make('tenant_id')
                    ->label('租戶')
                    ->relationship('tenant', 'name')
                    ->required(fn() => !auth()->user()->hasRole('tenant_admin'))
                    ->disabled(fn($record) => $record !== null),
                Forms\Components\Select::make('campaign_id')
                    ->label('活動')
                    ->relationship('campaign', 'name')
                    ->required()
                    ->disabled(fn($record) => $record !== null),
                Forms\Components\Select::make('campaign_reward_id')
                    ->label('獎勵')
                    ->relationship('campaignReward', 'id')
                    ->required()
                    ->disabled(fn($record) => $record !== null),
                Forms\Components\Select::make('customer_id')
                    ->label('客戶')
                    ->relationship('customer', 'name')
                    ->required()
                    ->disabled(fn($record) => $record !== null),
                Forms\Components\Select::make('status')
                    ->label('狀態')
                    ->options([
                        RewardGrant::STATUS_PENDING => '待處理',
                        RewardGrant::STATUS_GRANTED => '已發放',
                        RewardGrant::STATUS_FAILED => '失敗',
                    ])
                    ->required()
                    ->disabled(),
                Forms\Components\DateTimePicker::make('granted_at')
                    ->label('發放時間')
                    ->disabled(),
                Forms\Components\Textarea::make('failure_reason')
                    ->label('失敗原因')
                    ->columnSpanFull()
                    ->disabled(),
                Forms\Components\Select::make('point_transaction_id')
                    ->label('點數交易')
                    ->relationship('pointTransaction', 'id')
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $user = auth()->user();
                if ($user->hasRole('super_admin')) {
                    return RewardGrant::with(['tenant', 'campaign', 'campaignReward', 'customer', 'pointTransaction']);
                }
                return RewardGrant::where('tenant_id', $user->tenant_id)->with(['tenant', 'campaign', 'campaignReward', 'customer', 'pointTransaction']);
            })
            ->columns([
                Tables\Columns\TextColumn::make('campaign.name')
                    ->label('活動')
                    ->searchable(),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('客戶')
                    ->searchable(),
                Tables\Columns\TextColumn::make('campaignReward.reward_type')
                    ->label('獎勵類型')
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->colors([
                        'warning' => RewardGrant::STATUS_PENDING,
                        'success' => RewardGrant::STATUS_GRANTED,
                        'danger' => RewardGrant::STATUS_FAILED,
                    ]),
                Tables\Columns\TextColumn::make('granted_at')
                    ->label('發放時間')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('pointTransaction.id')
                    ->label('交易序號')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()->hasRole('super_admin')),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('狀態')
                    ->options([
                        RewardGrant::STATUS_PENDING => '待處理',
                        RewardGrant::STATUS_GRANTED => '已發放',
                        RewardGrant::STATUS_FAILED => '失敗',
                    ]),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
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
            'index' => Pages\ListRewardGrants::route('/'),
            'create' => Pages\CreateRewardGrant::route('/create'),
            'edit' => Pages\EditRewardGrant::route('/{record}/edit'),
            'view' => Pages\ViewRewardGrant::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'tenant_admin']);
    }

    public static function canCreate(): bool
    {
        // 管理員不應該手動建立獎勵發放，應該由系統自動處理
        return false;
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
