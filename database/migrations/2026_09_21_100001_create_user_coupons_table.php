<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_coupons', function (Blueprint $table) {
            $table->id()->comment('會員持有優惠券ID');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete()->comment('所屬租戶ID');
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete()->comment('持有優惠券的會員ID');
            $table->foreignId('coupon_template_id')->constrained()->cascadeOnDelete()->comment('關聯的優惠券模板ID');

            $table->string('status')->comment('優惠券狀態：available可使用, used已使用, expired已過期, cancelled已取消');

            $table->timestamp('issued_at')->comment('優惠券發放給會員的時間');
            $table->timestamp('used_at')->nullable()->comment('優惠券實際使用時間');
            $table->timestamp('expired_at')->nullable()->comment('優惠券過期時間');

            $table->string('reference')->nullable()->comment('外部系統參考編號');

            $table->timestamps();

            // Unique constraint to prevent same customer from claiming the same template beyond limit (database-level enforcement)
            $table->unique(['tenant_id', 'customer_id', 'coupon_template_id'])->comment('防止同一會員重複領取同一模板超過限制，資料庫層級強制');

            // Indexes
            $table->index(['tenant_id', 'customer_id', 'status'])->comment('用於查詢特定租戶下某會員的各狀態優惠券');
            $table->index(['coupon_template_id', 'status'])->comment('用於查詢特定模板下各狀態的會員優惠券');

            $table->comment('會員持有優惠券表，記錄每個會員實際領取並持有的優惠券');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_coupons');
    }
};
