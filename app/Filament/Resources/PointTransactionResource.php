<?php

namespace App\Filament\Resources;

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
    protected static ?string $model = PointTransaction::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-trending-up';
    protected static string|UnitEnum|null $navigationGroup = '會員管理';
    protected static ?int $navigationSort = 3;
    protected static ?string $modelLabel = '點數交易';
    protected static ?string $pluralModelLabel = '點數交易';
    protected static ?string $navigationLabel = '點數交易';

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
                Forms\Components\Select::make('point_account_id')
                    ->label('點數帳戶')
                    ->relationship('pointAccount', 'id')
                    ->required()
                    ->searchable(),
                Forms\Components\TextInput::make('amount')
                    ->label('金額')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('type')
                    ->label('類型')
                    ->required()
                    ->maxLength(50),
                Forms\Components\Textarea::make('description')
                    ->label('描述')
                    ->maxLength(65535)
                    ->columnSpanFull(),
                Forms\Components\KeyValue::make('metadata')
                    ->label('中繼資料'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $user = auth()->user();
                if ($user->hasRole('super_admin')) {
                    return PointTransaction::with(['tenant', 'pointAccount.customer']);
                }
                return PointTransaction::where('tenant_id', $user->tenant_id)->with(['tenant', 'pointAccount.customer']);
            })
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
