<?php

namespace App\Filament\Widgets;

use App\Models\PointTransaction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class RecentTransactionsWidget extends BaseWidget
{
    protected static ?int $sort = 6;
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $user = Auth::user();
        $isSuperAdmin = $user && $user->isSuperAdmin();

        return $table
            ->query(
                PointTransaction::with([
                    'pointAccount.customer',
                    'tenant'
                ])
                    ->latest('created_at')
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('pointAccount.customer.name')
                    ->label('客戶')
                    ->searchable()
                    ->sortable(),
                ...($isSuperAdmin ? [
                    TextColumn::make('tenant.name')
                        ->label('租戶')
                        ->searchable()
                        ->sortable(),
                ] : []),
                TextColumn::make('type')
                    ->label('類型')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        PointTransaction::TYPE_EARN => 'success',
                        PointTransaction::TYPE_REDEEM => 'warning',
                        PointTransaction::TYPE_EXPIRE => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        PointTransaction::TYPE_EARN => '獲得',
                        PointTransaction::TYPE_REDEEM => '使用',
                        PointTransaction::TYPE_ADJUST => '調整',
                        PointTransaction::TYPE_REFUND => '退款',
                        PointTransaction::TYPE_EXPIRE => '過期',
                        default => $state,
                    }),
                TextColumn::make('amount')
                    ->label('金額')
                    ->numeric()
                    ->sortable()
                    ->color(
                        fn(PointTransaction $record): string =>
                        in_array($record->type, [PointTransaction::TYPE_EARN, PointTransaction::TYPE_REFUND]) ? 'text-green-600' : 'text-red-600'
                    )
                    ->formatStateUsing(
                        fn($state, PointTransaction $record): string => (in_array($record->type, [PointTransaction::TYPE_EARN, PointTransaction::TYPE_REFUND]) ? '+' : '-') . number_format($state)
                    ),
                TextColumn::make('description')
                    ->label('描述')
                    ->limit(50),
                TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->paginated(false);
    }

    public function getHeading(): string
    {
        return '最近點數交易';
    }
}
