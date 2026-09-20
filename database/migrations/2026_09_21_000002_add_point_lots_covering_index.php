<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_lots', function (Blueprint $table) {
            // 為FIFO查詢添加覆蓋索引，優化查詢效能並減少鎖定範圍
            // 覆蓋point_account_id + 排序欄位(earned_at, id) + 查詢欄位(remaining_points)
            $table->index(
                ['point_account_id', 'earned_at', 'id', 'remaining_points'],
                'point_lots_fifo_covering_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('point_lots', function (Blueprint $table) {
            $table->dropIndex('point_lots_fifo_covering_index');
        });
    }
};
