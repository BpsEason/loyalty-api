<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Filament\Resources\CouponTemplateResource\Pages;
use App\Models\CouponTemplate;
use App\Forms\Components\TenantSelect;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
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

                \Filament\Schemas\Components\Section::make('優惠券基本資訊')
                    ->description('設定優惠券的核心識別資訊')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('優惠券名稱')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('code')
                            ->label('優惠券代碼')
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true),
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
                    ])
                    ->columns(2)
                    ->collapsible(),

                \Filament\Schemas\Components\Section::make('折扣設定')
                    ->description('設定優惠券的折扣規則')
                    ->schema([
                        Forms\Components\TextInput::make('discount_amount')
                            ->label('折抵金額')
                            ->numeric()
                            ->minValue(1)
                            ->required(fn(callable $get) => $get('type') === CouponTemplate::TYPE_FIXED_AMOUNT)
                            ->visible(fn(callable $get) => $get('type') === CouponTemplate::TYPE_FIXED_AMOUNT),
                        Forms\Components\TextInput::make('discount_percentage')
                            ->label('折扣比例 (%)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->required(fn(callable $get) => $get('type') === CouponTemplate::TYPE_PERCENTAGE)
                            ->visible(fn(callable $get) => $get('type') === CouponTemplate::TYPE_PERCENTAGE),
                        Forms\Components\TextInput::make('max_discount_amount')
                            ->label('最高折抵金額')
                            ->numeric()
                            ->minValue(1)
                            ->required(fn(callable $get) => $get('type') === CouponTemplate::TYPE_PERCENTAGE)
                            ->visible(fn(callable $get) => $get('type') === CouponTemplate::TYPE_PERCENTAGE),
                        Forms\Components\TextInput::make('minimum_order_amount')
                            ->label('最低消費金額')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                    ])
                    ->columns(2)
                    ->collapsible(),

                \Filament\Schemas\Components\Section::make('時間與數量限制')
                    ->description('設定優惠券的有效期限與發行數量')
                    ->schema([
                        Forms\Components\DateTimePicker::make('starts_at')
                            ->label('開始時間')
                            ->required(),
                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label('結束時間')
                            ->required()
                            ->after('starts_at'),
                        Forms\Components\TextInput::make('total_quantity')
                            ->label('發行總量')
                            ->numeric()
                            ->minValue(fn($record) => $record?->issued_quantity ?? 1)
                            ->required(),
                        Forms\Components\TextInput::make('per_customer_limit')
                            ->label('每人限領數量')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('名稱')
                    ->searchable(),
                Tables\Columns\TextColumn::make('code')
                    ->label('代碼')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
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
                Tables\Columns\TextColumn::make('status')
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
                Tables\Columns\TextColumn::make('starts_at')
                    ->label('有效期間')
                    ->dateTime()
                    ->sortable()
                    ->formatStateUsing(fn($record) => $record->starts_at?->format('Y-m-d') . ' ~ ' . $record->expires_at?->format('Y-m-d')),
                Tables\Columns\TextColumn::make('issued_quantity')
                    ->label('發行數量')
                    ->formatStateUsing(fn($record) => "{$record->issued_quantity} / {$record->total_quantity}"),
                Tables\Columns\TextColumn::make('per_customer_limit')
                    ->label('每人限領')
                    ->sortable(),
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
                \Filament\Schemas\Components\Section::make('優惠券基本資訊')
                    ->description('優惠券的核心識別資訊')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('name')
                            ->label('優惠券名稱')
                            ->columnSpan(2),
                        \Filament\Infolists\Components\TextEntry::make('code')
                            ->label('優惠券代碼')
                            ->copyable(),
                        \Filament\Infolists\Components\TextEntry::make('type')
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
                        \Filament\Infolists\Components\TextEntry::make('status')
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
                        \Filament\Infolists\Components\TextEntry::make('tenant.name')
                            ->label('所屬租戶')
                            ->visible(fn() => auth()->user()->hasRole('super_admin')),
                    ])->columns(3),

                \Filament\Schemas\Components\Section::make('折扣設定')
                    ->description('優惠券的折扣規則詳情')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('discount_amount')
                            ->label('折抵金額')
                            ->formatStateUsing(fn($state) => $state ? 'NT$ ' . number_format($state) : '-')
                            ->visible(fn($record) => $record->type === CouponTemplate::TYPE_FIXED_AMOUNT),
                        \Filament\Infolists\Components\TextEntry::make('discount_percentage')
                            ->label('折扣比例')
                            ->formatStateUsing(fn($state) => $state ? $state . '%' : '-')
                            ->visible(fn($record) => $record->type === CouponTemplate::TYPE_PERCENTAGE),
                        \Filament\Infolists\Components\TextEntry::make('max_discount_amount')
                            ->label('最高折抵金額')
                            ->formatStateUsing(fn($state) => $state ? 'NT$ ' . number_format($state) : '-')
                            ->visible(fn($record) => $record->type === CouponTemplate::TYPE_PERCENTAGE),
                        \Filament\Infolists\Components\TextEntry::make('minimum_order_amount')
                            ->label('最低消費金額')
                            ->formatStateUsing(fn($state) => 'NT$ ' . number_format($state)),
                    ])->columns(2),

                \Filament\Schemas\Components\Section::make('時間與數量統計')
                    ->description('優惠券的有效期限與發行狀況')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('starts_at')
                            ->label('開始時間')
                            ->dateTime(),
                        \Filament\Infolists\Components\TextEntry::make('expires_at')
                            ->label('結束時間')
                            ->dateTime(),
                        \Filament\Infolists\Components\TextEntry::make('issued_quantity')
                            ->label('已發行數量')
                            ->formatStateUsing(fn($record) => "{$record->issued_quantity} / {$record->total_quantity}"),
                        \Filament\Infolists\Components\TextEntry::make('per_customer_limit')
                            ->label('每人限領數量'),
                        \Filament\Infolists\Components\TextEntry::make('created_at')
                            ->label('建立時間')
                            ->dateTime(),
                        \Filament\Infolists\Components\TextEntry::make('updated_at')
                            ->label('最後更新時間')
                            ->dateTime(),
                    ])->columns(3),
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
