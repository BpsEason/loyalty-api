<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_templates', function (Blueprint $table) {
            $table->id()->comment('優惠券模板ID');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete()->comment('所屬租戶ID');

            $table->string('name')->comment('優惠券名稱');
            $table->string('code')->comment('租戶內唯一的優惠券代碼');
            $table->string('type')->comment('優惠券類型：FIXED_AMOUNT固定金額, PERCENTAGE百分比, FREE_SHIPPING免運費, GIFT禮品');

            // Discount fields
            $table->integer('discount_amount')->nullable()->comment('固定金額優惠：折抵金額，適用於FIXED_AMOUNT類型');
            $table->integer('discount_percentage')->nullable()->comment('百分比優惠：折扣比例(1-100)，適用於PERCENTAGE類型');
            $table->integer('max_discount_amount')->nullable()->comment('百分比優惠的最高折抵金額上限');

            $table->integer('minimum_order_amount')->default(0)->comment('使用此優惠券的最低訂單金額門檻');

            // Validity
            $table->timestamp('starts_at')->nullable()->comment('優惠券生效時間');
            $table->timestamp('expires_at')->nullable()->comment('優惠券失效時間');

            // Quantity limits
            $table->integer('total_quantity')->nullable()->comment('可發行的總數量，null代表無限制');
            $table->integer('issued_quantity')->default(0)->comment('已發行的數量');

            // Per customer limit
            $table->integer('per_customer_limit')->default(1)->comment('每位會員可領取的次數上限');

            $table->string('status')->default('active')->comment('模板狀態：active啟用, inactive停用, draft草稿');

            $table->timestamps();

            // Unique constraints
            $table->unique(['tenant_id', 'code'])->comment('同一租戶內的優惠券代碼必須唯一');

            // Indexes
            $table->index(['tenant_id', 'status', 'starts_at', 'expires_at'])->comment('用於租戶內依狀態與有效期間查詢可用的優惠券模板');

            $table->comment('優惠券模板表，定義所有優惠券的規則與參數');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_templates');
    }
};
