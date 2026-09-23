<?php

namespace App\Filament\Resources\CouponTemplateResource\Tables;

use App\Models\CouponTemplate;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Support\Enums\FontWeight;

class CouponTemplateTable
{
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
                    ->visible(fn() => auth()->user()?->isSuperAdmin()),
                TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('類型')
                    ->options([
                        CouponTemplate::TYPE_FIXED_AMOUNT => '固定金額折抵',
                        CouponTemplate::TYPE_PERCENTAGE => '比例折扣',
                        CouponTemplate::TYPE_FREE_SHIPPING => '免運費',
                        CouponTemplate::TYPE_GIFT => '贈品',
                    ]),
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options([
                        CouponTemplate::STATUS_ACTIVE => '啟用',
                        CouponTemplate::STATUS_INACTIVE => '停用',
                        CouponTemplate::STATUS_DRAFT => '草稿',
                    ]),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\DeleteBulkAction::make(),
            ]);
    }
}
