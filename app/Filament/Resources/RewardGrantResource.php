<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Models\RewardGrant;
use App\Filament\Resources\RewardGrantResource\Pages;
use App\Filament\Resources\RewardGrantResource\Schemas\RewardGrantForm;
use App\Filament\Resources\RewardGrantResource\Schemas\RewardGrantInfolist;
use App\Filament\Resources\RewardGrantResource\Tables\RewardGrantTable;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use BackedEnum;

class RewardGrantResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = RewardGrant::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';
    protected static string|UnitEnum|null $navigationGroup = '忠誠計劃';
    protected static ?int $navigationSort = 3;
    protected static ?string $modelLabel = '獎勵發放';
    protected static ?string $pluralModelLabel = '獎勵發放';
    protected static ?string $navigationLabel = '獎勵發放';

    /**
     * 處理Eloquent查詢，僅處理必要的eager loading
     * 租戶隔離由Filament原生機制和Model層全域範圍處理
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // 完全跟CustomerResource保持一致的写法，使用applyTenantScoping统一处理
        return static::applyTenantScoping($query, [
            'tenant',
            'campaign',
            'campaignReward',
            'customer',
            'pointTransaction',
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return RewardGrantForm::schema($schema);
    }

    public static function table(Table $table): Table
    {
        return RewardGrantTable::table($table);
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
            'index' => Pages\ListRewardGrants::route('/'),
            'view' => Pages\ViewRewardGrant::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()->hasAnyRole(['super_admin', 'tenant_admin']);
    }

    public static function canCreate(): bool
    {
        // 管理員不應該手動建立獎勵發放，應該由系統自動處理
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        // RewardGrant是系統自動產生的記錄，禁止編輯
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        // RewardGrant是系統自動產生的記錄，禁止刪除以保持歷史資料一致性
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return RewardGrantInfolist::schema($schema);
    }
}
