<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
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
    use HandlesTenantScoping;

    protected static ?string $model = RewardGrant::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';
    protected static string|UnitEnum|null $navigationGroup = '忠誠計劃';
    protected static ?int $navigationSort = 3;
    protected static ?string $modelLabel = '獎勵發放';
    protected static ?string $pluralModelLabel = '獎勵發放';
    protected static ?string $navigationLabel = '獎勵發放';

    /**
     * 處理Eloquent查詢，僅處理必要的eager loading
     * 租戶隔離由Filament原生機制和Model層全域範圍處理
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $query->with([
                'tenant' => fn($q) => $q->withoutGlobalScopes(),
                'campaign' => fn($q) => $q->withoutGlobalScopes(),
                'campaignReward' => fn($q) => $q->withoutGlobalScopes(),
                'customer' => fn($q) => $q->withoutGlobalScopes(),
                'pointTransaction' => fn($q) => $q->withoutGlobalScopes(),
            ]);
        }

        return $query->with([
            'tenant',
            'campaign',
            'campaignReward',
            'customer',
            'pointTransaction',
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \App\Forms\Components\TenantSelect::make(),
                Forms\Components\Select::make('campaign_id')
                    ->label('活動')
                    ->relationship('campaign', 'name', function ($query, $get) {
                        $user = auth()->user();
                        $panel = filament()->getCurrentOrDefaultPanel();
                        $tenantId = $get('tenant_id');

                        if ($user && $user->isSuperAdmin()) {
                            if ($panel?->hasTenancy()) {
                                $query->withoutGlobalScope($panel->getTenancyScopeName());
                            }
                            if ($tenantId) {
                                $query->where('tenant_id', $tenantId);
                            }
                        }

                        return $query;
                    })
                    ->required()
                    ->disabled(fn($record) => $record !== null)
                    ->reactive(),
                Forms\Components\Select::make('campaign_reward_id')
                    ->label('獎勵')
                    ->relationship('campaignReward', 'id', function ($query, $get) {
                        $campaignId = $get('campaign_id');
                        if ($campaignId) {
                            $query->where('campaign_id', $campaignId);
                        }
                        return $query;
                    })
                    ->getOptionLabelFromRecordUsing(fn($record) => "{$record->id} - {$record->reward_type}")
                    ->required()
                    ->disabled(fn($record) => $record !== null),
                Forms\Components\Select::make('customer_id')
                    ->label('客戶')
                    ->relationship('customer', 'name', function ($query, $get) {
                        $user = auth()->user();
                        $panel = filament()->getCurrentOrDefaultPanel();
                        $tenantId = $get('tenant_id');

                        if ($user && $user->isSuperAdmin()) {
                            if ($panel?->hasTenancy()) {
                                $query->withoutGlobalScope($panel->getTenancyScopeName());
                            }
                            if ($tenantId) {
                                $query->where('tenant_id', $tenantId);
                            }
                        }

                        return $query;
                    })
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
                    ->relationship('pointTransaction', 'id', function ($query, $get) {
                        $user = auth()->user();
                        $panel = filament()->getCurrentOrDefaultPanel();
                        $tenantId = $get('tenant_id');

                        if ($user && $user->isSuperAdmin()) {
                            if ($panel?->hasTenancy()) {
                                $query->withoutGlobalScope($panel->getTenancyScopeName());
                            }
                            if ($tenantId) {
                                $query->where('tenant_id', $tenantId);
                            }
                        }

                        return $query;
                    })
                    ->getOptionLabelFromRecordUsing(fn($record) => "交易 #{$record->id} - {$record->amount}點")
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
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
                    ->color(fn(string $state): string => match ($state) {
                        RewardGrant::STATUS_PENDING => 'warning',
                        RewardGrant::STATUS_GRANTED => 'success',
                        RewardGrant::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),
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
            ])
            ->bulkActions([]);
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
        // RewardGrant是系統自動產生的記錄，禁止編輯
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        // RewardGrant是系統自動產生的記錄，禁止刪除以保持歷史資料一致性
        return false;
    }
}
