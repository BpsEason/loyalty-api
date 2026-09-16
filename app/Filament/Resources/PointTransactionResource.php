<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Models\PointTransaction;
use App\Filament\Resources\PointTransactionResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use BackedEnum;

class PointTransactionResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = PointTransaction::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-trending-up';
    protected static string|UnitEnum|null $navigationGroup = '會員管理';
    protected static ?int $navigationSort = 3;
    protected static ?string $modelLabel = '點數交易';
    protected static ?string $pluralModelLabel = '點數交易';
    protected static ?string $navigationLabel = '點數交易';

    /**
     * 處理Eloquent查詢，僅處理必要的eager loading
     * 租戶隔離由Filament原生機制和Model層全域範圍處理
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // 僅處理eager loading，租戶範圍由底層機制處理
        $query = static::applyTenantScoping($query, ['tenant', 'pointAccount.customer']);

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \App\Forms\Components\TenantSelect::make(),
                Forms\Components\Select::make('point_account_id')
                    ->label('點數帳戶')
                    ->relationship(
                        'pointAccount',
                        'id',
                        function ($query, callable $get) {
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
                            // Tenant Admin 由Model全域範圍自動處理，無需手動過濾

                            return $query->select('id', 'customer_id');
                        }
                    )
                    ->getOptionLabelFromRecordUsing(fn($record) => "帳戶 #{$record->id} - {$record->customer->name}")
                    ->required()
                    ->searchable()
                    ->reactive(),
                Forms\Components\TextInput::make('amount')
                    ->label('金額')
                    ->required()
                    ->numeric()
                    ->disabled(fn($context) => $context !== 'create'),
                Forms\Components\TextInput::make('type')
                    ->label('類型')
                    ->required()
                    ->maxLength(50)
                    ->disabled(fn($context) => $context !== 'create'),
                Forms\Components\Textarea::make('description')
                    ->label('描述')
                    ->maxLength(65535)
                    ->columnSpanFull()
                    ->disabled(fn($context) => $context !== 'create'),
                Forms\Components\KeyValue::make('metadata')
                    ->label('中繼資料')
                    ->disabled(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(static::getEloquentQuery())
            ->columns([
                Tables\Columns\TextColumn::make('pointAccount.customer.name')
                    ->label('客戶')
                    ->searchable(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('金額')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('類型')
                    ->badge(),
                Tables\Columns\TextColumn::make('description')
                    ->label('描述')
                    ->limit(50),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()->hasRole('super_admin')),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('類型')
                    ->options([
                        'earn' => '獲得',
                        'redeem' => '兌換',
                        'expire' => '過期',
                        'adjust' => '調整',
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
            'index' => Pages\ListPointTransactions::route('/'),
            'create' => Pages\CreatePointTransaction::route('/create'),
            'edit' => Pages\EditPointTransaction::route('/{record}/edit'),
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
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
