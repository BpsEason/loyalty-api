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
        Schema::create('campaign_rewards', function (Blueprint $table) {
            $table->id()->comment('活動獎勵ID');
            $table->foreignId('campaign_id')->constrained()->onDelete('cascade')->comment('所屬活動ID');
            $table->string('reward_type')->comment('獎勵類型：points點數, badge徽章, coupon優惠券');
            $table->integer('points')->default(0)->comment('點數獎勵金額');
            $table->boolean('enabled')->default(true)->comment('是否啟用');
            $table->timestamps();

            $table->index(['campaign_id', 'enabled'])->comment('查詢索引：依活動、是否啟用');
            $table->comment('活動獎勵項目資料表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_rewards');
    }
};
