<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('point_lots', function (Blueprint $table) {
            // 優化全域過期掃描查詢：remaining_points > 0 AND expired_at IS NOT NULL AND expired_at < NOW()
            $table->index([
                'expired_at',
                'remaining_points',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('point_lots', function (Blueprint $table) {
            $table->dropIndex(['expired_at', 'remaining_points']);
        });
    }
};
