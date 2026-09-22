<?php

namespace App\Filament\Resources;

use App\Models\Tenant;
use App\Filament\Resources\TenantResource\Pages;
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

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';
    protected static string|UnitEnum|null $navigationGroup = '租戶管理';
    protected static ?string $modelLabel = '租戶';
    protected static ?string $pluralModelLabel = '租戶';
    protected static ?string $navigationLabel = '租戶';
    protected static ?int $navigationSort = 1;

    /**
     * 平台級資源，永遠不套用租戶範圍限制
     * 只有Super Admin可以存取此資源（由canViewAny等方法控制）
     */
    protected static bool $isScopedToTenant = false;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('租戶基本資訊')
                    ->description('管理租戶名稱與主要識別資訊')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('租戶名稱')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(1),
                                Forms\Components\TextInput::make('domain')
                                    ->label('網域')
                                    ->required()
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true)
                                    ->columnSpan(1),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('租戶狀態')
                    ->description('管理租戶目前是否可以正常使用')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('是否啟用')
                            ->required()
                            ->default(true)
                            ->helperText('停用後該租戶將無法正常使用平台功能'),
                    ])
                    ->columnSpanFull(),

                Section::make('系統設定')
                    ->description('設定此租戶的基本系統參數')
                    ->schema([
                        Forms\Components\KeyValue::make('settings')
                            ->label('系統參數')
                            ->default([
                                'currency' => 'TWD',
                                'timezone' => 'Asia/Taipei',
                            ])
                            ->helperText('管理此租戶的貨幣、時區等系統參數')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('租戶名稱')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn(Tenant $record) => $record->domain),
                Tables\Columns\TextColumn::make('domain')
                    ->label('網域')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                Tables\Columns\BadgeColumn::make('is_active')
                    ->label('狀態')
                    ->getStateUsing(fn(Tenant $record): string => $record->is_active ? '啟用' : '停用')
                    ->colors([
                        'success' => '啟用',
                        'danger' => '停用',
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('更新時間')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Actions\EditAction::make()
                    ->tooltip('編輯租戶')
                    ->icon('heroicon-o-pencil'),
                \Filament\Actions\DeleteAction::make()
                    ->tooltip('刪除租戶')
                    ->icon('heroicon-o-trash')
                    ->requiresConfirmation(),
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
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->hasRole('super_admin');
    }

    public static function canCreate(): bool
    {
        return auth()->user()->hasRole('super_admin');
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()->hasRole('super_admin');
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->hasRole('super_admin');
    }
}
