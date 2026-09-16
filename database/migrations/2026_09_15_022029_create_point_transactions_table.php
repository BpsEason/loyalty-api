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
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id()->comment('交易ID');
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade')->comment('所屬租戶ID');
            $table->foreignId('customer_id')->constrained()->onDelete('cascade')->comment('關聯客戶ID');
            $table->foreignId('point_account_id')->constrained()->onDelete('cascade')->comment('關聯點數帳戶ID');
            $table->string('type')->comment('交易類型：earn獲得, redeem使用, adjust調整, refund退款, expire過期');
            $table->integer('amount')->comment('交易金額');
            $table->integer('balance_before')->comment('交易前餘額');
            $table->integer('balance_after')->comment('交易後餘額');
            $table->string('reference_type')->nullable()->comment('關聯實體類型（多態）');
            $table->unsignedBigInteger('reference_id')->nullable()->comment('關聯實體ID（多態）');
            $table->text('description')->nullable()->comment('交易描述');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null')->comment('建立者使用者ID');
            $table->timestamps();

            $table->index(['tenant_id', 'point_account_id', 'created_at'])->comment('查詢索引：依租戶、帳戶、建立時間');
            $table->index(['reference_type', 'reference_id'])->comment('多態關聯索引');
            $table->comment('點數交易記錄表');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_transactions');
    }
};
