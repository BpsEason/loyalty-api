<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Models\Campaign;
use App\Filament\Resources\CampaignResource\Pages;
use App\Filament\Resources\CampaignResource\Schemas\CampaignForm;
use App\Filament\Resources\CampaignResource\Tables\CampaignTable;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use BackedEnum;

class CampaignResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = Campaign::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';
    protected static string|UnitEnum|null $navigationGroup = '忠誠計劃';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = '活動';
    protected static ?string $pluralModelLabel = '活動';
    protected static ?string $navigationLabel = '活動';

    /**
     * 覆蓋Filament的全域範圍查詢，確保Super Admin能看到所有租戶的活動
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // 套用共用的租戶範圍邏輯
        return static::applyTenantScoping($query, ['tenant']);
    }

    public static function form(Schema $schema): Schema
    {
        return CampaignForm::schema($schema);
    }

    public static function table(Table $table): Table
    {
        return CampaignTable::table($table);
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

    /**
     * 授權邏輯委託給 CampaignPolicy，由 Laravel 原生授權系統處理
     * Filament 會自動發現並使用註冊的 Policy 進行權限檢查
     */
}
