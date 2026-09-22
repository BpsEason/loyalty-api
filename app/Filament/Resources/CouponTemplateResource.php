<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Filament\Resources\CouponTemplateResource\Pages;
use App\Models\CouponTemplate;
use App\Forms\Components\TenantSelect;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use BackedEnum;

class CouponTemplateResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = CouponTemplate::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';
    protected static string|UnitEnum|null $navigationGroup = '忠誠計劃';
    protected static ?int $navigationSort = 4;
    protected static ?string $modelLabel = '優惠券';
    protected static ?string $pluralModelLabel = '優惠券';
    protected static ?string $navigationLabel = '優惠券';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $query->with([
                'tenant' => fn($q) => $q->withoutGlobalScopes(),
            ]);
        }

        return $query->with(['tenant']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TenantSelect::make(),

                Section::make('優惠券基本資訊')
                    ->description('這張優惠券是什麼？設定核心識別資訊')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('優惠券名稱')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->placeholder('請輸入優惠券名稱，例如：新會員專屬100元折抵券'),
                        Grid::make(3)->schema([
                            Forms\Components\TextInput::make('code')
                                ->label('優惠券代碼')
                                ->required()
                                ->maxLength(100)
                                ->unique(ignoreRecord: true)
                                ->placeholder('系統唯一識別代碼'),
                            Forms\Components\Select::make('type')
                                ->label('優惠券類型')
                                ->options([
                                    CouponTemplate::TYPE_FIXED_AMOUNT => '固定金額折抵',
                                    CouponTemplate::TYPE_PERCENTAGE => '比例折扣',
                                    CouponTemplate::TYPE_FREE_SHIPPING => '免運費',
                                    CouponTemplate::TYPE_GIFT => '贈品',
                                ])
                                ->required()
                                ->reactive(),
                            Forms\Components\Select::make('status')
                                ->label('狀態')
                                ->options([
                                    CouponTemplate::STATUS_ACTIVE => '啟用',
                                    CouponTemplate::STATUS_INACTIVE => '停用',
                                    CouponTemplate::STATUS_DRAFT => '草稿',
                                ])
                                ->required()
                                ->default(CouponTemplate::STATUS_DRAFT),
                        ]),
                    ])
                    ->columnSpanFull()
                    ->collapsible(),

                Section::make('折扣設定')
                    ->description('怎麼折？依優惠券類型自動顯示相關折扣規則')
                    ->schema([
                        Grid::make(4)->schema([
                            Forms\Components\TextInput::make('discount_amount')
                                ->label('折抵金額')
                                ->numeric()
                                ->minValue(1)
                                ->required(fn(callable $get) => $get('type') === CouponTemplate::TYPE_FIXED_AMOUNT)
                                ->visible(fn(callable $get) => $get('type') === CouponTemplate::TYPE_FIXED_AMOUNT)
                                ->prefix('NT$'),
                            Forms\Components\TextInput::make('discount_percentage')
                                ->label('折扣比例')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(100)
                                ->required(fn(callable $get) => $get('type') === CouponTemplate::TYPE_PERCENTAGE)
                                ->visible(fn(callable $get) => $get('type') === CouponTemplate::TYPE_PERCENTAGE)
                                ->suffix('%'),
                            Forms\Components\TextInput::make('max_discount_amount')
                                ->label('最高折抵金額')
                                ->numeric()
                                ->minValue(1)
                                ->required(fn(callable $get) => $get('type') === CouponTemplate::TYPE_PERCENTAGE)
                                ->visible(fn(callable $get) => $get('type') === CouponTemplate::TYPE_PERCENTAGE)
                                ->prefix('NT$'),
                            Forms\Components\TextInput::make('minimum_order_amount')
                                ->label('最低消費金額')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->prefix('NT$')
                                ->helperText('設定使用此優惠券需要達到的最低訂單金額，0表示無最低消費限制'),
                        ]),
                    ])
                    ->columnSpanFull()
                    ->collapsible(),

                Section::make('時間與數量限制')
                    ->description('什麼時候有效？可以發多少？設定有效期限與發行限制')
                    ->schema([
                        Grid::make(2)->schema([
                            Forms\Components\DateTimePicker::make('starts_at')
                                ->label('有效期開始')
                                ->required()
                                ->helperText('優惠券開始生效的時間'),
                            Forms\Components\DateTimePicker::make('expires_at')
                                ->label('有效期結束')
                                ->required()
                                ->after('starts_at')
                                ->helperText('優惠券失效的時間，必須晚於開始時間'),
                        ]),
                        Grid::make(2)->schema([
                            Forms\Components\TextInput::make('total_quantity')
                                ->label('發行總量')
                                ->numeric()
                                ->minValue(fn($record) => $record?->issued_quantity ?? 1)
                                ->required()
                                ->helperText(fn($record) => $record ? "不可低於已發行數量 {$record->issued_quantity}" : '此優惠券總共可以發行多少張'),
                            Forms\Components\TextInput::make('per_customer_limit')
                                ->label('每人限領數量')
                                ->numeric()
                                ->minValue(1)
                                ->required()
                                ->helperText('每位會員最多可以領取幾張此優惠券'),
                        ]),
                    ])
                    ->columnSpanFull()
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('優惠券名稱')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Bold),
                TextColumn::make('code')
                    ->label('優惠券代碼')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('monospace'),
                TextColumn::make('type')
                    ->label('類型')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        CouponTemplate::TYPE_FIXED_AMOUNT => 'success',
                        CouponTemplate::TYPE_PERCENTAGE => 'info',
                        CouponTemplate::TYPE_FREE_SHIPPING => 'warning',
                        CouponTemplate::TYPE_GIFT => 'purple',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        CouponTemplate::TYPE_FIXED_AMOUNT => '固定金額',
                        CouponTemplate::TYPE_PERCENTAGE => '比例折扣',
                        CouponTemplate::TYPE_FREE_SHIPPING => '免運費',
                        CouponTemplate::TYPE_GIFT => '贈品',
                        default => $state,
                    }),
                TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        CouponTemplate::STATUS_ACTIVE => 'success',
                        CouponTemplate::STATUS_INACTIVE => 'danger',
                        CouponTemplate::STATUS_DRAFT => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        CouponTemplate::STATUS_ACTIVE => '啟用',
                        CouponTemplate::STATUS_INACTIVE => '停用',
                        CouponTemplate::STATUS_DRAFT => '草稿',
                        default => $state,
                    }),
                TextColumn::make('starts_at')
                    ->label('有效期間')
                    ->dateTime()
                    ->sortable()
                    ->formatStateUsing(fn($record) => $record->starts_at?->format('Y-m-d') . ' ~ ' . $record->expires_at?->format('Y-m-d'))
                    ->wrap(),
                TextColumn::make('issued_quantity')
                    ->label('發行數量')
                    ->formatStateUsing(fn($record) => "已發行 {$record->issued_quantity} / 總量 {$record->total_quantity}"),
                TextColumn::make('per_customer_limit')
                    ->label('每人限領')
                    ->sortable(),
                TextColumn::make('tenant.name')
                    ->label('租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()->hasRole('super_admin')),
                TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('類型')
                    ->options([
                        CouponTemplate::TYPE_FIXED_AMOUNT => '固定金額折抵',
                        CouponTemplate::TYPE_PERCENTAGE => '比例折扣',
                        CouponTemplate::TYPE_FREE_SHIPPING => '免運費',
                        CouponTemplate::TYPE_GIFT => '贈品',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->label('狀態')
                    ->options([
                        CouponTemplate::STATUS_ACTIVE => '啟用',
                        CouponTemplate::STATUS_INACTIVE => '停用',
                        CouponTemplate::STATUS_DRAFT => '草稿',
                    ]),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
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

    public static function infolist(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema
            ->components([
                // 優惠券摘要 - 核心資訊區塊，第一眼可見
                Section::make('優惠券摘要')
                    ->description('這是什麼優惠券？核心識別資訊')
                    ->schema([
                        TextEntry::make('name')
                            ->label('優惠券名稱')
                            ->columnSpanFull()
                            ->weight(FontWeight::Bold),
                        Grid::make(4)->schema([
                            TextEntry::make('code')
                                ->label('優惠券代碼')
                                ->copyable()
                                ->fontFamily('monospace'),
                            TextEntry::make('type')
                                ->label('優惠券類型')
                                ->badge()
                                ->color(fn(string $state): string => match ($state) {
                                    CouponTemplate::TYPE_FIXED_AMOUNT => 'success',
                                    CouponTemplate::TYPE_PERCENTAGE => 'info',
                                    CouponTemplate::TYPE_FREE_SHIPPING => 'warning',
                                    CouponTemplate::TYPE_GIFT => 'purple',
                                    default => 'gray',
                                })
                                ->formatStateUsing(fn(string $state): string => match ($state) {
                                    CouponTemplate::TYPE_FIXED_AMOUNT => '固定金額折抵',
                                    CouponTemplate::TYPE_PERCENTAGE => '比例折扣',
                                    CouponTemplate::TYPE_FREE_SHIPPING => '免運費',
                                    CouponTemplate::TYPE_GIFT => '贈品',
                                    default => $state,
                                }),
                            TextEntry::make('status')
                                ->label('狀態')
                                ->badge()
                                ->color(fn(string $state): string => match ($state) {
                                    CouponTemplate::STATUS_ACTIVE => 'success',
                                    CouponTemplate::STATUS_INACTIVE => 'danger',
                                    CouponTemplate::STATUS_DRAFT => 'gray',
                                    default => 'gray',
                                })
                                ->formatStateUsing(fn(string $state): string => match ($state) {
                                    CouponTemplate::STATUS_ACTIVE => '啟用',
                                    CouponTemplate::STATUS_INACTIVE => '停用',
                                    CouponTemplate::STATUS_DRAFT => '草稿',
                                    default => $state,
                                }),
                            TextEntry::make('tenant.name')
                                ->label('所屬租戶')
                                ->visible(fn() => auth()->user()->hasRole('super_admin')),
                        ]),
                    ])
                    ->columnSpanFull()
                    ->collapsible(),

                // 折扣規則區塊
                Section::make('折扣規則')
                    ->description('怎麼折？折扣計算方式與使用條件')
                    ->schema([
                        Grid::make(4)->schema([
                            TextEntry::make('discount_amount')
                                ->label('折抵金額')
                                ->formatStateUsing(fn($state) => $state ? 'NT$ ' . number_format($state) : '-')
                                ->visible(fn($record) => $record->type === CouponTemplate::TYPE_FIXED_AMOUNT),
                            TextEntry::make('discount_percentage')
                                ->label('折扣比例')
                                ->formatStateUsing(fn($state) => $state ? $state . '%' : '-')
                                ->visible(fn($record) => $record->type === CouponTemplate::TYPE_PERCENTAGE),
                            TextEntry::make('max_discount_amount')
                                ->label('最高折抵金額')
                                ->formatStateUsing(fn($state) => $state ? 'NT$ ' . number_format($state) : '-')
                                ->visible(fn($record) => $record->type === CouponTemplate::TYPE_PERCENTAGE),
                            TextEntry::make('minimum_order_amount')
                                ->label('最低消費金額')
                                ->formatStateUsing(fn($state) => 'NT$ ' . number_format($state)),
                        ]),
                    ])
                    ->columnSpanFull()
                    ->collapsible(),

                // 時間與發行限制區塊
                Section::make('時間與發行限制')
                    ->description('什麼時候可以用？可以發多少？有效期限與發行數量規範')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('starts_at')
                                ->label('有效期開始')
                                ->dateTime(),
                            TextEntry::make('expires_at')
                                ->label('有效期結束')
                                ->dateTime(),
                        ]),
                        Grid::make(2)->schema([
                            TextEntry::make('issued_quantity')
                                ->label('已發行 / 發行總量')
                                ->formatStateUsing(fn($record) => "{$record->issued_quantity} / {$record->total_quantity}"),
                            TextEntry::make('per_customer_limit')
                                ->label('每人限領數量'),
                        ]),
                        Grid::make(2)->schema([
                            TextEntry::make('created_at')
                                ->label('系統建立時間')
                                ->dateTime(),
                            TextEntry::make('updated_at')
                                ->label('最後更新時間')
                                ->dateTime(),
                        ]),
                    ])
                    ->columnSpanFull()
                    ->collapsible(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCouponTemplates::route('/'),
            'create' => Pages\CreateCouponTemplate::route('/create'),
            'edit' => Pages\EditCouponTemplate::route('/{record}/edit'),
            'view' => Pages\ViewCouponTemplate::route('/{record}'),
        ];
    }
}
