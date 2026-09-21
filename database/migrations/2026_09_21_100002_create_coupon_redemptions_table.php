<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id()->comment('優惠券核銷紀錄ID');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete()->comment('所屬租戶ID');
            $table->foreignId('user_coupon_id')->constrained()->cascadeOnDelete()->comment('會員持有的優惠券ID');
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete()->comment('使用優惠券的會員ID');

            $table->string('reference')->comment('優惠券核銷唯一識別碼');
            $table->string('order_reference')->nullable()->comment('外部訂單或交易系統的訂單識別碼');

            $table->integer('discount_amount')->comment('本次核銷實際折抵金額');

            $table->timestamp('redeemed_at')->comment('優惠券實際核銷時間');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->comment('執行核銷操作的後台使用者ID');

            $table->timestamps();

            // Unique constraint
            $table->unique('reference')->comment('核銷參考編號必須唯一');

            // Indexes
            $table->index(['tenant_id', 'customer_id', 'redeemed_at'])->comment('用於租戶內會員優惠券核銷紀錄查詢與時間排序');
            $table->index(['user_coupon_id'])->comment('用於查詢特定會員優惠券的核銷紀錄');

            $table->comment('優惠券核銷紀錄表，保存會員優惠券實際核銷的歷史紀錄');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
