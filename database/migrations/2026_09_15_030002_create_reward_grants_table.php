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
        Schema::create('reward_grants', function (Blueprint $table) {
            $table->id()->comment('獎勵發放記錄ID');
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade')->comment('所屬租戶ID');
            $table->foreignId('campaign_id')->constrained()->onDelete('cascade')->comment('關聯活動ID');
            $table->foreignId('campaign_reward_id')->constrained()->onDelete('cascade')->comment('關聯活動獎勵ID');
            $table->foreignId('customer_id')->constrained()->onDelete('cascade')->comment('關聯客戶ID');
            $table->string('status')->default('pending')->comment('發放狀態：pending待處理, granted已發放, failed失敗');
            $table->timestamp('granted_at')->nullable()->comment('成功發放時間');
            $table->text('failure_reason')->nullable()->comment('失敗原因');
            $table->foreignId('point_transaction_id')->nullable()->constrained()->onDelete('set null')->comment('關聯點數交易ID');
            $table->timestamps();

            // 防止同一客戶在同一活動中重複獲得同一獎勵
            $table->unique(['tenant_id', 'campaign_id', 'campaign_reward_id', 'customer_id'], 'reward_grant_unique')->comment('唯一約束：同一租戶、活動、獎勵、客戶組合唯一');
            $table->index(['tenant_id', 'status'])->comment('查詢索引：依租戶、狀態');
            $table->index(['campaign_id', 'customer_id'])->comment('查詢索引：依活動、客戶');
            $table->comment('獎勵發放記錄表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reward_grants');
    }
};
