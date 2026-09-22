<?php

namespace App\Filament\Resources;

use App\Models\MembershipTier;
use App\Filament\Resources\MembershipTierResource\Pages;
use App\Filament\Concerns\HandlesTenantScoping;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use BackedEnum;

class MembershipTierResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = MembershipTier::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-trophy';
    protected static string|UnitEnum|null $navigationGroup = '客戶管理';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = '會員等級';
    protected static ?string $pluralModelLabel = '會員等級';
    protected static ?string $navigationLabel = '會員等級';

    /**
     * 處理Eloquent查詢，應用租戶隔離
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $query = static::applyTenantScoping($query, ['tenant']);
        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本設定')
                    ->description('會員等級的基本資訊')
                    ->schema([
                        \App\Forms\Components\TenantSelect::make()
                            ->label('所屬租戶')
                            ->helperText('請選擇此會員等級所屬的租戶')
                            ->columnSpanFull(),
                        Grid::make(2)->schema([
                            Forms\Components\TextInput::make('name')
                                ->label('等級名稱')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('例如：白金會員'),
                            Forms\Components\TextInput::make('slug')
                                ->label('標識符')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('platinum'),
                        ]),
                        Grid::make(2)->schema([
                            Forms\Components\Select::make('threshold_type')
                                ->label('門檻類型')
                                ->options([
                                    'spend' => '累積消費',
                                    'points' => '累積點數',
                                ])
                                ->required(),
                            Forms\Components\TextInput::make('sort_order')
                                ->label('排序順序')
                                ->numeric()
                                ->default(0)
                                ->required(),
                        ]),
                        Grid::make(2)->schema([
                            Forms\Components\TextInput::make('upgrade_threshold')
                                ->label('升級門檻')
                                ->numeric()
                                ->step(0.01)
                                ->default(0)
                                ->required(),
                            Forms\Components\Toggle::make('status')
                                ->label('啟用狀態')
                                ->default(true)
                                ->required(),
                        ]),
                    ])
                    ->columnSpanFull(),

                Section::make('權益設定')
                    ->description('此會員等級可享有的權益')
                    ->schema([
                        Grid::make(2)->schema([
                            Forms\Components\TextInput::make('points_multiplier')
                                ->label('點數倍增係數')
                                ->numeric()
                                ->step(0.01)
                                ->default(1.00)
                                ->required(),
                            Forms\Components\TextInput::make('discount_rate')
                                ->label('折扣率')
                                ->numeric()
                                ->step(0.0001)
                                ->default(0.0000)
                                ->required(),
                        ]),
                        Forms\Components\Toggle::make('free_shipping')
                            ->label('免運費')
                            ->default(false),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('等級名稱')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->label('標識符')
                    ->searchable(),
                Tables\Columns\TextColumn::make('threshold_type')
                    ->label('門檻類型')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'spend' => '累積消費',
                        'points' => '累積點數',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('upgrade_threshold')
                    ->label('升級門檻')
                    ->sortable(),
                Tables\Columns\IconColumn::make('status')
                    ->label('狀態')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('threshold_type')
                    ->label('門檻類型')
                    ->options([
                        'spend' => '累積消費',
                        'points' => '累積點數',
                    ]),
                Tables\Filters\TernaryFilter::make('status')
                    ->label('啟用狀態'),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
            'index' => Pages\ListMembershipTiers::route('/'),
            'create' => Pages\CreateMembershipTier::route('/create'),
            'view' => Pages\ViewMembershipTier::route('/{record}'),
            'edit' => Pages\EditMembershipTier::route('/{record}/edit'),
        ];
    }
}
