<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_lots', function (Blueprint $table) {
            $table->id()->comment('點數批次ID');
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade')->comment('所屬租戶ID');
            $table->foreignId('customer_id')->constrained()->onDelete('cascade')->comment('關聯客戶ID');
            $table->foreignId('point_account_id')->constrained()->onDelete('cascade')->comment('關聯點數帳戶ID');
            $table->integer('original_points')->comment('原始點數');
            $table->integer('remaining_points')->comment('剩餘可用點數');
            $table->timestamp('earned_at')->comment('獲得時間，用於FIFO排序');
            $table->timestamp('expired_at')->nullable()->comment('過期時間，null表示未過期');
            $table->foreignId('origin_transaction_id')->nullable()->constrained('point_transactions')->onDelete('cascade')->comment('來源交易ID');
            $table->timestamps();

            // 索引：確保FIFO查詢效率，使用earned_at + id保證完全確定性排序
            $table->index(['tenant_id', 'point_account_id', 'earned_at', 'id'])->comment('FIFO查詢索引');
            $table->index(['tenant_id', 'point_account_id', 'expired_at'])->comment('過期處理索引');
            $table->index(['tenant_id', 'customer_id', 'earned_at'])->comment('客戶點數批次查詢');
            $table->comment('點數批次表，實現FIFO消耗與過期管理');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_lots');
    }
};
