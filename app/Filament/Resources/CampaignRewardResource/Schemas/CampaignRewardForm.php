<?php

namespace App\Filament\Resources\CampaignRewardResource\Schemas;

use App\Models\CampaignReward;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class CampaignRewardForm
{
    public static function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('活動資訊')
                    ->description('第一步：選擇這個獎勵所屬的活動，確定獎勵的歸屬關係')
                    ->schema([
                        Select::make('campaign_id')
                            ->label('所屬活動')
                            ->placeholder('請搜尋並選擇一個活動')
                            ->relationship('campaign', 'name', function (Builder $query) {
                                $user = auth()->user();
                                $panel = filament()->getCurrentOrDefaultPanel();
                                $filamentTenancyScopeName = $panel?->hasTenancy() ? $panel->getTenancyScopeName() : null;

                                // 先移除所有租戶相關的全域範疇，和 HandlesTenantScoping 保持一致
                                if ($filamentTenancyScopeName) {
                                    $query->withoutGlobalScope($filamentTenancyScopeName);
                                }
                                $query->withoutGlobalScope('tenant');

                                // 如果不是 Super Admin，再套用目前使用者的租戶限制
                                if (!($user && $user->isSuperAdmin())) {
                                    return $query->where('tenant_id', $user?->tenant_id);
                                }

                                return $query;
                            })
                            ->required()
                            ->searchable()
                            ->columnSpanFull()
                            ->helperText('此獎勵將隸屬於您選擇的活動，只有對應活動啟用時此獎勵才會生效'),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),

                Section::make('獎勵設定')
                    ->description('第二步：配置獎勵的具體參數，包括類型、數量和啟用狀態')
                    ->schema([
                        Select::make('reward_type')
                            ->label('獎勵類型')
                            ->placeholder('請選擇獎勵類型')
                            ->options([
                                CampaignReward::TYPE_POINTS => '點數',
                                CampaignReward::TYPE_BADGE => '徽章',
                                CampaignReward::TYPE_COUPON => '優惠券',
                            ])
                            ->required()
                            ->helperText('點數：會員可累積的積分；徽章：成就類榮譽標誌；優惠券：可兌換的折扣券')
                            ->columnSpan(1),
                        TextInput::make('points')
                            ->label('點數數量')
                            ->placeholder('輸入點數數量')
                            ->numeric()
                            ->default(0)
                            ->required()
                            ->helperText('設定獎勵發放的點數數量，所有類型的獎勵皆可設定')
                            ->columnSpan(1),
                        Toggle::make('enabled')
                            ->label('立即啟用此獎勵')
                            ->default(true)
                            ->required()
                            ->helperText('開啟後，此獎勵將立即生效並可派發給符合條件的會員')
                            ->columnSpan(1),
                    ])
                    ->columns(3)
                    ->collapsible()
                    ->columnSpanFull(),
            ]);
    }
}
