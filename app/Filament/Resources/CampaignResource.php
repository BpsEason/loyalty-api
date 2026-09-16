<?php

namespace App\Filament\Resources;

use App\Models\Campaign;
use App\Filament\Resources\CampaignResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;
use BackedEnum;

class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';
    protected static string|UnitEnum|null $navigationGroup = 'Rewards';
    protected static ?int $navigationSort = 1;

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
                    ->label('Tenant')
                    ->relationship('tenant', 'name')
                    ->required(fn() => !auth()->user()->hasRole('tenant_admin')),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\Select::make('status')
                    ->options([
                        Campaign::STATUS_DRAFT => '草稿',
                        Campaign::STATUS_ACTIVE => '啟用',
                        Campaign::STATUS_INACTIVE => '停用',
                        Campaign::STATUS_COMPLETED => '已完成',
                    ])
                    ->required()
                    ->default(Campaign::STATUS_DRAFT),
                Forms\Components\DateTimePicker::make('starts_at')
                    ->label('開始時間')
                    ->nullable(),
                Forms\Components\DateTimePicker::make('ends_at')
                    ->label('結束時間')
                    ->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $user = auth()->user();
                if ($user->hasRole('super_admin')) {
                    return Campaign::with('tenant');
                }
                return Campaign::where('tenant_id', $user->tenant_id)->with('tenant');
            })
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => Campaign::STATUS_DRAFT,
                        'success' => Campaign::STATUS_ACTIVE,
                        'warning' => Campaign::STATUS_INACTIVE,
                        'info' => Campaign::STATUS_COMPLETED,
                    ]),
                Tables\Columns\TextColumn::make('starts_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ends_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->searchable()
                    ->visible(fn() => auth()->user()->hasRole('super_admin')),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        Campaign::STATUS_DRAFT => '草稿',
                        Campaign::STATUS_ACTIVE => '啟用',
                        Campaign::STATUS_INACTIVE => '停用',
                        Campaign::STATUS_COMPLETED => '已完成',
                    ]),
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
            'index' => Pages\ListCampaigns::route('/'),
            'create' => Pages\CreateCampaign::route('/create'),
            'edit' => Pages\EditCampaign::route('/{record}/edit'),
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
