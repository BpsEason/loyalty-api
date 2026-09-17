<?php

namespace App\Filament\Resources;

use App\Models\Customer;
use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Concerns\HandlesTenantScoping;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;
use BackedEnum;

class CustomerResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = Customer::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';
    protected static string|UnitEnum|null $navigationGroup = '客戶管理';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = '客戶';
    protected static ?string $pluralModelLabel = '客戶';
    protected static ?string $navigationLabel = '客戶';

    /**
     * 處理Eloquent查詢，僅處理必要的eager loading
     * 租戶隔離由Filament原生機制和Model層全域範圍處理
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // 僅處理eager loading，租戶範圍由底層機制處理
        $query = static::applyTenantScoping($query, ['tenant']);

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \App\Forms\Components\TenantSelect::make(),
                Forms\Components\TextInput::make('name')
                    ->label('名稱')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->label('電子郵件')
                    ->email()
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('phone')
                    ->label('電話')
                    ->maxLength(20),
                Forms\Components\KeyValue::make('metadata')
                    ->label('額外資訊')
                    ->default([
                        'member_since' => now()->format('Y-m-d'),
                        'tier' => 'bronze',
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(static::getEloquentQuery()->with('pointAccounts.pointTransactions'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('客戶')
                    ->searchable()
                    ->description(fn(Customer $record): string => $record->email),

                Tables\Columns\TextColumn::make('metadata.tier')
                    ->label('會員等級')
                    ->badge()
                    ->color(fn(string $state): string => match (strtolower($state)) {
                        'platinum' => 'warning',
                        'gold' => 'warning',
                        'silver' => 'info',
                        'bronze' => 'secondary',
                        default => 'secondary',
                    })
                    ->formatStateUsing(fn(string $state): string => match (strtolower($state)) {
                        'platinum' => '白金會員',
                        'gold' => '黃金會員',
                        'silver' => '白銀會員',
                        'bronze' => '青銅會員',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('total_points')
                    ->label('目前點數')
                    ->getStateUsing(fn(Customer $record) => number_format($record->pointAccounts?->balance ?? 0))
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_activity')
                    ->label('最後活動')
                    ->getStateUsing(function (Customer $record) {
                        if (!$record->pointAccounts) {
                            return '無活動記錄';
                        }
                        $lastTransaction = $record->pointAccounts->pointTransactions()->latest('created_at')->first();
                        return $lastTransaction?->created_at?->diffForHumans() ?? '無活動記錄';
                    }),

                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('所屬租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()->hasRole('super_admin'))
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('加入時間')
                    ->dateTime('Y-m-d')
                    ->sortable(),
            ])
            ->filters([
                //
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
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
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
