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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\FontWeight;
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
            ->components([
                Section::make('客戶基本資料')
                    ->description('建立新會員的基本聯絡資訊')
                    ->schema([
                        \App\Forms\Components\TenantSelect::make()
                            ->label('所屬租戶')
                            ->helperText('請選擇此客戶所屬的會員方案租戶')
                            ->columnSpanFull(),
                        Grid::make(2)->schema([
                            Forms\Components\TextInput::make('name')
                                ->label('客戶名稱')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('請輸入客戶姓名')
                                ->helperText('客戶的真實姓名或暱稱'),
                            Forms\Components\TextInput::make('email')
                                ->label('電子郵件')
                                ->email()
                                ->required()
                                ->maxLength(255)
                                ->placeholder('customer@example.com')
                                ->helperText('用於接收通知與驗證'),
                        ]),
                        Forms\Components\TextInput::make('phone')
                            ->label('聯絡電話')
                            ->maxLength(20)
                            ->placeholder('09xx-xxx-xxx')
                            ->helperText('客戶的手機號碼')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('會員資訊')
                    ->description('管理會員的額外資訊與等級設定')
                    ->schema([
                        Forms\Components\KeyValue::make('metadata')
                            ->label('額外會員資訊')
                            ->default([
                                'member_since' => now()->format('Y-m-d'),
                                'tier' => 'bronze',
                            ])
                            ->helperText('可新增額外的會員屬性，如會員加入日期、會員等級等')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(static::getEloquentQuery()->with('pointAccounts.pointTransactions'))
            ->columns([
                // 客戶 - 主要欄位，包含名稱與 Email
                Tables\Columns\TextColumn::make('name')
                    ->label('客戶')
                    ->searchable()
                    ->weight(FontWeight::Bold)
                    ->icon('heroicon-o-user')
                    ->description(fn(Customer $record): string => $record->email)
                    ->wrap(),

                // 會員等級 - Badge 顯示
                Tables\Columns\TextColumn::make('metadata.tier')
                    ->label('會員等級')
                    ->alignCenter()
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
                    })
                    ->icon(fn(string $state): string => match (strtolower($state)) {
                        'platinum' => 'heroicon-o-trophy',
                        'gold' => 'heroicon-o-star',
                        'silver' => 'heroicon-o-academic-cap',
                        'bronze' => 'heroicon-o-user',
                        default => 'heroicon-o-user',
                    }),

                // 目前點數 - 強調顯示
                Tables\Columns\TextColumn::make('total_points')
                    ->label('目前點數')
                    ->getStateUsing(fn(Customer $record) => number_format($record->pointAccounts?->balance ?? 0))
                    ->sortable()
                    ->alignRight()
                    ->weight(FontWeight::Bold)
                    ->color('primary')
                    ->icon('heroicon-o-currency-dollar'),

                // 最後活動
                Tables\Columns\TextColumn::make('last_activity')
                    ->label('最後活動')
                    ->getStateUsing(function (Customer $record) {
                        if (!$record->pointAccounts) {
                            return '無活動記錄';
                        }
                        $lastTransaction = $record->pointAccounts->pointTransactions()->latest('created_at')->first();
                        return $lastTransaction?->created_at?->diffForHumans() ?? '無活動記錄';
                    })
                    ->color('gray')
                    ->icon('heroicon-o-clock')
                    ->alignCenter(),

                // 所屬租戶 - 僅 Super Admin 可見
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('所屬租戶')
                    ->searchable()
                    ->visible(fn() => auth()->user()->hasRole('super_admin'))
                    ->badge()
                    ->color('gray')
                    ->icon('heroicon-o-building-office'),

                // 加入時間
                Tables\Columns\TextColumn::make('created_at')
                    ->label('加入時間')
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->color('gray')
                    ->icon('heroicon-o-calendar')
                    ->alignRight(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('tier')
                    ->label('會員等級')
                    ->options([
                        'platinum' => '白金會員',
                        'gold' => '黃金會員',
                        'silver' => '白銀會員',
                        'bronze' => '青銅會員',
                    ])
                    ->query(function (Builder $query, $data) {
                        if (filled($data['value'])) {
                            $query->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.tier")) = ?', [$data['value']]);
                        }
                    }),
            ])
            ->actions([
                \Filament\Actions\EditAction::make()->icon('heroicon-o-pencil'),
                \Filament\Actions\DeleteAction::make()->icon('heroicon-o-trash'),
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
