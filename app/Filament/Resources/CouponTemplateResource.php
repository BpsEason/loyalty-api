<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HandlesTenantScoping;
use App\Filament\Resources\CouponTemplateResource\Pages;
use App\Filament\Resources\CouponTemplateResource\Schemas\CouponTemplateForm;
use App\Filament\Resources\CouponTemplateResource\Tables\CouponTemplateTable;
use App\Filament\Resources\CouponTemplateResource\Schemas\CouponTemplateInfolist;
use App\Models\CouponTemplate;
use Filament\Resources\Resource;
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
        return static::applyTenantScoping(parent::getEloquentQuery(), ['tenant']);
    }

    public static function form(Schema $schema): Schema
    {
        return CouponTemplateForm::schema($schema);
    }

    public static function table(Table $table): Table
    {
        return CouponTemplateTable::table($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return CouponTemplateInfolist::schema($schema);
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
