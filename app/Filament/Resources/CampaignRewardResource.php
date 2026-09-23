<?php

namespace App\Filament\Resources;

use App\Models\CampaignReward;
use App\Filament\Resources\CampaignRewardResource\Pages;
use App\Filament\Resources\CampaignRewardResource\Schemas\CampaignRewardForm;
use App\Filament\Resources\CampaignRewardResource\Tables\CampaignRewardTable;
use App\Filament\Concerns\HandlesTenantScoping;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use BackedEnum;

class CampaignRewardResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = CampaignReward::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-gift';
    protected static string|UnitEnum|null $navigationGroup = '忠誠計劃';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = '活動獎勵';
    protected static ?string $pluralModelLabel = '活動獎勵';
    protected static ?string $navigationLabel = '活動獎勵';
    protected static ?string $tenantOwnershipRelationshipName = 'campaign';

    /**
     * 覆蓋Filament的全域範圍查詢，確保Super Admin能看到所有租戶的活動獎勵
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // 套用共用的租戶範圍邏輯
        return static::applyTenantScoping($query, ['campaign.tenant']);
    }

    public static function form(Schema $schema): Schema
    {
        return CampaignRewardForm::schema($schema);
    }

    public static function table(Table $table): Table
    {
        return CampaignRewardTable::table($table);
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
            'index' => Pages\ListCampaignRewards::route('/'),
            'create' => Pages\CreateCampaignReward::route('/create'),
            'edit' => Pages\EditCampaignReward::route('/{record}/edit'),
        ];
    }

    /**
     * 授權邏輯委託給 CampaignRewardPolicy，由 Laravel 原生授權系統處理
     * Filament 會自動發現並使用註冊的 Policy 進行權限檢查
     */
}
