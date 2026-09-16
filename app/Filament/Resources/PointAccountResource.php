<?php

namespace App\Filament\Resources;

use App\Models\PointAccount;
use App\Filament\Resources\PointAccountResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;
use BackedEnum;

class PointAccountResource extends Resource
{
    protected static ?string $model = PointAccount::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';
    protected static string|UnitEnum|null $navigationGroup = '會員管理';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = '點數帳戶';
    protected static ?string $pluralModelLabel = '點數帳戶';
    protected static ?string $navigationLabel = '點數帳戶';

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
                    ->required(fn() => !auth()->user()->hasRole('tenant_admin')),
                Forms\Components\Select::make('customer_id')
                    ->label('客戶')
                    ->relationship('customer', 'name')
                    ->required()
                    ->searchable(),
                Forms\Components\TextInput::make('balance')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('total_earned')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('total_redeemed')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $user = auth()->user();
                if ($user->hasRole('super_admin')) {
                    return PointAccount::with(['tenant', 'customer']);
                }
                return PointAccount::where('tenant_id', $user->tenant_id)->with(['tenant', 'customer']);
            })
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('客戶')
                    ->searchable(),
                Tables\Columns\TextColumn::make('balance')
                    ->label('餘額')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_earned')
                    ->label('累積獲得')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_redeemed')
                    ->label('累積兌換')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()->hasRole('super_admin')),
            ])
            ->filters([
                //
            ])
            ->actions([
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
