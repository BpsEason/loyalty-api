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
        Schema::create('membership_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade')->comment('所屬租戶ID');
            $table->string('name')->comment('會員等級名稱');
            $table->string('slug')->comment('等級標識符');
            $table->integer('sort_order')->default(0)->comment('排序順序');
            $table->decimal('upgrade_threshold', 12, 2)->default(0)->comment('升級門檻');
            $table->enum('threshold_type', ['spend', 'points'])->default('spend')->comment('門檻類型：累積消費或累積點數');
            $table->boolean('status')->default(true)->comment('是否啟用');
            // 權益欄位
            $table->decimal('points_multiplier', 3, 2)->default(1.00)->comment('點數倍增係數');
            $table->decimal('discount_rate', 5, 4)->default(0.0000)->comment('折扣率');
            $table->boolean('free_shipping')->default(false)->comment('是否免運費');
            $table->json('metadata')->nullable()->comment('其他元數據');
            $table->timestamps();

            // 索引與約束
            $table->unique(['tenant_id', 'slug'])->comment('同一租戶內slug唯一');
            $table->index(['tenant_id', 'status', 'sort_order']);
            $table->comment('會員等級資料表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_tiers');
    }
};
