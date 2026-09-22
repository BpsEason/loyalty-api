<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Models\PointAccount;
use App\Filament\Resources\PointAccountResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;
use BackedEnum;

class PointAccountResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = PointAccount::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';
    protected static string|UnitEnum|null $navigationGroup = '客戶管理';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = '點數帳戶';
    protected static ?string $pluralModelLabel = '點數帳戶';
    protected static ?string $navigationLabel = '點數帳戶';

    /**
     * 處理Eloquent查詢，僅處理必要的eager loading
     * 租戶隔離由Filament原生機制和Model層全域範圍處理
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        $panel = filament()->getCurrentOrDefaultPanel();

        // 永遠載入 tenant 關聯
        $withRelations = ['tenant'];

        if ($user && $user->isSuperAdmin() && $panel?->hasTenancy()) {
            $scopeName = $panel->getTenancyScopeName();
            // 正確格式：將 customer 關聯與其約束直接加入 with 陣列
            $withRelations['customer'] = fn($q) => $q->withoutGlobalScope($scopeName);
        } else {
            // 一般使用者直接載入 customer，由 Model 全域範圍自動處理
            $withRelations[] = 'customer';
        }

        // 處理 eager loading
        $query = static::applyTenantScoping($query, $withRelations);

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('點數帳戶')
                    ->description('設定此點數帳戶所屬的租戶與客戶')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                \App\Forms\Components\TenantSelect::make()
                                    ->reactive()
                                    ->columnSpan(1),
                                Forms\Components\Select::make('customer_id')
                                    ->label('客戶')
                                    ->relationship('customer', 'name', function ($query, $get) {
                                        $user = auth()->user();
                                        $panel = filament()->getCurrentOrDefaultPanel();
                                        $tenantId = $get('tenant_id');

                                        if ($user && $user->isSuperAdmin()) {
                                            // Super Admin 可以看到所有客戶（配合選取租戶過濾）
                                            if ($panel?->hasTenancy()) {
                                                $query->withoutGlobalScope($panel->getTenancyScopeName());
                                            }
                                            if ($tenantId) {
                                                $query->where('tenant_id', $tenantId);
                                            }
                                        }
                                        // Tenant Admin 由Model全域範圍自動處理，無需手動過濾

                                        return $query;
                                    })
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->columnSpan(1),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('點數餘額')
                    ->description('查看與管理此帳戶目前的點數狀態')
                    ->schema([
                        Forms\Components\TextInput::make('balance')
                            ->label('目前餘額')
                            ->required()
                            ->numeric()
                            ->default(0)
                            ->suffix('點')
                            ->helperText('此客戶當前可用的點數餘額')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('點數累積')
                    ->description('查看此帳戶的點數累積統計')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('total_earned')
                                    ->label('累積獲得')
                                    ->required()
                                    ->numeric()
                                    ->default(0)
                                    ->suffix('點')
                                    ->helperText('此帳戶累積獲得的總點數')
                                    ->columnSpan(1),
                                Forms\Components\TextInput::make('total_redeemed')
                                    ->label('累積兌換')
                                    ->required()
                                    ->numeric()
                                    ->default(0)
                                    ->suffix('點')
                                    ->helperText('此帳戶累積兌換使用的總點數')
                                    ->columnSpan(1),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(static::getEloquentQuery())
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('客戶')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn(PointAccount $record) => $record->tenant?->name ?? ''),
                Tables\Columns\BadgeColumn::make('balance')
                    ->label('目前餘額')
                    ->numeric()
                    ->sortable()
                    ->weight('bold')
                    ->color('success')
                    ->formatStateUsing(fn($state) => number_format($state) . ' 點')
                    ->alignRight(),
                Tables\Columns\TextColumn::make('total_earned')
                    ->label('累積獲得')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn($state) => number_format($state) . ' 點')
                    ->alignRight(),
                Tables\Columns\TextColumn::make('total_redeemed')
                    ->label('累積兌換')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(fn($state) => number_format($state) . ' 點')
                    ->alignRight(),
                Tables\Columns\BadgeColumn::make('tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()->hasRole('super_admin'))
                    ->color('info'),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Actions\EditAction::make()
                    ->tooltip('編輯點數帳戶')
                    ->icon('heroicon-o-pencil'),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()
                        ->requiresConfirmation(),
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
            'index' => Pages\ListPointAccounts::route('/'),
            'create' => Pages\CreatePointAccount::route('/create'),
            'edit' => Pages\EditPointAccount::route('/{record}/edit'),
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
