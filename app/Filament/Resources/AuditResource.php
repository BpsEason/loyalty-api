<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditResource\Pages;
use App\Filament\Resources\AuditResource\Schemas\AuditForm;
use App\Filament\Resources\AuditResource\Tables\AuditTable;
use App\Models\Audit;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use BackedEnum;

use App\Filament\Concerns\HandlesTenantScoping;

class AuditResource extends Resource
{
    use HandlesTenantScoping;

    protected static ?string $model = Audit::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static string|UnitEnum|null $navigationGroup = '系統管理';
    protected static ?string $navigationLabel = '操作記錄';
    protected static ?int $navigationSort = 99;

    /**
     * 處理Eloquent查詢，租戶隔離由Filament原生機制和Model層全域範圍處理
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        $panel = filament()->getCurrentOrDefaultPanel();

        // 永遠載入必要關聯
        $withRelations = ['tenant', 'user'];

        if ($user && $user->isSuperAdmin() && $panel?->hasTenancy()) {
            $scopeName = $panel->getTenancyScopeName();
            // 對超級管理員移除租戶範圍限制，讓其可以查看所有租戶的記錄
            $query = static::applyTenantScoping($query, $withRelations);
        } else {
            // 一般使用者直接載入，由Model全域範圍自動處理
            $query = static::applyTenantScoping($query, $withRelations);
        }

        return $query->latest();
    }

    public static function form(Schema $schema): Schema
    {
        return AuditForm::schema($schema);
    }

    public static function table(Table $table): Table
    {
        return AuditTable::table($table);
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
            'index' => Pages\ListAudits::route('/'),
            'view' => Pages\ViewAudit::route('/{record}'),
        ];
    }
}
