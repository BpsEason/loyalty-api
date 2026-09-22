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
        Schema::create('campaign_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade')->comment('所屬租戶ID');
            $table->foreignId('campaign_id')->constrained()->onDelete('cascade')->comment('所屬活動ID');
            $table->enum('rule_type', ['spend_threshold', 'product'])->comment('規則類型：消費門檻或指定商品');
            $table->integer('priority')->default(0)->comment('優先級，數字越高越先評估');
            $table->boolean('status')->default(true)->comment('是否啟用');
            // 規則條件
            $table->decimal('threshold', 12, 2)->nullable()->comment('消費滿額門檻');
            $table->json('product_ids')->nullable()->comment('符合條件的商品ID列表');
            // 獎勵設定
            $table->integer('points_reward')->default(0)->comment('贈送點數');
            $table->json('metadata')->nullable()->comment('其他元數據');
            $table->timestamps();

            // 索引與約束
            $table->index(['tenant_id', 'campaign_id', 'status', 'priority']);
            $table->comment('活動規則資料表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_rules');
    }
};
