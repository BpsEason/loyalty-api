<?php

namespace App\Filament\Resources\AuditResource\Schemas;

use Filament\Schemas\Schema;

class AuditForm
{
    public static function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                // 僅用於查看，不需要創建/編輯
            ]);
    }
}
