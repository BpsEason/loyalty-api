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
        Schema::table('audits', function (Blueprint $table) {
            // 優化查詢單一Model audit history
            $table->index([
                'auditable_type',
                'auditable_id',
                'created_at',
            ]);

            // 優化租戶內特定使用者的操作紀錄查詢
            $table->index([
                'tenant_id',
                'user_id',
                'created_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->dropIndex(['auditable_type', 'auditable_id', 'created_at']);
            $table->dropIndex(['tenant_id', 'user_id', 'created_at']);
        });
    }
};
