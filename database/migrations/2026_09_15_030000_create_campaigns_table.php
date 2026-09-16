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
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id()->comment('活動ID');
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade')->comment('所屬租戶ID');
            $table->string('name')->comment('活動名稱');
            $table->text('description')->nullable()->comment('活動描述');
            $table->string('status')->default('draft')->comment('活動狀態：draft草稿, active活躍, inactive停用, completed完成');
            $table->timestamp('starts_at')->nullable()->comment('活動開始時間');
            $table->timestamp('ends_at')->nullable()->comment('活動結束時間');
            $table->timestamps();

            $table->index(['tenant_id', 'status'])->comment('查詢索引：依租戶、狀態');
            $table->index(['starts_at', 'ends_at'])->comment('時間範圍索引');
            $table->comment('獎勵活動資料表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
